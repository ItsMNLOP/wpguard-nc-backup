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

return array(
	'test_rejects_parent_traversal',
	'test_rejects_absolute_unix_path',
	'test_rejects_absolute_windows_path',
	'test_rejects_embedded_traversal',
	'test_accepts_normal_relative_path',
	'test_rejects_empty',
);
