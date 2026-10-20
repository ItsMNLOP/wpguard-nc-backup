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
		);
		$result = Settings::save( $input );
		if ( $result['ok'] ) {
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

	public function ajax_advance_job(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => 'Missing job_id.' ) );
			return;
		}
		$job = Job_Orchestrator::advance( $job_id );
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
		$job = Job_Orchestrator::cancel( $job_id );
		wp_send_json_success( $this->public_job_view( $job ) );
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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WPGuard Nextcloud Backup', 'wpguard-nc-backup' ); ?></h1>

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
		</div>
		<script>
		(function(){
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var currentJobId = null;

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
					ca_bundle_path: document.getElementById('wpguard_nc_ca').value
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

			function renderJob(job) {
				currentJobId = job.job_id || currentJobId;
				document.getElementById('wpguard_nc_job_status').textContent = 'Status: ' + job.status + ' (step: ' + job.step + ')' + (job.error ? ' — ' + job.error : '');
				document.getElementById('wpguard_nc_job_log').textContent = (job.log || []).map(function(e){ return e.time_utc + ' [' + e.severity + '] ' + e.message; }).join('\n');
			}

			function pollJob(jobId) {
				post('wpguard_nc_advance_job', { job_id: jobId }).then(function(res){
					var job = res.data;
					renderJob(job);
					if (job.status !== 'verified' && job.status !== 'failed') {
						setTimeout(function(){ pollJob(jobId); }, 500);
					}
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
			// Nextcloud or the DB directly.
			post('wpguard_nc_job_status', {}).then(function(res){
				var job = res.data;
				if (job.status && job.status !== 'none') {
					renderJob(job);
					if (job.status !== 'verified' && job.status !== 'failed') {
						pollJob(job.job_id);
					}
				}
			});
		})();
		</script>
		<?php
	}
}
