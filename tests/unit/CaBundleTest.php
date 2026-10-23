<?php
namespace WPThistle\BackupRestore\Tests;
use WPThistle\BackupRestore\CA_Bundle;

function test_rejects_oversized_file_before_reading_content() {
	$path = tempnam( sys_get_temp_dir(), 'wpguard_ca_' );
	// One byte over the cap, and deliberately not PEM-shaped content —
	// if the size check didn't run first, this would fail with the
	// "does not look like PEM" error instead of the size error.
	$fh = fopen( $path, 'w' );
	fseek( $fh, CA_Bundle::MAX_BYTES ); // sparse-write past the cap
	fwrite( $fh, 'x' );
	fclose( $fh );

	$result = CA_Bundle::validate_path( $path );
	unlink( $path );

	wpguard_assert( true !== $result, 'an oversized file must be rejected' );
	wpguard_assert( false !== strpos( (string) $result, 'larger than expected' ), 'rejection must be the size-cap error, proving the size check runs before content is read' );
}

function test_accepts_small_file_past_the_size_gate() {
	$path = tempnam( sys_get_temp_dir(), 'wpguard_ca_' );
	file_put_contents( $path, "-----BEGIN CERTIFICATE-----\nnot-a-real-cert\n-----END CERTIFICATE-----\n" );

	$result = CA_Bundle::validate_path( $path );
	unlink( $path );

	// A small file must get past the size gate; it then correctly fails
	// X.509 parsing (not a real certificate) — proving the size check
	// doesn't over-reject legitimate small files.
	wpguard_assert( true !== $result, 'a non-parseable fake certificate must still be rejected' );
	wpguard_assert( false === strpos( (string) $result, 'larger than expected' ), 'a small file must not be rejected for size' );
}

return array(
	'test_rejects_oversized_file_before_reading_content',
	'test_accepts_small_file_past_the_size_gate',
);
