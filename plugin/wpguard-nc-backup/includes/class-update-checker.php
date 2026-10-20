<?php
namespace WPGuard\NCBackup;

/**
 * Self-hosted update feed (Phase 2, item 4): checks GitHub Releases for
 * this public repo instead of the WordPress.org plugin directory (this
 * plugin isn't listed there) or a self-hosted feed server (decided
 * against — GitHub Releases on the now-public repo is free and needs no
 * credentials to read).
 *
 * Fail-closed by design: the GitHub *zipball* for a release contains the
 * whole repo (docs/, tests/, .github/, etc.), not just the plugin
 * folder, so it is never used as an install package. An update is only
 * ever offered when the release has an explicit `.zip` asset — built
 * and attached by hand at release time (see docs/releasing.md) until
 * the CI bash port (Phase 2, item 10) can build and attach it
 * automatically. No asset means no update offered, not a fallback to
 * the wrong package.
 */
class Update_Checker {
	const GITHUB_REPO = 'ItsMNLOP/wpguard-nc-backup';
	const SLUG         = 'wpguard-nc-backup';
	const CACHE_KEY     = 'wpguard_nc_update_check';
	const CACHE_TTL      = 12 * HOUR_IN_SECONDS;
	const FAILURE_CACHE_TTL = HOUR_IN_SECONDS;

	private string $plugin_basename;

	public function __construct( string $plugin_basename ) {
		$this->plugin_basename = $plugin_basename;
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ) );
	}

	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Pure transform: GitHub's decoded /releases/latest JSON -> this
	 * plugin's normalized release shape, or null if the response isn't
	 * shaped the way we expect. No WP or network calls, so this is
	 * directly unit-testable.
	 */
	public static function parse_release( array $body ): ?array {
		if ( empty( $body['tag_name'] ) || ! is_string( $body['tag_name'] ) ) {
			return null;
		}

		return array(
			'version'  => ltrim( $body['tag_name'], 'v' ),
			'package'  => self::select_package_asset( (array) ( $body['assets'] ?? array() ) ),
			'body'     => is_string( $body['body'] ?? null ) ? $body['body'] : '',
			'html_url' => is_string( $body['html_url'] ?? null ) ? $body['html_url'] : ( 'https://github.com/' . self::GITHUB_REPO ),
		);
	}

	/**
	 * Pure: picks the release asset to install, or '' if none qualifies.
	 * Deliberately narrow (name must start with the plugin slug and end
	 * in .zip) so a release that also happens to carry e.g. a checksum
	 * file or a source tarball can't be picked by accident.
	 */
	public static function select_package_asset( array $assets ): string {
		foreach ( $assets as $asset ) {
			$name = $asset['name'] ?? '';
			if ( is_string( $name ) && str_starts_with( $name, self::SLUG ) && str_ends_with( $name, '.zip' ) ) {
				$url = $asset['browser_download_url'] ?? '';
				return is_string( $url ) ? $url : '';
			}
		}
		return '';
	}

	/** Pure: whether $candidate_version is a real upgrade over $current_version. */
	public static function is_newer_version( string $candidate_version, string $current_version ): bool {
		return '' !== $candidate_version && version_compare( $candidate_version, $current_version, '>' );
	}

	private function fetch_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached ) ? null : $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WPGuard-NC-Backup/' . WPGUARD_NC_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Covers "no release published yet" (404) same as any other
			// failure -- cache the miss briefly so a flaky or unreachable
			// GitHub, or simply no release existing yet, doesn't trigger a
			// real outbound HTTP request on every admin page load.
			set_transient( self::CACHE_KEY, array(), self::FAILURE_CACHE_TTL );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$release = is_array( $body ) ? self::parse_release( $body ) : null;

		if ( null === $release ) {
			set_transient( self::CACHE_KEY, array(), self::FAILURE_CACHE_TTL );
			return null;
		}

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		return $release;
	}

	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->fetch_latest_release();
		if ( ! $release || empty( $release['package'] ) ) {
			return $transient;
		}
		if ( ! self::is_newer_version( $release['version'], WPGUARD_NC_VERSION ) ) {
			return $transient;
		}

		$item               = new \stdClass();
		$item->slug         = self::SLUG;
		$item->plugin       = $this->plugin_basename;
		$item->new_version  = $release['version'];
		$item->url          = $release['html_url'];
		$item->package      = $release['package'];
		$item->requires_php = '8.1';

		$transient->response[ $this->plugin_basename ] = $item;
		return $transient;
	}

	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->fetch_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$info                = new \stdClass();
		$info->name          = 'WPGuard Nextcloud Backup';
		$info->slug          = self::SLUG;
		$info->version       = $release['version'];
		$info->author        = 'Holt (WPGuard project)';
		$info->homepage      = $release['html_url'];
		$info->requires      = '6.4';
		$info->requires_php  = '8.1';
		$info->download_link = $release['package'];
		$info->sections      = array(
			'description' => 'Backs up this WordPress site (files + database) to a user-controlled Nextcloud server over authenticated HTTPS WebDAV.',
			// Release body is attacker-controllable only by whoever can push
			// a GitHub release for this repo (same trust level as the code
			// itself), but it's rendered as HTML in the "View details"
			// popup regardless, so still run it through wp_kses_post()
			// rather than trusting it implicitly.
			'changelog'   => wpautop( wp_kses_post( $release['body'] ) ),
		);
		return $info;
	}
}
