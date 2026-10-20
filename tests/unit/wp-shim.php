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
if ( ! defined( 'WPGUARD_NC_DIR' ) ) {
	define( 'WPGUARD_NC_DIR', ABSPATH . 'wp-content/plugins/wpguard-nc-backup/' );
}
if ( ! defined( 'WPGUARD_NC_FORMAT_VERSION' ) ) {
	define( 'WPGUARD_NC_FORMAT_VERSION', 2 );
}
if ( ! defined( 'WPGUARD_NC_VERSION' ) ) {
	define( 'WPGUARD_NC_VERSION', '1.0.0' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
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
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private array $errors = array();
		private array $error_data = array();
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}
		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = array_key_first( $this->errors );
			}
			return $this->errors[ $code ][0] ?? '';
		}
		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = array_key_first( $this->errors );
			}
			return $this->error_data[ $code ] ?? null;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
