<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands. Drives the same Job_Orchestrator::advance() used by
 * the admin AJAX/cron path (ADR 0003) — synchronous and deterministic,
 * which is why the E2E test harness uses this instead of simulating
 * browser polling.
 *
 * Usage: wp wpguard-nc-backup run [--destination=nextcloud|download]
 *        wp wpguard-nc-backup restore <backup_id> [--confirm-url-mismatch]
 *        wp wpguard-nc-backup restore_local <path-to-zip> [--confirm-url-mismatch]
 *        wp wpguard-nc-backup status
 */
class CLI_Command {

	public function run( $args, $assoc_args ) {
		$destination = $assoc_args['destination'] ?? 'nextcloud';
		if ( ! in_array( $destination, array( 'nextcloud', 'download' ), true ) ) {
			\WP_CLI::error( "Invalid --destination: '{$destination}'. Must be 'nextcloud' or 'download'." );
			return;
		}
		// Same credential requirement as the admin AJAX path
		// (ajax_start_backup()) -- a download-destination backup needs
		// no Nextcloud destination configured at all.
		if ( 'nextcloud' === $destination && ! Settings::has_credentials() ) {
			\WP_CLI::error( 'Nextcloud URL, username, and app password must be configured first (wp option update wpguard_nc_settings, or the admin UI), or pass --destination=download.' );
			return;
		}
		$job_id = Job_Orchestrator::create_job( $destination );
		if ( '' === $job_id ) {
			\WP_CLI::error( 'A backup job is already running.' );
			return;
		}
		\WP_CLI::log( "Started job {$job_id}" );

		$max_steps = 100000;
		for ( $i = 0; $i < $max_steps; $i++ ) {
			$job = Job_Orchestrator::advance( $job_id );
			\WP_CLI::log( sprintf( '[%s] step=%s status=%s', gmdate( 'H:i:s' ), $job['step'] ?? '?', $job['status'] ?? '?' ) );
			if ( in_array( $job['status'] ?? '', array( 'verified', 'failed', 'not_found' ), true ) ) {
				break;
			}
		}

		if ( 'verified' === ( $job['status'] ?? '' ) ) {
			if ( 'download' === $destination ) {
				$bundle_path = $job['checkpoint']['download_bundle_path'] ?? '';
				\WP_CLI::success( "Backup {$job['backup_id']} verified. Local bundle: {$bundle_path}" );
			} else {
				\WP_CLI::success( "Backup {$job['backup_id']} verified." );
			}
		} else {
			\WP_CLI::error( 'Backup failed: ' . ( $job['error'] ?? 'unknown error' ) );
		}
	}

