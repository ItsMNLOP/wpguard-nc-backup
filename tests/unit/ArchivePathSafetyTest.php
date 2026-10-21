<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\Archive_Writer;

function test_rejects_parent_traversal() {
	wpguard_assert( false === Archive_Writer::is_path_safe( '../../etc/passwd' ), 'should reject ../ traversal' );
}
function test_rejects_absolute_unix_path() {
	wpguard_assert( false === Archive_Writer::is_path_safe( '/etc/passwd' ), 'should reject absolute unix path' );
}
function test_rejects_absolute_windows_path() {
	wpguard_assert( false === Archive_Writer::is_path_safe( 'C:\\Windows\\win.ini' ), 'should reject absolute windows path' );
}
function test_rejects_embedded_traversal() {
	wpguard_assert( false === Archive_Writer::is_path_safe( 'wp-content/uploads/../../../evil.php' ), 'should reject embedded ../ segment' );
}
function test_accepts_normal_relative_path() {
	wpguard_assert( true === Archive_Writer::is_path_safe( 'wp-content/themes/twentytwentyfour/style.css' ), 'should accept a normal relative path' );
}
function test_rejects_empty() {
	wpguard_assert( false === Archive_Writer::is_path_safe( '' ), 'should reject empty path' );
}

// security audit finding HIGH-PATH-1 (one-click restore): a bare '..' segment was
// the only thing rejected -- a '.' segment or a doubled separator both
// resolve as a no-op at the filesystem layer and bypassed
// Restore_Orchestrator::is_never_restored_path()'s exact-string-match
// exclusions for wp-config.php / the plugin's own directory, proven
// live: './wp-config.php' and 'wp-content//plugins/wpguard-nc-backup/evil.php'
// both resolve to exactly the protected target.
function test_rejects_single_dot_segment() {
	wpguard_assert( false === Archive_Writer::is_path_safe( './wp-config.php' ), 'a leading ./ segment must be rejected -- it resolves to the bare filename at the filesystem layer' );
}
function test_rejects_single_dot_segment_mid_path() {
	wpguard_assert( false === Archive_Writer::is_path_safe( 'wp-content/./uploads/foo.jpg' ), 'a ./ segment anywhere in the path must be rejected, not just at the start' );
}
function test_accepts_doubled_separator_as_equivalent_to_single() {
	// preg_split()'s '+' quantifier already collapses a doubled
	// separator into one split point, so no segment here is ever
	// actually empty -- correctly treated as equivalent to the
	// single-separator form, same safe set of real segments either
	// way. The bypass the security audit actually found here was one level
	// up: Restore_Orchestrator::is_never_restored_path()'s own EXACT
	// STRING comparison didn't match this form against its normal
	// single-separator exclusion prefix, even though is_path_safe()
	// was never the problem for this specific input -- fixed there via
	// normalize_relative_path(), not here.
	wpguard_assert( true === Archive_Writer::is_path_safe( 'wp-content//plugins/foo.php' ), 'a doubled separator must resolve to the same safe segment set as a single one, not be treated as containing an empty segment' );
}
function test_rejects_trailing_separator() {
	// Unlike a DOUBLED separator (collapsed by preg_split()'s '+'
	// quantifier into one split point, never an empty segment), a
	// TRAILING separator genuinely does produce one real empty
	// segment ('foo/' -> ['foo', '']) -- this is the actual case the
	// empty-segment rejection added for HIGH-PATH-1 exists to catch.
	wpguard_assert( false === Archive_Writer::is_path_safe( 'wp-content/uploads/foo.jpg/' ), 'a trailing separator produces a genuine empty final segment and must be rejected' );
}
function test_rejects_null_byte() {
	wpguard_assert( false === Archive_Writer::is_path_safe( "wp-content/uploads/foo.jpg\0.php" ), 'a NUL byte must be rejected' );
}
function test_accepts_path_with_single_dots_in_filename() {
	// A real, common shape (e.g. "style.min.css") must not be confused
	// with a '.' path SEGMENT -- the rejection is segment-based, not a
	// blanket ban on the '.' character appearing anywhere.
	wpguard_assert( true === Archive_Writer::is_path_safe( 'wp-content/themes/foo/style.min.css' ), 'a dot used inside a filename (not as its own path segment) must still be accepted' );
}

return array(
	'test_rejects_parent_traversal',
	'test_rejects_absolute_unix_path',
	'test_rejects_absolute_windows_path',
	'test_rejects_embedded_traversal',
	'test_accepts_normal_relative_path',
	'test_rejects_empty',
	'test_rejects_single_dot_segment',
	'test_rejects_single_dot_segment_mid_path',
	'test_accepts_doubled_separator_as_equivalent_to_single',
	'test_rejects_trailing_separator',
	'test_rejects_null_byte',
	'test_accepts_path_with_single_dots_in_filename',
);
