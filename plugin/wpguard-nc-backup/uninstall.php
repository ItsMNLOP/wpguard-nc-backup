<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'wpguard_nc_settings' );
delete_option( 'wpguard_nc_site_id' );
delete_option( 'wpguard_nc_db_schema_version' );

$table = $wpdb->prefix . 'wpguard_nc_jobs';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore -- table name is a hardcoded constant prefix, not user input.

$tmp_dir = rtrim( WP_CONTENT_DIR, '/\\' ) . '/wpguard-nc-backup-tmp/';
if ( is_dir( $tmp_dir ) ) {
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	@rmdir( $tmp_dir );
}
