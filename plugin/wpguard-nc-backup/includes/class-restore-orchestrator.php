<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click restore (Phase 2, RST-04 -- built with Mike's explicit
 * approval given the brief's own gate on this specific feature; see
 * STATUS.md). Reuses Job_Orchestrator's exact job-state-machine shape
 * (same wp_wpguard_nc_jobs table, same status vocabulary, same
 * try_lock()/release_lock() mutual exclusion via Job_Store) so a
 * restore in progress automatically blocks a new backup from starting
 * and vice versa -- Job_Store::has_running_job() only ever looks at
 * `status`, never at which orchestrator created the row.
 *
 * job_id is prefixed 'restore-' (vs 'job-' for backups); the `backup_id`
 * column is reused to hold the SOURCE backup_id being restored FROM,
 * not a backup this job produces.
 *
 * Steps: restore_acquire -> restore_preflight -> restore_apply_db ->
 * restore_apply_files -> restore_swap -> done (status verified|failed).
 *
 * Two-phase design (advisor consult, 2026-10-20): restore_acquire and
 * restore_preflight are entirely read-only with respect to the live
 * site -- every check that can fail safely happens before anything
 * live is touched. restore_apply_db onward is the point of no return;
 * failure there leaves clearly-labeled recovery artifacts (a `_wpgbak_`
 * table-prefix snapshot of what was live, and a rollback files
 * directory) rather than attempting automatic rollback, which would be
 * its own large source of new bugs.
 */
class Restore_Orchestrator {

	const UPLOAD_DIR_NAME = 'wpguard-nc-restore-upload';
	const MAX_MANIFEST_BYTES = 64 * 1024 * 1024;
	const STEP_TIME_BUDGET_SECONDS = 5.0;

	// Narrow fail-closed cap: an ancestor part with no directly-known
	// size bound (before its own manifest is fetched) is never allowed
	// to download more than this regardless -- archive-bomb defense
	// (threat-model T7) applied symmetrically to restore, not just backup.
	const MAX_PART_BYTES_FALLBACK = 512 * 1024 * 1024;

	public static function tmp_dir_for( string $job_id ): string {
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/wpguard-nc-restore-tmp/' . $job_id . '/';
	}

	/**
	 * Two hard exclusions, never touched by a restore under any
	 * circumstance -- neither quarantined nor overwritten, regardless
	 * of what the backup being restored contains for either path:
	 *
	 * 1. This plugin's own directory. Found live (2026-10-20, during
	 *    this feature's own E2E testing against a real production-age
	 *    backup): restoring an older backup quarantined this plugin's
	 *    OWN newer files -- ones added after that backup was taken --
	 *    because they simply aren't present in it, and a quarantine
	 *    candidate is defined as "live but not in the backup." That's
	 *    correct logic for ordinary site content, but catastrophic
	 *    applied to the plugin's own source: it broke the plugin's own
	 *    autoloader mid-restore. Plugin code updates are a separate,
	 *    independent mechanism (Update_Checker / the self-hosted
	 *    update feed), never this one.
	 * 2. wp-config.php. RST-04 (the brief's own gate on this feature):
	 *    "treat restoring wp-config.php ... as high-risk, and never
	 *    silently overwrite target environment secrets." Also found
	 *    live in the same E2E run, once (1) was fixed: the backup's
	 *    (redacted-of-plugin-credential-only) wp-config.php still
	 *    carries the SOURCE site's own DB_NAME/DB_USER/DB_PASSWORD/
	 *    DB_HOST, which must never silently replace the values this
	 *    exact WordPress installation actually needs to keep running
	 *    -- the whole reason restore-manual.md's own step 6 treats
	 *    this file as a manual, deliberate reconciliation, never a
	 *    plain overwrite, even for a disaster-recovery restore to a
	 *    brand new host. An in-place one-click restore has even less
	 *    reason to touch it: on the same site, it's already correct.
	 */
	private static function own_plugin_dir_prefix(): string {
		$abspath = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		$plugin_dir = rtrim( str_replace( '\\', '/', WPGUARD_NC_DIR ), '/' );
		return ltrim( substr( $plugin_dir, strlen( $abspath ) ), '/' ) . '/';
	}

	private static function is_never_restored_path( string $relative ): bool {
		return 'wp-config.php' === $relative || 0 === strpos( $relative, self::own_plugin_dir_prefix() );
	}

	public static function upload_dir(): string {
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . self::UPLOAD_DIR_NAME . '/';
	}

