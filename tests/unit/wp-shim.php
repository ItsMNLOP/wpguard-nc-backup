<?php
/**
 * Minimal WordPress function shim for unit tests that exercise logic
 * which happens to call a few WP helper functions, without pulling in a
 * full WordPress bootstrap. Deliberately tiny — anything needing real
 * WordPress behavior belongs in tests/integration or tests/e2e instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'WPGUARD_NC_FORMAT_VERSION' ) ) {
	define( 'WPGUARD_NC_FORMAT_VERSION', 1 );
}
if ( ! defined( 'WPGUARD_NC_VERSION' ) ) {
	define( 'WPGUARD_NC_VERSION', '1.0.0' );
}

$GLOBALS['__wpguard_test_options'] = array();

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) );
	}
}
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		return $url;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['__wpguard_test_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__wpguard_test_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url() {
		return 'https://unit-test.example.test';
	}
}
