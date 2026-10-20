<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\Update_Checker;

// Phase 2 (update feed). Covers only the pure transforms (parse_release,
// select_package_asset, is_newer_version) -- the actual GitHub API call
// and transient caching are network/WP-state concerns, exercised instead
// by tests/e2e/run-e2e-update-checker.sh against the real public API.

function test_select_package_asset_picks_matching_zip() {
	$assets = array(
		array( 'name' => 'wpguard-nc-backup.zip', 'browser_download_url' => 'https://example.test/wpguard-nc-backup.zip' ),
	);
	wpguard_assert_equals( 'https://example.test/wpguard-nc-backup.zip', Update_Checker::select_package_asset( $assets ), 'a .zip asset whose name starts with the plugin slug must be selected' );
}

function test_select_package_asset_ignores_non_matching_assets() {
	$assets = array(
		array( 'name' => 'wpguard-nc-backup.zip.sha256', 'browser_download_url' => 'https://example.test/checksum' ),
		array( 'name' => 'source.tar.gz', 'browser_download_url' => 'https://example.test/source.tar.gz' ),
	);
	wpguard_assert_equals( '', Update_Checker::select_package_asset( $assets ), 'a checksum file or non-.zip asset must never be selected as the install package' );
}

function test_select_package_asset_returns_empty_with_no_assets() {
	wpguard_assert_equals( '', Update_Checker::select_package_asset( array() ), 'no assets at all must select nothing, not error' );
}

function test_parse_release_strips_leading_v_from_tag() {
	$release = Update_Checker::parse_release( array( 'tag_name' => 'v1.2.0', 'body' => 'notes', 'assets' => array() ) );
	wpguard_assert_equals( '1.2.0', $release['version'], 'a v-prefixed tag must have the v stripped' );
}

function test_parse_release_rejects_missing_tag_name() {
	$release = Update_Checker::parse_release( array( 'body' => 'notes' ) );
	wpguard_assert( null === $release, 'a response with no tag_name must be rejected, not produce a half-built release' );
}

function test_parse_release_defaults_missing_body_to_empty_string() {
	$release = Update_Checker::parse_release( array( 'tag_name' => 'v1.0.1' ) );
	wpguard_assert_equals( '', $release['body'], 'a missing body field must default to an empty string, not null or an error' );
}

function test_is_newer_version_true_for_real_upgrade() {
	wpguard_assert( true === Update_Checker::is_newer_version( '1.1.0', '1.0.0' ), '1.1.0 must be considered newer than 1.0.0' );
}

function test_is_newer_version_false_for_same_version() {
	wpguard_assert( false === Update_Checker::is_newer_version( '1.0.0', '1.0.0' ), 'the same version must never be offered as an update' );
}

function test_is_newer_version_false_for_older_or_blank() {
	wpguard_assert( false === Update_Checker::is_newer_version( '0.9.0', '1.0.0' ), 'an older version string must never be offered as an update' );
	wpguard_assert( false === Update_Checker::is_newer_version( '', '1.0.0' ), 'an empty version string (no usable release found) must never be offered as an update' );
}

return array(
	'test_select_package_asset_picks_matching_zip',
	'test_select_package_asset_ignores_non_matching_assets',
	'test_select_package_asset_returns_empty_with_no_assets',
	'test_parse_release_strips_leading_v_from_tag',
	'test_parse_release_rejects_missing_tag_name',
	'test_parse_release_defaults_missing_body_to_empty_string',
	'test_is_newer_version_true_for_real_upgrade',
	'test_is_newer_version_false_for_same_version',
	'test_is_newer_version_false_for_older_or_blank',
);
