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
 * Usage: wp wpguard-nc-backup run
 *        wp wpguard-nc-backup status
 */
class CLI_Command {

	public function run( $args, $assoc_args ) {
		if ( ! Settings::has_credentials() ) {
			\WP_CLI::error( 'Nextcloud URL, username, and app password must be configured first (wp option update wpguard_nc_settings, or the admin UI).' );
			return;
		}
		$job_id = Job_Orchestrator::create_job();
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
			\WP_CLI::success( "Backup {$job['backup_id']} verified." );
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
}
