<?php
namespace WPThistle\BackupRestore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates an admin-supplied private CA bundle path. ADR 0007: TLS
 * verification is always on; this only ever adds a trusted root, never
 * removes verification.
 */
class CA_Bundle {

	/**
	 * @return true|string True if valid, else a translated error string.
	 */
	/** A real CA bundle (even one with many intermediates) is a few KB; this is a generous ceiling, not a tight one. */
	const MAX_BYTES = 1048576; // 1 MiB

	public static function validate_path( string $path ) {
		if ( ! is_readable( $path ) ) {
			return __( 'CA bundle path does not exist or is not readable by the web server.', 'wpthistle-backup-restore' );
		}
		// security audit finding L-04: this is an admin-only path (manage_options +
		// nonce), but file_get_contents() with no size bound still lets a
		// misconfigured or malicious path (e.g. a device file or a huge
		// file) exhaust PHP's memory limit before the content is even
		// inspected.
		$size = @filesize( $path );
		if ( false !== $size && $size > self::MAX_BYTES ) {
			return __( 'CA bundle file is larger than expected for a certificate bundle; refusing to read it.', 'wpthistle-backup-restore' );
		}
		$contents = file_get_contents( $path );
		if ( false === $contents || '' === trim( $contents ) ) {
			return __( 'CA bundle file is empty or unreadable.', 'wpthistle-backup-restore' );
		}
		if ( false === strpos( $contents, '-----BEGIN CERTIFICATE-----' ) ) {
			return __( 'CA bundle file does not look like a PEM-encoded certificate.', 'wpthistle-backup-restore' );
		}
		if ( function_exists( 'openssl_x509_parse' ) ) {
			$parsed = openssl_x509_parse( $contents );
			if ( false === $parsed ) {
				return __( 'CA bundle file could not be parsed as a valid X.509 certificate.', 'wpthistle-backup-restore' );
			}
		}
		return true;
	}
}
