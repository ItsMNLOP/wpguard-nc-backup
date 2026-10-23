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
 * AJAX, WP-Cron, or WP-CLI (ADR 0003) -- with one deliberate exception:
 * step_export_files() only commits a file (to either resolved_files or
 * files_remaining) once the archive close() that makes the rest of its
 * batch durable has already succeeded (security audit finding HIGH-ZIP-1 and its
 * re-verification NEW-HIGH-1), so a crash mid-batch there re-does that
 * batch's size/mtime checks on retry rather than losing a file outright.
 * Never buffers an entire backup in memory; the largest single
 * in-memory object at any point is one archive part (bounded by
 * PART_SIZE_BYTES, ADR 0004).
 *
 * Steps: init -> export_db -> export_files -> package_manifest -> then,
 * by destination (decided once at create_job(), see Job_Store::create()'s
 * docblock): 'nextcloud' -> upload_parts -> finalize -> (verified |
 * failed); 'download' (Phase 3) -> bundle_local -> (verified | failed).
 */
class Job_Orchestrator {

	const PART_SIZE_BYTES = 64 * 1024 * 1024;

	/**
	 * Phase 3 (downloadable backups): how long a pending local bundle
	 * (full DB dump + files, unencrypted) sits on disk before the hourly
	 * sweep removes it even if nobody ever clicked Download. Filterable;
	 * MIN floor exists for the same reason STALE_LOCK_SECONDS has one --
	 * a misbehaving filter must not be able to shrink this below the time
	 * it actually takes an admin to notice the button and click it.
	 */
	const DOWNLOAD_BUNDLE_TTL_SECONDS = DAY_IN_SECONDS;
	const MIN_DOWNLOAD_BUNDLE_TTL_SECONDS = 300;

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
	// the security audit delta audit, 2026-10-20 (LOW-JOB05-2): this value is NOT the
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

	/**
	 * Phase 3 (downloadable backups): a dedicated, 128-bit-random-token
	 * subdirectory, not the per-job tmp dir keyed by backup_id (only 32
	 * random bits -- plenty for a short-lived upload/restore staging dir,
	 * not for something meant to persist up to DOWNLOAD_BUNDLE_TTL_SECONDS
	 * holding a full DB dump + wp-config.php). Nested under the existing
	 * guarded wpguard-nc-backup-tmp/ base -- inherits its .htaccess
	 * (Apache "Require all denied" applies to descendants too) and is
	 * already covered by Inventory::EXCLUDED_PATTERNS's
	 * 'wp-content/wpguard-nc-backup-tmp' exact-match entry, which stops
	 * the enumeration walk before it ever descends into this directory at
	 * all -- no separate exclusion pattern needed.
	 */
	public static function download_base_dir(): string {
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/wpguard-nc-backup-tmp/downloads/';
	}

