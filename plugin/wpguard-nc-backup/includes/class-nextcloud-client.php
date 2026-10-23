<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebDAV transport adapter. The only class that knows the WebDAV URL
 * shape (MNT-03) all three verified backends share: Nextcloud, ownCloud
 * classic (10.x), and OpenCloud (confirmed live against 7.2.4's legacy
 * remote.php/dav/files/{user} compatibility shim -- see STATUS.md and
 * docs/threat-model.md T22). Not retested against oCIS, which likely
 * rides along since OpenCloud is its fork, or any other WebDAV server.
 * Never sets sslverify=>false (ADR 0007, threat-model T11 — enforced
 * here and checked by tests/unit/NoInsecureTlsTest.php).
 *
 * ADR 0010: upload_part() uses resumable WebDAV chunking v2 when the
 * server supports it (probed, not assumed -- confirmed live against
 * Nextcloud and ownCloud, confirmed absent on OpenCloud), falling back
 * to a single whole-file PUT otherwise.
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
		// in a server's PROPFIND response (security audit finding L-03). Already
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
			// security audit finding L-02: abort while streaming once the response
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
		return $this->list_children_at_url( $this->url_for( $relative_path ) );
	}

	/**
	 * Shared by list_children() (relative to the files/ root) and the
	 * chunked-upload resume logic (list_uploaded_chunk_indices(), which
	 * needs the uploads/ collection's own absolute URL, a different
	 * top-level path than files/ -- not expressible via url_for()).
	 *
	 * @return array<string>|\WP_Error
	 */
	private function list_children_at_url( string $url ) {
		$body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';
		$response = $this->request(
			'PROPFIND',
			$url,
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
		// LIBXML_NONET: same defense-in-depth rationale as propfind_size() (security audit finding L-03).
		$xml = simplexml_load_string( $xml_text, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return new \WP_Error( 'wpguard_nc_propfind_list_parse_failed', 'Could not parse PROPFIND XML response.' );
		}
		$xml->registerXPathNamespace( 'd', 'DAV:' );
		// the security audit delta audit, 2026-10-20 (RETENTION-1): compared encoded, this
		// match silently failed whenever the Nextcloud username contained
		// a character PHP's rawurlencode() (used by url_for()) escapes but
		// Nextcloud/Sabre's own href encoder does not (confirmed against
		// the real server: '@', '(', ')', ':' are left unescaped by
		// Sabre but escaped by rawurlencode()) -- the self-entry was then
		// never excluded, and its bare folder name ("backups") leaked
		// into the result as if it were a real sibling entry. Decoding
		// both sides before comparing makes the match encoder-agnostic.
		$self_path = rawurldecode( rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
		$names = array();
		foreach ( $xml->xpath( '//d:response' ) as $resp ) {
			$resp->registerXPathNamespace( 'd', 'DAV:' );
			$href_nodes = $resp->xpath( 'd:href' );
			if ( empty( $href_nodes ) ) {
				continue;
			}
			$href_path = rawurldecode( rtrim( (string) wp_parse_url( (string) $href_nodes[0], PHP_URL_PATH ), '/' ) );
			if ( $href_path === $self_path ) {
				continue; // Depth:1 includes the collection itself.
			}
			$segments = explode( '/', $href_path );
			$names[] = end( $segments );
		}
		return $names;
	}

	/**
	 * Phase 2 (incremental backups / chain-aware retention): fetches and
	 * JSON-decodes a small remote object -- a manifest.json, specifically
	 * -- rather than returning raw bytes, since every current caller
	 * (Backup_Chain, Retention) only ever wants decoded data. Reuses
	 * download_to_file()'s existing streamed-download-with-size-cap
	 * machinery (same archive-bomb defense as any other download here)
	 * instead of a new unbounded in-memory GET.
	 *
	 * @return array|\WP_Error Decoded associative array, or a WP_Error if
	 *   the object doesn't exist, can't be downloaded, exceeds $max_bytes,
	 *   or isn't valid JSON that decodes to an array.
	 */
	public function get_json( string $relative_path, int $max_bytes ) {
		$tmp = wp_tempnam( 'wpguard-nc-manifest' );
		$download = $this->download_to_file( $relative_path, $tmp, $max_bytes );
		if ( is_wp_error( $download ) ) {
			@unlink( $tmp );
			return $download;
		}
		$raw = file_get_contents( $tmp );
		@unlink( $tmp );
		if ( false === $raw ) {
			return new \WP_Error( 'wpguard_nc_get_json_read_failed', "Could not read downloaded object: {$relative_path}" );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'wpguard_nc_get_json_decode_failed', "Downloaded object is not valid JSON: {$relative_path}" );
		}
		return $decoded;
	}

	/**
	 * ADR 0010 (WebDAV chunking v2). Sub-chunk size for a resumable
	 * chunked upload -- deliberately much smaller than ADR 0004's
	 * PART_SIZE_BYTES bound (that cap stays unchanged; this splits each
	 * already-bounded part further, purely for transport-level
	 * resumability and lower peak memory per HTTP request). 8 MiB
	 * bounds a single retry's wasted bandwidth/time on a flaky
	 * connection without creating a pathological chunk count for a
	 * default 64 MiB part (8 chunks).
	 */
	const CHUNK_SIZE_BYTES = 8 * 1024 * 1024;

	/** Absolute URL for a WebDAV chunking v2 upload session (or one of its chunk sub-resources). A different top-level path (uploads/) than url_for()'s files/ root. */
	private function upload_session_url( string $upload_id, string $suffix = '' ): string {
		$encoded_user = rawurlencode( $this->user );
		$encoded_id   = rawurlencode( $upload_id );
		$url = $this->base_url . '/remote.php/dav/uploads/' . $encoded_user . '/' . $encoded_id;
		return '' === $suffix ? $url : $url . '/' . $suffix;
	}

	/**
	 * Probes whether the configured server supports WebDAV chunking v2
	 * at all (a throwaway MKCOL on the uploads/ collection, cleaned up
	 * immediately), caching the result for a day per server URL so
	 * every part of every future backup doesn't re-probe. Confirmed
	 * live (2026-10-22, ADR 0010): Nextcloud 34 and ownCloud 10.15
	 * support this identically; OpenCloud 7.2.4 returns 404 on this
	 * exact MKCOL -- not assumed, tested directly against all three.
	 *
	 * security audit finding M-01 (2026-10-23): the probe uses a FRESH
	 * random ID every time, which cannot already exist on the server --
	 * so unlike put_file_chunked()'s own MKCOL (where 405 correctly
	 * means "this deterministic session already exists, resume it"), a
	 * 405 here can only mean the server doesn't allow MKCOL on this
	 * collection at all. Treating it as "supported" (proven live:
	 * cached "yes" for a full day, every real upload attempt then fails
	 * trying to use a transport the server doesn't actually offer)
	 * directly contradicted this method's own claim of working
	 * correctly against a server this plugin has never been tested
	 * against. Only 201 means supported.
	 */
	public function supports_chunked_upload_v2(): bool {
		$cache_key = 'wpguard_nc_chunk_v2_support_' . md5( $this->base_url );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		$probe_id = 'probe-' . bin2hex( random_bytes( 8 ) );
		$mk = $this->request( 'MKCOL', $this->upload_session_url( $probe_id ) );
		$supported = ! is_wp_error( $mk ) && 201 === wp_remote_retrieve_response_code( $mk );
		if ( $supported ) {
			$this->request( 'DELETE', $this->upload_session_url( $probe_id ) );
		}
		set_transient( $cache_key, $supported ? 'yes' : 'no', DAY_IN_SECONDS );
		return $supported;
	}

	/**
	 * Re-discovers which numbered chunks already exist in an upload
	 * session by asking the server, not by trusting any local counter
	 * -- the same "never trust local-only state, re-verify against the
	 * real server" discipline this project already uses elsewhere
	 * (Backup_Chain re-verifying the prior chain backup live before
	 * trusting it). This is what makes a chunked upload genuinely
	 * resumable across a PHP process that was killed outright mid-loop
	 * with no chance to persist anything of its own: a fresh attempt,
	 * even with zero local state, re-derives the same deterministic
	 * $upload_id (see upload_part()'s docblock) and picks up exactly
	 * where the server says the last attempt left off.
	 *
	 * @return array<int>
	 */
	private function list_uploaded_chunk_indices( string $upload_id ): array {
		$names = $this->list_children_at_url( $this->upload_session_url( $upload_id ) );
		if ( is_wp_error( $names ) ) {
			// A session that doesn't exist yet (shouldn't happen right
			// after our own successful MKCOL, but fail closed rather
			// than assume) reads the same as "no chunks uploaded yet."
			return array();
		}
		return self::chunk_indices_from_names( $names );
	}

	/**
	 * Pure filter, split out from list_uploaded_chunk_indices() so this
	 * name-matching logic (which must exclude the session's own `.file`
	 * assembly target and anything else that isn't a plain 6-digit
	 * chunk name) is directly unit-testable without a live server.
	 *
	 * @param array<string> $names
	 * @return array<int>
	 */
	public static function chunk_indices_from_names( array $names ): array {
		$indices = array();
		foreach ( $names as $name ) {
			if ( 1 === preg_match( '/^\d{6}$/', $name ) ) {
				$indices[] = (int) $name;
			}
		}
		return $indices;
	}

	/**
	 * Uploads one local file via WebDAV chunking v2 (MKCOL the upload
	 * session, PUT each not-already-uploaded numbered chunk, MOVE
	 * .file to $relative_path with Destination + OC-Total-Length --
	 * all three steps confirmed live against Nextcloud 34 and ownCloud
	 * 10.15, ADR 0010). Reads the local file in CHUNK_SIZE_BYTES pieces
	 * via fopen/fread, never buffering more than one chunk in memory.
	 * $total_size must be the file's real size, read by the caller
	 * before any retry could change it.
	 *
	 * @return true|\WP_Error
	 */
	private function put_file_chunked( string $local_path, string $relative_path, string $upload_id, int $total_size ) {
		$mk = $this->request( 'MKCOL', $this->upload_session_url( $upload_id ) );
		if ( is_wp_error( $mk ) ) {
			return $mk;
		}
		$mk_code = wp_remote_retrieve_response_code( $mk );
		if ( ! in_array( $mk_code, array( 201, 405 ), true ) ) {
			return new \WP_Error( 'wpguard_nc_chunk_mkcol_failed', sprintf( 'Chunked-upload session MKCOL failed with HTTP %d', $mk_code ), array( 'status' => $mk_code ) );
		}

		$already = $this->list_uploaded_chunk_indices( $upload_id );

		$fh = fopen( $local_path, 'rb' );
		if ( false === $fh ) {
			return new \WP_Error( 'wpguard_nc_chunk_open_failed', "Could not open local file for chunked read: {$local_path}" );
		}
		$index = 0;
		while ( ! feof( $fh ) ) {
			$chunk = fread( $fh, self::CHUNK_SIZE_BYTES );
			if ( false === $chunk ) {
				fclose( $fh );
				return new \WP_Error( 'wpguard_nc_chunk_read_failed', "Could not read local file for chunked upload: {$local_path}" );
			}
			if ( '' === $chunk ) {
				break;
			}
			if ( ! in_array( $index, $already, true ) ) {
				$put = $this->request(
					'PUT',
					$this->upload_session_url( $upload_id, sprintf( '%06d', $index ) ),
					array( 'body' => $chunk, 'timeout' => 120 )
				);
				if ( is_wp_error( $put ) ) {
					fclose( $fh );
					return $put;
				}
				$put_code = wp_remote_retrieve_response_code( $put );
				if ( ! in_array( $put_code, array( 200, 201, 204 ), true ) ) {
					fclose( $fh );
					return new \WP_Error( 'wpguard_nc_chunk_put_failed', sprintf( 'Chunk upload failed with HTTP %d', $put_code ), array( 'status' => $put_code ) );
				}
			}
			$index++;
		}
		fclose( $fh );

		$move = $this->request(
			'MOVE',
			$this->upload_session_url( $upload_id, '.file' ),
			array(
				// security audit finding L-02 (2026-10-23): the default
				// 30s timeout could fire before the server finishes
				// assembling a large number of chunks server-side (a
				// multi-hundred-MB database.sql.gz, say); request()'s
				// own retry on timeout can then hit the session the
				// server already finished assembling and auto-removed,
				// surfacing a spurious 404 instead of the real success.
				// Matches download_to_file()'s timeout for a similarly
				// large server-side operation.
				'timeout' => 300,
				'headers' => array(
					'Destination'     => $this->url_for( $relative_path ),
					'OC-Total-Length' => (string) $total_size,
				),
			)
		);
		if ( is_wp_error( $move ) ) {
			return $move;
		}
		$move_code = wp_remote_retrieve_response_code( $move );
		if ( in_array( $move_code, array( 200, 201, 204 ), true ) ) {
			return true;
		}
		return new \WP_Error( 'wpguard_nc_chunk_move_failed', sprintf( 'Chunk-assembly MOVE failed with HTTP %d', $move_code ), array( 'status' => $move_code ) );
	}

	/**
	 * Uploads a local file to $relative_path -- the one entry point
	 * step_upload_parts() calls, replacing a raw file_get_contents()
	 * +put_bytes() pair. Uses resumable WebDAV chunking v2 when the
	 * server supports it (supports_chunked_upload_v2()) and the file is
	 * bigger than one chunk; a single whole-file PUT otherwise (small
	 * files, or a server without chunking-v2 support -- confirmed live,
	 * not assumed, that OpenCloud needs this fallback, ADR 0010).
	 *
	 * $upload_id must be a STABLE, deterministic string the caller
	 * derives from data that doesn't change across retries of the same
	 * logical upload (e.g. job_id + this part's own remote path) --
	 * never a fresh random token per call. A fresh ID on every attempt
	 * would silently orphan the previous attempt's server-side session
	 * and defeat the entire point of resumability; reusing the same ID
	 * is what lets list_uploaded_chunk_indices() find and skip whatever
	 * a prior, possibly-crashed attempt already got through.
	 *
	 * @return true|\WP_Error
	 */
	public function upload_part( string $local_path, string $relative_path, string $upload_id ) {
		$size = @filesize( $local_path );
		if ( false === $size ) {
			return new \WP_Error( 'wpguard_nc_upload_part_stat_failed', "Could not stat local file: {$local_path}" );
		}
		if ( $size <= self::CHUNK_SIZE_BYTES || ! $this->supports_chunked_upload_v2() ) {
			$bytes = file_get_contents( $local_path );
			if ( false === $bytes ) {
				return new \WP_Error( 'wpguard_nc_upload_part_read_failed', "Could not read local file: {$local_path}" );
			}
			return $this->put_bytes( $relative_path, $bytes );
		}
		return $this->put_file_chunked( $local_path, $relative_path, $upload_id, $size );
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
			return array( 'ok' => false, 'reason' => 'auth', 'message' => __( 'Authentication failed (401/403). Check the username and app password / app token.', 'wpguard-nc-backup' ) );
		}
		if ( 404 === $status ) {
			return array( 'ok' => false, 'reason' => 'not_found', 'message' => __( 'Destination not found (404). Check the server URL and folder path.', 'wpguard-nc-backup' ) );
		}
		if ( 507 === $status ) {
			return array( 'ok' => false, 'reason' => 'quota', 'message' => __( 'Server reported insufficient storage/quota.', 'wpguard-nc-backup' ) );
		}
		if ( 0 === $status ) {
			return array( 'ok' => false, 'reason' => 'network', 'message' => __( 'Network or TLS error reaching the server: ', 'wpguard-nc-backup' ) . $this->logger->redact( $message ) );
		}
		return array( 'ok' => false, 'reason' => $stage, 'message' => $this->logger->redact( $message ) );
	}
}
