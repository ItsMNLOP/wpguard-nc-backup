<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebDAV transport adapter. The only class that knows Nextcloud's WebDAV
 * URL shapes (MNT-03). Never sets sslverify=>false (ADR 0007, threat-model
 * T11 — enforced here and checked by tests/unit/NoInsecureTlsTest.php).
 */
class Nextcloud_Client {

	private string $base_url;
	private string $user;
	private string $app_password;
	private string $ca_bundle_path;
	private Logger $logger;

	public function __construct( string $base_url, string $user, string $app_password, string $ca_bundle_path, Logger $logger ) {
		$this->base_url       = rtrim( $base_url, '/' );
		$this->user           = $user;
		$this->app_password   = $app_password;
		$this->ca_bundle_path = $ca_bundle_path;
		$this->logger         = $logger;
	}

	public static function from_settings( Logger $logger ): self {
		return new self(
			Settings::get_nextcloud_url(),
			Settings::get_nextcloud_user(),
			Settings::get_app_password(),
			Settings::get_ca_bundle_path(),
			$logger
		);
	}

	/**
	 * Full remote.php/dav URL for a path relative to the user's WebDAV
	 * root. Does NOT prepend the configured destination folder — callers
	 * that want a path under it must build it via relative_path() first.
	 * (Keeping this one literal, instead of two different "relative to
	 * what" conventions, is what fixed a real double-prefix bug found
	 * during E2E testing: mkcol_recursive() needs to create the
	 * destination folder itself as part of its chain, which an
	 * auto-prepending url_for() made impossible to express correctly.)
	 */
	public function url_for( string $relative_path ): string {
		$relative_path = trim( $relative_path, '/' );
		$encoded_user = rawurlencode( $this->user );
		$encoded_path = $relative_path === '' ? '' : implode( '/', array_map( 'rawurlencode', explode( '/', $relative_path ) ) );
		return $this->base_url . '/remote.php/dav/files/' . $encoded_user . ( $encoded_path !== '' ? '/' . $encoded_path : '' );
	}

	/** Path relative to the WebDAV root, under the configured destination folder. */
	public function relative_path( string $suffix ): string {
		$folder = trim( Settings::get_remote_folder(), '/' );
		$suffix = trim( $suffix, '/' );
		return $suffix === '' ? $folder : $folder . '/' . $suffix;
	}

	/**
	 * @return array|\WP_Error Response array from wp_remote_request, or WP_Error.
	 */
	private function request( string $method, string $url, array $args = array() ) {
		$defaults = array(
			'method'    => $method,
			'timeout'   => 30,
			'headers'   => array(
				'Authorization' => 'Basic ' . base64_encode( $this->user . ':' . $this->app_password ),
			),
			'sslverify' => true,
			// WebDAV methods have no standardized redirect semantics (a
			// followed redirect can silently turn a PUT into a GET that
			// returns 200, or downgrade https->http on the same host,
			// forwarding the Authorization header over plaintext). A
			// correctly configured Nextcloud server never redirects
			// WebDAV requests; if one does, that must surface as an
			// error, not be followed transparently.
			'redirection' => 0,
		);
		if ( '' !== $this->ca_bundle_path ) {
			$defaults['sslcertificates'] = $this->ca_bundle_path;
		}
		$args = array_replace_recursive( $defaults, $args );
		if ( isset( $args['headers'] ) && isset( $defaults['headers'] ) ) {
			$args['headers'] = array_merge( $defaults['headers'], $args['headers'] );
		}

		$attempts = 0;
		$max_attempts = 4;
		do {
			$attempts++;
			$response = wp_remote_request( $url, $args );
			if ( ! is_wp_error( $response ) ) {
				$code = wp_remote_retrieve_response_code( $response );
				if ( $code < 500 || $attempts >= $max_attempts ) {
					return $response;
				}
			} elseif ( $attempts >= $max_attempts ) {
				return $response;
			}
			$delay_ms = (int) ( 250 * ( 2 ** ( $attempts - 1 ) ) + random_int( 0, 250 ) );
			usleep( $delay_ms * 1000 );
		} while ( $attempts < $max_attempts );

		return $response;
	}

