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
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
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
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['__wpguard_test_options'] = array();
$GLOBALS['__wpguard_test_transients'] = array();

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
// security audit finding M-01 (2026-10-23, ADR 0010 chunked upload):
// supports_chunked_upload_v2() had zero test coverage before the bug
// that let it through (misclassifying a probe's HTTP 405 as
// "supported") -- this minimal HTTP mock is what closes that gap.
// Scripted via $GLOBALS['__wpguard_test_http_responses'] (a queue: each
// call to wp_remote_request() shifts one entry off; an empty queue
// defaults to a 200 with no body rather than erroring, so tests that
// don't care about the HTTP layer at all are unaffected). Every call
// is also recorded into $GLOBALS['__wpguard_test_http_calls'] for
// assertions about what was actually requested (method/url).
$GLOBALS['__wpguard_test_http_responses'] = array();
$GLOBALS['__wpguard_test_http_calls'] = array();
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['__wpguard_test_http_calls'][] = array( 'method' => $args['method'] ?? 'GET', 'url' => $url );
		if ( empty( $GLOBALS['__wpguard_test_http_responses'] ) ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => '' );
		}
		return array_shift( $GLOBALS['__wpguard_test_http_responses'] );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return $response['response']['code'] ?? 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return $response['body'] ?? '';
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['__wpguard_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['__wpguard_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['__wpguard_test_transients'][ $key ] );
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
