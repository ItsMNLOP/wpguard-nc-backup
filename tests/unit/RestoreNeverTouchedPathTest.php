<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\Restore_Orchestrator;

// Phase 2 (one-click restore), security audit finding HIGH-PATH-1: the actual
// bypass was in is_never_restored_path()'s own exact-string comparison
// -- './wp-config.php' and 'wp-content//plugins/wpthistle-backup-restore/evil.php'
// both resolve to exactly the protected target at the filesystem layer,
// but neither matched the literal prefix/filename strings this method
// compared against before it normalized first. is_never_restored_path()
// is private; reached via reflection, the same way the security audit
// verified it.

function invoke_is_never_restored_path( string $relative ): bool {
	$method = new \ReflectionMethod( Restore_Orchestrator::class, 'is_never_restored_path' );
	$method->setAccessible( true );
	return $method->invoke( null, $relative );
}

function test_blocks_dot_slash_wp_config() {
	wpguard_assert( true === invoke_is_never_restored_path( './wp-config.php' ), "'./wp-config.php' must be recognized as wp-config.php after normalization" );
}

function test_blocks_doubled_separator_into_plugin_dir() {
	wpguard_assert( true === invoke_is_never_restored_path( 'wp-content//plugins/wpthistle-backup-restore/evil.php' ), 'a doubled separator leading into the plugin\'s own directory must still be recognized after normalization' );
}

function test_blocks_plain_wp_config() {
	wpguard_assert( true === invoke_is_never_restored_path( 'wp-config.php' ), 'the exact, un-decorated path must still be recognized (no regression from adding normalization)' );
}

function test_blocks_case_variant_wp_config() {
	wpguard_assert( true === invoke_is_never_restored_path( 'WP-CONFIG.PHP' ), 'a case-variant of wp-config.php must be recognized -- defense in depth for case-insensitive filesystems' );
}

function test_does_not_block_unrelated_path() {
	wpguard_assert( false === invoke_is_never_restored_path( 'wp-content/uploads/2026/10/photo.jpg' ), 'an ordinary, unrelated path must never be blocked' );
}

function test_does_not_block_file_merely_named_like_plugin_dir_prefix() {
	// A file whose name happens to start with the same characters as
	// the plugin's own directory name, but is NOT actually inside it,
	// must not be blocked -- the match is a real path-segment prefix,
	// not a naive string prefix.
	wpguard_assert( false === invoke_is_never_restored_path( 'wp-content/plugins/wpthistle-backup-restore-other/file.php' ), 'a differently-named sibling directory must not be mistaken for the plugin\'s own directory' );
}

// 2.0.0 rename: found live, restoring a real pre-rename backup -- its
// own archived files are under the OLD plugin's directory name, which
// own_plugin_dir_prefix() (derived from the CURRENT plugin constant)
// has no way to recognize on its own. legacy_own_plugin_dir_prefixes()
// must cover it explicitly.
function test_blocks_legacy_plugin_dir_from_a_pre_rename_backup() {
	wpguard_assert( true === invoke_is_never_restored_path( 'wp-content/plugins/wpguard-nc-backup/includes/class-admin.php' ), 'a pre-rename backup\'s own archived copy of the OLD plugin\'s files must still be excluded, not just the current plugin directory' );
}

return array(
	'test_blocks_dot_slash_wp_config',
	'test_blocks_doubled_separator_into_plugin_dir',
	'test_blocks_plain_wp_config',
	'test_blocks_case_variant_wp_config',
	'test_does_not_block_unrelated_path',
	'test_does_not_block_file_merely_named_like_plugin_dir_prefix',
	'test_blocks_legacy_plugin_dir_from_a_pre_rename_backup',
);
