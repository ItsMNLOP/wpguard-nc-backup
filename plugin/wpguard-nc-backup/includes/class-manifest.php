<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and validates manifest.json / COMPLETE.json per
 * docs/backup-format.md schema v1. Never receives the credential object —
 * no constructor or method here accepts anything resembling a secret.
 */
class Manifest {

	public static function new_skeleton( string $backup_id, string $site_id ): array {
		global $wpdb;
		return array(
			'format_version' => WPGUARD_NC_FORMAT_VERSION,
			'backup_id'      => $backup_id,
			'site_id'        => $site_id,
			'created_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'plugin_version' => WPGUARD_NC_VERSION,
			'generator'      => 'wpguard-nc-backup',
			'source'         => array(
				'wp_version'   => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '',
				'php_version'  => PHP_VERSION,
				'db_server'    => 'mysql-compatible',
				'db_version'   => $wpdb->db_version(),
				'table_prefix' => $wpdb->prefix,
				'home_url'     => function_exists( 'home_url' ) ? home_url() : '',
				'site_url'     => function_exists( 'site_url' ) ? site_url() : '',
			),
			'consistency'     => 'best-effort-live-snapshot',
			'scope'           => array(
				'database' => array( 'included' => true, 'tables' => array() ),
				'files'    => array(
					'included_roots'       => array( 'wp-content', 'wp-config.php', '.htaccess' ),
					'excluded_patterns'    => Inventory::EXCLUDED_PATTERNS,
					'excluded_external_note' => 'Files outside the WordPress document root, and any symlink targets, are not included. See docs/restore-manual.md.',
				),
			),
			'components'      => array(),
			'tables_metadata' => array(),
			'warnings'        => array(),
		);
	}

	public static function add_component( array &$manifest, string $path, string $kind, int $bytes, string $sha256, int $part, int $parts_total ): void {
		$manifest['components'][] = array(
			'path'        => $path,
			'kind'        => $kind,
			'bytes'       => $bytes,
			'sha256'      => $sha256,
			'part'        => $part,
			'parts_total' => $parts_total,
		);
	}

	public static function to_json( array $manifest ): string {
		return wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	public static function complete_json( string $backup_id, string $manifest_json ): string {
		return wp_json_encode(
			array(
				'format_version'   => WPGUARD_NC_FORMAT_VERSION,
				'backup_id'        => $backup_id,
				'completed_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'manifest_sha256'  => hash( 'sha256', $manifest_json ),
				'verification'     => 'read-back-sha256',
			),
			JSON_PRETTY_PRINT
		);
	}

	/** @return true|string True if a supported format version, else an error message. */
	public static function validate_format_version( array $decoded ) {
		if ( ! isset( $decoded['format_version'] ) || ! is_int( $decoded['format_version'] ) ) {
			return 'Manifest is missing a valid format_version field.';
		}
		if ( $decoded['format_version'] > WPGUARD_NC_FORMAT_VERSION ) {
			return "Manifest format_version {$decoded['format_version']} is newer than this plugin supports (max " . WPGUARD_NC_FORMAT_VERSION . '). Refusing to guess — upgrade the plugin/restore tooling.';
		}
		return true;
	}
}
