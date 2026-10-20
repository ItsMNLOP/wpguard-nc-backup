<?php
/**
 * Plugin Name:       WPGuard Nextcloud Backup
 * Plugin URI:        https://github.com/itsmnlop/wpguard-nc-backup
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

function activate() {
	Job_Store::install_or_upgrade_schema();
	Settings::ensure_site_id();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );

function boot() {
	Job_Store::install_or_upgrade_schema();

	if ( is_admin() ) {
		( new Admin() )->register();
	}

	add_action( 'wpguard_nc_cron_advance', __NAMESPACE__ . '\\cron_advance' );

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