	public function status( $args, $assoc_args ) {
		$job = Job_Store::get_latest();
		if ( null === $job ) {
			\WP_CLI::log( 'No jobs have been run yet.' );
			return;
		}
		\WP_CLI::log( wp_json_encode( array(
			'job_id'    => $job['job_id'],
			'backup_id' => $job['backup_id'],
			'status'    => $job['status'],
			'step'      => $job['step'],
			'error'     => $job['error'],
		), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Phase 2 (one-click restore). Drives Restore_Orchestrator::advance()
	 * the same synchronous way run() drives backups -- what the E2E
	 * restore test harness uses.
	 *
	 * Usage: wp wpguard-nc-backup restore <backup_id> [--confirm-url-mismatch]
	 */
	public function restore( $args, $assoc_args ) {
		if ( ! Settings::has_credentials() ) {
			\WP_CLI::error( 'Nextcloud URL, username, and app password must be configured first.' );
			return;
		}
		$backup_id = $args[0] ?? '';
		if ( '' === $backup_id ) {
			\WP_CLI::error( 'Usage: wp wpguard-nc-backup restore <backup_id> [--confirm-url-mismatch]' );
			return;
		}
		$confirm = isset( $assoc_args['confirm-url-mismatch'] );
		$job_id = Restore_Orchestrator::create_restore_job( array( 'type' => 'nextcloud' ), $backup_id, $confirm );
		if ( '' === $job_id ) {
			\WP_CLI::error( 'A backup or restore job is already running.' );
			return;
		}
		\WP_CLI::log( "Started restore job {$job_id} (restoring from backup {$backup_id})" );

		$max_steps = 1000000;
		for ( $i = 0; $i < $max_steps; $i++ ) {
			$job = Restore_Orchestrator::advance( $job_id );
			\WP_CLI::log( sprintf( '[%s] step=%s status=%s', gmdate( 'H:i:s' ), $job['step'] ?? '?', $job['status'] ?? '?' ) );
			if ( in_array( $job['status'] ?? '', array( 'verified', 'failed', 'not_found' ), true ) ) {
				break;
			}
		}

		if ( 'verified' === ( $job['status'] ?? '' ) ) {
			\WP_CLI::success( "Restore from {$backup_id} complete." );
		} else {
			\WP_CLI::error( 'Restore failed: ' . ( $job['error'] ?? 'unknown error' ) );
		}
	}

	/**
	 * Restore from a local backup bundle zip -- e.g. one produced by
	 * `run --destination=download` -- with zero Nextcloud dependency.
	 * The WP-CLI equivalent of the admin UI's "Upload a backup bundle"
	 * path (ajax_upload_restore_bundle()/ajax_start_restore()), reusing
	 * the exact same extraction and 'upload'-source restore mechanism
	 * rather than a separate one.
	 *
	 * Usage: wp wpguard-nc-backup restore_local <path-to-zip> [--confirm-url-mismatch]
	 */
	public function restore_local( $args, $assoc_args ) {
		$zip_path = $args[0] ?? '';
		if ( '' === $zip_path || ! is_readable( $zip_path ) ) {
			\WP_CLI::error( 'Usage: wp wpguard-nc-backup restore_local <path-to-zip> [--confirm-url-mismatch]' );
			return;
		}

		// security audit finding LOW-CLI-HOLD-1: has_running_job() must be
		// checked -- and the holding dir cleaned up on every early exit
		// below -- BEFORE extraction, not after. The admin AJAX path
		// (ajax_upload_restore_bundle()) already checks this first and
		// never writes anything to disk on lock contention; this command
		// originally extracted the full bundle (database.sql.gz, every
		// files.part-*.zip) first and only checked once create_restore_job()
		// ran, orphaning that extracted bundle on disk indefinitely on
		// WP_CLI::error()'s exit() whenever a job was already running.
		if ( Job_Store::has_running_job() ) {
			\WP_CLI::error( 'A backup or restore job is already running.' );
			return;
		}

		$token = Restore_Orchestrator::new_upload_token();
		$holding = Restore_Orchestrator::upload_dir_for_token( $token );
		wp_mkdir_p( $holding );

		$result = Restore_Orchestrator::extract_bundle_zip( $zip_path, $holding );
		if ( is_wp_error( $result ) ) {
			Restore_Orchestrator::delete_upload_dir( $holding );
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		$manifest = json_decode( (string) file_get_contents( $holding . 'manifest.json' ), true );
		$backup_id = is_array( $manifest ) ? (string) ( $manifest['backup_id'] ?? '' ) : '';
		if ( '' === $backup_id ) {
			Restore_Orchestrator::delete_upload_dir( $holding );
			\WP_CLI::error( "Bundle's manifest.json has no backup_id." );
			return;
		}

		$confirm = isset( $assoc_args['confirm-url-mismatch'] );
		$source = array( 'type' => 'upload', 'upload_dir' => $holding );
		$job_id = Restore_Orchestrator::create_restore_job( $source, $backup_id, $confirm );
		if ( '' === $job_id ) {
			// A '' return means create_restore_job() never reached
			// Job_Store::create() (lock contention, or a has_running_job()
			// race against the explicit check above) -- no checkpoint
			// exists for fail() to clean this up through, so it's on us.
			Restore_Orchestrator::delete_upload_dir( $holding );
			\WP_CLI::error( 'A backup or restore job is already running.' );
			return;
		}
		\WP_CLI::log( "Started restore job {$job_id} (restoring from local bundle {$zip_path})" );

		$max_steps = 1000000;
		for ( $i = 0; $i < $max_steps; $i++ ) {
			$job = Restore_Orchestrator::advance( $job_id );
			\WP_CLI::log( sprintf( '[%s] step=%s status=%s', gmdate( 'H:i:s' ), $job['step'] ?? '?', $job['status'] ?? '?' ) );
			if ( in_array( $job['status'] ?? '', array( 'verified', 'failed', 'not_found' ), true ) ) {
				break;
			}
		}

		if ( 'verified' === ( $job['status'] ?? '' ) ) {
			\WP_CLI::success( "Restore from local bundle {$backup_id} complete." );
		} else {
			\WP_CLI::error( 'Restore failed: ' . ( $job['error'] ?? 'unknown error' ) );
		}
	}
}
