<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 2 retention: keep-last-N pruning of old backup folders on
 * Nextcloud. backup_id is Ymd\THis\Z-<random suffix> (class-job-orchestrator.php
 * create_job()), so a plain lexicographic sort is also chronological
 * order -- no need to parse timestamps out of the name.
 */
class Retention {

	// Matches create_job()'s gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)).
	const BACKUP_ID_PATTERN = '/^\d{8}T\d{6}Z-[0-9a-f]{8}$/';

	/**
	 * Lists genuinely verified backup_ids under $parent_rel, oldest
	 * first (same lexicographic/chronological order as prune()'s own
	 * sort). Shared with the one-click restore feature's backup picker
	 * (Phase 2) -- both need exactly the same "what counts as a real,
	 * restorable/prunable backup" definition: name matches the real
	 * backup_id format AND a COMPLETE.json exists.
	 *
	 * @return array<string>|\WP_Error
	 */
	public static function list_verified( Nextcloud_Client $client, string $parent_rel ) {
		$children = $client->list_children( $parent_rel );
		if ( is_wp_error( $children ) ) {
			return $children;
		}
		$verified = array();
		foreach ( $children as $name ) {
			if ( ! preg_match( self::BACKUP_ID_PATTERN, $name ) ) {
				continue;
			}
			$complete = $client->propfind_size( $parent_rel . '/' . $name . '/COMPLETE.json' );
			if ( is_wp_error( $complete ) || empty( $complete['exists'] ) ) {
				continue; // Failed/interrupted job's folder -- not a real backup.
			}
			$verified[] = $name;
		}
		sort( $verified );
		return $verified;
	}

	/**
	 * Deletes every genuinely verified backup folder under $parent_rel
	 * except the $keep most recent (by lexicographic/chronological name
	 * order). A no-op if $keep is 0 (keep-all, the default) or there
	 * are $keep or fewer verified entries present. The just-finished
	 * job's own folder is always the newest verified entry and
	 * therefore always kept, by construction (no special-casing
	 * needed) -- this must only ever be called after that folder is
	 * actually complete. $parent_rel is caller-supplied (not hardcoded
	 * to any particular site/backups path here) so this can be pointed
	 * at an isolated folder in tests without touching real backups.
	 *
	 * Vex delta audit, 2026-10-20 (RETENTION-2): a folder only counts
	 * as a real backup -- for the keep-N count, and as a deletion
	 * candidate -- if its name matches the backup_id format AND it has
	 * a COMPLETE.json (same completion signal step_finalize() itself
	 * relies on). A failed/interrupted job's folder (created by
	 * step_init() before any failure, possibly carrying only a
	 * diagnostic job-log.json) is neither counted nor deleted: not
	 * counted, so it can't silently consume one of the N "keep" slots
	 * a verified backup should occupy; not deleted, since cleaning up
	 * non-backup remnants is a separate concern from this setting.
	 * Anything else unrecognized under $parent_rel (including a
	 * mis-excluded self-entry, should list_children()'s own exclusion
	 * ever fail again -- RETENTION-1) is likewise just ignored, never
	 * a deletion candidate, as defense in depth.
	 *
	 * Phase 2 (incremental backups): a kept backup's manifest may
	 * reference bytes that physically live in an EARLIER backup's
	 * folder (see class-backup-chain.php) -- an incremental's own
	 * folder can look like an independently prunable "old" backup by
	 * name alone, while still being load-bearing for every newer
	 * backup kept in the same chain. The naive oldest-first scheme
	 * below would delete exactly the chain's base first, since it's
	 * always the oldest, breaking every incremental built on it. Before
	 * computing what's prunable, every one of the `$keep` most recent
	 * verified backups' manifests is read to find the full set of
	 * backup_ids any of them transitively depends on; that set is
	 * never deleted regardless of its own age. For a site that never
	 * uses incremental backups, every manifest's files only ever
	 * reference themselves, so this set is just the `$keep` newest
	 * backups and behaves identically to before (RETENTION-2-era
	 * tests still cover that case).
	 *
	 * @return array{deleted:array<string>, kept:int}|\WP_Error
	 */
	public static function prune( Nextcloud_Client $client, string $parent_rel, int $keep ): array|\WP_Error {
		if ( $keep <= 0 ) {
			return array( 'deleted' => array(), 'kept' => -1 );
		}

		$verified = self::list_verified( $client, $parent_rel );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$excess = count( $verified ) - $keep;
		if ( $excess <= 0 ) {
			return array( 'deleted' => array(), 'kept' => count( $verified ) );
		}

		$must_keep = array();
		foreach ( array_slice( $verified, -$keep ) as $backup_id ) {
			$must_keep[ $backup_id ] = true;
			$refs = self::referenced_source_backups( $client, $parent_rel, $backup_id );
			if ( is_wp_error( $refs ) ) {
				// Fail-closed: if a kept backup's own manifest can't be
				// read to determine what it depends on, nothing is
				// deleted this run rather than risk breaking it.
				return $refs;
			}
			foreach ( $refs as $ref ) {
				$must_keep[ $ref ] = true;
			}
		}

		$to_delete = array_values( array_diff( $verified, array_keys( $must_keep ) ) );
		if ( empty( $to_delete ) ) {
			return array( 'deleted' => array(), 'kept' => count( $verified ) );
		}
		$deleted = array();
		foreach ( $to_delete as $name ) {
			$result = $client->delete( $parent_rel . '/' . $name );
			if ( is_wp_error( $result ) ) {
				// Best-effort: report what was actually deleted before
				// the failure, rather than losing that information by
				// returning only the error.
				return new \WP_Error(
					'wpguard_nc_retention_delete_failed',
					sprintf( 'Deleted %d of %d old backup(s) before a delete failed: %s', count( $deleted ), count( $to_delete ), $result->get_error_message() ),
					array( 'deleted' => $deleted )
				);
			}
			$deleted[] = $name;
		}

		return array( 'deleted' => $deleted, 'kept' => count( $verified ) - count( $deleted ) );
	}

	// Generous relative to realistic file counts; matches
	// Backup_Chain::MAX_MANIFEST_BYTES.
	const MAX_MANIFEST_BYTES = 64 * 1024 * 1024;

	/**
	 * @return array<string>|\WP_Error Distinct backup_ids (excluding
	 *   $backup_id itself) that $backup_id's own manifest.json's `files`
	 *   list points at. Empty for a full backup -- every entry there
	 *   points at itself.
	 */
	private static function referenced_source_backups( Nextcloud_Client $client, string $parent_rel, string $backup_id ) {
		$manifest = $client->get_json( $parent_rel . '/' . $backup_id . '/manifest.json', self::MAX_MANIFEST_BYTES );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$sources = array();
		foreach ( (array) ( $manifest['files'] ?? array() ) as $entry ) {
			$source = $entry['source_backup_id'] ?? '';
			if ( is_string( $source ) && '' !== $source && $source !== $backup_id ) {
				$sources[ $source ] = true;
			}
		}
		return array_keys( $sources );
	}
}
