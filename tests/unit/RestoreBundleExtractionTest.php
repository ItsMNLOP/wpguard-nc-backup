<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\Restore_Orchestrator;

/**
 * Restore_Orchestrator::extract_bundle_zip() -- extracted out of
 * ajax_upload_restore_bundle() so the WP-CLI restore_local command
 * (CLI gap found 2026-10-22: wp-cli had no credential-free restore
 * path, unlike the admin UI's upload-a-bundle route) shares the exact
 * same path-safety-critical logic rather than a second copy of it.
 * No unit coverage existed for this logic before -- only the E2E
 * upload-restore drill exercised it, and only on the happy path.
 */

function wpguard_test_make_bundle_zip( array $entries ): string {
	$path = sys_get_temp_dir() . '/wpguard-bundle-test-' . bin2hex( random_bytes( 8 ) ) . '.zip';
	$zip = new \ZipArchive();
	$zip->open( $path, \ZipArchive::CREATE );
	foreach ( $entries as $name => $content ) {
		$zip->addFromString( $name, $content );
	}
	$zip->close();
	return $path;
}

function wpguard_test_make_holding_dir(): string {
	$dir = sys_get_temp_dir() . '/wpguard-bundle-holding-' . bin2hex( random_bytes( 8 ) ) . '/';
	mkdir( $dir, 0700, true );
	return $dir;
}

function test_extract_bundle_zip_extracts_a_flat_bundle() {
	$zip_path = wpguard_test_make_bundle_zip( array(
		'manifest.json' => '{"backup_id":"abc"}',
		'database.sql.gz' => 'sql-bytes',
	) );
	$holding = wpguard_test_make_holding_dir();

	$result = Restore_Orchestrator::extract_bundle_zip( $zip_path, $holding );

	wpguard_assert( true === $result, 'a flat bundle must extract successfully' );
	wpguard_assert( file_exists( $holding . 'manifest.json' ), 'manifest.json must land directly in the holding dir' );
	wpguard_assert( '{"backup_id":"abc"}' === file_get_contents( $holding . 'manifest.json' ), 'extracted content must be byte-identical' );
	unlink( $zip_path );
}

function test_extract_bundle_zip_strips_a_single_common_wrapper_directory() {
	// The real-world shape found live: downloading the backup_id-named
	// folder straight from Nextcloud's web UI wraps every entry in it.
	$zip_path = wpguard_test_make_bundle_zip( array(
		'20261022T000000Z-deadbeef/manifest.json' => '{"backup_id":"wrapped"}',
		'20261022T000000Z-deadbeef/database.sql.gz' => 'sql-bytes',
	) );
	$holding = wpguard_test_make_holding_dir();

	$result = Restore_Orchestrator::extract_bundle_zip( $zip_path, $holding );

	wpguard_assert( true === $result, 'a single-common-wrapper bundle must extract successfully' );
	wpguard_assert( file_exists( $holding . 'manifest.json' ), 'the wrapper segment must be stripped so manifest.json lands flat' );
	wpguard_assert( ! file_exists( $holding . '20261022T000000Z-deadbeef' ), 'the wrapper directory name itself must not appear in the holding dir' );
	unlink( $zip_path );
}

function test_extract_bundle_zip_refuses_a_traversal_entry_name() {
	$zip_path = wpguard_test_make_bundle_zip( array(
		'../../etc/passwd' => 'evil',
	) );
	$holding = wpguard_test_make_holding_dir();

	$result = Restore_Orchestrator::extract_bundle_zip( $zip_path, $holding );

	wpguard_assert( \is_wp_error( $result ), 'a traversal-shaped entry name must be refused, not sanitized' );
	$written = array_values( array_diff( scandir( $holding ), array( '.', '..' ) ) );
	wpguard_assert( array() === $written, 'nothing must be written into the holding dir for a refused bundle' );
	unlink( $zip_path );
}

function test_extract_bundle_zip_refuses_mixed_nesting_with_no_common_wrapper() {
	// One entry flat, one entry one level deep -- not a single common
	// wrapper, must still refuse exactly as the flat-only contract says.
	$zip_path = wpguard_test_make_bundle_zip( array(
		'manifest.json' => '{}',
		'subdir/database.sql.gz' => 'sql-bytes',
	) );
	$holding = wpguard_test_make_holding_dir();

	$result = Restore_Orchestrator::extract_bundle_zip( $zip_path, $holding );

	wpguard_assert( \is_wp_error( $result ), 'mixed nesting with no single common wrapper must be refused' );
	unlink( $zip_path );
}

function test_extract_bundle_zip_rejects_a_non_zip_file() {
	$not_a_zip = sys_get_temp_dir() . '/wpguard-not-a-zip-' . bin2hex( random_bytes( 8 ) ) . '.txt';
	file_put_contents( $not_a_zip, 'definitely not a zip' );
	$holding = wpguard_test_make_holding_dir();

	$result = Restore_Orchestrator::extract_bundle_zip( $not_a_zip, $holding );

	wpguard_assert( \is_wp_error( $result ), 'a non-zip file must be rejected' );
	unlink( $not_a_zip );
}

function test_extract_bundle_zip_rewrites_a_bundle_supplied_htaccess() {
	// security audit finding LOW-HTACCESS-1: a bundle's own .htaccess
	// (Inventory::enumerate() always captures it) must never survive
	// extraction -- the deny-all guard is rewritten last, unconditionally.
	$zip_path = wpguard_test_make_bundle_zip( array(
		'manifest.json' => '{}',
		'.htaccess' => "Allow from all\n",
	) );
	$holding = wpguard_test_make_holding_dir();

	$result = Restore_Orchestrator::extract_bundle_zip( $zip_path, $holding );

	wpguard_assert( true === $result, 'extraction must still succeed' );
	wpguard_assert( false !== strpos( file_get_contents( $holding . '.htaccess' ), 'Require all denied' ), 'the bundle-supplied .htaccess must be overwritten with the deny-all guard, not kept' );
	unlink( $zip_path );
}

return array(
	'test_extract_bundle_zip_extracts_a_flat_bundle',
	'test_extract_bundle_zip_strips_a_single_common_wrapper_directory',
	'test_extract_bundle_zip_refuses_a_traversal_entry_name',
	'test_extract_bundle_zip_refuses_mixed_nesting_with_no_common_wrapper',
	'test_extract_bundle_zip_rejects_a_non_zip_file',
	'test_extract_bundle_zip_rewrites_a_bundle_supplied_htaccess',
);