	public static function new_download_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/** Constrained to exactly what new_download_token() produces -- never built from request input. */
	public static function download_dir_for_token( string $token ): string {
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return '';
		}
		return self::download_base_dir() . $token . '/';
	}

	/**
	 * @param bool $purge_all Phase 3: true removes every pending bundle
	 *   unconditionally (called when a new local-download backup starts --
	 *   at most one pending bundle exists at a time -- and on plugin
	 *   deactivation, since the hourly sweep that would otherwise catch a
	 *   TTL expiry stops firing once deactivated). False (the hourly
	 *   cron's own call, and render_page()'s) only removes bundles past
	 *   DOWNLOAD_BUNDLE_TTL_SECONDS.
	 */
	public static function sweep_download_bundles( bool $purge_all = false ): void {
		$base = self::download_base_dir();
		if ( ! is_dir( $base ) ) {
			return;
		}
		$ttl = max( self::MIN_DOWNLOAD_BUNDLE_TTL_SECONDS, (int) apply_filters( 'wpguard_nc_download_bundle_ttl_seconds', self::DOWNLOAD_BUNDLE_TTL_SECONDS ) );
		foreach ( new \DirectoryIterator( $base ) as $item ) {
			if ( $item->isDot() || ! $item->isDir() ) {
				continue;
			}
			if ( $purge_all || ( time() - $item->getMTime() ) > $ttl ) {
				$token = $item->getFilename();
				self::cleanup_tmp( $item->getPathname() . '/' );
				// security audit finding LOW-DL-3: the owning job's checkpoint must be
				// cleared in the same pass, not left pointing at a now-deleted
				// directory -- see Job_Store::find_by_download_token()'s docblock.
				$owner = Job_Store::find_by_download_token( $token );
				if ( null !== $owner ) {
					$cp = $owner['checkpoint'];
					unset( $cp['download_token'], $cp['download_bundle_path'], $cp['download_bundle_bytes'], $cp['download_ready_at'], $cp['bundle_local_token'], $cp['bundle_local_attempts'] );
					Job_Store::update( $owner['job_id'], array( 'checkpoint' => $cp ) );
				}
			}
		}
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

	/**
	 * @param string $destination 'nextcloud' (default) or 'download' (Phase 3). Any other value falls back to 'nextcloud'.
	 *
	 * Phase 3, security audit finding MEDIUM-DL-3: has_running_job() then insert was a
	 * plain check-then-act with no atomicity -- proven live, 5 concurrent
	 * requests produced 5 distinct running jobs. For the Nextcloud path
	 * this mostly wastes work; for 'download' it's worse, since cap-at-one
	 * (sweep_download_bundles()) and the explicit-delete endpoint both
	 * assume at most one pending bundle exists -- the audit proved a
	 * second verified bundle getting silently destroyed by a delete call
	 * for the first job's job_id. A short-lived MySQL advisory lock
	 * (same GET_LOCK/RELEASE_LOCK primitive Job_Store already uses for
	 * per-job locking, here keyed globally since no job_id exists yet)
	 * closes the whole check-then-act window, not just the download case.
	 */
	/**
	 * security audit finding NEW-LOW-2 (re-verification, 2026-10-21): a bare static
	 * lock name would contend with an unrelated site sharing the same
	 * MySQL server under a different database/prefix (common on shared
	 * hosting) -- namespaced per-site via the site_id this plugin already
	 * generates and persists for exactly this kind of cross-site
	 * disambiguation. Shared between create_job() (backups) and
	 * Restore_Orchestrator::create_restore_job() (security audit finding NEW-LOW-1)
	 * so a backup and a restore can never both pass their own
	 * check-then-act window at the same time either -- GET_LOCK names
	 * are process-global on the MySQL connection, not per-table, so one
	 * shared name is exactly what's needed to serialize both against
	 * each other, not just against themselves.
	 */
	public static function create_job_lock_name(): string {
		return 'wpguard_nc_create_' . substr( md5( Settings::get_site_id() ), 0, 20 );
	}

	public static function create_job( string $destination = 'nextcloud' ): string {
		$destination = in_array( $destination, array( 'nextcloud', 'download' ), true ) ? $destination : 'nextcloud';
		self::reap_stale_lock();

		global $wpdb;
		$lock_name = self::create_job_lock_name();
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $got ) {
			// Another create_job() call is genuinely in flight right now;
			// treat it the same as "a backup is already running" rather
			// than risk a second concurrent job.
			return '';
		}
		try {
			if ( Job_Store::has_running_job() ) {
				return '';
			}
			self::ensure_tmp_guarded();
			// Phase 3: cap-at-one-pending-bundle is enforced here, not by
			// correlating against a prior job row (which may no longer exist
			// or may not have reached 'verified') -- a new local backup
			// starting is the one unambiguous moment an old one is no longer
			// needed. Now race-free: this whole method runs under the lock
			// above, so no second create_job() call can be mid-flight.
			self::sweep_download_bundles( 'download' === $destination );
			$job_id = 'job-' . bin2hex( random_bytes( 8 ) );
			$backup_id = gmdate( 'Ymd\THis\Z' ) . '-' . bin2hex( random_bytes( 4 ) );
			wp_mkdir_p( self::tmp_dir_for( $backup_id ) );
			Job_Store::create( $job_id, $backup_id, $destination );
			return $job_id;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
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
	 * Uses the same try_lock()/release_lock() pair as cancel() (security audit
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
			// Phase 2 (one-click restore): a restore job uses this exact
			// same stale-lock mechanism (same table, same try_lock()), but
			// "previous backups are untouched" would be actively wrong --
			// and dangerous to believe -- for a restore stuck mid-apply.
			// Dispatched by checkpoint kind, set once at job creation.
			if ( 'restore' === ( $fresh['checkpoint']['kind'] ?? 'backup' ) ) {
				return Restore_Orchestrator::reap_stale( $fresh );
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
	 *   security audit finding LOW-C1), or 'not_found'.
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
		// security audit finding L-01: cleanup_tmp() previously only ran on the success
		// path, leaving partial database/archive files under
		// wp-content/wpguard-nc-backup-tmp/ indefinitely on failure —
		// .htaccess-protected on Apache but directly web-accessible on
		// Nginx with no equivalent guard.
		if ( null !== $job && ! empty( $job['backup_id'] ) ) {
			self::upload_diagnostic_log( $job['backup_id'], $job['log'] ?? array(), $job['destination'] ?? 'nextcloud' );
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
	private static function upload_diagnostic_log( string $backup_id, array $log, string $destination = 'nextcloud' ): void {
		// Phase 3, security audit finding LOW-DL-1: proven live -- a failed download job
		// with Nextcloud ALSO configured fired an authenticated WebDAV PUT
		// anyway (harmlessly rejected since step_init() skips creating the
		// remote folder for a download job, but contradicts "no Nextcloud
		// involvement at all" and is needless outbound credential use).
		if ( 'download' === $destination || empty( $log ) || ! Settings::has_credentials() ) {
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
		// security audit finding H-01: AJAX polling and the WP-Cron fallback can both
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
				case 'bundle_local':
					return self::step_bundle_local( $job );
				default:
					return self::fail( $job_id, $job['step'], 'Unknown job step: ' . $job['step'] );
			}
		} finally {
			Job_Store::release_lock( $job_id );
		}
	}

	private static function step_init( array $job ): array {
		$job_id = $job['job_id'];

		// Phase 3 (downloadable backups): no Nextcloud destination at all
		// for this job -- skip the remote folder/credential requirement
		// and the incremental-chain plan entirely (chain detection reads
		// the Nextcloud destination's own backup history; a local-only
		// bundle has no persistent location to diff against, so it's
		// always a full backup, same as the very first backup of a chain).
		if ( 'download' === ( $job['destination'] ?? 'nextcloud' ) ) {
			$chain = array( 'mode' => 'full', 'base_backup_id' => null, 'parent_backup_id' => null, 'prior_files_index' => array() );
			self::log( $job_id, 'init', 'info', 'Local download-only backup (full; no Nextcloud destination configured or selected).' );
			Job_Store::update( $job_id, array( 'step' => 'export_db', 'checkpoint' => array( 'chain' => $chain ) ) );
			return Job_Store::get( $job_id );
		}

		if ( ! Settings::has_credentials() ) {
			return self::fail( $job_id, 'init', 'Nextcloud URL, username, and app password must all be configured before a backup can run.' );
		}
		$client = Nextcloud_Client::from_settings( self::logger() );
		$mk = $client->mkcol_recursive( $client->relative_path( Settings::get_site_id() . '/backups/' . $job['backup_id'] ) );
		if ( is_wp_error( $mk ) ) {
			return self::fail( $job_id, 'init', 'Could not prepare remote destination folder: ' . $mk->get_error_message() );
		}

		// Phase 2 (incremental backups): decided once, up front, so a
		// decision to attempt incremental is made before any file is
		// skipped -- never discovered to be unsafe partway through
		// export_files, by which point unchanged files would already
		// have been skipped past recovery for this run. See
		// class-backup-chain.php for the fail-closed validation this
		// performs against the real server before trusting a parent.
		$parent_rel = $client->relative_path( Settings::get_site_id() . '/backups' );
		$chain = Backup_Chain::plan( $client, $parent_rel );
		self::log(
			$job_id,
			'init',
			'info',
			'incremental' === $chain['mode']
				? sprintf( 'Incremental backup (chain depth %d), based on %s.', $chain['depth'], $chain['parent_backup_id'] )
				: 'Full backup.'
		);

		self::log( $job_id, 'init', 'info', 'Remote destination folder ready.' );
		Job_Store::update( $job_id, array( 'step' => 'export_db', 'checkpoint' => array( 'chain' => $chain ) ) );
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
		$chain = $cp['chain'] ?? array( 'mode' => 'full', 'prior_files_index' => array() );
		$prior_index = $chain['prior_files_index'] ?? array();

		if ( ! isset( $cp['files_remaining'] ) ) {
			$inventory = Inventory::enumerate();
			$cp['files_remaining'] = $inventory['files'];
			$cp['file_warnings'] = $inventory['warnings'];
			$cp['current_part_index'] = 1;
			$cp['current_part_bytes'] = 0;
			$cp['current_part_file_count'] = 0;
			$cp['parts_meta'] = array();
			$cp['resolved_files'] = array();
		}

		if ( ! empty( $cp['files_remaining'] ) ) {
			$deadline = microtime( true ) + self::STEP_TIME_BUDGET_SECONDS;
			// Phase 2 (incremental backups): opened lazily, only once a
			// file actually needs archiving this batch -- a batch made
			// entirely of unchanged (skipped) files must never produce
			// an empty zip part on disk.
			$zip = null;
			// security audit finding HIGH-ZIP-1: ZipArchive::close() failing rolls
			// back the ENTIRE batch since the last successful close --
			// not just the file that triggered it -- so a file is only
			// ever recorded into $cp['resolved_files'] / removed from
			// $cp['files_remaining'] (the two the checkpoint persists)
			// once the close() that actually makes it durable on disk
			// has already succeeded. $pending holds this batch's
			// not-yet-durable archive entries; $remaining is the
			// in-progress working copy of files_remaining, only ever
			// copied into $cp (and therefore persisted) at the same
			// moment $pending is flushed. Anywhere this function
			// returns self::fail() without reaching one of those
			// flush points, nothing beyond the last successful commit
			// was ever persisted -- safe by construction, not by
			// care taken at each individual return site.
			$pending = array();
			$remaining = $cp['files_remaining'];
			do {
				$relative = array_key_first( $remaining );
				$abs = $remaining[ $relative ];

				// Checked against the ORIGINAL file (before any
				// wp-config.php redaction substitution below, whose
				// freshly-written copy would always have a fresh mtime
				// and therefore always look "changed").
				$orig_mtime = @filemtime( $abs );
				$orig_size = @filesize( $abs );
				$prior = $prior_index[ $relative ] ?? null;

				if (
					'incremental' === $chain['mode'] && null !== $prior
					&& false !== $orig_mtime && false !== $orig_size
					&& (int) $prior['mtime'] === (int) $orig_mtime
					&& (int) $prior['size'] === (int) $orig_size
				) {
					// Unchanged since the chain parent: carry its pointer
					// forward verbatim. Nothing is archived or uploaded
					// for this file this run -- no zip/close() of its own
					// is involved, but it still must NOT be committed
					// immediately (security re-verification, NEW-HIGH-1): an
					// earlier file in this SAME batch may already be
					// sitting in $pending, archived into the zip but not
					// yet durably closed -- committing $remaining here
					// would persist a files_remaining that's already
					// missing that in-flight file while it's still absent
					// from resolved_files too, the exact "neither list
					// has it" gap a crash in that window would expose.
					// Queued into $pending like any archived entry;
					// flushed only at the same two commit points below.
					unset( $remaining[ $relative ] );
					$pending[] = array(
						'path'             => $relative,
						'size'             => (int) $prior['size'],
						'mtime'            => (int) $prior['mtime'],
						'sha256'           => $prior['sha256'],
						'source_backup_id' => $prior['source_backup_id'],
						'source_part'      => $prior['source_part'],
					);
					continue;
				}

				$archive_abs = $abs;
				if ( 'wp-config.php' === $relative ) {
					$redacted = self::redacted_wp_config_copy( $abs, $tmp, $job['destination'] ?? 'nextcloud' );
					if ( is_wp_error( $redacted ) ) {
						if ( null !== $zip ) {
							Archive_Writer::close_part( $zip );
						}
						return self::fail( $job_id, 'export_files', $redacted->get_error_message() );
					}
					$archive_abs = $redacted;
				}

				$size = @filesize( $archive_abs );
				$size = false === $size ? 0 : $size;

				if ( null === $zip ) {
					$part_path = self::part_path( $tmp, $cp['current_part_index'] );
					$zip = Archive_Writer::open_part( $part_path );
					if ( is_wp_error( $zip ) ) {
						return self::fail( $job_id, 'export_files', 'Archiving failed: ' . $zip->get_error_message() );
					}
				}

				if ( $cp['current_part_bytes'] > 0 && ( $cp['current_part_bytes'] + $size ) > self::PART_SIZE_BYTES ) {
					$closed = Archive_Writer::close_part( $zip );
					if ( is_wp_error( $closed ) ) {
						return self::fail( $job_id, 'export_files', $closed->get_error_message() );
					}
					// The part is now confirmed durably closed -- safe to
					// commit everything pending for it, still leaving the
					// current (not-yet-archived) file correctly listed in
					// $remaining since it hasn't been decided yet.
					$cp['parts_meta'][] = self::finalize_files_part( $cp, $tmp );
					foreach ( $pending as $entry ) {
						$cp['resolved_files'][] = $entry;
					}
					$pending = array();
					$cp['current_part_index']++;
					$cp['current_part_bytes'] = 0;
					$cp['current_part_file_count'] = 0;
					$cp['files_remaining'] = $remaining;
					Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );

					$part_path = self::part_path( $tmp, $cp['current_part_index'] );
					$zip = Archive_Writer::open_part( $part_path );
					if ( is_wp_error( $zip ) ) {
						return self::fail( $job_id, 'export_files', 'Archiving failed: ' . $zip->get_error_message() );
					}
				}

				$result = Archive_Writer::add_file_to( $zip, $archive_abs, $relative );
				if ( is_wp_error( $result ) ) {
					Archive_Writer::close_part( $zip );
					return self::fail( $job_id, 'export_files', 'Archiving failed: ' . $result->get_error_message() );
				}

				// Only now -- the file is physically inside the still-open
				// zip -- is it safe to drop from $remaining. If close()
				// later fails for this batch, this call returns before
				// ever copying $remaining into $cp, so the persisted
				// files_remaining still lists it for a clean retry.
				unset( $remaining[ $relative ] );
				$pending[] = array(
					'path'             => $relative,
					'size'             => $size,
					'mtime'            => false !== $orig_mtime ? (int) $orig_mtime : 0,
					'sha256'           => hash_file( 'sha256', $archive_abs ),
					'source_backup_id' => $job['backup_id'],
					'source_part'      => sprintf( 'files.part-%04d.zip', $cp['current_part_index'] ),
				);
				$cp['current_part_bytes'] += $size;
				$cp['current_part_file_count']++;
			} while ( ! empty( $remaining ) && microtime( true ) < $deadline );

			if ( null !== $zip ) {
				$closed = Archive_Writer::close_part( $zip );
				if ( is_wp_error( $closed ) ) {
					return self::fail( $job_id, 'export_files', $closed->get_error_message() );
				}
			}
			// Close (if any) succeeded -- now safe to commit this batch's
			// pending archive entries and the resulting files_remaining.
			foreach ( $pending as $entry ) {
				$cp['resolved_files'][] = $entry;
			}
			$cp['files_remaining'] = $remaining;
			Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
			return Job_Store::get( $job_id );
		}

		// security audit finding MEDIUM-ZIP-2: current_part_bytes alone reads 0 even
		// when the current part genuinely holds content, if every file
		// added to it since the last rollover happened to be zero bytes
		// (plausible -- empty .log/.gitkeep/index-guard-style sentinel
		// files are real) -- current_part_file_count catches that case,
		// which byte-count alone could not, closing a gap that would
		// otherwise leave a real uploaded-nothing part referenced by
		// resolved_files but never finalized, uploaded, or restorable.
		if ( self::should_finalize_trailing_part( $cp['current_part_bytes'], $cp['current_part_file_count'] ?? 0, count( $cp['parts_meta'] ) ) ) {
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
	 * security audit finding H-02: ADR 0002's constant-override escape hatch puts the
	 * Nextcloud app password directly in wp-config.php — which this same
	 * backup then archives verbatim, circularly exposing the credential
	 * that protects the backup inside the backup itself. Strips any line
	 * defining the app-password or CA-bundle constants before the file
	 * reaches the archive, regardless of whether the override is
	 * currently active (so a backup taken while it's off can't later be
	 * "upgraded" into leaking a value that gets set afterward).
	 */
	/** @return string|\WP_Error Path to the redacted copy, or a WP_Error if the credential survived redaction. */
	private static function redacted_wp_config_copy( string $original_abs, string $tmp, string $destination = 'nextcloud' ) {
		$redacted = Settings::redact_wp_config_contents( file_get_contents( $original_abs ) );

		// security audit finding LOW-R1 (fail-closed hardening, post H-02 re-review):
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

		// Phase 3, security audit finding MEDIUM-DL-1: proven live -- a seeded DB_PASSWORD
		// survived into the archived wp-config.php unchanged before this
		// fix. A downloadable bundle leaves the admin's controlled
		// storage entirely (lands in a Downloads folder, plus sits on
		// disk here for up to 24h) in a way the Nextcloud path doesn't,
		// so only the download destination gets this extra strip -- see
		// Settings::redact_wp_config_db_credentials()'s docblock for why
		// AUTH_KEY/salts are deliberately left untouched. Same
		// fail-closed re-check pattern as LOW-R1 above.
		if ( 'download' === $destination ) {
			$redacted = Settings::redact_wp_config_db_credentials( $redacted );
			if ( defined( 'DB_PASSWORD' ) && '' !== DB_PASSWORD && false !== strpos( $redacted, DB_PASSWORD ) ) {
				return new \WP_Error( 'wpguard_nc_wp_config_db_redaction_failed', 'wp-config.php redaction did not remove the real database credential; refusing to archive it.' );
			}
		}

		$redacted_path = $tmp . 'wp-config.redacted.php';
		file_put_contents( $redacted_path, $redacted );
		return $redacted_path;
	}

	/**
	 * Pure. security audit finding MEDIUM-ZIP-2: byte count alone can read 0 even when
	 * the current (not-yet-finalized) part genuinely holds content, if
	 * every file added to it since the last rollover happened to be
	 * zero bytes (real: empty .log/.gitkeep/sentinel-style files) --
	 * without also checking file count, such a part would never get
	 * finalized, uploaded, or become restorable, while still being
	 * referenced by a resolved_files entry pointing at it.
	 */
	public static function should_finalize_trailing_part( int $current_part_bytes, int $current_part_file_count, int $parts_meta_count ): bool {
		return $current_part_bytes > 0 || $current_part_file_count > 0 || 0 === $parts_meta_count;
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

	/**
	 * security audit finding HIGH-ZIP-1 (defense in depth, called once before any
	 * upload begins): confirms every file this job itself claims to
	 * have archived (source_backup_id === this job's own backup_id) is
	 * actually present inside the local zip part it claims to live in,
	 * AND at the size the manifest claims. step_upload_parts()'s sha256
	 * read-back later only ever proves the ZIP FILE round-tripped
	 * intact -- that was never able to catch a part that round-trips
	 * perfectly but is missing entries a batch silently dropped (the
	 * exact HIGH-ZIP-1 failure mode, now also fixed at the source in
	 * step_export_files() -- this is the backstop that verifies the
	 * manifest's per-file claims against reality rather than trusting
	 * them). security re-verification, LOW-VERIFY-1: presence alone can't
	 * catch a source file that changed in the narrow window between
	 * this job's own hash_file() call and ZipArchive::close() actually
	 * reading it -- comparing the archived entry's uncompressed size
	 * against the manifest's claimed size closes most of that window
	 * cheaply, without re-hashing (which would re-introduce the exact
	 * I/O cost this feature exists to avoid). Not a full re-hash: a
	 * same-size content change in that same narrow window would still
	 * only surface at actual restore time via the per-file sha256, same
	 * as any other undetected ancestor-part corruption (see T19).
	 *
	 * @return true|\WP_Error
	 */
	private static function verify_own_parts_contain_claimed_files( array $resolved_files, string $backup_id, string $tmp ) {
		$by_part = array();
		foreach ( $resolved_files as $entry ) {
			if ( $entry['source_backup_id'] === $backup_id ) {
				$by_part[ $entry['source_part'] ][] = $entry;
			}
		}
		foreach ( $by_part as $part_name => $entries ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $tmp . $part_name ) ) {
				return new \WP_Error( 'wpguard_nc_part_unreadable', "Archive part {$part_name} could not be opened for pre-upload verification." );
			}
			foreach ( $entries as $entry ) {
				$stat = $zip->statName( $entry['path'] );
				if ( false === $stat ) {
					$zip->close();
					return new \WP_Error( 'wpguard_nc_file_missing_from_part', "File \"{$entry['path']}\" is missing from its own archive part ({$part_name}) -- the backup cannot be trusted as complete." );
				}
				if ( (int) $stat['size'] !== (int) $entry['size'] ) {
					$zip->close();
					return new \WP_Error( 'wpguard_nc_file_size_mismatch', "File \"{$entry['path']}\" inside {$part_name} is {$stat['size']} bytes but the manifest claims {$entry['size']} -- the backup cannot be trusted as complete." );
				}
			}
			$zip->close();
		}
		return true;
	}

	private static function step_package_manifest( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];
		$tmp = self::tmp_dir_for( $job['backup_id'] );

		$verify = self::verify_own_parts_contain_claimed_files( $cp['resolved_files'] ?? array(), $job['backup_id'], $tmp );
		if ( is_wp_error( $verify ) ) {
			return self::fail( $job_id, 'package_manifest', $verify->get_error_message() );
		}

		$chain = $cp['chain'] ?? array( 'mode' => 'full', 'base_backup_id' => null, 'parent_backup_id' => null );
		$manifest = Manifest::new_skeleton( $job['backup_id'], Settings::get_site_id(), $chain );
		$manifest['tables_metadata'] = $cp['tables_metadata'];
		$manifest['scope']['database']['tables'] = array_column( $cp['tables_metadata'], 'name' );
		$manifest['warnings'] = array_merge( $cp['db_warnings'] ?? array(), $cp['file_warnings'] ?? array() );

		Manifest::add_component( $manifest, 'database.sql.gz', 'database', $cp['database']['bytes'], $cp['database']['sha256'], 1, 1 );
		$parts_total = count( $cp['parts_meta'] );
		foreach ( $cp['parts_meta'] as $i => $part ) {
			$name = sprintf( 'files.part-%04d.zip', $part['index'] );
			Manifest::add_component( $manifest, $name, 'files', $part['bytes'], $part['sha256'], $part['index'], $parts_total );
		}
		// Phase 2 (incremental backups): the complete resolved file
		// index -- see class-backup-chain.php's docblock for why this is
		// never a diff, even on an incremental run.
		foreach ( $cp['resolved_files'] ?? array() as $f ) {
			Manifest::add_file_entry( $manifest, $f['path'], $f['size'], $f['mtime'], $f['sha256'], $f['source_backup_id'], $f['source_part'] );
		}

		$manifest_json = Manifest::to_json( $manifest );
		$manifest_path = $tmp . 'manifest.json';
		file_put_contents( $manifest_path, $manifest_json );

		$cp['manifest_local_path'] = $manifest_path;
		$cp['manifest_sha256'] = hash( 'sha256', $manifest_json );

		// Phase 3 (downloadable backups): no Nextcloud destination -- skip
		// straight to bundling everything into one local zip instead of
		// queuing a remote upload.
		if ( 'download' === ( $job['destination'] ?? 'nextcloud' ) ) {
			self::log( $job_id, 'package_manifest', 'info', 'Manifest built; packaging into a downloadable bundle.' );
			Job_Store::update( $job_id, array( 'step' => 'bundle_local', 'checkpoint' => $cp ) );
			return Job_Store::get( $job_id );
		}

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

		// hash_file()/filesize() instead of file_get_contents() -- this
		// fallback is rarely hit (sha256/bytes normally already come
		// from the manifest) but bounding its memory the same way as
		// the chunked-upload path below (ADR 0010) rather than buffering
		// a whole part just to hash it is a straightforward improvement,
		// not a behavior change.
		$local_sha256 = $item['sha256'] ?? hash_file( 'sha256', $item['local'] );
		$local_size = $item['bytes'] ?? filesize( $item['local'] );

		// ADR 0010 (WebDAV chunking v2): upload_id is DETERMINISTIC, not
		// a fresh token per call -- derived from this job+part so that
		// if this exact advance() call is killed outright mid-upload
		// (process/host death, not a caught WP_Error -- the checkpoint
		// below is never reached, so parts_to_upload still has this
		// same item on the next advance() of this still-'running' job),
		// the retry reuses the same server-side upload session and
		// resumes from whatever chunks the server says already landed,
		// instead of starting over or orphaning the earlier attempt.
		$upload_id = substr( hash( 'sha256', Settings::get_site_id() . '|' . $job_id . '|' . $item['remote'] ), 0, 32 );
		$put = $client->upload_part( $item['local'], $remote_rel, $upload_id );
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

	/**
	 * Phase 3 (downloadable backups): replaces upload_parts + finalize for
	 * a 'download'-destination job. Packages manifest.json, COMPLETE.json,
	 * database.sql.gz, and every files.part-*.zip into one flat zip --
	 * deliberately the exact same layout ajax_upload_restore_bundle()
	 * already accepts, so a downloaded bundle can be fed straight back
	 * through the existing "Upload a backup bundle" restore path with no
	 * new restore code. Every entry stored via CM_STORE (already-
	 * compressed gz/zip content -- DEFLATE-ing it again would spend CPU
	 * for ~0 size benefit, same reasoning as Archive_Writer::STORE_ONLY_EXTENSIONS).
	 * "Verified" here means the same thing it means for a Nextcloud
	 * upload: every entry re-read back off disk and checked against its
	 * expected size and sha256 before the job is allowed to reach that
	 * status, not just trusted because close() returned true.
	 */
	private static function step_bundle_local( array $job ): array {
		$job_id = $job['job_id'];
		$cp = $job['checkpoint'];
		$tmp = self::tmp_dir_for( $job['backup_id'] );

		$manifest_json = file_get_contents( $cp['manifest_local_path'] );
		$complete_json = Manifest::complete_json( $job['backup_id'], $manifest_json );
		$complete_path = $tmp . 'COMPLETE.json';
		file_put_contents( $complete_path, $complete_json );

		$entries = array();
		$entries[] = array( 'local' => $cp['manifest_local_path'], 'name' => 'manifest.json', 'sha256' => $cp['manifest_sha256'], 'bytes' => strlen( $manifest_json ) );
		$entries[] = array( 'local' => $complete_path, 'name' => 'COMPLETE.json', 'sha256' => hash( 'sha256', $complete_json ), 'bytes' => strlen( $complete_json ) );
		$entries[] = array( 'local' => $cp['database']['local_path'], 'name' => 'database.sql.gz', 'sha256' => $cp['database']['sha256'], 'bytes' => $cp['database']['bytes'] );
		foreach ( $cp['parts_meta'] as $part ) {
			$entries[] = array(
				'local'  => $part['local_path'],
				'name'   => sprintf( 'files.part-%04d.zip', $part['index'] ),
				'sha256' => $part['sha256'],
				'bytes'  => $part['bytes'],
			);
		}

		// Phase 3, security audit finding MEDIUM-DL-4: a fresh token (and therefore a
		// fresh directory) was allocated on every call into this step,
		// with no check for one already in progress -- a process killed
		// mid-assembly (e.g. max_execution_time) orphaned its partial
		// directory forever while the next poll silently started a new
		// one, unbounded for as long as the admin's tab kept retrying.
		// Reusing the token from checkpoint, wiping and rebuilding its
		// directory rather than allocating a new one, bounds this to
		// exactly one directory per job; a hard attempt cap stops an
		// unrecoverable failure (e.g. disk genuinely full) from retrying
		// forever instead of ever reaching 'failed'.
		if ( ! empty( $cp['bundle_local_token'] ) ) {
			$token = $cp['bundle_local_token'];
			$dir = self::download_dir_for_token( $token );
			self::cleanup_tmp( $dir );
		} else {
			$token = self::new_download_token();
			$dir = self::download_dir_for_token( $token );
			$cp['bundle_local_token'] = $token;
		}
		$cp['bundle_local_attempts'] = (int) ( $cp['bundle_local_attempts'] ?? 0 ) + 1;
		Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
		if ( $cp['bundle_local_attempts'] > 3 ) {
			self::cleanup_tmp( $dir );
			return self::fail( $job_id, 'bundle_local', 'Could not assemble the downloadable bundle after 3 attempts; giving up. Check available disk space under wp-content/.' );
		}
		wp_mkdir_p( $dir );

		$needed_bytes = array_sum( array_column( $entries, 'bytes' ) );
		$free_bytes = @disk_free_space( $dir );
		if ( false !== $free_bytes && $free_bytes < $needed_bytes ) {
			self::cleanup_tmp( $dir );
			return self::fail( $job_id, 'bundle_local', sprintf( 'Not enough free disk space to assemble the downloadable bundle (need at least %s, have %s).', size_format( $needed_bytes ), size_format( $free_bytes ) ) );
		}

		$bundle_path = $dir . 'wpguard-' . $job['backup_id'] . '.zip';

		// A failure anywhere below this point means the bundle isn't
		// trustworthy -- the whole token directory is disposable until
		// verification actually completes, so every failure path removes
		// it rather than leaving a partial/corrupt bundle sitting under
		// download_base_dir() where the download endpoint could otherwise
		// be pointed at it by a stale checkpoint read.
		$zip = Archive_Writer::open_part( $bundle_path );
		if ( is_wp_error( $zip ) ) {
			self::cleanup_tmp( $dir );
			return self::fail( $job_id, 'bundle_local', 'Could not create downloadable bundle: ' . $zip->get_error_message() );
		}
		foreach ( $entries as $e ) {
			$added = Archive_Writer::add_file_to( $zip, $e['local'], $e['name'] );
			if ( is_wp_error( $added ) ) {
				Archive_Writer::close_part( $zip );
				self::cleanup_tmp( $dir );
				return self::fail( $job_id, 'bundle_local', 'Could not build downloadable bundle: ' . $added->get_error_message() );
			}
		}
		$closed = Archive_Writer::close_part( $zip );
		if ( is_wp_error( $closed ) ) {
			self::cleanup_tmp( $dir );
			return self::fail( $job_id, 'bundle_local', $closed->get_error_message() );
		}

		// Re-open independently (not the same handle that just wrote it)
		// and verify every entry is actually present, at the right size,
		// with the right content -- extracting to disk one entry at a
		// time rather than ZipArchive::getFromName() so this never buffers
		// a whole database dump or files part in PHP memory (ADR 0004).
		$verify = new \ZipArchive();
		if ( true !== $verify->open( $bundle_path, \ZipArchive::CHECKCONS ) ) {
			self::cleanup_tmp( $dir );
			return self::fail( $job_id, 'bundle_local', 'Downloadable bundle failed its consistency check after creation.' );
		}
		$verify_dir = $dir . 'verify-tmp/';
		wp_mkdir_p( $verify_dir );
		foreach ( $entries as $e ) {
			$stat = $verify->statName( $e['name'] );
			if ( false === $stat || (int) $stat['size'] !== (int) $e['bytes'] ) {
				$verify->close();
				self::cleanup_tmp( $dir );
				return self::fail( $job_id, 'bundle_local', "Downloadable bundle is missing or has a size mismatch for {$e['name']}." );
			}
			if ( ! $verify->extractTo( $verify_dir, $e['name'] ) ) {
				$verify->close();
				self::cleanup_tmp( $dir );
				return self::fail( $job_id, 'bundle_local', "Could not read back {$e['name']} from the downloadable bundle for verification." );
			}
			$extracted_path = $verify_dir . $e['name'];
			$hash = hash_file( 'sha256', $extracted_path );
			@unlink( $extracted_path );
			if ( $hash !== $e['sha256'] ) {
				$verify->close();
				self::cleanup_tmp( $dir );
				return self::fail( $job_id, 'bundle_local', "Downloadable bundle entry {$e['name']} does not match its expected checksum -- backup NOT verified." );
			}
		}
		$verify->close();
		self::cleanup_tmp( $verify_dir );

		$cp['download_token'] = $token;
		$cp['download_bundle_path'] = $bundle_path;
		$cp['download_bundle_bytes'] = filesize( $bundle_path );
		$cp['download_ready_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		unset( $cp['bundle_local_token'], $cp['bundle_local_attempts'] );

		// Everything of value now lives inside the verified bundle under
		// download_base_dir(); the loose originals in the per-job tmp dir
		// (keyed by backup_id, only 32 random bits -- not meant to persist)
		// serve no further purpose.
		self::cleanup_tmp( $tmp );
		self::log( $job_id, 'bundle_local', 'info', sprintf( 'Downloadable backup bundle ready (%s).', size_format( $cp['download_bundle_bytes'] ) ) );
		Job_Store::update( $job_id, array( 'status' => 'verified', 'step' => 'done', 'checkpoint' => $cp ) );
		// Deliberately NOT calling Backup_Chain::record_result() here --
		// this job's bytes were never uploaded anywhere persistent,
		// so recording it as the chain's new parent would let a future
		// Nextcloud-destined incremental backup believe a file is already
		// safely stored remotely (via this backup_id/source_part) when it
		// was only ever local, and has likely already been deleted by the
		// TTL sweep by the time that incremental tries to rely on it.
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
		// Phase 2 (incremental backups): only ever advances/resets local
		// chain state once a job is already verified -- a failed job
		// must never affect what the next job decides, same discipline
		// as retention pruning below only running post-verification.
		if ( isset( $cp['chain'] ) ) {
			Backup_Chain::record_result( $cp['chain'], $job['backup_id'] );
		}
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
	const RETENTION_ERROR_OPTION = 'wpguard_nc_retention_last_error';

	/**
	 * security audit finding LOW-RETAIN-1: Retention::prune()'s fail-closed abort (if
	 * any kept backup's manifest can't be read, nothing is deleted) is
	 * the correct behavior, but a per-job log warning alone means an
	 * admin who isn't reading every job's log line by line has no way
	 * to notice retention has quietly stopped working at all -- storage
	 * grows unboundedly with no visible symptom until it's a problem.
	 * This stores the last pruning error (empty string = none) so the
	 * admin settings page can surface it as a persistent notice, and
	 * clears it the moment pruning next succeeds.
	 */
	private static function maybe_prune_old_backups( string $job_id, Nextcloud_Client $client ): void {
		$keep = Settings::get_retention_count();
		if ( $keep <= 0 ) {
			return;
		}
		try {
			$parent_rel = $client->relative_path( Settings::get_site_id() . '/backups' );
			$result = Retention::prune( $client, $parent_rel, $keep );
			if ( is_wp_error( $result ) ) {
				$message = self::logger()->redact( $result->get_error_message() );
				self::log( $job_id, 'finalize', 'warning', 'Retention pruning did not fully complete: ' . $message );
				update_option( self::RETENTION_ERROR_OPTION, $message, false );
				return;
			}
			update_option( self::RETENTION_ERROR_OPTION, '', false );
			if ( ! empty( $result['deleted'] ) ) {
				self::log( $job_id, 'finalize', 'info', sprintf( 'Retention: removed %d old backup(s), kept %d.', count( $result['deleted'] ), $result['kept'] ) );
			}
		} catch ( \Throwable $e ) {
			$message = self::logger()->redact( $e->getMessage() );
			self::log( $job_id, 'finalize', 'warning', 'Retention pruning threw an unexpected error: ' . $message );
			update_option( self::RETENTION_ERROR_OPTION, $message, false );
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
