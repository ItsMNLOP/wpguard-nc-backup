<?php
namespace WPGuard\NCBackup;

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
	public static function validate_path( string $path ) {
		if ( ! is_readable( $path ) ) {
			return __( 'CA bundle path does not exist or is not readable by the web server.', 'wpguard-nc-backup' );
		}
		$contents = file_get_contents( $path );
		if ( false === $contents || '' === trim( $contents ) ) {
			return __( 'CA bundle file is empty or unreadable.', 'wpguard-nc-backup' );
		}
		if ( false === strpos( $contents, '-----BEGIN CERTIFICATE-----' ) ) {
			return __( 'CA bundle file does not look like a PEM-encoded certificate.', 'wpguard-nc-backup' );
		}
		if ( function_exists( 'openssl_x509_parse' ) ) {
			$parsed = openssl_x509_parse( $contents );
			if ( false === $parsed ) {
				return __( 'CA bundle file could not be parsed as a valid X.509 certificate.', 'wpguard-nc-backup' );
			}
		}
		return true;
	}
}
