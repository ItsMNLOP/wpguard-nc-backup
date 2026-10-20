<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent job state in a dedicated table (see docs/architecture.md —
 * "Job state storage: custom table, not options"). Statuses per NFR-01:
 * queued, running, interrupted, failed, uploaded-unverified, verified.
 */
class Job_Store {

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpguard_nc_jobs';
	}

	public static function install_or_upgrade_schema(): void {
		$installed = get_option( 'wpguard_nc_db_schema_version', 0 );
		if ( (int) $installed >= WPGUARD_NC_DB_SCHEMA_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			job_id VARCHAR(40) NOT NULL,
			backup_id VARCHAR(64) NOT NULL,
			status VARCHAR(24) NOT NULL DEFAULT 'queued',
			step VARCHAR(40) NOT NULL DEFAULT 'init',
			checkpoint LONGTEXT NULL,
			log LONGTEXT NULL,
			error TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (job_id)
		) {$charset_collate};";
		dbDelta( $sql );
		update_option( 'wpguard_nc_db_schema_version', WPGUARD_NC_DB_SCHEMA_VERSION, true );
	}

	public static function create( string $job_id, string $backup_id ): void {
		global $wpdb;
		$wpdb->insert(
			self::table_name(),
			array(
				'job_id'     => $job_id,
				'backup_id'  => $backup_id,
				'status'     => 'queued',
				'step'       => 'init',
				'checkpoint' => wp_json_encode( array() ),
				'log'        => wp_json_encode( array() ),
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function get( string $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE job_id = %s', $job_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['checkpoint'] = json_decode( (string) $row['checkpoint'], true ) ?: array();
		$row['log'] = json_decode( (string) $row['log'], true ) ?: array();
		return $row;
	}

	public static function update( string $job_id, array $fields ): void {
		global $wpdb;
		$data = array();
		$format = array();
		foreach ( $fields as $k => $v ) {
			if ( in_array( $k, array( 'checkpoint', 'log' ), true ) ) {
				$data[ $k ] = wp_json_encode( $v );
			} else {
				$data[ $k ] = $v;
			}
			$format[] = '%s';
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$format[] = '%s';
		$wpdb->update( self::table_name(), $data, array( 'job_id' => $job_id ), $format, array( '%s' ) );
	}

	public static function append_log( string $job_id, array $entry ): void {
		$job = self::get( $job_id );
		if ( null === $job ) {
			return;
		}
		$log = $job['log'];
		$log[] = $entry;
		if ( count( $log ) > 200 ) {
			$log = array_slice( $log, -200 );
		}
		self::update( $job_id, array( 'log' => $log ) );
	}

	/** @return array|null Most recent job row, or null if none exist. */
	public static function get_latest(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::table_name() . ' ORDER BY created_at DESC LIMIT 1', ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['checkpoint'] = json_decode( (string) $row['checkpoint'], true ) ?: array();
		$row['log'] = json_decode( (string) $row['log'], true ) ?: array();
		return $row;
	}

	public static function has_running_job(): bool {
		global $wpdb;
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table_name() . " WHERE status IN ('queued','running','uploaded-unverified')" );
		return (int) $count > 0;
	}

	/**
	 * JOB-05 (Phase 2): the single non-terminal job, if one exists — the
	 * same row has_running_job() is counting. Used by the automatic
	 * stale-lock reaper to find a candidate without a second COUNT query.
	 *
	 * @return array|null
	 */
	public static function get_running(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM " . self::table_name() . " WHERE status IN ('queued','running','uploaded-unverified') ORDER BY created_at ASC LIMIT 1", ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['checkpoint'] = json_decode( (string) $row['checkpoint'], true ) ?: array();
		$row['log'] = json_decode( (string) $row['log'], true ) ?: array();
		return $row;
	}

	/**
	 * Non-blocking MySQL advisory lock keyed by job_id (Vex audit H-01):
	 * AJAX polling and the WP-Cron fallback can both call
	 * Job_Orchestrator::advance() for the same job in overlapping
	 * requests. Without mutual exclusion, two processes race on the same
	 * checkpoint read-modify-write, corrupting the SQL dump or losing zip
	 * entries while each side's locally-computed hash still matches what
	 * it uploaded — a backup that passes verification while being
	 * structurally broken. GET_LOCK is session-scoped on the $wpdb
	 * connection, so it is also released automatically if the PHP
	 * process dies before reaching release_lock().
	 */
	public static function try_lock( string $job_id ): bool {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name( $job_id ) ) );
		return '1' === (string) $got;
	}

	public static function release_lock( string $job_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $job_id ) ) );
	}

	private static function lock_name( string $job_id ): string {
		return 'wpguard_nc_job_' . $job_id;
	}

	/** @return array|null The most recent job with status = 'verified'. */
	public static function get_latest_verified(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM " . self::table_name() . " WHERE status = 'verified' ORDER BY created_at DESC LIMIT 1", ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['checkpoint'] = json_decode( (string) $row['checkpoint'], true ) ?: array();
		$row['log'] = json_decode( (string) $row['log'], true ) ?: array();
		return $row;
	}
}