	/** Creates a remote collection (directory). Idempotent: 405 (already exists) is treated as success. */
	public function mkcol( string $relative_path ) {
		$response = $this->request( 'MKCOL', $this->url_for( $relative_path ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 201, 405 ), true ) ) {
			return true;
		}
		return new \WP_Error( 'wpguard_nc_mkcol_failed', sprintf( 'MKCOL failed with HTTP %d', $code ), array( 'status' => $code ) );
	}

	/** Creates nested remote directories one level at a time. */
	public function mkcol_recursive( string $relative_path ) {
		$parts = array_filter( explode( '/', trim( $relative_path, '/' ) ) );
		$built = '';
		foreach ( $parts as $part ) {
			$built = ( '' === $built ) ? $part : $built . '/' . $part;
			$result = $this->mkcol( $built );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/** Uploads bytes already read into memory (bounded by caller's part-size cap, ADR 0004). */
	public function put_bytes( string $relative_path, string $bytes ) {
		$response = $this->request(
			'PUT',
			$this->url_for( $relative_path ),
			array( 'body' => $bytes, 'timeout' => 120 )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 200, 201, 204 ), true ) ) {
			return true;
		}
		return new \WP_Error( 'wpguard_nc_put_failed', sprintf( 'PUT failed with HTTP %d', $code ), array( 'status' => $code ) );
	}

	/**
	 * PROPFIND (Depth: 0) for existence + reported content length.
	 * Never trusts an ETag as a hash (ADR 0006) — this only reports size.
	 *
	 * @return array{exists:bool, length:int}|\WP_Error
	 */
	public function propfind_size( string $relative_path ) {
		$body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/></d:prop></d:propfind>';
		$response = $this->request(
			'PROPFIND',
			$this->url_for( $relative_path ),
			array(
				'headers' => array( 'Depth' => '0', 'Content-Type' => 'application/xml' ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return array( 'exists' => false, 'length' => 0 );
		}
		if ( 207 !== $code ) {
			return new \WP_Error( 'wpguard_nc_propfind_failed', sprintf( 'PROPFIND failed with HTTP %d', $code ), array( 'status' => $code ) );
		}
		$xml_text = wp_remote_retrieve_body( $response );
		$previous = libxml_use_internal_errors( true );
		// LIBXML_NONET: defense in depth against external entity loading
		// in a server's PROPFIND response (Vex audit L-03). Already
		// disabled by default on the libxml >=2.9 that ships with this
		// plugin's required PHP 8.1+, but explicit here so the guarantee
		// doesn't silently depend on a future runtime's default.
		$xml = simplexml_load_string( $xml_text, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return new \WP_Error( 'wpguard_nc_propfind_parse_failed', 'Could not parse PROPFIND XML response.' );
		}
		$xml->registerXPathNamespace( 'd', 'DAV:' );
		$nodes = $xml->xpath( '//d:getcontentlength' );
		$length = ( $nodes && isset( $nodes[0] ) ) ? (int) (string) $nodes[0] : 0;
		return array( 'exists' => true, 'length' => $length );
	}

	/**
	 * Downloads a remote object to a local temp file, streamed (never fully
	 * buffered in memory), aborting early if the declared size is exceeded
	 * beyond tolerance (threat-model T7, archive-bomb defense).
	 *
	 * @return true|\WP_Error
	 */
	public function download_to_file( string $relative_path, string $local_path, int $expected_max_bytes = 0 ) {
		$args = array(
			'stream'   => true,
			'filename' => $local_path,
			'timeout'  => 300,
		);
		$tolerance = 0;
		if ( $expected_max_bytes > 0 ) {
			$tolerance = max( 4096, (int) ( $expected_max_bytes * 0.01 ) );
			// Vex audit L-02: abort while streaming once the response
			// exceeds the manifest's declared size (plus tolerance),
			// rather than only discovering the overrun after the full
			// object has already landed on disk.
			$args['limit_response_size'] = $expected_max_bytes + $tolerance;
		}
		$response = $this->request( 'GET', $this->url_for( $relative_path ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'wpguard_nc_get_failed', sprintf( 'GET failed with HTTP %d', $code ), array( 'status' => $code ) );
		}
		// Kept as defense in depth: limit_response_size's behavior isn't
		// identical across every WP HTTP transport, so the post-hoc check
		// still catches anything that slipped past the streaming limit.
		if ( $expected_max_bytes > 0 && file_exists( $local_path ) ) {
			$actual = filesize( $local_path );
			if ( $actual > $expected_max_bytes + $tolerance ) {
				unlink( $local_path );
				return new \WP_Error( 'wpguard_nc_size_exceeded', 'Downloaded object exceeded the manifest-declared size; aborted.' );
			}
		}
		return true;
	}

	/**
	 * PROPFIND (Depth: 1) for the immediate child names of a collection.
	 * Phase 2 retention pruning uses this to enumerate existing
	 * backup_id folders before deciding what to delete. Returns bare
	 * names (the collection's own entry is excluded, matched by
	 * comparing the response href's path against this collection's own
	 * URL path -- not by position/order, which WebDAV makes no guarantee
	 * about), URL-decoded, not full paths.
	 *
	 * @return array<string>|\WP_Error
	 */
	public function list_children( string $relative_path ) {
		$body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';
		$response = $this->request(
			'PROPFIND',
			$this->url_for( $relative_path ),
			array(
				'headers' => array( 'Depth' => '1', 'Content-Type' => 'application/xml' ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return array();
		}
		if ( 207 !== $code ) {
			return new \WP_Error( 'wpguard_nc_propfind_list_failed', sprintf( 'PROPFIND (list) failed with HTTP %d', $code ), array( 'status' => $code ) );
		}
		$xml_text = wp_remote_retrieve_body( $response );
		$previous = libxml_use_internal_errors( true );
		// LIBXML_NONET: same defense-in-depth rationale as propfind_size() (Vex audit L-03).
		$xml = simplexml_load_string( $xml_text, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return new \WP_Error( 'wpguard_nc_propfind_list_parse_failed', 'Could not parse PROPFIND XML response.' );
		}
		$xml->registerXPathNamespace( 'd', 'DAV:' );
		$self_path = rtrim( (string) wp_parse_url( $this->url_for( $relative_path ), PHP_URL_PATH ), '/' );
		$names = array();
		foreach ( $xml->xpath( '//d:response' ) as $resp ) {
			$resp->registerXPathNamespace( 'd', 'DAV:' );
			$href_nodes = $resp->xpath( 'd:href' );
			if ( empty( $href_nodes ) ) {
				continue;
			}
			$href_path = rtrim( (string) wp_parse_url( (string) $href_nodes[0], PHP_URL_PATH ), '/' );
			if ( $href_path === $self_path ) {
				continue; // Depth:1 includes the collection itself.
			}
			$segments = explode( '/', $href_path );
			$names[] = rawurldecode( end( $segments ) );
		}
		return $names;
	}

	public function delete( string $relative_path ) {
		$response = $this->request( 'DELETE', $this->url_for( $relative_path ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 200, 204, 404 ), true ) ) {
			return true;
		}
		return new \WP_Error( 'wpguard_nc_delete_failed', sprintf( 'DELETE failed with HTTP %d', $code ), array( 'status' => $code ) );
	}

	/**
	 * Reversible connection test: PUT a uniquely named temp object, read
	 * back its size, delete it. Returns a structured, distinct-reason
	 * result — never just true/false — per CFG-02/TRN-05.
	 *
	 * @return array{ok:bool, reason:string, message:string}
	 */
	public function test_connection(): array {
		$probe_name = '.wpguard-nc-backup-test-' . bin2hex( random_bytes( 6 ) );

		$mk = $this->mkcol_recursive( trim( Settings::get_remote_folder(), '/' ) );
		if ( is_wp_error( $mk ) ) {
			return $this->classify_failure( $mk, 'folder' );
		}

		$probe_rel = $this->relative_path( $probe_name );
		$put = $this->put_bytes( $probe_rel, 'wpguard-nc-backup-connection-test' );
		if ( is_wp_error( $put ) ) {
			return $this->classify_failure( $put, 'write' );
		}

		$info = $this->propfind_size( $probe_rel );
		if ( is_wp_error( $info ) || empty( $info['exists'] ) ) {
			$this->delete( $probe_rel );
			return array( 'ok' => false, 'reason' => 'read', 'message' => __( 'Wrote the test file but could not read it back.', 'wpguard-nc-backup' ) );
		}

		$del = $this->delete( $probe_rel );
		if ( is_wp_error( $del ) ) {
			return array( 'ok' => false, 'reason' => 'delete', 'message' => __( 'Connected and wrote/read a test file, but could not delete it. Check folder permissions.', 'wpguard-nc-backup' ) );
		}

		return array( 'ok' => true, 'reason' => 'ok', 'message' => __( 'Connection verified: authenticated, and a test file could be written, read back, and deleted.', 'wpguard-nc-backup' ) );
	}

	private function classify_failure( \WP_Error $error, string $stage ): array {
		$data = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$message = $error->get_error_message();

		if ( 401 === $status || 403 === $status ) {
			return array( 'ok' => false, 'reason' => 'auth', 'message' => __( 'Authentication failed (401/403). Check the username and app password.', 'wpguard-nc-backup' ) );
		}
		if ( 404 === $status ) {
			return array( 'ok' => false, 'reason' => 'not_found', 'message' => __( 'Destination not found (404). Check the Nextcloud URL and folder path.', 'wpguard-nc-backup' ) );
		}
		if ( 507 === $status ) {
			return array( 'ok' => false, 'reason' => 'quota', 'message' => __( 'Nextcloud reported insufficient storage/quota.', 'wpguard-nc-backup' ) );
		}
		if ( 0 === $status ) {
			return array( 'ok' => false, 'reason' => 'network', 'message' => __( 'Network or TLS error reaching Nextcloud: ', 'wpguard-nc-backup' ) . $this->logger->redact( $message ) );
		}
		return array( 'ok' => false, 'reason' => $stage, 'message' => $this->logger->redact( $message ) );
	}
}
