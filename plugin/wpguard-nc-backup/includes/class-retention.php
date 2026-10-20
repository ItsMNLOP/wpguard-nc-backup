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

	/**
	 * Deletes every folder under $parent_rel except the $keep most
	 * recent (by lexicographic/chronological name order). A no-op if
	 * $keep is 0 (keep-all, the default) or there are $keep or fewer
	 * entries present. The just-finished job's own folder is always the
	 * newest and therefore always kept, by construction (no
	 * special-casing needed) -- this must only ever be called after
	 * that folder is actually complete. $parent_rel is caller-supplied
	 * (not hardcoded to any particular site/backups path here) so this
	 * can be pointed at an isolated folder in tests without touching
	 * real backups.
	 *
	 * @return array{deleted:array<string>, kept:int}|\WP_Error
	 */
	public static function prune( Nextcloud_Client $client, string $parent_rel, int $keep ): array {
		if ( $keep <= 0 ) {
			return array( 'deleted' => array(), 'kept' => -1 );
		}

		$children = $client->list_children( $parent_rel );
		if ( is_wp_error( $children ) ) {
			return $children;
		}

		sort( $children );
		$excess = count( $children ) - $keep;
		if ( $excess <= 0 ) {
			return array( 'deleted' => array(), 'kept' => count( $children ) );
		}

		$to_delete = array_slice( $children, 0, $excess );
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

		return array( 'deleted' => $deleted, 'kept' => count( $children ) - count( $deleted ) );
	}
}
