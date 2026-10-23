<?php
/**
 * Plugin Name:       WPThistle Backup and Restore
 * Plugin URI:        https://github.com/ItsMNLOP/wpthistle-backup-restore
 * Update URI:        https://github.com/ItsMNLOP/wpthistle-backup-restore
 * Description:       Creates recoverable backups of this WordPress site (files + database) and transfers them to a user-controlled Nextcloud, ownCloud, or OpenCloud server over authenticated HTTPS WebDAV -- or packages them straight to your browser with zero cloud dependency. Manual or scheduled backups, retention pruning, and one-click in-place restore from either a configured server destination or an uploaded backup bundle.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            WPThistle project
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpthistle-backup-restore
 */

namespace WPThistle\BackupRestore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPTHISTLE_BACKUP_VERSION', '2.0.0' );
// Phase 2 (incremental backups): bumped 1 -> 2. Every manifest this
// plugin writes from here on -- full or incremental alike -- carries
// new backup_mode/base_backup_id/parent_backup_id/files fields.
// validate_format_version() rejects anything newer than what's running,
// so this forces any older restore tooling (written against the
// format_version 1 docs) to refuse touching a backup it doesn't fully
// understand, rather than silently restoring a partial file set.
// Restoring an OLDER format_version 1 backup is unaffected -- only
// forward-compatibility is the thing being deliberately broken here.
define( 'WPTHISTLE_BACKUP_FORMAT_VERSION', 2 );
define( 'WPTHISTLE_BACKUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPTHISTLE_BACKUP_URL', plugin_dir_url( __FILE__ ) );
// Phase 3 (downloadable backups): bumped 1 -> 2, adds the jobs table's
// own `destination` column (see class-job-store.php's create() docblock).
define( 'WPTHISTLE_BACKUP_DB_SCHEMA_VERSION', 2 );

