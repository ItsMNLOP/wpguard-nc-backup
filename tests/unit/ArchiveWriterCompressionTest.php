<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\Archive_Writer;

/**
 * Perf fix (2026-10-20): already-compressed binary extensions are stored
 * (CM_STORE), not DEFLATEd, to avoid burning CPU for near-zero size
 * benefit. Verifies both that the right compression method actually gets
 * applied per extension, and -- more importantly -- that files extract
 * back byte-identical either way. A correctness bug here would corrupt
 * every JPEG/video/PDF in every backup's file archive.
 */
function test_incompressible_extension_is_stored_not_deflated() {
	$dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpguard_awtest_' . bin2hex( random_bytes( 4 ) ) . '/';
	mkdir( $dir );

	$src = $dir . 'source.bin';
	file_put_contents( $src, random_bytes( 20000 ) );

	$zip_path = $dir . 'test.zip';
	$zip = Archive_Writer::open_part( $zip_path );
	$result = Archive_Writer::add_file_to( $zip, $src, 'photo.jpg' );
	Archive_Writer::close_part( $zip );

	wpguard_assert( true === $result, 'add_file_to must succeed for a normal file' );

	$check = new \ZipArchive();
	$check->open( $zip_path );
	$stat = $check->statName( 'photo.jpg' );
	$check->close();

	wpguard_assert( \ZipArchive::CM_STORE === $stat['comp_method'], 'a .jpg entry must be stored, not DEFLATEd' );

	exec( 'rm -rf ' . escapeshellarg( $dir ) );
}

function test_compressible_extension_still_deflates() {
	$dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpguard_awtest_' . bin2hex( random_bytes( 4 ) ) . '/';
	mkdir( $dir );

	$src = $dir . 'source.css';
	file_put_contents( $src, str_repeat( 'body { color: red; } ', 2000 ) );

	$zip_path = $dir . 'test.zip';
	$zip = Archive_Writer::open_part( $zip_path );
	Archive_Writer::add_file_to( $zip, $src, 'style.css' );
	Archive_Writer::close_part( $zip );

	$check = new \ZipArchive();
	$check->open( $zip_path );
	$stat = $check->statName( 'style.css' );
	$check->close();

	wpguard_assert( \ZipArchive::CM_DEFLATE === $stat['comp_method'], 'a .css entry must still be DEFLATEd' );
	wpguard_assert( $stat['comp_size'] < $stat['size'], 'highly repetitive text must actually shrink when DEFLATEd' );

	exec( 'rm -rf ' . escapeshellarg( $dir ) );
}

function test_stored_and_deflated_entries_both_extract_byte_identical() {
	$dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpguard_awtest_' . bin2hex( random_bytes( 4 ) ) . '/';
	mkdir( $dir );

	$bin_src = $dir . 'source.bin';
	file_put_contents( $bin_src, random_bytes( 20000 ) );
	$bin_sha = hash_file( 'sha256', $bin_src );

	$text_src = $dir . 'source.css';
	file_put_contents( $text_src, str_repeat( 'body { color: red; } ', 2000 ) );
	$text_sha = hash_file( 'sha256', $text_src );

	$zip_path = $dir . 'test.zip';
	$zip = Archive_Writer::open_part( $zip_path );
	Archive_Writer::add_file_to( $zip, $bin_src, 'photo.jpg' );
	Archive_Writer::add_file_to( $zip, $text_src, 'style.css' );
	Archive_Writer::close_part( $zip );

	$extract_dir = $dir . 'out/';
	mkdir( $extract_dir );
	$check = new \ZipArchive();
	$check->open( $zip_path );
	$check->extractTo( $extract_dir );
	$check->close();

	wpguard_assert( hash_file( 'sha256', $extract_dir . 'photo.jpg' ) === $bin_sha, 'stored .jpg entry must extract byte-identical to the source' );
	wpguard_assert( hash_file( 'sha256', $extract_dir . 'style.css' ) === $text_sha, 'deflated .css entry must extract byte-identical to the source' );

	exec( 'rm -rf ' . escapeshellarg( $dir ) );
}

