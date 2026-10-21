<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page + AJAX action handlers. Every handler checks capability
 * and nonce before doing anything else (threat-model T1/T2) — this is
 * the one place in the plugin that talks to $_POST / current_user_can().
 */
class Admin {

	const NONCE_ACTION = 'wpguard_nc_admin';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_wpguard_nc_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_wpguard_nc_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wpguard_nc_start_backup', array( $this, 'ajax_start_backup' ) );
		add_action( 'wp_ajax_wpguard_nc_advance_job', array( $this, 'ajax_advance_job' ) );
		add_action( 'wp_ajax_wpguard_nc_job_status', array( $this, 'ajax_job_status' ) );
		add_action( 'wp_ajax_wpguard_nc_cancel_job', array( $this, 'ajax_cancel_job' ) );
		add_action( 'wp_ajax_wpguard_nc_list_restorable_backups', array( $this, 'ajax_list_restorable_backups' ) );
		add_action( 'wp_ajax_wpguard_nc_upload_restore_bundle', array( $this, 'ajax_upload_restore_bundle' ) );
		add_action( 'wp_ajax_wpguard_nc_start_restore', array( $this, 'ajax_start_restore' ) );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'WPGuard Nextcloud Backup', 'wpguard-nc-backup' ),
			__( 'Nextcloud Backup', 'wpguard-nc-backup' ),
			'manage_options',
			'wpguard-nc-backup',
			array( $this, 'render_page' )
		);
	}

	private function require_capability_and_nonce(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'wpguard-nc-backup' ) ), 403 );
			return false;
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed; please reload the page.', 'wpguard-nc-backup' ) ), 403 );
			return false;
		}
		return true;
	}

	public function ajax_save_settings(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$input = array(
			'nextcloud_url'  => isset( $_POST['nextcloud_url'] ) ? wp_unslash( $_POST['nextcloud_url'] ) : '',
			'nextcloud_user' => isset( $_POST['nextcloud_user'] ) ? wp_unslash( $_POST['nextcloud_user'] ) : '',
			'app_password'   => isset( $_POST['app_password'] ) ? wp_unslash( $_POST['app_password'] ) : '',
			'remote_folder'  => isset( $_POST['remote_folder'] ) ? wp_unslash( $_POST['remote_folder'] ) : '',
			'ca_bundle_path' => isset( $_POST['ca_bundle_path'] ) ? wp_unslash( $_POST['ca_bundle_path'] ) : '',
			'schedule'       => isset( $_POST['schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) : 'disabled',
			'retention_count' => isset( $_POST['retention_count'] ) ? (int) $_POST['retention_count'] : 0,
			'incremental_enabled' => isset( $_POST['incremental_enabled'] ) ? ! empty( $_POST['incremental_enabled'] ) : false,
		);
		$result = Settings::save( $input );
		if ( $result['ok'] ) {
			// Phase 2 (scheduling): apply a schedule change immediately
			// rather than waiting for the next page load to reconcile it.
			sync_scheduled_backup();
			wp_send_json_success( array( 'message' => __( 'Settings saved.', 'wpguard-nc-backup' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Please fix the errors below.', 'wpguard-nc-backup' ), 'errors' => $result['errors'] ) );
		}
	}

	public function ajax_test_connection(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		if ( ! Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => __( 'Enter and save the Nextcloud URL, username, and app password first.', 'wpguard-nc-backup' ) ) );
			return;
		}
		$logger = new Logger( array( Settings::get_app_password() ) );
		$client = Nextcloud_Client::from_settings( $logger );
		$result = $client->test_connection();
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'], 'reason' => $result['reason'] ) );
		}
	}

	public function ajax_start_backup(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		if ( ! Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => __( 'Configure and save Nextcloud settings before starting a backup.', 'wpguard-nc-backup' ) ) );
			return;
		}
		$job_id = Job_Orchestrator::create_job();
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'A backup is already running.', 'wpguard-nc-backup' ) ) );
			return;
		}
		wp_schedule_single_event( time() + 5, 'wpguard_nc_cron_advance', array( $job_id ) );
		wp_send_json_success( array( 'job_id' => $job_id ) );
	}

	/** Phase 2 (one-click restore): a job_id may belong to either orchestrator; dispatched by checkpoint kind. */
	private function is_restore_job( ?array $job ): bool {
		return null !== $job && 'restore' === ( $job['checkpoint']['kind'] ?? 'backup' );
	}

	public function ajax_advance_job(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => 'Missing job_id.' ) );
			return;
		}
		$job = $this->is_restore_job( Job_Store::get( $job_id ) ) ? Restore_Orchestrator::advance( $job_id ) : Job_Orchestrator::advance( $job_id );
		wp_send_json_success( $this->public_job_view( $job ) );
	}

	public function ajax_job_status(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job = '' !== $job_id ? Job_Store::get( $job_id ) : Job_Store::get_latest();
		wp_send_json_success( $job ? $this->public_job_view( $job ) : array( 'status' => 'none' ) );
	}

	/** JOB-05 manual recovery: let an admin mark a stuck job failed instead of needing direct DB access. */
	public function ajax_cancel_job(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( '' === $job_id ) {
			$latest = Job_Store::get_latest();
			if ( null === $latest ) {
				wp_send_json_error( array( 'message' => __( 'No backup job found to cancel.', 'wpguard-nc-backup' ) ) );
				return;
			}
			$job_id = $latest['job_id'];
		}
		$job = $this->is_restore_job( Job_Store::get( $job_id ) ) ? Restore_Orchestrator::cancel( $job_id ) : Job_Orchestrator::cancel( $job_id );

		// security audit finding LOW-C1: a lock-contended cancel (advance() is actively
		// running right now) changes nothing, and must not be reported as
		// success -- the admin would otherwise see "success" from the
		// fetch call while the job silently stayed running.
		if ( 'lock_contended' === ( $job['cancel_result'] ?? '' ) ) {
			wp_send_json_error( array_merge(
				$this->public_job_view( $job ),
				array( 'message' => __( 'Job is actively being advanced right now (AJAX or WP-Cron) -- nothing was changed. Wait a moment and try Cancel again.', 'wpguard-nc-backup' ) )
			) );
			return;
		}
		wp_send_json_success( $this->public_job_view( $job ) );
	}

	/** Phase 2 (one-click restore): populates the "restore from Nextcloud" dropdown. */
	public function ajax_list_restorable_backups(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		if ( ! Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => __( 'Configure and save Nextcloud settings first.', 'wpguard-nc-backup' ) ) );
			return;
		}
		$client = Nextcloud_Client::from_settings( new Logger( array( Settings::get_app_password() ) ) );
		$parent_rel = $client->relative_path( Settings::get_site_id() . '/backups' );
		$list = Retention::list_verified( $client, $parent_rel );
		if ( is_wp_error( $list ) ) {
			wp_send_json_error( array( 'message' => $list->get_error_message() ) );
			return;
		}
		// Newest first for the dropdown; list_verified() returns oldest first.
		wp_send_json_success( array( 'backups' => array_reverse( $list ) ) );
	}

	/**
	 * Phase 2 (one-click restore): accepts a single uploaded .zip bundle
	 * (manifest.json + COMPLETE.json + database.sql.gz + files.part-*.zip
	 * at its root -- a full/self-contained backup only; an incremental
	 * chain needs the Nextcloud source to reach its ancestor backups).
	 * Extracted once, into a freshly wiped, guarded holding directory --
	 * has_running_job() already prevents two restores (and therefore two
	 * uploads) from being prepared concurrently.
	 */
	public function ajax_upload_restore_bundle(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		if ( Job_Store::has_running_job() ) {
			wp_send_json_error( array( 'message' => __( 'A backup or restore is already running.', 'wpguard-nc-backup' ) ) );
			return;
		}
		if ( empty( $_FILES['bundle'] ) || UPLOAD_ERR_OK !== ( $_FILES['bundle']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			wp_send_json_error( array( 'message' => __( 'No file was uploaded, or the upload failed.', 'wpguard-nc-backup' ) ) );
			return;
		}
		$tmp_upload = $_FILES['bundle']['tmp_name'];
		if ( ! is_uploaded_file( $tmp_upload ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload.', 'wpguard-nc-backup' ) ) );
			return;
		}

		// security audit finding MEDIUM-UPLOAD-1: a fresh, random-token subdirectory
		// per upload (never the fixed, guessable path this used to be),
		// deleted by Restore_Orchestrator once its content has been
		// staged into the job's own tmp dir (or on an earlier failure)
		// -- see Restore_Orchestrator::new_upload_token()'s docblock.
		$token = Restore_Orchestrator::new_upload_token();
		$holding = Restore_Orchestrator::upload_dir_for_token( $token );
		wp_mkdir_p( $holding );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp_upload ) ) {
			wp_send_json_error( array( 'message' => __( 'Uploaded file is not a valid .zip archive.', 'wpguard-nc-backup' ) ) );
			return;
		}

		// A bundle is expected flat (manifest.json, database.sql.gz,
		// files.part-NNNN.zip directly at the zip root). In practice,
		// the most common way an admin actually gets this file is
		// downloading the backup_id-named folder straight from
		// Nextcloud's own web UI, which naturally zips it WITH that
		// folder name wrapping every entry -- found live, not
		// theoretical. If every real (non-directory) entry shares the
		// exact same single leading path segment, treat that segment
		// as that wrapper and strip it before validating/extracting;
		// anything else (no common wrapper, or a genuinely mixed/deeper
		// layout) still refuses exactly as before.
		$entry_names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false === $name || '/' === substr( $name, -1 ) ) {
				continue;
			}
			$entry_names[] = $name;
		}
		$wrapper = null;
		if ( ! empty( $entry_names ) ) {
			$all_nested = true;
			$first_segment = null;
			foreach ( $entry_names as $name ) {
				$slash = strpos( $name, '/' );
				if ( false === $slash ) {
					$all_nested = false;
					break;
				}
				$segment = substr( $name, 0, $slash );
				if ( null === $first_segment ) {
					$first_segment = $segment;
				} elseif ( $segment !== $first_segment ) {
					$all_nested = false;
					break;
				}
			}
			if ( $all_nested && null !== $first_segment && Archive_Writer::is_path_safe( $first_segment . '/x' ) ) {
				$wrapper = $first_segment . '/';
			}
		}

		foreach ( $entry_names as $name ) {
			$relative = $name;
			if ( null !== $wrapper ) {
				if ( 0 !== strpos( $name, $wrapper ) ) {
					// Can't happen given how $wrapper was derived above, but
					// never extract anything outside the detected wrapper.
					continue;
				}
				$relative = substr( $name, strlen( $wrapper ) );
			}
			if ( '' === $relative || ! Archive_Writer::is_path_safe( $relative ) || false !== strpos( $relative, '/' ) ) {
				// Flat bundle contents only -- refusing anything with a
				// directory separator left (after stripping at most one
				// common wrapper above) is simpler and safer than trying
				// to validate an arbitrary nested layout.
				$zip->close();
				wp_send_json_error( array( 'message' => sprintf( __( 'Refusing to extract unexpected nested path in uploaded bundle: %s', 'wpguard-nc-backup' ), $name ) ) );
				return;
			}
			$content = $zip->getFromName( $name );
			if ( false === $content ) {
				continue;
			}
			file_put_contents( $holding . $relative, $content );
		}
		$zip->close();

		// security audit finding LOW-HTACCESS-1: a legitimate backup bundle contains
		// its own .htaccess (Inventory::enumerate() always captures it),
		// which the loop above just wrote over the access guard with the
		// SITE's own rewrite rules, not a deny-all. Rewritten last,
		// unconditionally, regardless of what the bundle itself contained.
		file_put_contents( $holding . 'index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents( $holding . '.htaccess', "Require all denied\n" );

		wp_send_json_success( array(
			'message'      => __( 'Bundle uploaded and extracted; ready to verify.', 'wpguard-nc-backup' ),
			'upload_token' => $token,
		) );
	}

	/** Phase 2 (one-click restore): starts the restore job itself; everything before this was read-only prep. */
	public function ajax_start_restore(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$source_type = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$confirm = ! empty( $_POST['confirm_url_mismatch'] );

		if ( 'upload' === $source_type ) {
			$upload_token = isset( $_POST['upload_token'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_token'] ) ) : '';
			$holding = Restore_Orchestrator::upload_dir_for_token( $upload_token );
			if ( '' === $holding || ! file_exists( $holding . 'manifest.json' ) ) {
				wp_send_json_error( array( 'message' => __( 'Upload a backup bundle first.', 'wpguard-nc-backup' ) ) );
				return;
			}
			$manifest = json_decode( (string) file_get_contents( $holding . 'manifest.json' ), true );
			$backup_id = is_array( $manifest ) ? (string) ( $manifest['backup_id'] ?? '' ) : '';
			if ( '' === $backup_id ) {
				wp_send_json_error( array( 'message' => __( 'Uploaded bundle\'s manifest.json has no backup_id.', 'wpguard-nc-backup' ) ) );
				return;
			}
			$source = array( 'type' => 'upload', 'upload_dir' => $holding );
		} else {
			$backup_id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
			if ( '' === $backup_id ) {
				wp_send_json_error( array( 'message' => __( 'Choose a backup to restore.', 'wpguard-nc-backup' ) ) );
				return;
			}
			$source = array( 'type' => 'nextcloud' );
		}

		$job_id = Restore_Orchestrator::create_restore_job( $source, $backup_id, $confirm );
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'A backup or restore is already running.', 'wpguard-nc-backup' ) ) );
			return;
		}
		// security audit finding HIGH-AJAX-1: backups get a WP-Cron fallback so a
		// closed browser tab doesn't strand them; restore had none at
		// all until now, the one place a stranded job matters most.
		wp_schedule_single_event( time() + 5, 'wpguard_nc_cron_advance', array( $job_id ) );
		wp_send_json_success( array( 'job_id' => $job_id ) );
	}

	/** Strips anything that shouldn't reach the browser even redacted-in-principle (defense in depth). */
	private function public_job_view( array $job ): array {
		return array(
			'job_id'     => $job['job_id'] ?? '',
			'backup_id'  => $job['backup_id'] ?? '',
			'status'     => $job['status'] ?? '',
			'step'       => $job['step'] ?? '',
			'error'      => $job['error'] ?? '',
			'log'        => array_slice( $job['log'] ?? array(), -10 ),
			'kind'       => 'restore' === ( $job['checkpoint']['kind'] ?? 'backup' ) ? 'restore' : 'backup',
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::get_all();
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		$locked_pw = Settings::is_app_password_locked();
		$locked_ca = Settings::is_ca_bundle_locked();
		$latest_verified = Job_Store::get_latest_verified();
		// security audit finding LOW-RETAIN-1: a chain-aware retention prune that
		// keeps failing (e.g. a kept backup's manifest.json went
		// missing) fails closed -- correctly deletes nothing -- but
		// that's invisible unless someone reads job logs line by line.
		// Surfaced here instead; cleared automatically the next time
		// pruning succeeds (Job_Orchestrator::maybe_prune_old_backups()).
		$retention_error = get_option( Job_Orchestrator::RETENTION_ERROR_OPTION, '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WPGuard Nextcloud Backup', 'wpguard-nc-backup' ); ?></h1>

			<?php if ( $retention_error ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Retention pruning is not completing.', 'wpguard-nc-backup' ); ?></strong>
					<?php esc_html_e( 'Old backups are NOT being deleted (nothing was deleted either, by design) until this is resolved:', 'wpguard-nc-backup' ); ?>
					<?php echo esc_html( $retention_error ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $latest_verified ) : ?>
				<p><strong><?php esc_html_e( 'Last verified backup:', 'wpguard-nc-backup' ); ?></strong>
				<?php echo esc_html( $latest_verified['backup_id'] . ' (' . $latest_verified['updated_at'] . ' UTC)' ); ?></p>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No verified backup yet.', 'wpguard-nc-backup' ); ?></em></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connection', 'wpguard-nc-backup' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="wpguard_nc_url"><?php esc_html_e( 'Nextcloud URL (https only)', 'wpguard-nc-backup' ); ?></label></th>
					<td><input type="url" id="wpguard_nc_url" value="<?php echo esc_attr( $settings['nextcloud_url'] ); ?>" class="regular-text" placeholder="https://your-nextcloud.example.com"></td>
				</tr>
				<tr>
					<th><label for="wpguard_nc_user"><?php esc_html_e( 'Username (dedicated, non-admin account recommended)', 'wpguard-nc-backup' ); ?></label></th>
					<td><input type="text" id="wpguard_nc_user" value="<?php echo esc_attr( $settings['nextcloud_user'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="wpguard_nc_pw"><?php esc_html_e( 'App password', 'wpguard-nc-backup' ); ?></label></th>
					<td>
						<input type="password" id="wpguard_nc_pw" value="" class="regular-text" autocomplete="new-password" <?php echo $locked_pw ? 'disabled' : ''; ?> placeholder="<?php echo esc_attr( $locked_pw ? __( 'Set via WPGUARD_NC_APP_PASSWORD constant', 'wpguard-nc-backup' ) : __( 'leave blank to keep current value', 'wpguard-nc-backup' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Never echoed back. Generate a dedicated app password in Nextcloud under Personal Settings > Security — do not use your main account password.', 'wpguard-nc-backup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpguard_nc_folder"><?php esc_html_e( 'Destination folder', 'wpguard-nc-backup' ); ?></label></th>
					<td><input type="text" id="wpguard_nc_folder" value="<?php echo esc_attr( $settings['remote_folder'] ); ?>" class="regular-text" placeholder="wpguard-backups"></td>
				</tr>
				<tr>
					<th><label for="wpguard_nc_ca"><?php esc_html_e( 'Custom CA bundle path (advanced, optional)', 'wpguard-nc-backup' ); ?></label></th>
					<td>
						<input type="text" id="wpguard_nc_ca" value="<?php echo esc_attr( $settings['ca_bundle_path'] ); ?>" class="regular-text" <?php echo $locked_ca ? 'disabled' : ''; ?> placeholder="/absolute/path/to/private-ca.pem">
						<p class="description"><?php esc_html_e( 'Only for a private CA you operate (e.g. self-hosted Nextcloud). Leave blank for a normal publicly trusted certificate — this never weakens TLS verification, it only adds a trusted root.', 'wpguard-nc-backup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpguard_nc_schedule"><?php esc_html_e( 'Automatic backup schedule', 'wpguard-nc-backup' ); ?></label></th>
					<td>
						<select id="wpguard_nc_schedule">
							<option value="disabled" <?php selected( $settings['schedule'], 'disabled' ); ?>><?php esc_html_e( 'Disabled — manual only', 'wpguard-nc-backup' ); ?></option>
							<option value="daily" <?php selected( $settings['schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'wpguard-nc-backup' ); ?></option>
							<option value="weekly" <?php selected( $settings['schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wpguard-nc-backup' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Relies on WP-Cron, same as the manual backup fallback — only fires on site traffic (see Operations docs). Skips a run rather than overlapping if a backup is still genuinely in progress.', 'wpguard-nc-backup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpguard_nc_retention"><?php esc_html_e( 'Keep last N backups', 'wpguard-nc-backup' ); ?></label></th>
					<td>
						<input type="number" id="wpguard_nc_retention" min="0" step="1" value="<?php echo esc_attr( (string) $settings['retention_count'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( '0 = keep every backup forever (default). A positive number deletes the oldest backup folder(s) on Nextcloud right after each new backup verifies successfully, once more than that many exist. This permanently deletes data — only increase from 0 once you are comfortable with the number chosen.', 'wpguard-nc-backup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpguard_nc_incremental"><?php esc_html_e( 'Incremental file backups', 'wpguard-nc-backup' ); ?></label></th>
					<td>
						<label><input type="checkbox" id="wpguard_nc_incremental" <?php checked( $settings['incremental_enabled'] ); ?>> <?php esc_html_e( 'Skip re-uploading files unchanged since the last backup', 'wpguard-nc-backup' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off by default. When on, a file whose size and modified time match the previous backup is referenced instead of re-archived and re-uploaded — the usual speed-up on a site with many files. A full backup is still taken periodically (every 7 backups or 7 days, whichever comes first) regardless of this setting. Every individual backup remains independently verified before being marked complete; see Operations docs for how restore works with this on.', 'wpguard-nc-backup' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="wpguard_nc_save"><?php esc_html_e( 'Save Settings', 'wpguard-nc-backup' ); ?></button>
				<button type="button" class="button" id="wpguard_nc_test"><?php esc_html_e( 'Test Connection', 'wpguard-nc-backup' ); ?></button>
			</p>
			<div id="wpguard_nc_settings_result" role="status"></div>

			<h2><?php esc_html_e( 'Manual backup', 'wpguard-nc-backup' ); ?></h2>
			<p>
				<button type="button" class="button button-primary" id="wpguard_nc_backup_now"><?php esc_html_e( 'Back Up Now', 'wpguard-nc-backup' ); ?></button>
				<button type="button" class="button" id="wpguard_nc_cancel_job"><?php esc_html_e( 'Cancel / Reset Stuck Job', 'wpguard-nc-backup' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'If a backup never completes (e.g. the site had no other traffic to trigger the WP-Cron fallback), use Cancel to mark it failed so a new backup can start. Already-uploaded parts of a cancelled job are left on Nextcloud untouched.', 'wpguard-nc-backup' ); ?></p>
			<div id="wpguard_nc_job_status" role="status"></div>
			<pre id="wpguard_nc_job_log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:8px;"></pre>

			<h2><?php esc_html_e( 'Restore', 'wpguard-nc-backup' ); ?></h2>
			<p class="description"><strong><?php esc_html_e( 'This overwrites this site\'s current content and database with a chosen backup, in place.', 'wpguard-nc-backup' ); ?></strong>
			<?php esc_html_e( 'Nothing is ever permanently deleted by it — anything it replaces is renamed aside (database tables under a "wpgbak…" prefix, files into a timestamped rollback folder) for manual recovery, never dropped outright. Still: back up anything you cannot afford to lose before using this on a site that matters.', 'wpguard-nc-backup' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Source', 'wpguard-nc-backup' ); ?></th>
					<td>
						<label><input type="radio" name="wpguard_nc_restore_source" value="nextcloud" checked> <?php esc_html_e( 'Choose from Nextcloud', 'wpguard-nc-backup' ); ?></label>
						<select id="wpguard_nc_restore_backup_list" style="margin-left:8px;"><option value=""><?php esc_html_e( '— loading —', 'wpguard-nc-backup' ); ?></option></select>
						<br><br>
						<label><input type="radio" name="wpguard_nc_restore_source" value="upload"> <?php esc_html_e( 'Upload a backup bundle', 'wpguard-nc-backup' ); ?></label>
						<input type="file" id="wpguard_nc_restore_upload_file" accept=".zip" style="margin-left:8px;">
						<button type="button" class="button" id="wpguard_nc_restore_upload_btn"><?php esc_html_e( 'Upload', 'wpguard-nc-backup' ); ?></button>
						<span id="wpguard_nc_restore_upload_result"></span>
						<p class="description"><?php esc_html_e( 'A single .zip containing manifest.json, COMPLETE.json, database.sql.gz, and files.part-*.zip at its root. Only supports a self-contained (full) backup — an incremental backup\'s ancestor parts can only be reached via the Nextcloud source.', 'wpguard-nc-backup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpguard_nc_restore_confirm_text"><?php esc_html_e( 'Confirm', 'wpguard-nc-backup' ); ?></label></th>
					<td>
						<input type="text" id="wpguard_nc_restore_confirm_text" class="regular-text" placeholder="RESTORE">
						<p class="description"><?php esc_html_e( 'Type RESTORE to enable the button below.', 'wpguard-nc-backup' ); ?></p>
						<label><input type="checkbox" id="wpguard_nc_restore_confirm_url"> <?php esc_html_e( 'Migrate this backup to this site: this backup is from a different site, and I want every URL in it rewritten to this site\'s own address', 'wpguard-nc-backup' ); ?></label>
						<p class="description"><?php esc_html_e( 'Checking this does not just skip the mismatch warning -- it rewrites every occurrence of the backup\'s own domain (in post content, widget settings, and everywhere else WordPress stores a URL) to this site\'s domain, before anything is restored live. Leave unchecked to restore this backup onto the exact site it came from.', 'wpguard-nc-backup' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="wpguard_nc_restore_start" disabled><?php esc_html_e( 'Restore Now', 'wpguard-nc-backup' ); ?></button>
			</p>
			<div id="wpguard_nc_restore_status" role="status"></div>
			<pre id="wpguard_nc_restore_log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:8px;"></pre>
		</div>
		<script>
		(function(){
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var currentJobId = null;
			var currentRestoreJobId = null;
			var currentUploadToken = null;

			function post(action, extra) {
				var body = new URLSearchParams(Object.assign({ action: action, nonce: nonce }, extra || {}));
				return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function(r){ return r.json(); });
			}

			document.getElementById('wpguard_nc_save').addEventListener('click', function(){
				post('wpguard_nc_save_settings', {
					nextcloud_url: document.getElementById('wpguard_nc_url').value,
					nextcloud_user: document.getElementById('wpguard_nc_user').value,
					app_password: document.getElementById('wpguard_nc_pw').value,
					remote_folder: document.getElementById('wpguard_nc_folder').value,
					ca_bundle_path: document.getElementById('wpguard_nc_ca').value,
					schedule: document.getElementById('wpguard_nc_schedule').value,
					retention_count: document.getElementById('wpguard_nc_retention').value,
					incremental_enabled: document.getElementById('wpguard_nc_incremental').checked ? '1' : ''
				}).then(function(res){
					document.getElementById('wpguard_nc_settings_result').textContent = res.data.message;
				});
			});

			document.getElementById('wpguard_nc_test').addEventListener('click', function(){
				document.getElementById('wpguard_nc_settings_result').textContent = 'Testing...';
				post('wpguard_nc_test_connection', {}).then(function(res){
					document.getElementById('wpguard_nc_settings_result').textContent = res.data.message;
				});
			});

			function renderJobInto(statusId, logId, job) {
				document.getElementById(statusId).textContent = 'Status: ' + job.status + ' (step: ' + job.step + ')' + (job.error ? ' — ' + job.error : '');
				document.getElementById(logId).textContent = (job.log || []).map(function(e){ return e.time_utc + ' [' + e.severity + '] ' + e.message; }).join('\n');
			}

			function renderJob(job) {
				currentJobId = job.job_id || currentJobId;
				renderJobInto('wpguard_nc_job_status', 'wpguard_nc_job_log', job);
			}

			function pollJob(jobId) {
				post('wpguard_nc_advance_job', { job_id: jobId }).then(function(res){
					var job = res.data;
					renderJob(job);
					if (job.status !== 'verified' && job.status !== 'failed') {
						setTimeout(function(){ pollJob(jobId); }, 500);
					}
				}).catch(function(){
					// A single failed poll (network blip, a transient
					// 5xx) must not silently stop polling forever -- the
					// WP-Cron fallback keeps the job itself moving
					// regardless; this just needs to keep checking.
					document.getElementById('wpguard_nc_job_status').textContent = 'Network error polling for status -- the backup may still be running via WP-Cron. Retrying...';
					setTimeout(function(){ pollJob(jobId); }, 2000);
				});
			}

			function renderRestoreJob(job) {
				currentRestoreJobId = job.job_id || currentRestoreJobId;
				renderJobInto('wpguard_nc_restore_status', 'wpguard_nc_restore_log', job);
			}

			function pollRestoreJob(jobId) {
				post('wpguard_nc_advance_job', { job_id: jobId }).then(function(res){
					var job = res.data;
					renderRestoreJob(job);
					if (job.status !== 'verified' && job.status !== 'failed') {
						setTimeout(function(){ pollRestoreJob(jobId); }, 500);
					}
				}).catch(function(){
					// security audit finding HIGH-AJAX-1: a single failed poll (a
					// transient error response, a timed-out request --
					// plausible mid-restore, when apply_files can
					// legitimately take a while) must not silently stop
					// polling forever. The WP-Cron fallback keeps the
					// restore itself moving regardless; this just needs
					// to keep checking rather than leave the admin
					// looking at a frozen status box with no indication
					// anything is still happening.
					document.getElementById('wpguard_nc_restore_status').textContent = 'Network error polling for status -- the restore may still be running via WP-Cron. Retrying...';
					setTimeout(function(){ pollRestoreJob(jobId); }, 2000);
				});
			}

			document.getElementById('wpguard_nc_backup_now').addEventListener('click', function(){
				post('wpguard_nc_start_backup', {}).then(function(res){
					if (res.success) {
						pollJob(res.data.job_id);
					} else {
						document.getElementById('wpguard_nc_job_status').textContent = res.data.message;
					}
				});
			});

			document.getElementById('wpguard_nc_cancel_job').addEventListener('click', function(){
				if (!window.confirm('Cancel the current/stuck backup job? This marks it failed so a new backup can start. Already-uploaded parts on Nextcloud are left untouched. This cannot be undone.')) {
					return;
				}
				post('wpguard_nc_cancel_job', currentJobId ? { job_id: currentJobId } : {}).then(function(res){
					if (res.success) {
						renderJob(res.data);
					} else {
						document.getElementById('wpguard_nc_job_status').textContent = res.data.message;
					}
				});
			});

			// Resume reporting an in-progress job on page load -- previously
			// the status box stayed blank after navigating away and back,
			// even though the job itself (advanced by the WP-Cron fallback)
			// was likely still running; this looked identical to a crashed
			// backup with no way to tell the difference without checking
			// Nextcloud or the DB directly. The latest job may be a
			// backup OR a restore (Job_Store::get_latest() doesn't
			// distinguish) -- routed to the matching UI section by kind.
			post('wpguard_nc_job_status', {}).then(function(res){
				var job = res.data;
				if (job.status && job.status !== 'none') {
					if (job.kind === 'restore') {
						renderRestoreJob(job);
						if (job.status !== 'verified' && job.status !== 'failed') {
							pollRestoreJob(job.job_id);
						}
					} else {
						renderJob(job);
						if (job.status !== 'verified' && job.status !== 'failed') {
							pollJob(job.job_id);
						}
					}
				}
			});

			// --- Restore ---

			function selectedRestoreSource() {
				var checked = document.querySelector('input[name="wpguard_nc_restore_source"]:checked');
				return checked ? checked.value : 'nextcloud';
			}

			function loadRestorableBackups() {
				var select = document.getElementById('wpguard_nc_restore_backup_list');
				post('wpguard_nc_list_restorable_backups', {}).then(function(res){
					select.innerHTML = '';
					if (!res.success) {
						var opt = document.createElement('option');
						opt.textContent = res.data.message || 'Could not list backups';
						select.appendChild(opt);
						return;
					}
					if (!res.data.backups.length) {
						var none = document.createElement('option');
						none.textContent = '— no backups found —';
						select.appendChild(none);
						return;
					}
					res.data.backups.forEach(function(id){
						var opt = document.createElement('option');
						opt.value = id;
						opt.textContent = id;
						select.appendChild(opt);
					});
				});
			}
			loadRestorableBackups();

			document.getElementById('wpguard_nc_restore_upload_btn').addEventListener('click', function(){
				var fileInput = document.getElementById('wpguard_nc_restore_upload_file');
				if (!fileInput.files.length) {
					document.getElementById('wpguard_nc_restore_upload_result').textContent = 'Choose a .zip file first.';
					return;
				}
				var body = new FormData();
				body.append('action', 'wpguard_nc_upload_restore_bundle');
				body.append('nonce', nonce);
				body.append('bundle', fileInput.files[0]);
				document.getElementById('wpguard_nc_restore_upload_result').textContent = 'Uploading...';
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function(r){ return r.json(); })
					.then(function(res){
						document.getElementById('wpguard_nc_restore_upload_result').textContent = res.data.message;
						if (res.success) {
							currentUploadToken = res.data.upload_token;
						}
					});
			});

			function updateRestoreButtonState() {
				var confirmed = document.getElementById('wpguard_nc_restore_confirm_text').value.trim() === 'RESTORE';
				document.getElementById('wpguard_nc_restore_start').disabled = !confirmed;
			}
			document.getElementById('wpguard_nc_restore_confirm_text').addEventListener('input', updateRestoreButtonState);

			document.getElementById('wpguard_nc_restore_start').addEventListener('click', function(){
				var source = selectedRestoreSource();
				var payload = {
					source: source,
					confirm_url_mismatch: document.getElementById('wpguard_nc_restore_confirm_url').checked ? '1' : ''
				};
				if (source === 'nextcloud') {
					var backupId = document.getElementById('wpguard_nc_restore_backup_list').value;
					if (!backupId) {
						document.getElementById('wpguard_nc_restore_status').textContent = 'Choose a backup first.';
						return;
					}
					payload.backup_id = backupId;
				} else {
					if (!currentUploadToken) {
						document.getElementById('wpguard_nc_restore_status').textContent = 'Upload a backup bundle first.';
						return;
					}
					payload.upload_token = currentUploadToken;
				}
				if (!window.confirm('This will overwrite this site\'s current content and database with the chosen backup, in place. Continue?')) {
					return;
				}
				post('wpguard_nc_start_restore', payload).then(function(res){
					if (res.success) {
						pollRestoreJob(res.data.job_id);
					} else {
						document.getElementById('wpguard_nc_restore_status').textContent = res.data.message;
					}
				});
			});
		})();
		</script>
		<?php
	}
}
