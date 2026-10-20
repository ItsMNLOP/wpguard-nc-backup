<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job state machine. Each advance() call does one bounded unit of work
 * (or, for export_db/export_files, as many as fit in
 * STEP_TIME_BUDGET_SECONDS) and persists a checkpoint after every single
 * item regardless of batching (JOB-03) — safe to call repeatedly from
 * AJAX, WP-Cron, or WP-CLI (ADR 0003). Never buffers an entire backup in
 * memory; the largest single in-memory object at any point is one archive
 * part (bounded by PART_SIZE_BYTES, ADR 0004).
 *
 * Steps: init -> export_db -> export_files -> package_manifest ->
 * upload_parts -> finalize -> (verified | failed).
 */
class Job_Orchestrator {

	const PART_SIZE_BYTES = 64 * 1024 * 1024;

	// Perf: export_db/export_files used to do exactly one table/file per
	// advance() call, so a site with thousands of small files meant
	// thousands of separate AJAX round trips (each a full WP bootstrap,
	// plus the client's 500ms poll delay). Each call now keeps working
	// through additional tables/files until this budget is spent, while
	// still persisting the checkpoint after every single item (same
	// durability as before — a crash mid-batch can re-run at most the one
	// item in flight, not the whole batch). Raised from 2.0 to 5.0
	// (2026-10-20) after a real 8,559-file backup showed the per-batch
	// archive open/close (see Archive_Writer) was still a measurable share
	// of export_files time even after batching -- fewer, bigger batches
	// amortize that further. Still well inside typical shared-hosting
	// max_execution_time.
	const STEP_TIME_BUDGET_SECONDS = 5.0;

	// JOB-05 (Phase 2): a job whose checkpoint hasn't been touched in this
	// long is treated as abandoned (PHP killed, container restarted, both
	// the browser tab and the WP-Cron fallback failing to pick it back
	// up) rather than genuinely in-progress.
	//
	// Vex delta audit, 2026-10-20 (LOW-JOB05-2): this value is NOT the
	// real protection against reaping an active job -- try_lock() is.
	// A single step_upload_parts() iteration can legitimately run far
	// longer than this threshold once every per-attempt timeout and
	// retry in class-nextcloud-client.php is counted (put_bytes: up to
	// 120s x 4 attempts; propfind_size: up to 30s x 4; download_to_file:
	// up to 300s x 4 -- on the order of 1800s combined, not the ~120s
	// this comment previously claimed). The lock is held for that
	// entire duration regardless, so try_lock() correctly fails and
	// reap_stale_lock() leaves the job alone no matter how stale its
	// clock looks (see docs/operations.md's "Stale-lock recovery" and
	// LOW-R2 for the one known way the lock itself can be dropped
	// early -- an aggressive MySQL wait_timeout -- which is a pre-
	// existing condition, not something this threshold controls).
	// This value only governs how long a job with NO active process at
	// all (lock already free) sits before being reaped. Filterable
	// since "how long is reasonable to wait with no visible progress"
	// varies by site.
	const STALE_LOCK_SECONDS = 600;

	// LOW-JOB05-3: a filtered value at or below this is rejected in
	// favor of this floor, so a misbehaving filter can't shrink the
	// window below the time it takes create_job() to return and the
	// first advance() call to actually acquire the lock -- the one
	// genuinely lock-free moment in a job's life.
	const MIN_STALE_LOCK_SECONDS = 60;

	public static function tmp_dir_for( string $backup_id ): string {
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/wpguard-nc-backup-tmp/' . $backup_id . '/';
	}