// security audit finding HIGH-ZIP-1: ZipArchive::close() failing rolls back the
// ENTIRE pending batch (every addFile() since the last successful
// close()), not just the entry whose source actually disappeared --
// proven here directly against the real libzip behavior, not assumed.
// This is the exact condition close_part() must now detect and
// propagate instead of silently discarding close()'s return value.
// security re-verification, LOW-TEST-1: the rollback proof below must not
// depend on whether a later ZipArchive::open() of the (possibly now
// corrupted-on-disk) zip happens to succeed -- that made the key
// assertion conditional and silently skippable. Two phases instead:
// phase 1 commits one entry with a confirmed, independent close();
// phase 2 is the batch that gets rolled back. A FRESH open() after
// phase 2 unconditionally proves both halves of the rollback: the
// phase-1 entry survives (the file itself wasn't destroyed), and the
// phase-2 entry whose own source was never touched is nonetheless gone
// (the whole batch rolled back, not just the vanished file).
function test_close_part_reports_failure_when_a_source_file_disappears_mid_batch() {
	$dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpguard_awtest_' . bin2hex( random_bytes( 4 ) ) . '/';
	mkdir( $dir );

	$zip_path = $dir . 'test.zip';

	// Phase 1: one entry, committed via its own successful close().
	$confirmed_src = $dir . 'confirmed.txt';
	file_put_contents( $confirmed_src, 'committed in an earlier, independent close()' );
	$zip = Archive_Writer::open_part( $zip_path );
	Archive_Writer::add_file_to( $zip, $confirmed_src, 'confirmed.txt' );
	$phase1_result = Archive_Writer::close_part( $zip );
	wpguard_assert( true === $phase1_result, 'phase 1 close() must succeed on its own (no missing sources yet)' );

	// Phase 2: a batch that will fail to close -- one file whose source
	// is never touched again, one whose source vanishes before close().
	$survives_src = $dir . 'survives.txt';
	file_put_contents( $survives_src, 'intact source, never touched again' );
	$vanishes_src = $dir . 'vanishes.txt';
	file_put_contents( $vanishes_src, 'source about to disappear before close()' );

	$zip = Archive_Writer::open_part( $zip_path ); // Reopens in append mode (file already exists).
	Archive_Writer::add_file_to( $zip, $survives_src, 'survives.txt' );
	Archive_Writer::add_file_to( $zip, $vanishes_src, 'vanishes.txt' );
	unlink( $vanishes_src ); // Disappears before close() actually reads it.

	$result = Archive_Writer::close_part( $zip );
	wpguard_assert( is_wp_error( $result ), 'close_part() must report failure (not silently succeed) when a batched source file disappears before close()' );

	// Unconditional -- a fresh open() must succeed (the zip file itself
	// still exists and is a valid archive; only phase 2's batch failed).
	$check = new \ZipArchive();
	wpguard_assert( true === $check->open( $zip_path ), 'the zip file itself must still be a valid, openable archive after a failed close() -- only the pending batch is lost, not the file' );
	wpguard_assert( false !== $check->locateName( 'confirmed.txt' ), 'phase 1\'s already-committed entry must survive a later, unrelated close() failure' );
	wpguard_assert( false === $check->locateName( 'survives.txt' ), 'a close() failure must roll back the WHOLE pending batch, including an entry whose own source was never touched -- this is why a half-trusted per-file commit (addFile() succeeding) is not sufficient' );
	wpguard_assert( false === $check->locateName( 'vanishes.txt' ), 'the file whose source actually disappeared must also be absent' );
	$check->close();

	exec( 'rm -rf ' . escapeshellarg( $dir ) );
}

return array(
	'test_incompressible_extension_is_stored_not_deflated',
	'test_compressible_extension_still_deflates',
	'test_stored_and_deflated_entries_both_extract_byte_identical',
	'test_close_part_reports_failure_when_a_source_file_disappears_mid_batch',
);
