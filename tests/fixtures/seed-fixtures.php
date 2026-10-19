<?php
/**
 * Run via: wp eval-file tests/fixtures/seed-fixtures.php
 * Seeds deterministic, assertable content on the SOURCE site so the E2E
 * restore test can verify specific known values survived the round trip
 * (brief section 9 integration-test requirements: emoji/multibyte, BLOB,
 * serialized data, media checksums, a sentinel option).
 */

$sentinel_title = 'WPGuard sentinel post — émoji check 🎯🧪✅ and multibyte 日本語テスト';
$sentinel_content = "This post exists only to be checked after restore.\n\nMultibyte: héllo wörld, 日本語, emoji 🚀🔥🎉.\n\nA literal single-quote ' and a backslash \\ and a NUL-adjacent byte test follows.";

$existing = get_page_by_title( $sentinel_title, OBJECT, 'post' );
if ( $existing ) {
	$post_id = $existing->ID;
} else {
	$post_id = wp_insert_post(
		array(
			'post_title'   => $sentinel_title,
			'post_content' => $sentinel_content,
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
}
if ( is_wp_error( $post_id ) || ! $post_id ) {
	fwrite( STDERR, "Failed to create sentinel post\n" );
	exit( 1 );
}

// Serialized postmeta (array), to exercise WordPress's serialized-data path.
update_post_meta( $post_id, 'wpguard_sentinel_meta', array( 'nested' => array( 'ok' => true, 'n' => 42 ), 'label' => 'emoji 🎯' ) );

// BLOB-equivalent: binary data in postmeta (stored as a longtext column but
// containing raw binary bytes via base64 would defeat the purpose of
// testing binary-safe export; store raw bytes through $wpdb directly into
// a dedicated option instead, which maps straight to a real column value.
global $wpdb;
$binary = random_bytes( 256 );
$wpdb->replace(
	$wpdb->options,
	array(
		'option_name'  => 'wpguard_sentinel_binary',
		'option_value' => $binary,
		'autoload'     => 'no',
	)
);

// Plain sentinel option, read back by name after restore.
update_option( 'wpguard_sentinel_option', 'sentinel-value-' . wp_hash( 'wpguard' ), true );

// A tiny media attachment with a known, recorded SHA-256.
$upload_dir = wp_upload_dir();
$filename = 'wpguard-sentinel.txt';
$path = trailingslashit( $upload_dir['path'] ) . $filename;
$media_contents = "wpguard sentinel media file\némoji 🎯\n" . bin2hex( random_bytes( 16 ) ) . "\n";
file_put_contents( $path, $media_contents );
$sha256 = hash_file( 'sha256', $path );

$attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'text/plain',
		'post_title'     => 'WPGuard sentinel media',
		'post_status'    => 'inherit',
	),
	$path
);
update_post_meta( $attachment_id, 'wpguard_sentinel_media_sha256', $sha256 );

echo wp_json_encode(
	array(
		'post_id'            => $post_id,
		'post_title'         => $sentinel_title,
		'attachment_id'      => $attachment_id,
		'media_relative_path' => str_replace( trailingslashit( $upload_dir['basedir'] ), '', $path ),
		'media_sha256'       => $sha256,
		'binary_option_len'  => strlen( $binary ),
	)
) . "\n";
