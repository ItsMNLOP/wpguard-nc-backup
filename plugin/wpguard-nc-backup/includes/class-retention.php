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
	 * @return array{deleted:array<string>, kept:int}|\WP_Error
	 */
	public static function prune( Nextcloud_Client $client, string $parent_rel, int $keep ): array|\WP_Error {
		if ( $keep <= 0 ) {
			return array( 'deleted' => array(), 'kept' => -1 );
		}

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
		$excess = count( $verified ) - $keep;
		if ( $excess <= 0 ) {
			return array( 'deleted' => array(), 'kept' => count( $verified ) );
		}

		$to_delete = array_slice( $verified, 0, $excess );
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
}
