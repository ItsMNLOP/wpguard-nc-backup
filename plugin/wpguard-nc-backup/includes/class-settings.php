<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings storage, validation, and the constant-override escape hatch.
 * ADR 0002 (credential storage), ADR 0007 (CA bundle).
 *
 * No WordPress function calls outside get_option/update_option/defined/
 * constant/wp_generate_password, so this class is cheap to exercise in
 * unit tests with a minimal WP shim.
 */
class Settings {

	const OPTION_KEY    = 'wpguard_nc_settings';
	const SITE_ID_KEY   = 'wpguard_nc_site_id';
	const APP_PW_CONST  = 'WPGUARD_NC_APP_PASSWORD';
	const CA_BUNDLE_CONST = 'WPGUARD_NC_CA_BUNDLE';

	// Phase 2 (scheduling): a plain WP-Cron recurrence name, reused
	// directly as both the stored setting value and the schedule's
	// registered name (see register_weekly_schedule() in the main
	// plugin file). 'disabled' means no recurring event at all --
	// chosen as the default so activating or updating the plugin never
	// silently starts recurring jobs an admin didn't ask for.
	const SCHEDULE_CHOICES = array( 'disabled', 'daily', 'weekly' );

	/** Raw stored option (excludes constant-overridden fields' stored value when locked). */
	public static function get_all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = array(
			'nextcloud_url'  => '',
			'nextcloud_user' => '',
			'app_password'   => '',
			'remote_folder'  => '',
			'ca_bundle_path' => '',
			'schedule'       => 'disabled',
		);
		return array_merge( $defaults, $stored );
	}

	public static function get_schedule(): string {
		$value = (string) self::get_all()['schedule'];
		return in_array( $value, self::SCHEDULE_CHOICES, true ) ? $value : 'disabled';
	}

	public static function is_app_password_locked(): bool {
		return defined( self::APP_PW_CONST ) && constant( self::APP_PW_CONST ) !== '';
	}

	public static function is_ca_bundle_locked(): bool {
		return defined( self::CA_BUNDLE_CONST ) && constant( self::CA_BUNDLE_CONST ) !== '';
	}

	public static function get_app_password(): string {
		if ( self::is_app_password_locked() ) {
			return (string) constant( self::APP_PW_CONST );
		}
		return (string) self::get_all()['app_password'];
	}

	public static function get_ca_bundle_path(): string {
		if ( self::is_ca_bundle_locked() ) {
			return (string) constant( self::CA_BUNDLE_CONST );
		}
		return (string) self::get_all()['ca_bundle_path'];
	}

	public static function get_nextcloud_url(): string {
		return (string) self::get_all()['nextcloud_url'];
	}

	public static function get_nextcloud_user(): string {
		return (string) self::get_all()['nextcloud_user'];
	}

	public static function get_remote_folder(): string {
		return (string) self::get_all()['remote_folder'];
	}

	public static function has_credentials(): bool {
		return '' !== self::get_nextcloud_url()
			&& '' !== self::get_nextcloud_user()
			&& '' !== self::get_app_password();
	}

	/**
	 * Validate and persist admin-submitted settings.
	 *
	 * @param array $input Raw $_POST-derived values (already slashes-stripped).
	 * @return array{ok:bool, errors:array<string,string>}
	 */
	public static function save( array $input ): array {
		$errors  = array();
		$current = self::get_all();
		$clean   = array();

		$url = isset( $input['nextcloud_url'] ) ? trim( (string) $input['nextcloud_url'] ) : '';
		$url = rtrim( $url, '/' );
		if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) {
			$errors['nextcloud_url'] = __( 'Nextcloud URL must start with https:// — plain HTTP is not permitted.', 'wpguard-nc-backup' );
		} elseif ( ! self::is_syntactically_valid_destination_url( $url ) ) {
			$errors['nextcloud_url'] = __( 'That does not look like a valid URL.', 'wpguard-nc-backup' );
		} else {
			$clean['nextcloud_url'] = sanitize_text_field( $url );
		}

		$user = isset( $input['nextcloud_user'] ) ? sanitize_text_field( trim( (string) $input['nextcloud_user'] ) ) : '';
		if ( '' === $user ) {
			$errors['nextcloud_user'] = __( 'Nextcloud username is required.', 'wpguard-nc-backup' );
		} else {
			$clean['nextcloud_user'] = $user;
		}

		$folder = isset( $input['remote_folder'] ) ? trim( (string) $input['remote_folder'] ) : '';
		$folder = trim( $folder, '/' );
		if ( '' === $folder ) {
			$errors['remote_folder'] = __( 'Destination folder is required.', 'wpguard-nc-backup' );
		} elseif ( ! preg_match( '#^[A-Za-z0-9._/-]+$#', $folder ) || false !== strpos( $folder, '..' ) ) {
			$errors['remote_folder'] = __( 'Destination folder may only contain letters, numbers, dots, dashes, underscores, and / as a separator, and may not contain ".."', 'wpguard-nc-backup' );
		} else {
			$clean['remote_folder'] = $folder;
		}

		// App password: blank submission means "leave unchanged" (never clears it).
		if ( self::is_app_password_locked() ) {
			// Vex audit H-02: purge any previously option-stored value once
			// the constant override takes over. Leaving the old value in
			// wp_options meant switching to the "more secure" constant path
			// didn't actually revoke the old credential from the database.
			$clean['app_password'] = '';
		} else {
			$submitted = isset( $input['app_password'] ) ? (string) $input['app_password'] : '';
			$clean['app_password'] = ( '' === $submitted ) ? $current['app_password'] : $submitted;
		}

		// CA bundle path: optional, validated against the filesystem if provided.
		if ( self::is_ca_bundle_locked() ) {
			$clean['ca_bundle_path'] = $current['ca_bundle_path'];
		} else {
			$ca_path = isset( $input['ca_bundle_path'] ) ? trim( (string) $input['ca_bundle_path'] ) : '';
			if ( '' === $ca_path ) {
				$clean['ca_bundle_path'] = '';
			} else {
				$validation = CA_Bundle::validate_path( $ca_path );
				if ( true !== $validation ) {
					$errors['ca_bundle_path'] = $validation;
				} else {
					$clean['ca_bundle_path'] = $ca_path;
				}
			}
		}

		// Always a <select> with a fixed option list in the admin UI, so an
		// unrecognized value (tampering, or a future form change missed
		// here) falls back to the safe default rather than erroring the
		// whole save.
		$schedule = isset( $input['schedule'] ) ? (string) $input['schedule'] : 'disabled';
		$clean['schedule'] = in_array( $schedule, self::SCHEDULE_CHOICES, true ) ? $schedule : 'disabled';

		if ( ! empty( $errors ) ) {
			return array( 'ok' => false, 'errors' => $errors );
		}

		update_option( self::OPTION_KEY, $clean, false );
		return array( 'ok' => true, 'errors' => array() );
	}

	/**
	 * Deliberately NOT wp_http_validate_url(): that function's default
	 * private-IP/hostname blocklist (SSRF hardening meant for fetching
	 * arbitrary third-party URLs) would reject exactly the self-hosted,
	 * private-network Nextcloud instances this plugin exists to support
	 * (NFR-03: "restrict network targets as practical while supporting
	 * private Nextcloud"; threat-model T5 accepts this as an admin-only,
	 * fully trusted, explicitly-configured destination, not a general
	 * fetcher). This only checks that it's syntactically a URL with a
	 * host and no embedded credentials/control characters.
	 */
	private static function is_syntactically_valid_destination_url( string $url ): bool {
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return false;
		}
		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return false;
		}
		if ( false !== strpbrk( $parsed['host'], ":#?[]" ) ) {
			return false;
		}
		return true;
	}

	public static function ensure_site_id(): string {
		$existing = get_option( self::SITE_ID_KEY, '' );
		if ( is_string( $existing ) && '' !== $existing ) {
			return $existing;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? $host : 'site';
		$slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) );
		$slug = trim( $slug, '-' );
		$suffix = substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
		$site_id = $slug . '-' . $suffix;
		update_option( self::SITE_ID_KEY, $site_id, true );
		return $site_id;
	}

	public static function get_site_id(): string {
		$id = get_option( self::SITE_ID_KEY, '' );
		return is_string( $id ) && '' !== $id ? $id : self::ensure_site_id();
	}

	/**
	 * Strips any line defining the app-password or CA-bundle constants
	 * (ADR 0002's escape hatch) out of wp-config.php content before it is
	 * archived (Vex audit H-02) — a backup that depends on the
	 * credential protecting it must not also contain that credential.
	 * Pure string transform, no I/O, so the orchestrator handles reading
	 * the real file and writing the redacted copy to the job's tmp dir.
	 */
	public static function redact_wp_config_contents( string $content ): string {
		$pattern = '/define\s*\(\s*[\'"](?:' . preg_quote( self::APP_PW_CONST, '/' ) . '|' . preg_quote( self::CA_BUNDLE_CONST, '/' ) . ')[\'"]\s*,.*?\)\s*;/';
		return preg_replace( $pattern, '// wpguard-nc-backup: line redacted from backup archive (credential constant).', $content );
	}
}
