<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'wpthistle_backup_settings' );
delete_option( 'wpthistle_backup_site_id' );
delete_option( 'wpthistle_backup_db_schema_version' );
delete_option( 'wpthistle_backup_chain_state' );
delete_option( 'wpthistle_backup_retention_last_error' );

// Vex security audit (2026-10-23, non-blocking LOW): a site migrated
// from the pre-2.0.0 plugin (migrate_from_legacy_wpguard()) left these
// legacy-named rows in place deliberately, non-destructively. If this
// plugin is later removed by deleting its files directly rather than
// through WP's own plugin-delete flow, nothing else ever runs this
// file against the OLD name either -- cleaned up here too, so
// uninstalling this plugin doesn't leave a credential-bearing legacy
// row behind permanently.
delete_option( 'wpguard_nc_settings' );
delete_option( 'wpguard_nc_site_id' );
delete_option( 'wpguard_nc_db_schema_version' );
delete_option( 'wpguard_nc_chain_state' );
delete_option( 'wpguard_nc_retention_last_error' );

$table = $wpdb->prefix . 'wpthistle_backup_jobs';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore -- table name is a hardcoded constant prefix, not user input.

$tmp_dir = rtrim( WP_CONTENT_DIR, '/\\' ) . '/wpthistle-backup-restore-tmp/';
if ( is_dir( $tmp_dir ) ) {
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	@rmdir( $tmp_dir );
}