spl_autoload_register(
	function ( $class ) {
		$prefix = 'WPThistle\\BackupRestore\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = WPTHISTLE_BACKUP_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

const STALE_LOCK_CHECK_HOOK = 'wpthistle_backup_stale_lock_check';
const SCHEDULED_BACKUP_HOOK = 'wpthistle_backup_scheduled_backup';

function activate() {
	migrate_from_legacy_wpguard();
	Job_Store::install_or_upgrade_schema();
	Settings::ensure_site_id();
	sync_stale_lock_check();
	sync_scheduled_backup();
}

/**
 * 2.0.0 rename (WPGuard Nextcloud Backup -> WPThistle Backup and
 * Restore): migrates data from the old plugin's storage into this
 * plugin's own. Non-destructive -- nothing under the old names is
 * deleted here; that only happens if the old plugin is itself
 * uninstalled via its own uninstall.php, which this never touches. Safe
 * cutover order: deactivate the old plugin (data stays on disk/in the
 * DB), activate this one (this function runs and migrates it), verify,
 * THEN delete the old plugin's files.
 *
 * A no-op on a brand-new install (nothing old to find) and on every
 * activation after the first (the new settings option is already
 * present, so this returns immediately).
 */
function migrate_from_legacy_wpguard(): void {
	if ( false !== get_option( Settings::OPTION_KEY, false ) ) {
		return;
	}
	$legacy_settings = get_option( 'wpguard_nc_settings', false );
	if ( false === $legacy_settings ) {
		return; // No legacy plugin data found -- genuinely a fresh install.
	}
	update_option( Settings::OPTION_KEY, $legacy_settings );

	$legacy_site_id = get_option( 'wpguard_nc_site_id', false );
	if ( false !== $legacy_site_id ) {
		update_option( Settings::SITE_ID_KEY, $legacy_site_id );
	}

	$legacy_schema_version = get_option( 'wpguard_nc_db_schema_version', false );
	if ( false !== $legacy_schema_version ) {
		// Job_Store::install_or_upgrade_schema() runs right after this
		// (still inside activate()) and reads this exact option to decide
		// whether dbDelta() needs to run -- carrying the old value over
		// means it correctly sees "already current" once the table itself
		// is renamed below, rather than creating a second, empty table.
		update_option( 'wpthistle_backup_db_schema_version', $legacy_schema_version );
	}

	$legacy_chain_state = get_option( 'wpguard_nc_chain_state', false );
	if ( false !== $legacy_chain_state ) {
		update_option( Backup_Chain::STATE_OPTION, $legacy_chain_state );
	}

	$legacy_retention_error = get_option( 'wpguard_nc_retention_last_error', false );
	if ( false !== $legacy_retention_error ) {
		update_option( Job_Orchestrator::RETENTION_ERROR_OPTION, $legacy_retention_error );
	}

	global $wpdb;
	$old_table = $wpdb->prefix . 'wpguard_nc_jobs';
	$new_table = Job_Store::table_name();
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- both names are
	// fixed constant-derived strings, never user input.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table ) {
		$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
	}

	// Orphaned entries under the old hook names would otherwise sit in
	// wp_options('cron') forever -- nothing listens for them post-rename.
	// sync_stale_lock_check()/sync_scheduled_backup(), called right after
	// this inside activate(), schedule fresh events under the new names
	// regardless of whether this clears anything.
	wp_clear_scheduled_hook( 'wpguard_nc_stale_lock_check' );
	wp_clear_scheduled_hook( 'wpguard_nc_scheduled_backup' );
	// wpguard_nc_cron_advance is scheduled per-job (wp_schedule_single_event,
	// not a single recurring hook), so there can be several pending events
	// to clear here, not just one.
	wp_clear_scheduled_hook( 'wpguard_nc_cron_advance' );
}

/**
 * the security audit delta audit, 2026-10-20 (OBS-1): register_activation_hook() only
 * fires on a fresh activation, not on a plugin update while already
 * active -- a site upgrading in place would silently lose the hourly
 * safety-net cron (the create_job()-triggered reap path still works
 * regardless) until it happened to be deactivated and reactivated.
 * Idempotent and cheap like sync_scheduled_backup(), so also called
 * from boot() on every load, not just activate().
 */
function sync_stale_lock_check(): void {
	if ( ! wp_next_scheduled( STALE_LOCK_CHECK_HOOK ) ) {
		wp_schedule_event( time(), 'hourly', STALE_LOCK_CHECK_HOOK );
	}
}

function deactivate() {
	wp_clear_scheduled_hook( STALE_LOCK_CHECK_HOOK );
	wp_clear_scheduled_hook( SCHEDULED_BACKUP_HOOK );
	// Phase 3 (downloadable backups): the hourly sweep this deactivates
	// is the only thing that would otherwise ever clean up a pending
	// local bundle holding a full DB dump -- purge unconditionally
	// rather than leave one sitting on disk for as long as the plugin
	// stays deactivated.
	Job_Orchestrator::sweep_download_bundles( true );
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
			'display'  => __( 'Once Weekly', 'wpthistle-backup-restore' ),
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
	wp_schedule_single_event( time() + 5, 'wpthistle_backup_cron_advance', array( $job_id ) );
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );

function boot() {
	Job_Store::install_or_upgrade_schema();

	if ( is_admin() ) {
		( new Admin() )->register();
	}

	// Phase 2 (self-hosted update feed, item 4): registered unconditionally,
	// not just is_admin() -- WP's own update-check cron
	// (`wp_update_plugins`, hooked to pre_set_site_transient_update_plugins)
	// runs outside admin context too. The GitHub API call itself only
	// happens when that transient is actually being rebuilt, and is
	// transient-cached for 12h beyond that -- this registration alone adds
	// no new outbound requests or cron events. No request input of any
	// kind (security audit, 2026-10-20) reaches either hooked method -- the
	// only data path in is the TLS-authenticated GitHub API response.
	( new Update_Checker( plugin_basename( __FILE__ ) ) )->register();

	add_action( 'wpthistle_backup_cron_advance', __NAMESPACE__ . '\\cron_advance' );

	// JOB-05 (Phase 2) safety net: Job_Orchestrator::create_job() already
	// reaps a stale job the moment anyone next tries to back up, but a
	// site that nobody visits for a while would otherwise leave a stuck
	// job sitting untouched until someone does. This recurring hourly
	// event (itself still WP-Cron pseudo-cron -- fires on the next
	// traffic after it's due, same limitation as the advance-chain
	// fallback) clears it proactively instead of waiting for that.
	add_action( STALE_LOCK_CHECK_HOOK, __NAMESPACE__ . '\\Job_Orchestrator::reap_stale_lock' );

	// Phase 3 (downloadable backups): same hourly safety net, TTL-only
	// (no $purge_all arg passed, so an unexpired pending bundle survives
	// until an admin downloads/deletes it or it genuinely goes stale).
	add_action( STALE_LOCK_CHECK_HOOK, __NAMESPACE__ . '\\Job_Orchestrator::sweep_download_bundles' );

	// Idempotent reconciliation on every load (OBS-1: covers an
	// in-place plugin update, which activate() alone would miss), in
	// case either schedule was ever changed or lost through a path
	// other than the admin save handler.
	sync_stale_lock_check();
	sync_scheduled_backup();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'wpthistle-backup-restore', new CLI_Command() );
	}
}

/**
 * WP-Cron fallback so a closed browser tab doesn't strand a job
 * (ADR 0003). Advances until terminal or until a time budget is spent,
 * then reschedules itself for jobs still in progress.
 *
 * security audit finding HIGH-AJAX-1 (one-click restore): this previously called
 * Job_Orchestrator::advance() unconditionally -- fine as long as only
 * backups ever scheduled this event, but restore had no cron fallback
 * registered at all, meaning a closed tab or a network blip during the
 * one phase where that matters most (restore_apply_db onward, the
 * point of no return) left the job stuck until the stale-lock reaper's
 * timeout, with no automated way to finish it. Dispatched by
 * checkpoint kind here so restore jobs now get the exact same
 * closed-tab protection backups always have.
 */
function cron_advance( string $job_id ) {
	$deadline = time() + 20;
	while ( time() < $deadline ) {
		$job_row = Job_Store::get( $job_id );
		$is_restore = null !== $job_row && 'restore' === ( $job_row['checkpoint']['kind'] ?? 'backup' );
		$job = $is_restore ? Restore_Orchestrator::advance( $job_id ) : Job_Orchestrator::advance( $job_id );
		if ( in_array( $job['status'] ?? '', array( 'verified', 'failed', 'not_found' ), true ) ) {
			return;
		}
	}
	wp_schedule_single_event( time() + 5, 'wpthistle_backup_cron_advance', array( $job_id ) );
}
