<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\Nextcloud_Client;
use WPGuard\NCBackup\Logger;

/**
 * ADR 0010 (WebDAV chunking v2). Covers the purely algorithmic pieces
 * of the chunked-upload path directly, without a live server -- the
 * real protocol behavior (MKCOL/PUT/MOVE, resume-after-interruption,
 * the OpenCloud fallback) is proven live in
 * tests/e2e/run-e2e-chunked-upload.sh instead, since it genuinely
 * needs a real WebDAV server to mean anything.
 */

function wpguard_test_make_client(): Nextcloud_Client {
	return new Nextcloud_Client( 'https://nc.example.test', 'u', 'pw', '', new Logger( array() ) );
}

function invoke_upload_session_url( Nextcloud_Client $client, string $upload_id, string $suffix = '' ): string {
	$method = new \ReflectionMethod( Nextcloud_Client::class, 'upload_session_url' );
	$method->setAccessible( true );
	return $method->invoke( $client, $upload_id, $suffix );
}

function test_upload_session_url_is_under_uploads_not_files() {
	$url = invoke_upload_session_url( wpguard_test_make_client(), 'abc123' );
	wpguard_assert( false !== strpos( $url, '/remote.php/dav/uploads/u/abc123' ), 'the chunking-v2 session URL must live under the uploads/ top-level path, not files/' );
	wpguard_assert( false === strpos( $url, '/remote.php/dav/files/' ), 'the chunking-v2 session URL must NOT reuse the files/ root url_for() builds' );
}

function test_upload_session_url_appends_chunk_suffix() {
	$url = invoke_upload_session_url( wpguard_test_make_client(), 'abc123', '000005' );
	wpguard_assert( 1 === preg_match( '#/uploads/u/abc123/000005$#', $url ), 'a chunk suffix must append as a child path segment of the session' );
}

function test_upload_session_url_appends_dot_file_suffix() {
	$url = invoke_upload_session_url( wpguard_test_make_client(), 'abc123', '.file' );
	wpguard_assert( 1 === preg_match( '#/uploads/u/abc123/\.file$#', $url ), 'the assembly-target suffix (.file) must append the same way a chunk suffix does' );
}

function test_upload_session_url_rawurlencodes_the_upload_id() {
	// upload_id is derived from hash('sha256', ...) in practice (always
	// plain lowercase hex), but this must not silently assume that --
	// defense in depth against a future caller passing something else.
	$url = invoke_upload_session_url( wpguard_test_make_client(), 'a b/c' );
	wpguard_assert( false === strpos( $url, 'a b/c' ), 'the upload_id must be encoded, not interpolated raw into the URL path' );
}

function test_chunk_indices_from_names_parses_six_digit_names() {
	$indices = Nextcloud_Client::chunk_indices_from_names( array( '000000', '000001', '000002' ) );
	wpguard_assert_equals( array( 0, 1, 2 ), $indices, 'plain 6-digit chunk names must parse to their integer index, in order' );
}

function test_chunk_indices_from_names_excludes_the_dot_file_entry() {
	$indices = Nextcloud_Client::chunk_indices_from_names( array( '000000', '.file' ) );
	wpguard_assert_equals( array( 0 ), $indices, 'the .file assembly-target entry must never be mistaken for a chunk index' );
}

function test_chunk_indices_from_names_excludes_names_of_the_wrong_length() {
	// A real server never emits these shapes for this collection, but
	// the filter itself must be exact, not merely "looks numeric."
	$indices = Nextcloud_Client::chunk_indices_from_names( array( '00000', '0000000', '1', '' ) );
	wpguard_assert_equals( array(), $indices, 'a name that is not exactly 6 digits must never be parsed as a chunk index' );
}

function test_chunk_indices_from_names_returns_empty_for_empty_input() {
	wpguard_assert_equals( array(), Nextcloud_Client::chunk_indices_from_names( array() ), 'no names at all must mean no chunks' );
}

function test_chunk_size_is_smaller_than_a_reasonable_part_size() {
	// Sanity check on the constant itself: chunking only has a point if
	// CHUNK_SIZE_BYTES is meaningfully smaller than ADR 0004's default
	// 64 MiB PART_SIZE_BYTES bound -- otherwise every part is "one chunk," never resumable mid-part.
	wpguard_assert( Nextcloud_Client::CHUNK_SIZE_BYTES < 64 * 1024 * 1024, 'CHUNK_SIZE_BYTES must be smaller than the default part-size bound for chunking to ever actually sub-divide a part' );
	wpguard_assert( Nextcloud_Client::CHUNK_SIZE_BYTES > 0, 'CHUNK_SIZE_BYTES must be positive' );
}

return array(
	'test_upload_session_url_is_under_uploads_not_files',
	'test_upload_session_url_appends_chunk_suffix',
	'test_upload_session_url_appends_dot_file_suffix',
	'test_upload_session_url_rawurlencodes_the_upload_id',
	'test_chunk_indices_from_names_parses_six_digit_names',
	'test_chunk_indices_from_names_excludes_the_dot_file_entry',
	'test_chunk_indices_from_names_excludes_names_of_the_wrong_length',
	'test_chunk_indices_from_names_returns_empty_for_empty_input',
	'test_chunk_size_is_smaller_than_a_reasonable_part_size',
);