	private static function ensure_tmp_guarded( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n" );
		}
	}

	private static function logger(): Logger {
		return new Logger( array( Settings::get_app_password() ) );
	}

	private static function log( string $job_id, string $step, string $severity, string $message ): void {
		Job_Store::append_log( $job_id, self::logger()->entry( $job_id, $step, $severity, $message ) );
	}

	/**
	 * @param array $source {'type': 'nextcloud'} or {'type': 'upload', 'upload_dir': string}
	 * @return string New restore job_id, or '' if a backup or restore is already running.
	 */
	public static function create_restore_job( array $source, string $source_backup_id, bool $confirm_url_mismatch = false ): string {
		if ( Job_Store::has_running_job() ) {
			return '';
		}
		$job_id = 'restore-' . bin2hex( random_bytes( 8 ) );
		self::ensure_tmp_guarded( self::tmp_dir_for( $job_id ) );
		Job_Store::create( $job_id, $source_backup_id );
		Job_Store::update( $job_id, array(
			'step'       => 'restore_acquire',
			'checkpoint' => array(
				'kind'                  => 'restore',
				'source'                => $source,
				'confirm_url_mismatch'  => $confirm_url_mismatch,
			),
		) );
		return $job_id;
	}

	public static function advance( string $job_id ): array {
		if ( ! Job_Store::try_lock( $job_id ) ) {
			$job = Job_Store::get( $job_id );
			return $job ?? array( 'status' => 'not_found' );
		}
		try {
			$job = Job_Store::get( $job_id );
			if ( null === $job ) {
				return array( 'status' => 'not_found' );
			}
			if ( in_array( $job['status'], array( 'verified', 'failed' ), true ) ) {
				return $job;
			}
			Job_Store::update( $job_id, array( 'status' => 'running' ) );

			switch ( $job['step'] ) {
				case 'restore_acquire':
					return self::step_acquire( $job );
				case 'restore_preflight':
					return self::step_preflight( $job );
				case 'restore_apply_db':
					return self::step_apply_db( $job );
				case 'restore_apply_files':
					return self::step_apply_files( $job );
				case 'restore_swap':
					return self::step_swap( $job );
				default:
					return self::fail( $job_id, $job['step'], "Unknown restore step: {$job['step']}" );
			}
		} finally {
			Job_Store::release_lock( $job_id );
		}
	}

	/**
	 * JOB-05-equivalent for a stale restore job (dispatched from
	 * Job_Orchestrator::reap_stale_lock() by checkpoint kind). A restore
	 * stuck in restore_acquire/restore_preflight is fully safe to just
	 * mark failed -- nothing live was touched. One stuck at
	 * restore_apply_db or later gets a message that says so plainly,
	 * pointing at the rollback artifacts, rather than the backup-flavored
	 * "previous backups are untouched" reassurance, which would be
	 * actively misleading here.
	 */
	public static function reap_stale( array $job ): ?array {
		$destructive = in_array( $job['step'], array( 'restore_apply_db', 'restore_apply_files', 'restore_swap' ), true );
		return self::fail(
			$job['job_id'],
			$job['step'],
			$destructive
				? 'Automatically marked failed: no progress for an extended period during the apply phase. This restore may have left the site in a partially-restored state -- check the log above for the rollback table prefix and rollback files directory before taking any further action.'
				: 'Automatically marked failed: no progress for an extended period. Nothing live was touched at this step; it is safe to start a new restore attempt.'
		);
	}

	/**
	 * Manual admin override, mirroring Job_Orchestrator::cancel()'s
	 * lock-contention-aware shape but with restore-appropriate
	 * messaging -- calling the BACKUP cancel() on a restore job would
	 * try to clean up the wrong tmp directory and upload a diagnostic
	 * log into the SOURCE backup's own Nextcloud folder (the one being
	 * restored FROM), neither of which makes sense here.
	 *
	 * @return array Job row with an added 'cancel_result' key, same
	 *   vocabulary as Job_Orchestrator::cancel().
	 */
	public static function cancel( string $job_id ): array {
		if ( ! Job_Store::try_lock( $job_id ) ) {
			$job = Job_Store::get( $job_id ) ?? array( 'status' => 'not_found' );
			$job['cancel_result'] = 'lock_contended';
			return $job;
		}
		try {
			$job = Job_Store::get( $job_id );
			if ( null === $job ) {
				return array( 'status' => 'not_found', 'cancel_result' => 'not_found' );
			}
			if ( in_array( $job['status'], array( 'verified', 'failed' ), true ) ) {
				$job['cancel_result'] = 'already_terminal';
				return $job;
			}
			$destructive = in_array( $job['step'], array( 'restore_apply_db', 'restore_apply_files', 'restore_swap' ), true );
			$job = self::fail(
				$job_id,
				$job['step'],
				$destructive
					? 'Cancelled by administrator during the apply phase. This restore may have left the site in a partially-restored state -- check the log above for the rollback table prefix and rollback files directory before taking any further action.'
					: 'Cancelled by administrator. Nothing live was touched at this step; it is safe to start a new restore attempt.'
			);
			$job['cancel_result'] = 'cancelled';
			return $job;
		} finally {
			Job_Store::release_lock( $job_id );
		}
	}

	private static function fail( string $job_id, string $step, string $message ): array {
		$safe = self::logger()->redact( $message );
		self::log( $job_id, $step, 'error', $safe );
		Job_Store::update( $job_id, array( 'status' => 'failed', 'error' => $safe ) );
		return Job_Store::get( $job_id );
	}

	// ------------------------------------------------------------------
	// Step: restore_acquire
	// ------------------------------------------------------------------

	private static function step_acquire( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];
		$tmp = self::tmp_dir_for( $job_id );

		if ( ! isset( $cp['manifest'] ) ) {
			$init = self::acquire_init( $job, $cp, $tmp );
			if ( is_wp_error( $init ) ) {
				return self::fail( $job_id, 'restore_acquire', $init->get_error_message() );
			}
			$cp = $init;
			Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
			return Job_Store::get( $job_id );
		}

		if ( ! empty( $cp['needed_parts_remaining'] ) ) {
			$deadline = microtime( true ) + self::STEP_TIME_BUDGET_SECONDS;
			do {
				$part = array_shift( $cp['needed_parts_remaining'] );
				$result = self::acquire_one_part( $job, $cp, $tmp, $part );
				if ( is_wp_error( $result ) ) {
					return self::fail( $job_id, 'restore_acquire', $result->get_error_message() );
				}
				Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
			} while ( ! empty( $cp['needed_parts_remaining'] ) && microtime( true ) < $deadline );
			return Job_Store::get( $job_id );
		}

		if ( ! isset( $cp['database_staged'] ) ) {
			$result = self::acquire_database( $job, $cp, $tmp );
			if ( is_wp_error( $result ) ) {
				return self::fail( $job_id, 'restore_acquire', $result->get_error_message() );
			}
			Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
			return Job_Store::get( $job_id );
		}

		self::log( $job_id, 'restore_acquire', 'info', 'All backup content fetched and integrity-verified.' );
		Job_Store::update( $job_id, array( 'step' => 'restore_preflight', 'checkpoint' => $cp ) );
		return Job_Store::get( $job_id );
	}

	/** @return array|\WP_Error The initialized checkpoint, or an error. */
	private static function acquire_init( array $job, array $cp, string $tmp ) {
		$source = $cp['source'];
		if ( 'upload' === $source['type'] ) {
			$manifest_path_local = rtrim( $source['upload_dir'], '/\\' ) . '/manifest.json';
			$complete_path_local = rtrim( $source['upload_dir'], '/\\' ) . '/COMPLETE.json';
			if ( ! file_exists( $manifest_path_local ) || ! file_exists( $complete_path_local ) ) {
				return new \WP_Error( 'wpguard_nc_restore_upload_incomplete', 'Uploaded bundle is missing manifest.json and/or COMPLETE.json.' );
			}
			$manifest_raw = file_get_contents( $manifest_path_local );
			$complete_raw = file_get_contents( $complete_path_local );
		} else {
			$client = Nextcloud_Client::from_settings( self::logger() );
			$parent_rel = $client->relative_path( Settings::get_site_id() . '/backups' );
			$manifest_dl = self::download_text( $client, $parent_rel . '/' . $job['backup_id'] . '/manifest.json', self::MAX_MANIFEST_BYTES, $tmp );
			if ( is_wp_error( $manifest_dl ) ) {
				return $manifest_dl;
			}
			$manifest_raw = $manifest_dl;
			$complete_dl = self::download_text( $client, $parent_rel . '/' . $job['backup_id'] . '/COMPLETE.json', self::MAX_MANIFEST_BYTES, $tmp );
			if ( is_wp_error( $complete_dl ) ) {
				return $complete_dl;
			}
			$complete_raw = $complete_dl;
		}

		$manifest = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) ) {
			return new \WP_Error( 'wpguard_nc_restore_bad_manifest', 'manifest.json is not valid JSON.' );
		}
		$version_check = Manifest::validate_format_version( $manifest );
		if ( true !== $version_check ) {
			return new \WP_Error( 'wpguard_nc_restore_bad_format_version', $version_check );
		}
		$complete = json_decode( $complete_raw, true );
		if ( ! is_array( $complete ) || empty( $complete['manifest_sha256'] ) ) {
			return new \WP_Error( 'wpguard_nc_restore_bad_complete', 'COMPLETE.json is missing or not valid JSON.' );
		}
		if ( ( $complete['backup_id'] ?? '' ) !== $job['backup_id'] ) {
			return new \WP_Error( 'wpguard_nc_restore_backup_id_mismatch', 'COMPLETE.json\'s backup_id does not match the folder it was fetched from.' );
		}
		if ( ! hash_equals( (string) $complete['manifest_sha256'], hash( 'sha256', $manifest_raw ) ) ) {
			return new \WP_Error( 'wpguard_nc_restore_manifest_hash_mismatch', 'manifest.json does not match the hash recorded in COMPLETE.json -- refusing to trust a backup that failed its own completion check.' );
		}

		$mode = $manifest['backup_mode'] ?? 'full';
		$own_parts = array(); // name => array(bytes, sha256)
		foreach ( (array) ( $manifest['components'] ?? array() ) as $component ) {
			if ( 'files' === ( $component['kind'] ?? '' ) ) {
				$own_parts[ $component['path'] ] = array( 'bytes' => (int) $component['bytes'], 'sha256' => (string) $component['sha256'] );
			}
		}

		$needed = array(); // keyed by "backup_id/part" to dedupe
		if ( 'incremental' === $mode && ! empty( $manifest['files'] ) ) {
			foreach ( (array) $manifest['files'] as $entry ) {
				if ( empty( $entry['source_backup_id'] ) || empty( $entry['source_part'] ) ) {
					return new \WP_Error( 'wpguard_nc_restore_bad_file_entry', 'manifest.json has a files[] entry missing source_backup_id/source_part -- refusing to guess.' );
				}
				$key = $entry['source_backup_id'] . '/' . $entry['source_part'];
				$needed[ $key ] = array( 'source_backup_id' => $entry['source_backup_id'], 'source_part' => $entry['source_part'] );
			}
		} else {
			foreach ( array_keys( $own_parts ) as $name ) {
				$key = $job['backup_id'] . '/' . $name;
				$needed[ $key ] = array( 'source_backup_id' => $job['backup_id'], 'source_part' => $name );
			}
		}

		$cp['manifest'] = $manifest;
		$cp['own_parts'] = $own_parts;
		$cp['backup_mode'] = $mode;
		$cp['needed_parts_remaining'] = array_values( $needed );
		$cp['staged_parts'] = array(); // "backup_id/part" => local path
		$cp['ancestor_manifests'] = array(); // ancestor backup_id => components (for size/sha256 bounds)
		return $cp;
	}

	/** Downloads a small object fully into memory via a temp file, returning its content as a string. */
	private static function download_text( Nextcloud_Client $client, string $relative_path, int $max_bytes, string $tmp ) {
		$local = $tmp . 'dl-' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$dl = $client->download_to_file( $relative_path, $local, $max_bytes );
		if ( is_wp_error( $dl ) ) {
			return $dl;
		}
		$content = file_get_contents( $local );
		@unlink( $local );
		return false === $content ? new \WP_Error( 'wpguard_nc_restore_read_failed', "Could not read downloaded object: {$relative_path}" ) : $content;
	}

	/** @return true|\WP_Error */
	private static function acquire_one_part( array $job, array &$cp, string $tmp, array $part ) {
		$source_backup_id = $part['source_backup_id'];
		$part_name = $part['source_part'];
		$key = $source_backup_id . '/' . $part_name;

		$expected_bytes = 0;
		$expected_sha256 = '';
		if ( $source_backup_id === $job['backup_id'] ) {
			if ( empty( $cp['own_parts'][ $part_name ] ) ) {
				return new \WP_Error( 'wpguard_nc_restore_unknown_part', "manifest.json references {$part_name} as its own, but no matching components[] entry exists." );
			}
			$expected_bytes = $cp['own_parts'][ $part_name ]['bytes'];
			$expected_sha256 = $cp['own_parts'][ $part_name ]['sha256'];
		} elseif ( 'upload' !== $cp['source']['type'] ) {
			// Ancestor part (incremental chain) -- only resolvable against
			// the real Nextcloud server; a local upload bundle is only
			// ever supported for a self-contained ('full') backup.
			$ancestor = self::ancestor_components( $cp, $source_backup_id, $tmp );
			if ( is_wp_error( $ancestor ) ) {
				return $ancestor;
			}
			$cp['ancestor_manifests'][ $source_backup_id ] = $ancestor;
			if ( isset( $ancestor[ $part_name ] ) ) {
				$expected_bytes = $ancestor[ $part_name ]['bytes'];
				$expected_sha256 = $ancestor[ $part_name ]['sha256'];
			} else {
				// Ancestor's own manifest doesn't account for this part
				// by name -- fall back to the fail-closed size cap; the
				// per-file sha256 check after extraction is still the
				// real integrity gate regardless.
				$expected_bytes = self::MAX_PART_BYTES_FALLBACK;
			}
		} else {
			return new \WP_Error( 'wpguard_nc_restore_chain_needs_nextcloud', "This backup references an ancestor backup ({$source_backup_id}) and cannot be restored from a local upload alone -- use the Nextcloud source instead." );
		}

		$local_path = $tmp . 'part-' . md5( $key ) . '.zip';
		if ( 'upload' === $cp['source']['type'] ) {
			$source_path = rtrim( $cp['source']['upload_dir'], '/\\' ) . '/' . $part_name;
			if ( ! file_exists( $source_path ) ) {
				return new \WP_Error( 'wpguard_nc_restore_upload_missing_part', "Uploaded bundle is missing {$part_name}." );
			}
			if ( ! copy( $source_path, $local_path ) ) {
				return new \WP_Error( 'wpguard_nc_restore_copy_failed', "Could not stage {$part_name} from the uploaded bundle." );
			}
		} else {
			$client = Nextcloud_Client::from_settings( self::logger() );
			$parent_rel = $client->relative_path( Settings::get_site_id() . '/backups' );
			$dl = $client->download_to_file( $parent_rel . '/' . $source_backup_id . '/' . $part_name, $local_path, $expected_bytes > 0 ? $expected_bytes : self::MAX_PART_BYTES_FALLBACK );
			if ( is_wp_error( $dl ) ) {
				return $dl;
			}
		}

		if ( '' !== $expected_sha256 ) {
			$actual = hash_file( 'sha256', $local_path );
			if ( ! hash_equals( $expected_sha256, $actual ) ) {
				@unlink( $local_path );
				return new \WP_Error( 'wpguard_nc_restore_part_hash_mismatch', "{$part_name} (from {$source_backup_id}) does not match its recorded checksum -- refusing to restore from a corrupted or tampered part." );
			}
		}

		$cp['staged_parts'][ $key ] = $local_path;
		return true;
	}

	/** @return array|\WP_Error Ancestor's components[] (kind=files only), keyed by part name. */
	private static function ancestor_components( array $cp, string $ancestor_backup_id, string $tmp ) {
		if ( isset( $cp['ancestor_manifests'][ $ancestor_backup_id ] ) ) {
			return $cp['ancestor_manifests'][ $ancestor_backup_id ];
		}
		$client = Nextcloud_Client::from_settings( self::logger() );
		$parent_rel = $client->relative_path( Settings::get_site_id() . '/backups' );
		$raw = self::download_text( $client, $parent_rel . '/' . $ancestor_backup_id . '/manifest.json', self::MAX_MANIFEST_BYTES, $tmp );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$manifest = json_decode( $raw, true );
		if ( ! is_array( $manifest ) ) {
			return new \WP_Error( 'wpguard_nc_restore_ancestor_unreadable', "Ancestor backup {$ancestor_backup_id}'s manifest.json could not be read -- refusing to trust an unverifiable chain link." );
		}
		$components = array();
		foreach ( (array) ( $manifest['components'] ?? array() ) as $component ) {
			if ( 'files' === ( $component['kind'] ?? '' ) ) {
				$components[ $component['path'] ] = array( 'bytes' => (int) $component['bytes'], 'sha256' => (string) $component['sha256'] );
			}
		}
		return $components;
	}

	/** @return true|\WP_Error */
	private static function acquire_database( array $job, array &$cp, string $tmp ) {
		$db_component = null;
		foreach ( (array) ( $cp['manifest']['components'] ?? array() ) as $component ) {
			if ( 'database' === ( $component['kind'] ?? '' ) ) {
				$db_component = $component;
				break;
			}
		}
		if ( null === $db_component ) {
			return new \WP_Error( 'wpguard_nc_restore_no_db_component', 'manifest.json has no database component.' );
		}

		$local_path = $tmp . 'database.sql.gz';
		if ( 'upload' === $cp['source']['type'] ) {
			$source_path = rtrim( $cp['source']['upload_dir'], '/\\' ) . '/' . $db_component['path'];
			if ( ! file_exists( $source_path ) || ! copy( $source_path, $local_path ) ) {
				return new \WP_Error( 'wpguard_nc_restore_upload_missing_db', 'Uploaded bundle is missing the database component.' );
			}
		} else {
			$client = Nextcloud_Client::from_settings( self::logger() );
			$parent_rel = $client->relative_path( Settings::get_site_id() . '/backups' );
			$dl = $client->download_to_file( $parent_rel . '/' . $job['backup_id'] . '/' . $db_component['path'], $local_path, (int) $db_component['bytes'] );
			if ( is_wp_error( $dl ) ) {
				return $dl;
			}
		}

		$actual = hash_file( 'sha256', $local_path );
		if ( ! hash_equals( (string) $db_component['sha256'], $actual ) ) {
			@unlink( $local_path );
			return new \WP_Error( 'wpguard_nc_restore_db_hash_mismatch', 'database.sql.gz does not match its recorded checksum -- refusing to restore from a corrupted or tampered export.' );
		}

		$cp['database_staged'] = $local_path;
		return true;
	}

	// ------------------------------------------------------------------
	// Step: restore_preflight -- last entirely read-only step. Every
	// check that can abort safely happens here, before restore_apply_db
	// touches anything live.
	// ------------------------------------------------------------------

	private static function step_preflight( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];

		if ( ! isset( $cp['target_files'] ) ) {
			$build = self::build_target_files( $job, $cp );
			if ( is_wp_error( $build ) ) {
				return self::fail( $job_id, 'restore_preflight', $build->get_error_message() );
			}
			$cp = $build;
			Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
			return Job_Store::get( $job_id );
		}

		$manifest = $cp['manifest'];
		$source_home = $manifest['source']['home_url'] ?? '';
		$current_home = home_url();
		if ( '' !== $source_home && $source_home !== $current_home && empty( $cp['confirm_url_mismatch'] ) ) {
			return self::fail(
				$job_id,
				'restore_preflight',
				"This backup was taken from {$source_home}, but this site is {$current_home}. Restoring it here would overwrite this site's content with a different site's. If this is intentional, re-run with explicit confirmation of the mismatch."
			);
		}

		$source_wp_version = $manifest['source']['wp_version'] ?? '';
		$current_wp_version = get_bloginfo( 'version' );
		if ( '' !== $source_wp_version && version_compare( $source_wp_version, $current_wp_version, '>' ) ) {
			return self::fail(
				$job_id,
				'restore_preflight',
				"This backup was taken on WordPress {$source_wp_version}, newer than the {$current_wp_version} running here. Restoring its database could introduce a schema this core version doesn't understand. Upgrade WordPress core first, or choose an older backup."
			);
		}

		$space_check = self::check_disk_space( $cp );
		if ( is_wp_error( $space_check ) ) {
			return self::fail( $job_id, 'restore_preflight', $space_check->get_error_message() );
		}

		// Captured now, re-applied via update_option() after restore_swap
		// -- the dump's own wp_options table otherwise overwrites this
		// site's Nextcloud connection settings/site_id/chain state
		// wholesale (DB_Exporter only excludes the single
		// Settings::OPTION_KEY *row*, nothing else plugin-related).
		$cp['captured_options'] = array(
			Settings::OPTION_KEY                     => get_option( Settings::OPTION_KEY, array() ),
			Settings::SITE_ID_KEY                     => get_option( Settings::SITE_ID_KEY, '' ),
			Backup_Chain::STATE_OPTION                => get_option( Backup_Chain::STATE_OPTION, null ),
			'wpguard_nc_db_schema_version'             => get_option( 'wpguard_nc_db_schema_version', 0 ),
			Job_Orchestrator::RETENTION_ERROR_OPTION   => get_option( Job_Orchestrator::RETENTION_ERROR_OPTION, '' ),
		);

		$cp['quarantine_candidates'] = self::compute_quarantine_candidates( $cp );

		$cp['shadow_prefix'] = 'wpgshd' . substr( md5( $job_id ), 0, 8 ) . '_';
		$cp['backup_table_prefix'] = 'wpgbak' . substr( md5( $job_id . 'bak' ), 0, 8 ) . '_';
		$cp['rollback_dir'] = self::tmp_dir_for( $job_id ) . 'rollback-files/';
		self::ensure_tmp_guarded( $cp['rollback_dir'] );

		self::log( $job_id, 'restore_preflight', 'info', sprintf(
			'Preflight passed. %d file(s) will be restored; %d existing file(s) not in this backup will be quarantined (moved aside), not deleted.',
			count( $cp['target_files'] ), count( $cp['quarantine_candidates'] )
		) );
		Job_Store::update( $job_id, array( 'step' => 'restore_apply_db', 'checkpoint' => $cp ) );
		return Job_Store::get( $job_id );
	}

	/** @return array|\WP_Error */
	private static function build_target_files( array $job, array $cp ) {
		if ( 'incremental' === $cp['backup_mode'] ) {
			$targets = array();
			foreach ( (array) ( $cp['manifest']['files'] ?? array() ) as $entry ) {
				$targets[] = array(
					'path'             => $entry['path'],
					'sha256'           => $entry['sha256'] ?? null,
					'source_backup_id' => $entry['source_backup_id'],
					'source_part'      => $entry['source_part'],
				);
			}
			$cp['target_files'] = self::exclude_own_plugin_paths( $targets );
			return $cp;
		}

		// Full backup / format_version 1 (no files[] index): the
		// authoritative list is simply every real entry inside this
		// backup's own part(s) -- no manifest field needed, mirrors
		// exactly what a human following restore-manual.md step 6 would
		// extract.
		$targets = array();
		foreach ( $cp['own_parts'] as $part_name => $info ) {
			$key = $job['backup_id'] . '/' . $part_name;
			if ( empty( $cp['staged_parts'][ $key ] ) ) {
				return new \WP_Error( 'wpguard_nc_restore_part_not_staged', "Expected {$part_name} to already be staged." );
			}
			$entries = self::list_zip_entries( $cp['staged_parts'][ $key ] );
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}
			foreach ( $entries as $entry ) {
				$targets[] = array(
					'path'             => $entry['path'],
					'sha256'           => null,
					'source_backup_id' => $job['backup_id'],
					'source_part'      => $part_name,
				);
			}
		}
		$cp['target_files'] = self::exclude_own_plugin_paths( $targets );
		return $cp;
	}

	/** Drops any entry under the plugin's own directory, or wp-config.php -- see is_never_restored_path()'s docblock. */
	private static function exclude_own_plugin_paths( array $targets ): array {
		return array_values( array_filter( $targets, function ( $t ) {
			return ! self::is_never_restored_path( $t['path'] );
		} ) );
	}

	/** @return array{path:string}[]|\WP_Error */
	private static function list_zip_entries( string $zip_path ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'wpguard_nc_restore_zip_unreadable', "Could not open {$zip_path} to list its contents." );
		}
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false === $name || '/' === substr( $name, -1 ) ) {
				continue; // Directory entry.
			}
			$entries[] = array( 'path' => $name );
		}
		$zip->close();
		return $entries;
	}

	/** @return true|\WP_Error */
	private static function check_disk_space( array $cp ) {
		$staged_bytes = (int) ( @filesize( $cp['database_staged'] ) ?: 0 );
		foreach ( $cp['staged_parts'] as $local_path ) {
			$staged_bytes += (int) ( @filesize( $local_path ) ?: 0 );
		}
		// Conservative heuristic, not exact: the downloaded parts already
		// consume this much disk; extracting them and keeping a rollback
		// copy of whatever they replace each cost roughly the same again.
		$needed = $staged_bytes * 3;
		$free = @disk_free_space( ABSPATH );
		if ( false !== $free && $free < $needed ) {
			return new \WP_Error(
				'wpguard_nc_restore_low_disk_space',
				sprintf( 'Not enough free disk space for this restore: need roughly %s, only %s available.', size_format( $needed ), size_format( $free ) )
			);
		}
		return true;
	}

	/** @return string[] Relative paths that exist live but are not part of this restore. */
	private static function compute_quarantine_candidates( array $cp ): array {
		$target_paths = array();
		foreach ( $cp['target_files'] as $t ) {
			$target_paths[ $t['path'] ] = true;
		}
		$live = Inventory::enumerate();
		$quarantine = array();
		foreach ( array_keys( $live['files'] ) as $relative ) {
			if ( self::is_never_restored_path( $relative ) ) {
				// Never quarantined, regardless of whether the backup
				// being restored predates this exact file -- see
				// is_never_restored_path()'s docblock.
				continue;
			}
			if ( ! isset( $target_paths[ $relative ] ) ) {
				$quarantine[] = $relative;
			}
		}
		return $quarantine;
	}

	// ------------------------------------------------------------------
	// Step: restore_apply_db -- the point of no return begins here.
	// Imports into SHADOW-prefixed tables only; nothing live is touched
	// until restore_swap. The plugin's own job table is never imported
	// at all (skipped entirely while splitting the dump), so the
	// in-progress restore's own tracking row can never be at risk from
	// its own DB apply, regardless of what restore_swap does afterward.
	// ------------------------------------------------------------------

	private static function step_apply_db( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];

		if ( ! isset( $cp['db_statements_remaining'] ) ) {
			$prepared = self::prepare_db_statements( $job, $cp );
			if ( is_wp_error( $prepared ) ) {
				return self::fail( $job_id, 'restore_apply_db', $prepared->get_error_message() );
			}
			$cp = $prepared;
			Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
			return Job_Store::get( $job_id );
		}

		global $wpdb;
		$deadline = microtime( true ) + self::STEP_TIME_BUDGET_SECONDS;
		do {
			$statement = array_shift( $cp['db_statements_remaining'] );
			$wpdb->query( $statement );
			if ( '' !== (string) $wpdb->last_error ) {
				return self::fail(
					$job_id,
					'restore_apply_db',
					'Database import failed on a shadow table (nothing live was touched yet): ' . self::logger()->redact( (string) $wpdb->last_error )
				);
			}
			Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
		} while ( ! empty( $cp['db_statements_remaining'] ) && microtime( true ) < $deadline );

		if ( empty( $cp['db_statements_remaining'] ) ) {
			self::log( $job_id, 'restore_apply_db', 'info', 'Database staged into shadow tables and verified importable.' );
			Job_Store::update( $job_id, array( 'step' => 'restore_apply_files', 'checkpoint' => $cp ) );
			return Job_Store::get( $job_id );
		}
		return Job_Store::get( $job_id );
	}

	/** @return array|\WP_Error */
	private static function prepare_db_statements( array $job, array $cp ) {
		$gz = file_get_contents( $cp['database_staged'] );
		if ( false === $gz ) {
			return new \WP_Error( 'wpguard_nc_restore_db_read_failed', 'Could not read the staged database export.' );
		}
		$sql = gzdecode( $gz );
		unset( $gz );
		if ( false === $sql ) {
			return new \WP_Error( 'wpguard_nc_restore_db_decompress_failed', 'Could not decompress the staged database export -- it may be corrupted.' );
		}

		$source_prefix = (string) ( $cp['manifest']['source']['table_prefix'] ?? '' );
		if ( '' === $source_prefix ) {
			return new \WP_Error( 'wpguard_nc_restore_no_table_prefix', 'manifest.json does not record a source table_prefix -- refusing to guess which tables to rename.' );
		}

		$blocks = explode( "\n-- Table: ", $sql );
		unset( $sql );
		$statements = array();

		// The first element is whatever precedes the first table marker
		// (write_header()'s SET NAMES / FOREIGN_KEY_CHECKS lines) -- no
		// table name involved, run as-is.
		$header = array_shift( $blocks );
		foreach ( DB_Exporter::split_statements( $header ) as $s ) {
			$statements[] = $s;
		}

		foreach ( $blocks as $block ) {
			$newline_pos = strpos( $block, "\n" );
			if ( false === $newline_pos ) {
				continue;
			}
			$table_name = substr( $block, 0, $newline_pos );
			$rest = substr( $block, $newline_pos + 1 );

			if ( 0 !== strpos( $table_name, $source_prefix ) ) {
				return new \WP_Error( 'wpguard_nc_restore_unexpected_table_name', "Table \"{$table_name}\" in the database export doesn't start with the recorded source prefix \"{$source_prefix}\" -- refusing to guess how to rename it." );
			}
			$suffix = substr( $table_name, strlen( $source_prefix ) );
			if ( 'wpguard_nc_jobs' === $suffix ) {
				// Never imported at all -- this plugin's own job-tracking
				// table must never be a candidate for the later swap,
				// and skipping it here (rather than only excluding it at
				// swap time) means there is no shadow copy of it in
				// existence for anything to go wrong with.
				continue;
			}

			$shadow_table_name = $cp['shadow_prefix'] . $suffix;
			$rewritten = str_replace( "`{$table_name}`", "`{$shadow_table_name}`", $rest );
			foreach ( DB_Exporter::split_statements( $rewritten ) as $s ) {
				$statements[] = $s;
			}
		}

		$cp['db_statements_remaining'] = $statements;
		return $cp;
	}

	// ------------------------------------------------------------------
	// Step: restore_apply_files -- extracts verified content into place,
	// moving anything it would overwrite (or anything live but not part
	// of this backup) into a timestamped rollback directory first.
	// Nothing is ever deleted outright by this step.
	// ------------------------------------------------------------------

	private static function step_apply_files( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];
		$abspath = rtrim( ABSPATH, '/\\' );

		if ( ! isset( $cp['files_to_apply'] ) ) {
			$cp['files_to_apply'] = $cp['target_files'];
			$cp['quarantine_remaining'] = $cp['quarantine_candidates'];
		}

		// Quarantine first: anything live but not part of this backup,
		// moved aside (never deleted) before any extraction begins.
		if ( ! empty( $cp['quarantine_remaining'] ) ) {
			$deadline = microtime( true ) + self::STEP_TIME_BUDGET_SECONDS;
			do {
				$relative = array_shift( $cp['quarantine_remaining'] );
				$live_abs = $abspath . '/' . $relative;
				if ( file_exists( $live_abs ) || is_link( $live_abs ) ) {
					$dest = $cp['rollback_dir'] . 'quarantine/' . $relative;
					wp_mkdir_p( dirname( $dest ) );
					@rename( $live_abs, $dest );
				}
				Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
			} while ( ! empty( $cp['quarantine_remaining'] ) && microtime( true ) < $deadline );
			return Job_Store::get( $job_id );
		}

		if ( ! empty( $cp['files_to_apply'] ) ) {
			$deadline = microtime( true ) + self::STEP_TIME_BUDGET_SECONDS;
			$open_zips = array(); // "backup_id/part" => ZipArchive, kept open across this batch only
			do {
				$entry = array_shift( $cp['files_to_apply'] );
				$result = self::apply_one_file( $job, $cp, $entry, $abspath, $open_zips );
				if ( is_wp_error( $result ) ) {
					foreach ( $open_zips as $z ) {
						$z->close();
					}
					return self::fail( $job_id, 'restore_apply_files', $result->get_error_message() );
				}
				Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
			} while ( ! empty( $cp['files_to_apply'] ) && microtime( true ) < $deadline );
			foreach ( $open_zips as $z ) {
				$z->close();
			}
			return Job_Store::get( $job_id );
		}

		self::log( $job_id, 'restore_apply_files', 'info', 'Files restored and verified in place.' );
		Job_Store::update( $job_id, array( 'step' => 'restore_swap', 'checkpoint' => $cp ) );
		return Job_Store::get( $job_id );
	}

	/** @return true|\WP_Error */
	private static function apply_one_file( array $job, array &$cp, array $entry, string $abspath, array &$open_zips ) {
		// Defense in depth: build_target_files() already excludes these,
		// but a restore must never write into its own plugin directory
		// or wp-config.php under any circumstance -- checked again
		// here, at the one place an actual write happens, not only
		// where the list was first assembled.
		if ( self::is_never_restored_path( $entry['path'] ) ) {
			return true; // Silently skip, not an error -- this is expected to be filtered already.
		}
		$key = $entry['source_backup_id'] . '/' . $entry['source_part'];
		if ( ! isset( $open_zips[ $key ] ) ) {
			if ( empty( $cp['staged_parts'][ $key ] ) ) {
				return new \WP_Error( 'wpguard_nc_restore_part_missing_for_file', "No staged part found for {$entry['path']} (expected from {$key})." );
			}
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $cp['staged_parts'][ $key ] ) ) {
				return new \WP_Error( 'wpguard_nc_restore_zip_open_failed', "Could not open {$key} to extract {$entry['path']}." );
			}
			$open_zips[ $key ] = $zip;
		}
		$zip = $open_zips[ $key ];

		// Vex-informed defense in depth: re-check path safety at
		// extraction time too, not only at archive-write time -- these
		// bytes came over the network (or from an uploaded file) and
		// could in principle have been tampered with between backup and
		// restore, independent of the sha256 check below.
		if ( ! Archive_Writer::is_path_safe( $entry['path'] ) ) {
			return new \WP_Error( 'wpguard_nc_restore_unsafe_path', "Refusing to extract unsafe path: {$entry['path']}" );
		}

		$content = $zip->getFromName( $entry['path'] );
		if ( false === $content ) {
			return new \WP_Error( 'wpguard_nc_restore_file_missing_in_part', "{$entry['path']} is missing from its claimed archive part ({$entry['source_part']})." );
		}
		if ( null !== $entry['sha256'] && ! hash_equals( $entry['sha256'], hash( 'sha256', $content ) ) ) {
			return new \WP_Error( 'wpguard_nc_restore_file_hash_mismatch', "{$entry['path']} does not match its recorded checksum -- refusing to restore a corrupted or tampered file." );
		}

		$target_abs = $abspath . '/' . $entry['path'];
		// Already moved into the rollback dir by the quarantine pass if
		// it existed and wasn't part of this backup; a path that IS part
		// of this backup and also currently exists live still needs its
		// current version preserved before being overwritten.
		if ( file_exists( $target_abs ) || is_link( $target_abs ) ) {
			$dest = $cp['rollback_dir'] . 'replaced/' . $entry['path'];
			wp_mkdir_p( dirname( $dest ) );
			@rename( $target_abs, $dest );
		}
		wp_mkdir_p( dirname( $target_abs ) );
		if ( false === file_put_contents( $target_abs, $content ) ) {
			return new \WP_Error( 'wpguard_nc_restore_write_failed', "Could not write {$entry['path']} to its target location." );
		}
		if ( null !== ( $entry['mtime'] ?? null ) ) {
			@touch( $target_abs, (int) $entry['mtime'] );
		}
		return true;
	}

	// ------------------------------------------------------------------
	// Step: restore_swap -- one atomic multi-table RENAME TABLE. The
	// live tables this restore replaces are never dropped, only renamed
	// aside under $cp['backup_table_prefix'] for manual recovery.
	// ------------------------------------------------------------------

	private static function step_swap( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];
		global $wpdb;

		$shadow_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $cp['shadow_prefix'] ) . '%' ) );
		$renames = array();
		foreach ( (array) $shadow_tables as $shadow_table ) {
			$suffix = substr( $shadow_table, strlen( $cp['shadow_prefix'] ) );
			$live_table = $wpdb->prefix . $suffix;
			$exists_live = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $live_table ) );
			if ( $exists_live ) {
				$renames[] = "`{$live_table}` TO `{$cp['backup_table_prefix']}{$suffix}`";
			}
			$renames[] = "`{$shadow_table}` TO `{$live_table}`";
		}

		if ( ! empty( $renames ) ) {
			$wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return self::fail(
					$job_id,
					'restore_swap',
					'The table swap itself failed: ' . self::logger()->redact( (string) $wpdb->last_error ) .
					". Shadow tables (prefix {$cp['shadow_prefix']}) and any renamed-aside live tables (prefix {$cp['backup_table_prefix']}) remain on the database for manual inspection/recovery -- nothing was deleted."
				);
			}
		}

		// Defensive: invalidate any persistent object cache so this
		// request (and the next one) read the just-swapped data, not a
		// cached value from before the swap.
		wp_cache_flush();

		foreach ( $cp['captured_options'] as $key => $value ) {
			update_option( $key, $value, false );
		}

		self::log( $job_id, 'restore_swap', 'info', sprintf(
			'Restore complete. Previous live tables (if any existed) preserved under the "%s" prefix; quarantined/replaced files preserved under %s. Nothing was deleted -- review and manually clean those up once you have confirmed the restored site is correct.',
			$cp['backup_table_prefix'], $cp['rollback_dir']
		) );
		Job_Store::update( $job_id, array( 'status' => 'verified', 'step' => 'done' ) );
		return Job_Store::get( $job_id );
	}
}
