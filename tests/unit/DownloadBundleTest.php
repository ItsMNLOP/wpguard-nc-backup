<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\Job_Orchestrator;

/**
 * Phase 3 (downloadable backups), security audit finding LOW-DL-5: download_dir_for_token()
 * is the one choke point between a stored/request-adjacent token string
 * and an actual filesystem path this plugin will later wp_mkdir_p()/
 * readfile() -- had no unit coverage at all before this file.
 */

function test_download_dir_for_token_accepts_a_real_token() {
	$token = Job_Orchestrator::new_download_token();
	$dir = Job_Orchestrator::download_dir_for_token( $token );
	wpguard_assert( '' !== $dir, 'a real new_download_token() output must be accepted' );
	wpguard_assert( false !== strpos( $dir, $token ), 'the returned directory must actually contain the token' );
	wpguard_assert( false !== strpos( $dir, Job_Orchestrator::download_base_dir() ), 'the returned directory must live under download_base_dir()' );
}

function test_download_dir_for_token_rejects_empty_string() {
	wpguard_assert( '' === Job_Orchestrator::download_dir_for_token( '' ), 'an empty token must be rejected' );
}

function test_download_dir_for_token_rejects_short_token() {
	wpguard_assert( '' === Job_Orchestrator::download_dir_for_token( str_repeat( 'a', 31 ) ), 'a too-short token must be rejected' );
}

function test_download_dir_for_token_rejects_long_token() {
	wpguard_assert( '' === Job_Orchestrator::download_dir_for_token( str_repeat( 'a', 33 ) ), 'a too-long token must be rejected' );
}

function test_download_dir_for_token_rejects_uppercase_hex() {
	wpguard_assert( '' === Job_Orchestrator::download_dir_for_token( strtoupper( str_repeat( 'ab', 16 ) ) ), 'uppercase hex must be rejected -- new_download_token() only ever produces lowercase' );
}

function test_download_dir_for_token_rejects_non_hex_characters() {
	wpguard_assert( '' === Job_Orchestrator::download_dir_for_token( str_repeat( 'g', 32 ) ), 'non-hex characters must be rejected' );
}

function test_download_dir_for_token_rejects_path_traversal_attempt() {
	$attempt = '../../../../etc/passwd' . str_repeat( '0', 10 );
	wpguard_assert( '' === Job_Orchestrator::download_dir_for_token( $attempt ), 'a traversal-shaped string must be rejected outright, not sanitized' );
}

function test_download_dir_for_token_rejects_embedded_null_byte() {
	$attempt = str_repeat( 'a', 16 ) . "\0" . str_repeat( 'a', 15 );
	wpguard_assert( '' === Job_Orchestrator::download_dir_for_token( $attempt ), 'a token containing a null byte must be rejected' );
}

function test_new_download_token_is_32_lowercase_hex_chars() {
	$token = Job_Orchestrator::new_download_token();
	wpguard_assert( 1 === preg_match( '/^[0-9a-f]{32}$/', $token ), 'new_download_token() must itself always produce exactly what download_dir_for_token() accepts' );
}

return array(
	'test_download_dir_for_token_accepts_a_real_token',
	'test_download_dir_for_token_rejects_empty_string',
	'test_download_dir_for_token_rejects_short_token',
	'test_download_dir_for_token_rejects_long_token',
	'test_download_dir_for_token_rejects_uppercase_hex',
	'test_download_dir_for_token_rejects_non_hex_characters',
	'test_download_dir_for_token_rejects_path_traversal_attempt',
	'test_download_dir_for_token_rejects_embedded_null_byte',
	'test_new_download_token_is_32_lowercase_hex_chars',
);
