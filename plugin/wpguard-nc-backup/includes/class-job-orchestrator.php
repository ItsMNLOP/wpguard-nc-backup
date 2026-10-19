<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job state machine. Each advance() call does one bounded unit of work
 * and persists a checkpoint before returning (JOB-03) — safe to call
 * repeatedly from AJAX, WP-Cron, or WP-CLI (ADR 0003). Never buffers an
 * entire backup in memory; the largest single in-memory object at any
 * point is one archive part (bounded by PART_SIZE_BYTES, ADR 0004).
 *
 * Steps: init -> export_db -> export_files -> package_manifest ->
 * upload_parts -> finalize -> (verified | failed).
 */
class Job_Orchestrator {

	const PART_SIZE_BYTES = 64 * 1024 * 1024;

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

	private static function logger(): Logger {
		return new Logger( array( Settings::get_app_password() ) );
	}

	private static function log( string $job_id, string $step, string $severity, string $message, array $context = array() ): void {
		Job_Store::append_log( $job_id, self::logger()->entry( $job_id, $step, $severity, $message, $context ) );
	}

	private static function fail( string $job_id, string $step, string $message ): array {
		$safe = self::logger()->redact( $message );
		self::log( $job_id, $step, 'error', $safe );
		Job_Store::update( $job_id, array( 'status' => 'failed', 'error' => $safe ) );
		return Job_Store::get( $job_id );
	}

	/**
	 * Advance the job by exactly one bounded unit of work.
	 *
	 * @return array Updated job row (see Job_Store::get()).
	 */
	public static function advance( string $job_id ): array {
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
			$relative = array_key_first( $cp['files_remaining'] );
			$abs = $cp['files_remaining'][ $relative ];
			unset( $cp['files_remaining'][ $relative ] );

			$size = @filesize( $abs );
			$size = false === $size ? 0 : $size;

			if ( $cp['current_part_bytes'] > 0 && ( $cp['current_part_bytes'] + $size ) > self::PART_SIZE_BYTES ) {
				$cp['parts_meta'][] = self::finalize_files_part( $cp, $tmp );
				$cp['current_part_index']++;
				$cp['current_part_bytes'] = 0;
			}

			$part_path = self::part_path( $tmp, $cp['current_part_index'] );
			$result = Archive_Writer::add_file( $part_path, $abs, $relative );
			if ( is_wp_error( $result ) ) {
				return self::fail( $job_id, 'export_files', 'Archiving failed: ' . $result->get_error_message() );
			}
			$cp['current_part_bytes'] += $size;
			Job_Store::update( $job_id, array( 'checkpoint' => $cp ) );
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

		self::cleanup_tmp( $tmp );
		self::log( $job_id, 'finalize', 'info', 'Backup verified and complete.' );
		Job_Store::update( $job_id, array( 'status' => 'verified', 'step' => 'done' ) );
		return Job_Store::get( $job_id );
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
