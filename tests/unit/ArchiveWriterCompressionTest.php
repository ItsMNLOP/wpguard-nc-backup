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

return array(
	'test_incompressible_extension_is_stored_not_deflated',
	'test_compressible_extension_still_deflates',
	'test_stored_and_deflated_entries_both_extract_byte_identical',
);
