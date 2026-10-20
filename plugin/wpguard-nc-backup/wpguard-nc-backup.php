<?php
/**
 * Plugin Name:       WPGuard Nextcloud Backup
 * Plugin URI:        https://github.com/ItsMNLOP/wpguard-nc-backup
 * Description:       Creates recoverable backups of this WordPress site (files + database) and transfers them to a user-controlled Nextcloud server over authenticated HTTPS WebDAV. Manual backups only in this release; see docs/restore-manual.md for disaster recovery.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Holt (WPGuard project)
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpguard-nc-backup
 */

namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPGUARD_NC_VERSION', '1.0.0' );
define( 'WPGUARD_NC_FORMAT_VERSION', 1 );
define( 'WPGUARD_NC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPGUARD_NC_URL', plugin_dir_url( __FILE__ ) );
define( 'WPGUARD_NC_DB_SCHEMA_VERSION', 1 );

spl_autoload_register(
	function ( $class ) {
		$prefix = 'WPGuard\\NCBackup\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = WPGUARD_NC_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

const STALE_LOCK_CHECK_HOOK = 'wpguard_nc_stale_lock_check';
const SCHEDULED_BACKUP_HOOK = 'wpguard_nc_scheduled_backup';

function activate() {
	Job_Store::install_or_upgrade_schema();
	Settings::ensure_site_id();
	if ( ! wp_next_scheduled( STALE_LOCK_CHECK_HOOK ) ) {
		wp_schedule_event( time(), 'hourly', STALE_LOCK_CHECK_HOOK );
	}
	sync_scheduled_backup();
}

function deactivate() {
	wp_clear_scheduled_hook( STALE_LOCK_CHECK_HOOK );
	wp_clear_scheduled_hook( SCHEDULED_BACKUP_HOOK );
}

/**
 * Phase 2 (scheduling): WP has no built-in 'weekly' recurrence (only
 * hourly/twicedaily/daily) -- 'daily' is used as-is, 'weekly' is
 * registered here under the exact same name as the Settings::get_schedule()
 * value, so the setting's stored string can be passed straight to
 * wp_schedule_event() without a separate name-mapping table.
 */
add_filter( 'cron_schedules', __NAMESPACE__ . '\\register_weekly_schedule' );
function register_weekly_schedule( array $schedules ): array {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array(
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'wpguard-nc-backup' ),
		);
	}
	return $schedules;
}

/**
 * Reconciles the recurring WP-Cron event with the current setting.
 * Idempotent and cheap (wp_next_scheduled()/wp_get_scheduled_event() are
 * simple option/cron-array reads) -- safe to call on every boot(), and
 * also called right after a settings save so a schedule change takes
 * effect immediately rather than waiting for the next page load.
 */
function sync_scheduled_backup(): void {
	$schedule = Settings::get_schedule();
	$next = wp_next_scheduled( SCHEDULED_BACKUP_HOOK );

	if ( 'disabled' === $schedule ) {
		if ( $next ) {
			wp_clear_scheduled_hook( SCHEDULED_BACKUP_HOOK );
		}
		return;
	}

	if ( $next ) {
		$event = wp_get_scheduled_event( SCHEDULED_BACKUP_HOOK );
		if ( $event && $schedule === $event->schedule ) {
			return; // Already scheduled at the requested recurrence.
		}
		wp_clear_scheduled_hook( SCHEDULED_BACKUP_HOOK );
	}
	wp_schedule_event( time(), $schedule, SCHEDULED_BACKUP_HOOK );
}

/**
 * Starts a backup on the configured recurring schedule. Reuses
 * create_job() as-is -- including its JOB-05 stale-lock reap -- so a
 * scheduled run naturally clears out a genuinely abandoned previous job
 * before starting, and correctly skips (returns '') if a healthy backup
 * is still legitimately in progress rather than overlapping it.
 */
add_action( SCHEDULED_BACKUP_HOOK, __NAMESPACE__ . '\\run_scheduled_backup' );
function run_scheduled_backup(): void {
	if ( ! Settings::has_credentials() ) {
		return;
	}
	$job_id = Job_Orchestrator::create_job();
	if ( '' === $job_id ) {
		return;
	}
	wp_schedule_single_event( time() + 5, 'wpguard_nc_cron_advance', array( $job_id ) );
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );

function boot() {
	Job_Store::install_or_upgrade_schema();

	if ( is_admin() ) {
		( new Admin() )->register();
	}

	add_action( 'wpguard_nc_cron_advance', __NAMESPACE__ . '\\cron_advance' );

	// JOB-05 (Phase 2) safety net: Job_Orchestrator::create_job() already
	// reaps a stale job the moment anyone next tries to back up, but a
	// site that nobody visits for a while would otherwise leave a stuck
	// job sitting untouched until someone does. This recurring hourly
	// event (itself still WP-Cron pseudo-cron -- fires on the next
	// traffic after it's due, same limitation as the advance-chain
	// fallback) clears it proactively instead of waiting for that.
	add_action( STALE_LOCK_CHECK_HOOK, __NAMESPACE__ . '\\Job_Orchestrator::reap_stale_lock' );

	// Idempotent reconciliation on every load, in case the schedule
	// setting was ever changed through a path other than the admin
	// save handler (e.g. a future WP-CLI command, or direct DB access).
	sync_scheduled_backup();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'wpguard-nc-backup', new CLI_Command() );
	}
}

/**
 * WP-Cron fallback so a closed browser tab doesn't strand a job
 * (ADR 0003). Advances until terminal or until a time budget is spent,
 * then reschedules itself for jobs still in progress.
 */
function cron_advance( string $job_id ) {
	$deadline = time() + 20;
	while ( time() < $deadline ) {
		$job = Job_Orchestrator::advance( $job_id );
		if ( in_array( $job['status'] ?? '', array( 'verified', 'failed', 'not_found' ), true ) ) {
			return;
		}
	}
	wp_schedule_single_event( time() + 5, 'wpguard_nc_cron_advance', array( $job_id ) );
}
