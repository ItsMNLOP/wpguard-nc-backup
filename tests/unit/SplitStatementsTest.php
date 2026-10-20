<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\DB_Exporter;

// Phase 2 (one-click restore). $wpdb->query() runs exactly one
// statement per call, so the restore DB-apply step must split a dump
// back into individual statements itself -- correctly, since a naive
// split on ";\n" would corrupt the first value containing a literal
// semicolon followed by a newline inside a string.

function test_splits_simple_statements() {
	$sql = "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts` (id INT);\n";
	$result = DB_Exporter::split_statements( $sql );
	wpguard_assert_equals( 2, count( $result ), 'two simple statements must split into two' );
	wpguard_assert_equals( 'DROP TABLE IF EXISTS `wp_posts`;', $result[0], 'first statement must be trimmed and include its own semicolon' );
	wpguard_assert_equals( 'CREATE TABLE `wp_posts` (id INT);', $result[1], 'second statement must be trimmed and include its own semicolon' );
}

function test_does_not_split_on_semicolon_inside_string_literal() {
	$sql = "INSERT INTO `wp_posts` (`post_content`) VALUES ('line one; line two');\nDROP TABLE IF EXISTS `wp_x`;\n";
	$result = DB_Exporter::split_statements( $sql );
	wpguard_assert_equals( 2, count( $result ), 'a semicolon inside a string literal must not create an extra split' );
	wpguard_assert( false !== strpos( $result[0], 'line one; line two' ), 'the full string literal, semicolon included, must survive intact in one statement' );
}

function test_does_not_split_on_semicolon_followed_by_newline_inside_string() {
	// The exact corruption-prone shape: a value containing ";\n" --
	// indistinguishable from a real statement boundary by a naive
	// line-based or regex-based splitter.
	$sql = "INSERT INTO `wp_posts` (`post_content`) VALUES ('para one;\npara two');\n";
	$result = DB_Exporter::split_statements( $sql );
	wpguard_assert_equals( 1, count( $result ), 'a semicolon-then-newline inside a string literal must not split the statement' );
}

function test_handles_escaped_single_quote_inside_string() {
	// esc_sql()'s own escaping shape: a literal ' inside a value
	// becomes \' -- the scanner must not treat that as the string's
	// closing quote.
	$sql = "INSERT INTO `wp_posts` (`post_title`) VALUES ('it\\'s a test; really');\nDROP TABLE IF EXISTS `wp_x`;\n";
	$result = DB_Exporter::split_statements( $sql );
	wpguard_assert_equals( 2, count( $result ), 'an escaped quote inside a string must not end the string early, so the semicolon after it must not split' );
}

function test_handles_escaped_backslash_before_quote() {
	// A value ending in a literal backslash, escaped as \\, followed by
	// the string's REAL closing quote -- the scanner must correctly
	// treat \\ as one escaped character (not accidentally consume the
	// closing quote as if it were escaped too).
	$sql = "INSERT INTO `wp_posts` (`post_title`) VALUES ('path\\\\\\\\');\nDROP TABLE IF EXISTS `wp_x`;\n";
	$result = DB_Exporter::split_statements( $sql );
	wpguard_assert_equals( 2, count( $result ), 'an escaped trailing backslash must not consume the string\'s real closing quote' );
}

function test_handles_quoted_default_value_in_create_table() {
	// A realistic WP column default (post_status-shaped) containing a
	// quoted string inside a CREATE TABLE statement, not just INSERT.
	$sql = "CREATE TABLE `wp_posts` (`post_status` varchar(20) NOT NULL DEFAULT 'auto-draft');\nINSERT INTO `wp_x` VALUES (1);\n";
	$result = DB_Exporter::split_statements( $sql );
	wpguard_assert_equals( 2, count( $result ), 'a quoted DEFAULT value in CREATE TABLE must not confuse the splitter' );
}

function test_ignores_empty_statements_and_whitespace() {
	$sql = "\n\n  ;  \nDROP TABLE IF EXISTS `wp_x`;\n\n";
	$result = DB_Exporter::split_statements( $sql );
	wpguard_assert_equals( 1, count( $result ), 'blank/whitespace-only segments must not produce empty statements' );
}

function test_keeps_trailing_statement_with_no_final_semicolon() {
	$sql = "DROP TABLE IF EXISTS `wp_x`;\nSET FOREIGN_KEY_CHECKS=1";
	$result = DB_Exporter::split_statements( $sql );
	wpguard_assert_equals( 2, count( $result ), 'a trailing statement with no final semicolon must still be returned, not silently dropped' );
}

return array(
	'test_splits_simple_statements',
	'test_does_not_split_on_semicolon_inside_string_literal',
	'test_does_not_split_on_semicolon_followed_by_newline_inside_string',
	'test_handles_escaped_single_quote_inside_string',
	'test_handles_escaped_backslash_before_quote',
	'test_handles_quoted_default_value_in_create_table',
	'test_ignores_empty_statements_and_whitespace',
	'test_keeps_trailing_statement_with_no_final_semicolon',
);
