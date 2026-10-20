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

	/**
	 * $chain_plan is Backup_Chain::plan()'s return shape. Every manifest
	 * -- full or incremental alike -- carries backup_mode/base_backup_id/
	 * parent_backup_id from format_version 2 onward (Phase 2), even
	 * though a full backup's own values are always 'full'/null/null:
	 * restore tooling written against the format_version 1 docs must
	 * refuse ANY manifest from this plugin version, not just an
	 * incremental one, until it understands the new `files` field --
	 * the global version bump (not a per-manifest flag alone) is what
	 * forces that, matching validate_format_version()'s existing
	 * reject-anything-newer-than-supported behavior.
	 */
	public static function new_skeleton( string $backup_id, string $site_id, array $chain_plan ): array {
		global $wpdb;
		return array(
			'format_version'    => WPGUARD_NC_FORMAT_VERSION,
			'backup_id'         => $backup_id,
			'site_id'           => $site_id,
			'backup_mode'       => $chain_plan['mode'],
			'base_backup_id'    => $chain_plan['base_backup_id'],
			'parent_backup_id'  => $chain_plan['parent_backup_id'],
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
			'files'           => array(),
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

	/**
	 * One entry per file currently on the site (Phase 2, incremental
	 * backups) -- the complete resolved index, not a diff; see
	 * class-backup-chain.php's docblock. source_backup_id/source_part
	 * point at wherever this file's bytes physically live: this
	 * backup's own freshly archived part (full backups, and any
	 * new/changed file in an incremental), or an earlier chain member's
	 * part, carried forward verbatim (an unchanged file in an
	 * incremental).
	 */
	public static function add_file_entry( array &$manifest, string $path, int $size, int $mtime, string $sha256, string $source_backup_id, string $source_part ): void {
		$manifest['files'][] = array(
			'path'              => $path,
			'size'              => $size,
			'mtime'             => $mtime,
			'sha256'            => $sha256,
			'source_backup_id'  => $source_backup_id,
			'source_part'       => $source_part,
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
