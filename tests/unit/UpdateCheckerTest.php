<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\Update_Checker;

// Phase 2 (update feed). Covers only the pure transforms (parse_release,
// select_package_asset, is_newer_version, is_trusted_package_url) -- the
// actual GitHub API call and transient caching are network/WP-state
// concerns, exercised instead by tests/e2e/run-e2e-update-checker.sh
// against the real public API.

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

// Vex audit L-001: a package URL must be confirmed to point at this
// specific repo's own release assets, not just any github.com URL,
// before it's trusted enough to hand to WP core's installer.
function test_is_trusted_package_url_accepts_this_repos_release_asset() {
	wpguard_assert( true === Update_Checker::is_trusted_package_url( 'https://github.com/ItsMNLOP/wpguard-nc-backup/releases/download/v1.1.0/wpguard-nc-backup.zip' ), 'a release asset URL from this exact repo must be trusted' );
}

function test_is_trusted_package_url_rejects_different_repo_same_host() {
	wpguard_assert( false === Update_Checker::is_trusted_package_url( 'https://github.com/someone-else/other-repo/releases/download/v1/wpguard-nc-backup.zip' ), 'a release asset from a different repo must never be trusted just because it is also on github.com' );
}

function test_is_trusted_package_url_rejects_bare_host_url() {
	wpguard_assert( false === Update_Checker::is_trusted_package_url( 'https://github.com/evil' ), 'a URL that is merely on github.com, with no matching repo release-download path, must be rejected' );
}

function test_is_trusted_package_url_rejects_non_https() {
	wpguard_assert( false === Update_Checker::is_trusted_package_url( 'http://github.com/ItsMNLOP/wpguard-nc-backup/releases/download/v1/wpguard-nc-backup.zip' ), 'a plain http:// URL must never be trusted, even with an otherwise-matching path' );
}

function test_is_trusted_package_url_accepts_empty_string() {
	wpguard_assert( true === Update_Checker::is_trusted_package_url( '' ), 'an empty package URL (no asset selected) must be allowed through -- callers already treat "" as nothing to offer' );
}

// Vex re-verification, 2026-10-20: the original strncasecmp()-based
// prefix check was proven bypassable via path traversal -- a prefix
// match never inspects what follows it. These cover the exact bypass
// shapes proven live against the real GitHub CDN, plus adjacent
// injection vectors the anchored regex must also close.
function test_is_trusted_package_url_rejects_plain_traversal() {
	wpguard_assert( false === Update_Checker::is_trusted_package_url( 'https://github.com/ItsMNLOP/wpguard-nc-backup/releases/download/../../../../attacker/repo/releases/download/v1/x.zip' ), 'a plain ../ path-traversal tag segment must be rejected' );
}

function test_is_trusted_package_url_rejects_url_encoded_traversal() {
	wpguard_assert( false === Update_Checker::is_trusted_package_url( 'https://github.com/ItsMNLOP/wpguard-nc-backup/releases/download/%2e%2e/%2e%2e/attacker/file.zip' ), 'a URL-encoded ../ path-traversal tag segment must be rejected' );
}

function test_is_trusted_package_url_rejects_mixed_encoded_traversal() {
	wpguard_assert( false === Update_Checker::is_trusted_package_url( 'https://github.com/ItsMNLOP/wpguard-nc-backup/releases/download/..%2f..%2fattacker/file.zip' ), 'a mixed literal/encoded traversal tag segment must be rejected' );
}

function test_is_trusted_package_url_rejects_query_string_injection() {
	wpguard_assert( false === Update_Checker::is_trusted_package_url( 'https://github.com/ItsMNLOP/wpguard-nc-backup/releases/download/v1.1.0/wpguard-nc-backup.zip?q=evil' ), 'a trailing query string must be rejected -- the filename must end exactly at .zip' );
}

function test_is_trusted_package_url_rejects_fragment_injection() {
	wpguard_assert( false === Update_Checker::is_trusted_package_url( 'https://github.com/ItsMNLOP/wpguard-nc-backup/releases/download/v1.1.0/wpguard-nc-backup.zip#evil' ), 'a trailing fragment must be rejected -- the filename must end exactly at .zip' );
}

function test_is_trusted_package_url_rejects_trailing_newline() {
	wpguard_assert( false === Update_Checker::is_trusted_package_url( "https://github.com/ItsMNLOP/wpguard-nc-backup/releases/download/v1.1.0/wpguard-nc-backup.zip\n" ), 'a trailing newline must be rejected -- \\z anchors at the true end of string, not just before a trailing newline the way $ would' );
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
	'test_is_trusted_package_url_accepts_this_repos_release_asset',
	'test_is_trusted_package_url_rejects_different_repo_same_host',
	'test_is_trusted_package_url_rejects_bare_host_url',
	'test_is_trusted_package_url_rejects_non_https',
	'test_is_trusted_package_url_accepts_empty_string',
	'test_is_trusted_package_url_rejects_plain_traversal',
	'test_is_trusted_package_url_rejects_url_encoded_traversal',
	'test_is_trusted_package_url_rejects_mixed_encoded_traversal',
	'test_is_trusted_package_url_rejects_query_string_injection',
	'test_is_trusted_package_url_rejects_fragment_injection',
	'test_is_trusted_package_url_rejects_trailing_newline',
);
