<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 2 incremental backups. Design note (not a diff/patch chain):
 * every backup's manifest `files` list is always a COMPLETE, resolved
 * index of every current file, each entry naming the `source_backup_id`
 * + `source_part` where its bytes actually live -- its own freshly
 * archived part for a new/changed file, or an earlier chain member's
 * part, carried forward verbatim, for an unchanged one. A deletion
 * needs no separate tracking: a removed file simply has no entry the
 * next time Inventory::enumerate() runs, so it's absent from that
 * backup's (complete, not diffed) `files` list and therefore never
 * resurrected on restore. This keeps restore logic uniform (walk the
 * latest manifest's `files` list, fetch each distinct referenced part
 * once) with no chain-walking or patch-apply step required.
 *
 * Local WP-options state (not remote) decides whether to attempt an
 * incremental this run -- cheap, and fails safe: if the option is ever
 * lost (fresh install, migration), the next backup just does a full,
 * never guesses. Even when local state says "incremental is safe," the
 * immediately-previous backup's manifest and every part it transitively
 * references are re-verified against the real server before being
 * trusted (see plan()) -- if anything referenced has gone missing, this
 * falls back to a full for this run rather than failing the backup or
 * guessing.
 */
class Backup_Chain {

	const STATE_OPTION = 'wpguard_nc_chain_state';

	// Bounds how long a chain can grow before a full is forced, by
	// whichever limit is hit first. Two independent bounds: a pure
	// count catches an actively-changing site; a time cap catches a
	// quiet site where the count rarely trips, bounding the window in
	// which the (size, mtime) unchanged-check's known blind spot -- a
	// file whose content changed but whose mtime was deliberately or
	// incidentally preserved -- can go undetected. Both filterable
	// since "how long is reasonable" varies by site.
	const MAX_CHAIN_DEFAULT = 7;
	const MAX_CHAIN_DAYS_DEFAULT = 7;

	// Generous relative to realistic file counts (~150-250 bytes of
	// JSON per file-index entry); matches the PART_SIZE_BYTES order of
	// magnitude used elsewhere as "large but bounded."
	const MAX_MANIFEST_BYTES = 64 * 1024 * 1024;

	private static function get_state(): ?array {
		$state = get_option( self::STATE_OPTION, null );
		if ( ! is_array( $state ) || empty( $state['base_backup_id'] ) || empty( $state['parent_backup_id'] ) ) {
			return null;
		}
		return $state;
	}

	private static function full_plan(): array {
		return array(
			'mode'               => 'full',
			'base_backup_id'     => null,
			'parent_backup_id'   => null,
			'depth'              => 0,
			'base_created_at'    => null,
			'prior_files_index'  => array(),
		);
	}

	/**
	 * Decides full vs incremental for the job about to run, and -- only
	 * when attempting incremental -- fetches and validates the
	 * immediately-previous chain backup's manifest. Every distinct
	 * (source_backup_id, source_part) its `files` list references is
	 * confirmed to still exist remotely before any of its entries are
	 * trusted; any failure anywhere in this process (setting disabled,
	 * no local state, chain too long/old, manifest missing/corrupt, a
	 * referenced part gone) falls back to a full, never partially
	 * trusts a parent it couldn't fully verify.
	 */
	public static function plan( Nextcloud_Client $client, string $parent_rel ): array {
		if ( ! Settings::get_incremental_enabled() ) {
			return self::full_plan();
		}

		$state = self::get_state();
		if ( null === $state ) {
			return self::full_plan();
		}

		$max_chain = max( 1, (int) apply_filters( 'wpguard_nc_incremental_max_chain', self::MAX_CHAIN_DEFAULT ) );
		$max_days  = max( 1, (int) apply_filters( 'wpguard_nc_incremental_max_chain_days', self::MAX_CHAIN_DAYS_DEFAULT ) );

		if ( (int) $state['depth'] >= $max_chain ) {
			return self::full_plan();
		}
		if ( ( time() - (int) $state['base_created_at'] ) >= $max_days * DAY_IN_SECONDS ) {
			return self::full_plan();
		}

		$parent_manifest = $client->get_json( $parent_rel . '/' . $state['parent_backup_id'] . '/manifest.json', self::MAX_MANIFEST_BYTES );
		if ( is_wp_error( $parent_manifest ) || empty( $parent_manifest['files'] ) || ! is_array( $parent_manifest['files'] ) ) {
			return self::full_plan();
		}

		$referenced_parts = array();
		$prior_index = array();
		foreach ( $parent_manifest['files'] as $entry ) {
			if ( empty( $entry['path'] ) || empty( $entry['source_backup_id'] ) || empty( $entry['source_part'] ) ) {
				// the security audit-grade paranoia: a manifest whose shape we can't fully
				// trust must not be partially trusted -- fall back whole.
				return self::full_plan();
			}
			$prior_index[ $entry['path'] ] = $entry;
			$referenced_parts[ $entry['source_backup_id'] ][ $entry['source_part'] ] = true;
		}

		foreach ( $referenced_parts as $source_backup_id => $parts ) {
			foreach ( array_keys( $parts ) as $part_name ) {
				$check = $client->propfind_size( $parent_rel . '/' . $source_backup_id . '/' . $part_name );
				if ( is_wp_error( $check ) || empty( $check['exists'] ) ) {
					// A referenced ancestor part has gone missing (manual
					// deletion, a retention bug, server-side data loss) --
					// fall back to a full rather than produce a backup
					// that silently can't be fully restored.
					return self::full_plan();
				}
			}
		}

		return array(
			'mode'              => 'incremental',
			'base_backup_id'    => $state['base_backup_id'],
			'parent_backup_id'  => $state['parent_backup_id'],
			'depth'             => (int) $state['depth'] + 1,
			'base_created_at'   => (int) $state['base_created_at'],
			'prior_files_index' => $prior_index,
		);
	}

	/**
	 * Called once, from step_finalize(), only after the job is already
	 * marked verified -- a failed job must never advance (or reset)
	 * local chain state, same discipline as retention pruning only ever
	 * running post-verification.
	 *
	 * security audit finding LOW-CHAIN-1: previously re-read the option here for
	 * base_created_at instead of using the value already captured in
	 * $chain_plan at plan() time (step_init(), earlier in this same
	 * job) -- if the option were lost in between (an aggressive
	 * object-cache flush, an options-table restore mid-job), the
	 * `?? time()` fallback would silently reset the chain's age clock
	 * rather than surfacing anything wrong. Using the plan's own
	 * snapshot removes the redundant re-read and the failure mode
	 * along with it -- not just a cosmetic change.
	 */
	public static function record_result( array $chain_plan, string $backup_id ): void {
		if ( 'incremental' === $chain_plan['mode'] ) {
			update_option( self::STATE_OPTION, array(
				'base_backup_id'   => $chain_plan['base_backup_id'],
				'parent_backup_id' => $backup_id,
				'depth'            => $chain_plan['depth'],
				'base_created_at'  => $chain_plan['base_created_at'],
			), false );
			return;
		}

		update_option( self::STATE_OPTION, array(
			'base_backup_id'   => $backup_id,
			'parent_backup_id' => $backup_id,
			'depth'            => 0,
			'base_created_at'  => time(),
		), false );
	}
}