	private static function ensure_tmp_guarded(): void {
		$base = rtrim( WP_CONTENT_DIR, '/\\' ) . '/wpguard-nc-backup-tmp/';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		$index = $base . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$htaccess = $base . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n" );
		}
	}

	public static function create_job(): string {
		self::reap_stale_lock();
		if ( Job_Store::has_running_job() ) {
			return '';
		}
		self::ensure_tmp_guarded();
		$job_id = 'job-' . bin2hex( random_bytes( 8 ) );
		$backup_id = gmdate( 'Ymd\THis\Z' ) . '-' . bin2hex( random_bytes( 4 ) );
		wp_mkdir_p( self::tmp_dir_for( $backup_id ) );
		Job_Store::create( $job_id, $backup_id );
		return $job_id;
	}

	/**
	 * JOB-05 automatic recovery: replaces having to wait for an admin to
	 * notice a stuck job and click "Cancel / Reset" (still available,
	 * kept for an immediate human override). Called from create_job()
	 * so the fix applies the moment anyone next tries to back up --
	 * no admin action and no real server-cron (JOB-02, separate item)
	 * required -- and also from a recurring WP-Cron safety-net event
	 * (see wpguard-nc-backup.php) so a stuck job doesn't sit untouched
	 * indefinitely just because nobody happens to press "Back Up Now".
	 *
	 * Uses the same try_lock()/release_lock() pair as cancel() (Vex audit
	 * H-01's primitive) so this can never race a genuinely active
	 * advance() call: if the lock is held, the process is alive and
	 * working regardless of how old updated_at looks, and we correctly
	 * leave it alone.
	 *
	 * @return array|null The reaped job row (now 'failed'), or null if
	 *   there was nothing to reap.
	 */
	public static function reap_stale_lock(): ?array {
		$job = Job_Store::get_running();
		if ( null === $job ) {
			return null;
		}
		$threshold = max( self::MIN_STALE_LOCK_SECONDS, (int) apply_filters( 'wpguard_nc_stale_lock_seconds', self::STALE_LOCK_SECONDS ) );
		$age = time() - strtotime( $job['updated_at'] . ' UTC' );
		if ( $age < $threshold ) {
			return null;
		}
		if ( ! Job_Store::try_lock( $job['job_id'] ) ) {
			// Lock is genuinely held right now -- a live process is
			// working, regardless of how stale updated_at looks. Don't
			// race it; let it finish or fail on its own.
			return null;
		}
		try {
			$fresh = Job_Store::get( $job['job_id'] );
			if ( null === $fresh || in_array( $fresh['status'], array( 'verified', 'failed' ), true ) ) {
				// Finished (or was already handled) between our read
				// and acquiring the lock.
				return null;
			}
			return self::fail(
				$job['job_id'],
				$fresh['step'],
				sprintf(
					'Automatically recovered: no progress for over %d seconds, likely an interrupted process. Previous backups are untouched.',
					$threshold
				)
			);
		} finally {
			Job_Store::release_lock( $job['job_id'] );
		}
	}

	private static function logger(): Logger {
		return new Logger( array( Settings::get_app_password() ) );
	}

	private static function log( string $job_id, string $step, string $severity, string $message, array $context = array() ): void {
		Job_Store::append_log( $job_id, self::logger()->entry( $job_id, $step, $severity, $message, $context ) );
	}

	/**
	 * Manual admin override for JOB-05, kept alongside the automatic
	 * stale-lock reaper (reap_stale_lock()) below: a genuinely interrupted
	 * job -- WP-Cron fallback never picked it up (operations.md:
	 * pseudo-cron only fires on site traffic) -- would otherwise stay
	 * `running`/`queued`/`uploaded-unverified` and block every future
	 * "Back Up Now" via Job_Store::has_running_job(). The automatic reaper
	 * now clears this on its own once a job is stale enough; this button
	 * stays as an explicit, immediate, auditable human override for when
	 * an admin doesn't want to wait out the staleness threshold, or wants
	 * to cancel a job that's healthy but no longer wanted.
	 *
	 * @return array Updated job row, or the job unchanged if it was
	 *   already terminal, or {status: not_found}.
	 */
	/**
	 * @return array Job row with an added 'cancel_result' key: 'cancelled',
	 *   'already_terminal', 'lock_contended' (advance() held the lock right
	 *   now -- nothing was changed, caller must not report this as success,
	 *   Vex audit LOW-C1), or 'not_found'.
	 */
	public static function cancel( string $job_id ): array {
		if ( ! Job_Store::try_lock( $job_id ) ) {
			// Another process (AJAX/cron) is actively advancing it right
			// now -- don't race a live advance() call. Nothing changed.
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
			$job = self::fail( $job_id, $job['step'], 'Cancelled by administrator (stuck-job recovery).' );
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
		$job = Job_Store::get( $job_id );
		// Vex audit L-01: cleanup_tmp() previously only ran on the success
		// path, leaving partial database/archive files under
		// wp-content/wpguard-nc-backup-tmp/ indefinitely on failure —
		// .htaccess-protected on Apache but directly web-accessible on
		// Nginx with no equivalent guard.
		if ( null !== $job && ! empty( $job['backup_id'] ) ) {
			self::upload_diagnostic_log( $job['backup_id'], $job['log'] ?? array() );
			self::cleanup_tmp( self::tmp_dir_for( $job['backup_id'] ) );
		}
		return $job;
	}

	/**
	 * Dev/testing diagnostic artifact (not part of the restorable backup
	 * set, not listed in manifest.json's components[]): the full per-step
	 * job log, uploaded alongside the backup as job-log.json so real
	 * per-step timestamps are visible without needing WP-CLI/SSH access to
	 * the site. Every entry already passed through Logger::redact() at
	 * write time (T8), so this carries no more exposure than the log
	 * already visible in the admin UI. Best-effort and silently swallowed
	 * on failure -- this must never affect job status, on the success path
	 * or the failure path that calls it from fail() above.
	 */
	private static function upload_diagnostic_log( string $backup_id, array $log ): void {
		if ( empty( $log ) || ! Settings::has_credentials() ) {
			return;
		}
		try {
			$client = Nextcloud_Client::from_settings( self::logger() );
			$remote_rel = $client->relative_path( Settings::get_site_id() . '/backups/' . $backup_id . '/job-log.json' );
			$client->put_bytes( $remote_rel, wp_json_encode( $log, JSON_PRETTY_PRINT ) );
		} catch ( \Throwable $e ) {
			// Diagnostic only; intentionally not surfaced or retried.
		}
	}

	/**
	 * Advance the job by exactly one bounded unit of work.
	 *
	 * @return array Updated job row (see Job_Store::get()).
	 */
	public static function advance( string $job_id ): array {
		// Vex audit H-01: AJAX polling and the WP-Cron fallback can both
		// reach this method for the same job in overlapping requests. If
		// another process already holds the lock, just report the
		// current state instead of racing it on the same checkpoint.
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
				case 'init':
					return self::step_init( $job );
				case 'export_db':
					return self::step_export_db( $job );
				case 'export_files':
					return self::step_export_files( $job );
				case 'package_manifest':
					return self::step_package_manifest( $job );
				case 'upload_parts':
					return self::step_upload_parts( $job );
				case 'finalize':
					return self::step_finalize( $job );
				default:
					return self::fail( $job_id, $job['step'], 'Unknown job step: ' . $job['step'] );
			}
		} finally {
			Job_Store::release_lock( $job_id );
		}
	}

	private static function step_init( array $job ): array {
		$job_id = $job['job_id'];
		if ( ! Settings::has_credentials() ) {
			return self::fail( $job_id, 'init', 'Nextcloud URL, username, and app password must all be configured before a backup can run.' );
		}
		$client = Nextcloud_Client::from_settings( self::logger() );
		$mk = $client->mkcol_recursive( $client->relative_path( Settings::get_site_id() . '/backups/' . $job['backup_id'] ) );
		if ( is_wp_error( $mk ) ) {
			return self::fail( $job_id, 'init', 'Could not prepare remote destination folder: ' . $mk->get_error_message() );
		}
		self::log( $job_id, 'init', 'info', 'Remote destination folder ready.' );
		Job_Store::update( $job_id, array( 'step' => 'export_db', 'checkpoint' => array() ) );
		return Job_Store::get( $job_id );
	}

	private static function step_export_db( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];
		$tmp = self::tmp_dir_for( $job['backup_id'] );
		$sql_path = $tmp . 'database.sql';

		if ( ! isset( $cp['tables_remaining'] ) ) {
			$cp['tables_remaining'] = DB_Exporter::list_tables();
			$cp['tables_metadata'] = array();
			$cp['db_warnings'] = array();
			$fh = fopen( $sql_path, 'w' );
			DB_Exporter::write_header( $fh );
			fclose( $fh );
		}

		if ( ! empty( $cp['tables_remaining'] ) ) {
			$deadline = microtime( true ) + self::STEP_TIME_BUDGET_SECONDS;
			do {
				$table = array_shift( $cp['tables_remaining'] );
				$fh = fopen( $sql_path, 'a' );
				$result = DB_Exporter::export_table( $fh, $table );
				fclose( $fh );
				$cp['tables_metadata'][] = array(
					'name'              => $table,
					'row_count'         => $result['row_count'],
					'ordering_strategy' => $result['ordering_strategy'],
					'primary_key'       => $result['primary_key'],
				);
				if ( $result['warning'] ) {
					$cp['db_warnings'][] = $result['warning'];
				}
				self::log( $job_id, 'export_db', 'info', "Exported table {$table} ({$result['row_count']} rows)." );
				Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
			} while ( ! empty( $cp['tables_remaining'] ) && microtime( true ) < $deadline );
			return Job_Store::get( $job_id );
		}

		$fh = fopen( $sql_path, 'a' );
		DB_Exporter::write_footer( $fh );
		fclose( $fh );

		$gz_path = $sql_path . '.gz';
		$plain = file_get_contents( $sql_path );
		file_put_contents( $gz_path, gzencode( $plain, 6 ) );
		unlink( $sql_path );

		$cp['database'] = array(
			'local_path' => $gz_path,
			'bytes'      => filesize( $gz_path ),
			'sha256'     => hash_file( 'sha256', $gz_path ),
		);
		self::log( $job_id, 'export_db', 'info', 'Database export complete and compressed.' );
		Job_Store::update( $job_id, array( 'step' => 'export_files', 'checkpoint' => $cp ) );
		return Job_Store::get( $job_id );
	}

	private static function step_export_files( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];
		$tmp = self::tmp_dir_for( $job['backup_id'] );

		if ( ! isset( $cp['files_remaining'] ) ) {
			$inventory = Inventory::enumerate();
			$cp['files_remaining'] = $inventory['files'];
			$cp['file_warnings'] = $inventory['warnings'];
			$cp['current_part_index'] = 1;
			$cp['current_part_bytes'] = 0;
			$cp['parts_meta'] = array();
		}

		if ( ! empty( $cp['files_remaining'] ) ) {
			$deadline = microtime( true ) + self::STEP_TIME_BUDGET_SECONDS;
			$part_path = self::part_path( $tmp, $cp['current_part_index'] );
			$zip = Archive_Writer::open_part( $part_path );
			if ( is_wp_error( $zip ) ) {
				return self::fail( $job_id, 'export_files', 'Archiving failed: ' . $zip->get_error_message() );
			}
			do {
				$relative = array_key_first( $cp['files_remaining'] );
				$abs = $cp['files_remaining'][ $relative ];
				unset( $cp['files_remaining'][ $relative ] );

				if ( 'wp-config.php' === $relative ) {
					$redacted = self::redacted_wp_config_copy( $abs, $tmp );
					if ( is_wp_error( $redacted ) ) {
						Archive_Writer::close_part( $zip );
						return self::fail( $job_id, 'export_files', $redacted->get_error_message() );
					}
					$abs = $redacted;
				}

				$size = @filesize( $abs );
				$size = false === $size ? 0 : $size;

				if ( $cp['current_part_bytes'] > 0 && ( $cp['current_part_bytes'] + $size ) > self::PART_SIZE_BYTES ) {
					Archive_Writer::close_part( $zip );
					$cp['parts_meta'][] = self::finalize_files_part( $cp, $tmp );
					$cp['current_part_index']++;
					$cp['current_part_bytes'] = 0;
					$part_path = self::part_path( $tmp, $cp['current_part_index'] );
					$zip = Archive_Writer::open_part( $part_path );
					if ( is_wp_error( $zip ) ) {
						return self::fail( $job_id, 'export_files', 'Archiving failed: ' . $zip->get_error_message() );
					}
				}

				$result = Archive_Writer::add_file_to( $zip, $abs, $relative );
				if ( is_wp_error( $result ) ) {
					Archive_Writer::close_part( $zip );
					return self::fail( $job_id, 'export_files', 'Archiving failed: ' . $result->get_error_message() );
				}
				$cp['current_part_bytes'] += $size;
				Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
			} while ( ! empty( $cp['files_remaining'] ) && microtime( true ) < $deadline );
			Archive_Writer::close_part( $zip );
			return Job_Store::get( $job_id );
		}

		if ( $cp['current_part_bytes'] > 0 || empty( $cp['parts_meta'] ) ) {
			$part_path = self::part_path( $tmp, $cp['current_part_index'] );
			if ( file_exists( $part_path ) ) {
				$cp['parts_meta'][] = self::finalize_files_part( $cp, $tmp );
			}
		}

		self::log( $job_id, 'export_files', 'info', 'File archiving complete (' . count( $cp['parts_meta'] ) . ' part(s)).' );
		Job_Store::update( $job_id, array( 'step' => 'package_manifest', 'checkpoint' => $cp ) );
		return Job_Store::get( $job_id );
	}

	/**
	 * Vex audit H-02: ADR 0002's constant-override escape hatch puts the
	 * Nextcloud app password directly in wp-config.php — which this same
	 * backup then archives verbatim, circularly exposing the credential
	 * that protects the backup inside the backup itself. Strips any line
	 * defining the app-password or CA-bundle constants before the file
	 * reaches the archive, regardless of whether the override is
	 * currently active (so a backup taken while it's off can't later be
	 * "upgraded" into leaking a value that gets set afterward).
	 */
	/** @return string|\WP_Error Path to the redacted copy, or a WP_Error if the credential survived redaction. */
	private static function redacted_wp_config_copy( string $original_abs, string $tmp ) {
		$redacted = Settings::redact_wp_config_contents( file_get_contents( $original_abs ) );

		// Vex audit LOW-R1 (fail-closed hardening, post H-02 re-review):
		// the redaction regex can't cover every conceivable define()
		// syntax (multi-line, `const`, mixed-case DEFINE) — none appear
		// in any real wp-config.php, but if the currently-active
		// credential literal somehow survives redaction, fail the job
		// rather than silently archive a leaked credential.
		if ( Settings::is_app_password_locked() ) {
			$secret = Settings::get_app_password();
			if ( '' !== $secret && false !== strpos( $redacted, $secret ) ) {
				return new \WP_Error( 'wpguard_nc_wp_config_redaction_failed', 'wp-config.php redaction did not remove the active credential; refusing to archive it.' );
			}
		}

		$redacted_path = $tmp . 'wp-config.redacted.php';
		file_put_contents( $redacted_path, $redacted );
		return $redacted_path;
	}

	private static function part_path( string $tmp, int $index ): string {
		return $tmp . sprintf( 'files.part-%04d.zip', $index );
	}

	private static function finalize_files_part( array $cp, string $tmp ): array {
		$part_path = self::part_path( $tmp, $cp['current_part_index'] );
		$meta = Archive_Writer::finalize_part( $part_path );
		$meta['index'] = $cp['current_part_index'];
		$meta['local_path'] = $part_path;
		return $meta;
	}

	private static function step_package_manifest( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];
		$tmp = self::tmp_dir_for( $job['backup_id'] );

		$manifest = Manifest::new_skeleton( $job['backup_id'], Settings::get_site_id() );
		$manifest['tables_metadata'] = $cp['tables_metadata'];
		$manifest['scope']['database']['tables'] = array_column( $cp['tables_metadata'], 'name' );
		$manifest['warnings'] = array_merge( $cp['db_warnings'] ?? array(), $cp['file_warnings'] ?? array() );

		Manifest::add_component( $manifest, 'database.sql.gz', 'database', $cp['database']['bytes'], $cp['database']['sha256'], 1, 1 );
		$parts_total = count( $cp['parts_meta'] );
		foreach ( $cp['parts_meta'] as $i => $part ) {
			$name = sprintf( 'files.part-%04d.zip', $part['index'] );
			Manifest::add_component( $manifest, $name, 'files', $part['bytes'], $part['sha256'], $part['index'], $parts_total );
		}

		$manifest_json = Manifest::to_json( $manifest );
		$manifest_path = $tmp . 'manifest.json';
		file_put_contents( $manifest_path, $manifest_json );

		$cp['manifest_local_path'] = $manifest_path;
		$cp['manifest_sha256'] = hash( 'sha256', $manifest_json );

		$to_upload = array();
		$to_upload[] = array( 'local' => $manifest_path, 'remote' => 'manifest.json', 'label' => 'manifest.json' );
		$to_upload[] = array( 'local' => $cp['database']['local_path'], 'remote' => 'database.sql.gz', 'label' => 'database.sql.gz' );
		foreach ( $cp['parts_meta'] as $part ) {
			$to_upload[] = array(
				'local'  => $part['local_path'],
				'remote' => sprintf( 'files.part-%04d.zip', $part['index'] ),
				'label'  => sprintf( 'files.part-%04d.zip', $part['index'] ),
				'sha256' => $part['sha256'],
				'bytes'  => $part['bytes'],
			);
		}
		$cp['parts_to_upload'] = $to_upload;
		$cp['uploaded'] = array();

		self::log( $job_id, 'package_manifest', 'info', 'Manifest built; ' . count( $to_upload ) . ' object(s) queued for upload.' );
		Job_Store::update( $job_id, array( 'step' => 'upload_parts', 'checkpoint' => $cp ) );
		return Job_Store::get( $job_id );
	}

	private static function step_upload_parts( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];

		if ( empty( $cp['parts_to_upload'] ) ) {
			self::log( $job_id, 'upload_parts', 'info', 'All objects uploaded and verified.' );
			Job_Store::update( $job_id, array( 'step' => 'finalize', 'status' => 'uploaded-unverified', 'checkpoint' => $cp ) );
			return Job_Store::get( $job_id );
		}

		$item = array_shift( $cp['parts_to_upload'] );
		$client = Nextcloud_Client::from_settings( self::logger() );
		$remote_rel = $client->relative_path( Settings::get_site_id() . '/backups/' . $job['backup_id'] . '/' . $item['remote'] );

		$local_bytes = file_get_contents( $item['local'] );
		$local_sha256 = $item['sha256'] ?? hash( 'sha256', $local_bytes );
		$local_size = $item['bytes'] ?? strlen( $local_bytes );

		$put = $client->put_bytes( $remote_rel, $local_bytes );
		unset( $local_bytes );
		if ( is_wp_error( $put ) ) {
			return self::fail( $job_id, 'upload_parts', "Upload failed for {$item['label']}: " . $put->get_error_message() );
		}

		$size_check = $client->propfind_size( $remote_rel );
		if ( is_wp_error( $size_check ) || empty( $size_check['exists'] ) || (int) $size_check['length'] !== (int) $local_size ) {
			return self::fail( $job_id, 'upload_parts', "Remote size mismatch after upload for {$item['label']}; backup not verified." );
		}

		$verify_tmp = $item['local'] . '.verify';
		$download = $client->download_to_file( $remote_rel, $verify_tmp, $local_size );
		if ( is_wp_error( $download ) ) {
			return self::fail( $job_id, 'upload_parts', "Read-back download failed for {$item['label']}: " . $download->get_error_message() );
		}
		$remote_sha256 = hash_file( 'sha256', $verify_tmp );
		unlink( $verify_tmp );

		if ( $remote_sha256 !== $local_sha256 ) {
			return self::fail( $job_id, 'upload_parts', "Checksum mismatch after upload for {$item['label']} — backup NOT verified. Previous backups are untouched." );
		}

		$cp['uploaded'][] = $item['label'];
		self::log( $job_id, 'upload_parts', 'info', "Uploaded and verified: {$item['label']}" );
		Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
		return Job_Store::get( $job_id );
	}

	private static function step_finalize( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];
		$tmp = self::tmp_dir_for( $job['backup_id'] );

		$complete_json = Manifest::complete_json( $job['backup_id'], file_get_contents( $cp['manifest_local_path'] ) );
		$complete_path = $tmp . 'COMPLETE.json';
		file_put_contents( $complete_path, $complete_json );

		$client = Nextcloud_Client::from_settings( self::logger() );
		$remote_rel = $client->relative_path( Settings::get_site_id() . '/backups/' . $job['backup_id'] . '/COMPLETE.json' );
		$put = $client->put_bytes( $remote_rel, $complete_json );
		if ( is_wp_error( $put ) ) {
			return self::fail( $job_id, 'finalize', 'Could not upload completion marker: ' . $put->get_error_message() );
		}

		$check = $client->propfind_size( $remote_rel );
		if ( is_wp_error( $check ) || empty( $check['exists'] ) || (int) $check['length'] !== strlen( $complete_json ) ) {
			return self::fail( $job_id, 'finalize', 'Completion marker did not verify after upload — backup remains uploaded-unverified.' );
		}

		self::upload_diagnostic_log( $job['backup_id'], $job['log'] ?? array() );
		self::cleanup_tmp( $tmp );
		self::log( $job_id, 'finalize', 'info', 'Backup verified and complete.' );
		Job_Store::update( $job_id, array( 'status' => 'verified', 'step' => 'done' ) );
		self::maybe_prune_old_backups( $job_id, $client );
		return Job_Store::get( $job_id );
	}

	/**
	 * Phase 2 retention: runs only after the job above is already marked
	 * verified, so a pruning failure (network blip, permission error)
	 * can never turn a good backup into a failed one -- same
	 * never-affects-job-status discipline as upload_diagnostic_log().
	 * Reuses the $client already built for this job's upload instead of
	 * constructing a second one.
	 */
	private static function maybe_prune_old_backups( string $job_id, Nextcloud_Client $client ): void {
		$keep = Settings::get_retention_count();
		if ( $keep <= 0 ) {
			return;
		}
		try {
			$result = Retention::prune( $client, $keep );
			if ( is_wp_error( $result ) ) {
				self::log( $job_id, 'finalize', 'warning', 'Retention pruning did not fully complete: ' . self::logger()->redact( $result->get_error_message() ) );
				return;
			}
			if ( ! empty( $result['deleted'] ) ) {
				self::log( $job_id, 'finalize', 'info', sprintf( 'Retention: removed %d old backup(s), kept %d.', count( $result['deleted'] ), $result['kept'] ) );
			}
		} catch ( \Throwable $e ) {
			self::log( $job_id, 'finalize', 'warning', 'Retention pruning threw an unexpected error: ' . self::logger()->redact( $e->getMessage() ) );
		}
	}

	private static function cleanup_tmp( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $dir );
	}
}
