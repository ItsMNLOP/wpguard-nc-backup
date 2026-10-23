<?php
namespace WPThistle\BackupRestore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page + AJAX action handlers. Every handler checks capability
 * and nonce before doing anything else (threat-model T1/T2) — this is
 * the one place in the plugin that talks to $_POST / current_user_can().
 */
class Admin {

	const NONCE_ACTION = 'wpthistle_backup_admin';

	/**
	 * Phase 3, security audit finding LOW-DL-4: the download link previously reused
	 * NONCE_ACTION, the same nonce shared by every other action on this
	 * page (save settings, start backup, cancel job, start/upload
	 * restore) -- that value sits in a navigable GET URL (browser
	 * history, server access logs, Referer headers), a materially wider
	 * exposure surface than a POSTed nonce never leaves the request body.
	 * A nonce alone (without the session cookie) can't perform any
	 * privileged action, but least-privilege nonce scoping is cheap here.
	 */
	const NONCE_ACTION_DOWNLOAD = 'wpthistle_backup_download_bundle';

	/**
	 * Shared top-level menu slug for the whole WPThistle plugin family, not
	 * just this plugin. Every WPThistle plugin's admin.php should carry an
	 * identical copy of add_menu() below: whichever plugin's admin_menu
	 * callback runs first creates the 'WPThistle' parent (and immediately
	 * removes the duplicate landing submenu WP auto-adds under it, since
	 * no plugin owns a real page there), and every other WPThistle plugin
	 * just finds it already in $menu and adds its own submenu under it.
	 * No shared code loads across plugin boundaries -- this is the same
	 * "copy the idempotent snippet, let whichever loads first win"
	 * pattern WP libraries like Action Scheduler use, since separate
	 * plugin zips can't literally share a PHP class.
	 */
	const FAMILY_PARENT_SLUG = 'wpthistle';

	/**
	 * Phase 4 (family hub landing page): the contract any WPThistle-family
	 * plugin hooks into to appear on the shared parent page's status
	 * grid. A card is a plain associative array:
	 *   slug         string  unique across the family (this plugin's: 'backup-restore')
	 *   title        string  plain text, no markup
	 *   icon         string  a 'dashicons-*' class name
	 *   status_class string  one of 'ok' | 'warn' | 'error' | 'neutral'
	 *   status_label string  plain text, no markup (e.g. "Backup verified")
	 *   detail       string  plain text, no markup (e.g. "2 hours ago")
	 *   url          string  this plugin's own settings page URL
	 * Every field is treated as plain text and escaped on render
	 * (esc_html/esc_url/a dashicons-class allowlist) -- a sibling
	 * WPThistle plugin's bug is still foreign input to this one once it
	 * crosses the filter, same as any other untrusted string this
	 * codebase renders.
	 */
	const STATUS_CARDS_FILTER = 'wpthistle_family_status_cards';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( self::STATUS_CARDS_FILTER, array( $this, 'add_own_status_card' ) );
		add_action( 'wp_ajax_wpthistle_backup_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_wpthistle_backup_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wpthistle_backup_start_backup', array( $this, 'ajax_start_backup' ) );
		add_action( 'wp_ajax_wpthistle_backup_advance_job', array( $this, 'ajax_advance_job' ) );
		add_action( 'wp_ajax_wpthistle_backup_job_status', array( $this, 'ajax_job_status' ) );
		add_action( 'wp_ajax_wpthistle_backup_cancel_job', array( $this, 'ajax_cancel_job' ) );
		add_action( 'wp_ajax_wpthistle_backup_list_restorable_backups', array( $this, 'ajax_list_restorable_backups' ) );
		add_action( 'wp_ajax_wpthistle_backup_upload_restore_bundle', array( $this, 'ajax_upload_restore_bundle' ) );
		add_action( 'wp_ajax_wpthistle_backup_start_restore', array( $this, 'ajax_start_restore' ) );
		// Phase 3 (downloadable backups): a navigable GET, not AJAX --
		// fetch() would buffer the whole zip in browser memory before
		// handing it to the user, a real problem for a full-site backup.
		// The priv-only admin_post_ hook refuses an unauthenticated request
		// before this callback is even reached.
		add_action( 'admin_post_wpthistle_backup_download_bundle', array( $this, 'handle_download_bundle' ) );
		add_action( 'wp_ajax_wpthistle_backup_delete_download_bundle', array( $this, 'ajax_delete_download_bundle' ) );
	}

	public function add_menu(): void {
		global $menu;
		$parent_exists = false;
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && self::FAMILY_PARENT_SLUG === $item[2] ) {
				$parent_exists = true;
				break;
			}
		}
		if ( ! $parent_exists ) {
			add_menu_page(
				__( 'WPThistle', 'wpthistle-backup-restore' ),
				__( 'WPThistle', 'wpthistle-backup-restore' ),
				'manage_options',
				self::FAMILY_PARENT_SLUG,
				// Phase 4: this used to be '' (no callback), so clicking the
				// parent menu item itself 404'd -- WP only auto-adds a
				// submenu matching the parent slug for a landing page, and
				// nothing rendered one. Whichever WPThistle plugin's
				// admin_menu callback runs first now owns the real family
				// hub page instead of a dead link, same "first one wins"
				// rule already governing who creates the parent at all.
				array( $this, 'render_family_hub' ),
				'dashicons-shield'
			);
			// WP auto-adds a submenu item duplicating the parent label/slug;
			// render_family_hub() above is that page now, so this just
			// removes the duplicate auto-added entry from the submenu list
			// (the page itself still renders via the parent's own callback).
			remove_submenu_page( self::FAMILY_PARENT_SLUG, self::FAMILY_PARENT_SLUG );
		}
		add_submenu_page(
			self::FAMILY_PARENT_SLUG,
			__( 'WPThistle Backup and Restore', 'wpthistle-backup-restore' ),
			__( 'Backup and Restore', 'wpthistle-backup-restore' ),
			'manage_options',
			'wpthistle-backup-restore',
			array( $this, 'render_page' )
		);
	}

	/**
	 * The family hub landing page (top-level "WPThistle" click target).
	 * Renders a status card per installed WPThistle plugin, gathered via
	 * self::STATUS_CARDS_FILTER -- see that constant's docblock for the
	 * card contract. Every field from a card is treated as untrusted
	 * plain text regardless of origin (this plugin's own card included),
	 * same escaping discipline as render_page() below.
	 */
	public function render_family_hub(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cards = apply_filters( self::STATUS_CARDS_FILTER, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WPThistle', 'wpthistle-backup-restore' ); ?></h1>
			<p><?php esc_html_e( 'A home base for every WPThistle plugin on this site. Each one below reports its own status; open a card to manage that plugin.', 'wpthistle-backup-restore' ); ?></p>

			<?php if ( empty( $cards ) ) : ?>
				<p><em><?php esc_html_e( 'No WPThistle plugins reported a status card.', 'wpthistle-backup-restore' ); ?></em></p>
			<?php else : ?>
				<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:16px;">
					<?php foreach ( $cards as $card ) : ?>
						<?php
						$card        = wp_parse_args(
							$card,
							array(
								'slug'         => '',
								'title'        => __( '(untitled)', 'wpthistle-backup-restore' ),
								'icon'         => 'dashicons-admin-generic',
								'status_class' => 'neutral',
								'status_label' => '',
								'detail'       => '',
								'url'          => '',
							)
						);
						$status_colors = array(
							'ok'      => '#46b450',
							'warn'    => '#ffb900',
							'error'   => '#dc3232',
							'neutral' => '#787c82',
						);
						$dot_color = $status_colors[ $card['status_class'] ] ?? $status_colors['neutral'];
						$icon      = preg_match( '/^dashicons-[a-z0-9-]+$/', (string) $card['icon'] ) ? $card['icon'] : 'dashicons-admin-generic';
						?>
						<a href="<?php echo esc_url( $card['url'] ); ?>" style="display:block;width:280px;padding:16px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;text-decoration:none;color:inherit;">
							<div style="display:flex;align-items:center;gap:8px;">
								<span class="dashicons <?php echo esc_attr( $icon ); ?>" style="font-size:20px;"></span>
								<strong style="font-size:14px;"><?php echo esc_html( $card['title'] ); ?></strong>
							</div>
							<div style="margin-top:10px;display:flex;align-items:center;gap:6px;">
								<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $dot_color ); ?>;"></span>
								<span><?php echo esc_html( $card['status_label'] ); ?></span>
							</div>
							<?php if ( '' !== $card['detail'] ) : ?>
								<p class="description" style="margin-top:6px;"><?php echo esc_html( $card['detail'] ); ?></p>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * This plugin's own entry in the family hub's status grid. Mirrors
	 * render_page()'s "last verified backup" summary, but against the
	 * latest job of any outcome (not just the latest *verified* one) --
	 * the hub's job is to surface a stuck or failed backup, not just the
	 * last success.
	 */
	public function add_own_status_card( array $cards ): array {
		$url = admin_url( 'admin.php?page=wpthistle-backup-restore' );

		if ( ! Settings::has_credentials() ) {
			$cards[] = array(
				'slug'         => 'backup-restore',
				'title'        => __( 'Backup and Restore', 'wpthistle-backup-restore' ),
				'icon'         => 'dashicons-cloud-upload',
				'status_class' => 'neutral',
				'status_label' => __( 'Not configured', 'wpthistle-backup-restore' ),
				'detail'       => __( 'Connect a Nextcloud, ownCloud, or OpenCloud server to start backing up.', 'wpthistle-backup-restore' ),
				'url'          => $url,
			);
			return $cards;
		}

		$latest = Job_Store::get_latest();
		if ( null === $latest ) {
			$cards[] = array(
				'slug'         => 'backup-restore',
				'title'        => __( 'Backup and Restore', 'wpthistle-backup-restore' ),
				'icon'         => 'dashicons-cloud-upload',
				'status_class' => 'warn',
				'status_label' => __( 'No backup yet', 'wpthistle-backup-restore' ),
				'detail'       => __( 'Configured -- run your first backup.', 'wpthistle-backup-restore' ),
				'url'          => $url,
			);
			return $cards;
		}

		$status_map = array(
			'verified'           => array( 'ok', __( 'Backup verified', 'wpthistle-backup-restore' ) ),
			'failed'             => array( 'error', __( 'Last backup failed', 'wpthistle-backup-restore' ) ),
			'uploaded-unverified' => array( 'warn', __( 'Backup in progress', 'wpthistle-backup-restore' ) ),
			'running'            => array( 'warn', __( 'Backup in progress', 'wpthistle-backup-restore' ) ),
			'queued'             => array( 'warn', __( 'Backup in progress', 'wpthistle-backup-restore' ) ),
		);
		list( $status_class, $status_label ) = $status_map[ $latest['status'] ] ?? array( 'neutral', __( 'Unknown status', 'wpthistle-backup-restore' ) );

		$cards[] = array(
			'slug'         => 'backup-restore',
			'title'        => __( 'Backup and Restore', 'wpthistle-backup-restore' ),
			'icon'         => 'dashicons-cloud-upload',
			'status_class' => $status_class,
			'status_label' => $status_label,
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			'detail'       => sprintf( __( '%s ago', 'wpthistle-backup-restore' ), human_time_diff( strtotime( $latest['updated_at'] . ' UTC' ) ) ),
			'url'          => $url,
		);
		return $cards;
	}

	private function require_capability_and_nonce(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'wpthistle-backup-restore' ) ), 403 );
			return false;
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed; please reload the page.', 'wpthistle-backup-restore' ) ), 403 );
			return false;
		}
		return true;
	}

	public function ajax_save_settings(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		$input = array(
			'backend'        => isset( $_POST['backend'] ) ? sanitize_text_field( wp_unslash( $_POST['backend'] ) ) : 'nextcloud',
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
			wp_send_json_success( array( 'message' => __( 'Settings saved.', 'wpthistle-backup-restore' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Please fix the errors below.', 'wpthistle-backup-restore' ), 'errors' => $result['errors'] ) );
		}
	}

	public function ajax_test_connection(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		if ( ! Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => __( 'Enter and save the server URL, username, and app password / app token first.', 'wpthistle-backup-restore' ) ) );
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
		// Phase 3 (downloadable backups): 'download' is always offered,
		// Nextcloud configured or not -- 'nextcloud' (the default, for any
		// caller that predates this param) still requires credentials,
		// unchanged.
		$destination = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : 'nextcloud';
		if ( ! in_array( $destination, array( 'nextcloud', 'download' ), true ) ) {
			$destination = 'nextcloud';
		}
		if ( 'nextcloud' === $destination && ! Settings::has_credentials() ) {
			wp_send_json_error( array( 'message' => __( 'Configure and save your cloud storage destination before backing up to it, or use Download Now instead.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		$job_id = Job_Orchestrator::create_job( $destination );
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'A backup is already running.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		wp_schedule_single_event( time() + 5, 'wpthistle_backup_cron_advance', array( $job_id ) );
		wp_send_json_success( array( 'job_id' => $job_id ) );
	}

	/**
	 * Phase 3 (downloadable backups): streams the verified local bundle.
	 * Capability + nonce gated like every other handler here; job_id is
	 * the only request input, the actual file path is derived entirely
	 * from server-side job state, never from anything request-supplied.
	 */
	public function handle_download_bundle(): void {
		// Note: an anonymous request never reaches this method at all --
		// wp-admin/admin-post.php itself returns wp_die('', 400) for any
		// admin_post_{action} with no matching admin_post_nopriv_{action}
		// registered (deliberately not registered here), confirmed live.
		// The check below is still real defense in depth for a logged-in
		// user who lacks the capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpthistle-backup-restore' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_DOWNLOAD ) ) {
			wp_die( esc_html__( 'Security check failed; please reload the page and try again.', 'wpthistle-backup-restore' ), '', array( 'response' => 403 ) );
		}
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$job = '' !== $job_id ? Job_Store::get( $job_id ) : null;
		if ( null === $job || 'download' !== ( $job['destination'] ?? '' ) || 'verified' !== ( $job['status'] ?? '' ) ) {
			wp_die( esc_html__( 'No downloadable backup is available for that job.', 'wpthistle-backup-restore' ), '', array( 'response' => 404 ) );
		}

		$token = $job['checkpoint']['download_token'] ?? '';
		$dir = Job_Orchestrator::download_dir_for_token( $token );
		$base = realpath( Job_Orchestrator::download_base_dir() );
		$real_dir = '' !== $dir ? realpath( $dir ) : false;
		// Defense in depth: the token/path come entirely from server-side
		// checkpoint state, never from this request, but confirm the
		// resolved path still lands inside the one directory this
		// endpoint is ever allowed to serve from before trusting it.
		if ( false === $base || false === $real_dir || 0 !== strpos( $real_dir . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR ) ) {
			wp_die( esc_html__( 'Downloadable backup could not be located.', 'wpthistle-backup-restore' ), '', array( 'response' => 404 ) );
		}
		$bundle_path = $job['checkpoint']['download_bundle_path'] ?? '';
		$real_bundle = '' !== $bundle_path ? realpath( $bundle_path ) : false;
		if ( false === $real_bundle || 0 !== strpos( $real_bundle, $real_dir ) || ! is_file( $real_bundle ) ) {
			wp_die( esc_html__( 'Downloadable backup file is missing -- it may have expired (pending bundles are removed after 24 hours). Run a new backup.', 'wpthistle-backup-restore' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $real_bundle ) ) . '"' );
		header( 'Content-Length: ' . filesize( $real_bundle ) );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		@ini_set( 'zlib.output_compression', 'Off' );
		readfile( $real_bundle );
		exit;
	}

	/**
	 * Phase 3: POST + nonce -- an admin explicitly discarding a pending
	 * local bundle before its TTL. At most one pending bundle ever exists
	 * at a time (cap-at-one, enforced in Job_Orchestrator::create_job()),
	 * so purging unconditionally is equivalent to "delete job_id's bundle"
	 * without needing to resolve job_id to a specific directory first.
	 */
	public function ajax_delete_download_bundle(): void {
		if ( ! $this->require_capability_and_nonce() ) {
			return;
		}
		Job_Orchestrator::sweep_download_bundles( true );
		wp_send_json_success( array( 'message' => __( 'Local backup bundle deleted.', 'wpthistle-backup-restore' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'No backup job found to cancel.', 'wpthistle-backup-restore' ) ) );
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
				array( 'message' => __( 'Job is actively being advanced right now (AJAX or WP-Cron) -- nothing was changed. Wait a moment and try Cancel again.', 'wpthistle-backup-restore' ) )
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
			wp_send_json_error( array( 'message' => __( 'Configure and save your cloud storage destination first.', 'wpthistle-backup-restore' ) ) );
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
			wp_send_json_error( array( 'message' => __( 'A backup or restore is already running.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		if ( empty( $_FILES['bundle'] ) || UPLOAD_ERR_OK !== ( $_FILES['bundle']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			wp_send_json_error( array( 'message' => __( 'No file was uploaded, or the upload failed.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		$tmp_upload = $_FILES['bundle']['tmp_name'];
		if ( ! is_uploaded_file( $tmp_upload ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload.', 'wpthistle-backup-restore' ) ) );
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

		$result = Restore_Orchestrator::extract_bundle_zip( $tmp_upload, $holding );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( array(
			'message'      => __( 'Bundle uploaded and extracted; ready to verify.', 'wpthistle-backup-restore' ),
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
				wp_send_json_error( array( 'message' => __( 'Upload a backup bundle first.', 'wpthistle-backup-restore' ) ) );
				return;
			}
			$manifest = json_decode( (string) file_get_contents( $holding . 'manifest.json' ), true );
			$backup_id = is_array( $manifest ) ? (string) ( $manifest['backup_id'] ?? '' ) : '';
			if ( '' === $backup_id ) {
				wp_send_json_error( array( 'message' => __( 'Uploaded bundle\'s manifest.json has no backup_id.', 'wpthistle-backup-restore' ) ) );
				return;
			}
			$source = array( 'type' => 'upload', 'upload_dir' => $holding );
		} else {
			$backup_id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
			if ( '' === $backup_id ) {
				wp_send_json_error( array( 'message' => __( 'Choose a backup to restore.', 'wpthistle-backup-restore' ) ) );
				return;
			}
			$source = array( 'type' => 'nextcloud' );
		}

		$job_id = Restore_Orchestrator::create_restore_job( $source, $backup_id, $confirm );
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'A backup or restore is already running.', 'wpthistle-backup-restore' ) ) );
			return;
		}
		// security audit finding HIGH-AJAX-1: backups get a WP-Cron fallback so a
		// closed browser tab doesn't strand them; restore had none at
		// all until now, the one place a stranded job matters most.
		wp_schedule_single_event( time() + 5, 'wpthistle_backup_cron_advance', array( $job_id ) );
		wp_send_json_success( array( 'job_id' => $job_id ) );
	}

	/** Strips anything that shouldn't reach the browser even redacted-in-principle (defense in depth). */
	private function public_job_view( array $job ): array {
		return array(
			'job_id'             => $job['job_id'] ?? '',
			'backup_id'          => $job['backup_id'] ?? '',
			'status'             => $job['status'] ?? '',
			'step'               => $job['step'] ?? '',
			'error'              => $job['error'] ?? '',
			'log'                => array_slice( $job['log'] ?? array(), -10 ),
			'kind'               => 'restore' === ( $job['checkpoint']['kind'] ?? 'backup' ) ? 'restore' : 'backup',
			// Phase 3: never exposes the bundle's real filesystem path --
			// just enough for the UI to show/hide the download link and
			// build the admin_post.php URL from job_id + nonce.
			'destination'        => $job['destination'] ?? 'nextcloud',
			'download_available' => 'download' === ( $job['destination'] ?? '' )
				&& 'verified' === ( $job['status'] ?? '' )
				&& ! empty( $job['checkpoint']['download_token'] ),
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Phase 3: housekeeping sweep on every settings-page load, same as
		// the hourly cron -- catches a TTL-expired bundle promptly even on
		// a low-traffic site where the cron's own pseudo-cron trigger
		// might lag.
		Job_Orchestrator::sweep_download_bundles();
		$settings = Settings::get_all();
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		$download_nonce = wp_create_nonce( self::NONCE_ACTION_DOWNLOAD );
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
			<h1><?php esc_html_e( 'WPThistle Backup and Restore', 'wpthistle-backup-restore' ); ?></h1>

			<?php if ( $retention_error ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Retention pruning is not completing.', 'wpthistle-backup-restore' ); ?></strong>
					<?php esc_html_e( 'Old backups are NOT being deleted (nothing was deleted either, by design) until this is resolved:', 'wpthistle-backup-restore' ); ?>
					<?php echo esc_html( $retention_error ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $latest_verified ) : ?>
				<p><strong><?php esc_html_e( 'Last verified backup:', 'wpthistle-backup-restore' ); ?></strong>
				<?php echo esc_html( $latest_verified['backup_id'] . ' (' . $latest_verified['updated_at'] . ' UTC)' ); ?></p>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No verified backup yet.', 'wpthistle-backup-restore' ); ?></em></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connection', 'wpthistle-backup-restore' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="wpthistle_backup_backend"><?php esc_html_e( 'Cloud storage type', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<select id="wpthistle_backup_backend">
							<option value="nextcloud" <?php selected( $settings['backend'], 'nextcloud' ); ?>><?php esc_html_e( 'Nextcloud', 'wpthistle-backup-restore' ); ?></option>
							<option value="owncloud" <?php selected( $settings['backend'], 'owncloud' ); ?>><?php esc_html_e( 'ownCloud (classic, 10.x)', 'wpthistle-backup-restore' ); ?></option>
							<option value="opencloud" <?php selected( $settings['backend'], 'opencloud' ); ?>><?php esc_html_e( 'OpenCloud', 'wpthistle-backup-restore' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'All three speak the same WebDAV dialect this plugin uses, verified directly against each. The connection and every backup/restore step work identically regardless of which you pick -- this is for your own record (the credential field below already covers all three without needing to know which one is selected).', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_url"><?php esc_html_e( 'Server URL (https only)', 'wpthistle-backup-restore' ); ?></label></th>
					<td><input type="url" id="wpthistle_backup_url" value="<?php echo esc_attr( $settings['nextcloud_url'] ); ?>" class="regular-text" placeholder="https://your-server.example.com"></td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_user"><?php esc_html_e( 'Username (dedicated, non-admin account recommended)', 'wpthistle-backup-restore' ); ?></label></th>
					<td><input type="text" id="wpthistle_backup_user" value="<?php echo esc_attr( $settings['nextcloud_user'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_pw"><?php esc_html_e( 'App password / app token', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<input type="password" id="wpthistle_backup_pw" value="" class="regular-text" autocomplete="new-password" <?php echo $locked_pw ? 'disabled' : ''; ?> placeholder="<?php echo esc_attr( $locked_pw ? __( 'Set via WPTHISTLE_BACKUP_APP_PASSWORD constant', 'wpthistle-backup-restore' ) : __( 'leave blank to keep current value', 'wpthistle-backup-restore' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Never echoed back. Generate a dedicated app password (Nextcloud/ownCloud) or app token (OpenCloud) under your account\'s Security settings — do not use your main account password.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_folder"><?php esc_html_e( 'Destination folder', 'wpthistle-backup-restore' ); ?></label></th>
					<td><input type="text" id="wpthistle_backup_folder" value="<?php echo esc_attr( $settings['remote_folder'] ); ?>" class="regular-text" placeholder="wpthistle-backups"></td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_ca"><?php esc_html_e( 'Custom CA bundle path (advanced, optional)', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<input type="text" id="wpthistle_backup_ca" value="<?php echo esc_attr( $settings['ca_bundle_path'] ); ?>" class="regular-text" <?php echo $locked_ca ? 'disabled' : ''; ?> placeholder="/absolute/path/to/private-ca.pem">
						<p class="description"><?php esc_html_e( 'Only for a private CA you operate (e.g. self-hosted Nextcloud). Leave blank for a normal publicly trusted certificate — this never weakens TLS verification, it only adds a trusted root.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_schedule"><?php esc_html_e( 'Automatic backup schedule', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<select id="wpthistle_backup_schedule">
							<option value="disabled" <?php selected( $settings['schedule'], 'disabled' ); ?>><?php esc_html_e( 'Disabled — manual only', 'wpthistle-backup-restore' ); ?></option>
							<option value="daily" <?php selected( $settings['schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'wpthistle-backup-restore' ); ?></option>
							<option value="weekly" <?php selected( $settings['schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wpthistle-backup-restore' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Relies on WP-Cron, same as the manual backup fallback — only fires on site traffic (see Operations docs). Skips a run rather than overlapping if a backup is still genuinely in progress.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_retention"><?php esc_html_e( 'Keep last N backups', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<input type="number" id="wpthistle_backup_retention" min="0" step="1" value="<?php echo esc_attr( (string) $settings['retention_count'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( '0 = keep every backup forever (default). A positive number deletes the oldest backup folder(s) on Nextcloud right after each new backup verifies successfully, once more than that many exist. This permanently deletes data — only increase from 0 once you are comfortable with the number chosen.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_incremental"><?php esc_html_e( 'Incremental file backups', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<label><input type="checkbox" id="wpthistle_backup_incremental" <?php checked( $settings['incremental_enabled'] ); ?>> <?php esc_html_e( 'Skip re-uploading files unchanged since the last backup', 'wpthistle-backup-restore' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off by default. When on, a file whose size and modified time match the previous backup is referenced instead of re-archived and re-uploaded — the usual speed-up on a site with many files. A full backup is still taken periodically (every 7 backups or 7 days, whichever comes first) regardless of this setting. Every individual backup remains independently verified before being marked complete; see Operations docs for how restore works with this on.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="wpthistle_backup_save"><?php esc_html_e( 'Save Settings', 'wpthistle-backup-restore' ); ?></button>
				<button type="button" class="button" id="wpthistle_backup_test"><?php esc_html_e( 'Test Connection', 'wpthistle-backup-restore' ); ?></button>
			</p>
			<div id="wpthistle_backup_settings_result" role="status"></div>

			<h2><?php esc_html_e( 'Manual backup', 'wpthistle-backup-restore' ); ?></h2>
			<p>
				<button type="button" class="button button-primary" id="wpthistle_backup_backup_now"><?php esc_html_e( 'Back Up Now', 'wpthistle-backup-restore' ); ?></button>
				<button type="button" class="button" id="wpthistle_backup_download_now"><?php esc_html_e( 'Download Now', 'wpthistle-backup-restore' ); ?></button>
				<button type="button" class="button" id="wpthistle_backup_cancel_job"><?php esc_html_e( 'Cancel / Reset Stuck Job', 'wpthistle-backup-restore' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Back Up Now sends this backup to your configured cloud storage destination. Download Now instead packages the same backup into one .zip and lets you save it straight to your own computer -- no cloud storage configuration required. A pending download is NOT deleted automatically just because you clicked Download (the server cannot confirm your browser received every byte) -- it stays on this server, guarded against direct web access, until you click "Delete local copy" below, start another Download Now backup (which replaces it), or 24 hours pass, whichever comes first.', 'wpthistle-backup-restore' ); ?></p>
			<p class="description"><?php esc_html_e( 'If a backup never completes (e.g. the site had no other traffic to trigger the WP-Cron fallback), use Cancel to mark it failed so a new backup can start. Already-uploaded parts of a cancelled job are left on Nextcloud untouched.', 'wpthistle-backup-restore' ); ?></p>
			<div id="wpthistle_backup_job_status" role="status"></div>
			<p id="wpthistle_backup_job_download_row" style="display:none;"><a class="button button-primary" id="wpthistle_backup_job_download_link" href="#"><?php esc_html_e( 'Download backup (.zip)', 'wpthistle-backup-restore' ); ?></a> <button type="button" class="button" id="wpthistle_backup_job_download_delete"><?php esc_html_e( 'Delete local copy', 'wpthistle-backup-restore' ); ?></button></p>
			<pre id="wpthistle_backup_job_log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:8px;"></pre>

			<h2><?php esc_html_e( 'Restore', 'wpthistle-backup-restore' ); ?></h2>
			<p class="description"><strong><?php esc_html_e( 'This overwrites this site\'s current content and database with a chosen backup, in place.', 'wpthistle-backup-restore' ); ?></strong>
			<?php esc_html_e( 'Nothing is ever permanently deleted by it — anything it replaces is renamed aside (database tables under a "wpgbak…" prefix, files into a timestamped rollback folder) for manual recovery, never dropped outright. Still: back up anything you cannot afford to lose before using this on a site that matters.', 'wpthistle-backup-restore' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Source', 'wpthistle-backup-restore' ); ?></th>
					<td>
						<label><input type="radio" name="wpthistle_backup_restore_source" value="nextcloud" checked> <?php esc_html_e( 'Choose from configured cloud storage', 'wpthistle-backup-restore' ); ?></label>
						<select id="wpthistle_backup_restore_backup_list" style="margin-left:8px;"><option value=""><?php esc_html_e( '— loading —', 'wpthistle-backup-restore' ); ?></option></select>
						<br><br>
						<label><input type="radio" name="wpthistle_backup_restore_source" value="upload"> <?php esc_html_e( 'Upload a backup bundle', 'wpthistle-backup-restore' ); ?></label>
						<input type="file" id="wpthistle_backup_restore_upload_file" accept=".zip" style="margin-left:8px;">
						<button type="button" class="button" id="wpthistle_backup_restore_upload_btn"><?php esc_html_e( 'Upload', 'wpthistle-backup-restore' ); ?></button>
						<span id="wpthistle_backup_restore_upload_result"></span>
						<p class="description"><?php esc_html_e( 'A single .zip containing manifest.json, COMPLETE.json, database.sql.gz, and files.part-*.zip at its root. Only supports a self-contained (full) backup — an incremental backup\'s ancestor parts can only be reached via the Nextcloud source.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wpthistle_backup_restore_confirm_text"><?php esc_html_e( 'Confirm', 'wpthistle-backup-restore' ); ?></label></th>
					<td>
						<input type="text" id="wpthistle_backup_restore_confirm_text" class="regular-text" placeholder="RESTORE">
						<p class="description"><?php esc_html_e( 'Type RESTORE to enable the button below.', 'wpthistle-backup-restore' ); ?></p>
						<label><input type="checkbox" id="wpthistle_backup_restore_confirm_url"> <?php esc_html_e( 'Migrate this backup to this site: this backup is from a different site, and I want every URL in it rewritten to this site\'s own address', 'wpthistle-backup-restore' ); ?></label>
						<p class="description"><?php esc_html_e( 'Checking this does not just skip the mismatch warning -- it rewrites every occurrence of the backup\'s own domain (in post content, widget settings, and everywhere else WordPress stores a URL) to this site\'s domain, before anything is restored live. Leave unchecked to restore this backup onto the exact site it came from.', 'wpthistle-backup-restore' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="wpthistle_backup_restore_start" disabled><?php esc_html_e( 'Restore Now', 'wpthistle-backup-restore' ); ?></button>
			</p>
			<div id="wpthistle_backup_restore_status" role="status"></div>
			<pre id="wpthistle_backup_restore_log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:8px;"></pre>
		</div>
		<script>
		(function(){
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var downloadNonce = <?php echo wp_json_encode( $download_nonce ); ?>;
			var currentJobId = null;
			var currentRestoreJobId = null;
			var currentUploadToken = null;

			function post(action, extra) {
				var body = new URLSearchParams(Object.assign({ action: action, nonce: nonce }, extra || {}));
				return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function(r){ return r.json(); });
			}

			document.getElementById('wpthistle_backup_save').addEventListener('click', function(){
				post('wpthistle_backup_save_settings', {
					backend: document.getElementById('wpthistle_backup_backend').value,
					nextcloud_url: document.getElementById('wpthistle_backup_url').value,
					nextcloud_user: document.getElementById('wpthistle_backup_user').value,
					app_password: document.getElementById('wpthistle_backup_pw').value,
					remote_folder: document.getElementById('wpthistle_backup_folder').value,
					ca_bundle_path: document.getElementById('wpthistle_backup_ca').value,
					schedule: document.getElementById('wpthistle_backup_schedule').value,
					retention_count: document.getElementById('wpthistle_backup_retention').value,
					incremental_enabled: document.getElementById('wpthistle_backup_incremental').checked ? '1' : ''
				}).then(function(res){
					document.getElementById('wpthistle_backup_settings_result').textContent = res.data.message;
				});
			});

			document.getElementById('wpthistle_backup_test').addEventListener('click', function(){
				document.getElementById('wpthistle_backup_settings_result').textContent = 'Testing...';
				post('wpthistle_backup_test_connection', {}).then(function(res){
					document.getElementById('wpthistle_backup_settings_result').textContent = res.data.message;
				});
			});

			function renderJobInto(statusId, logId, job) {
				document.getElementById(statusId).textContent = 'Status: ' + job.status + ' (step: ' + job.step + ')' + (job.error ? ' — ' + job.error : '');
				document.getElementById(logId).textContent = (job.log || []).map(function(e){ return e.time_utc + ' [' + e.severity + '] ' + e.message; }).join('\n');
			}

			function renderJob(job) {
				currentJobId = job.job_id || currentJobId;
				renderJobInto('wpthistle_backup_job_status', 'wpthistle_backup_job_log', job);
				var row = document.getElementById('wpthistle_backup_job_download_row');
				if (job.download_available) {
					var url = ajaxUrl.replace('admin-ajax.php', 'admin-post.php') +
						'?action=wpthistle_backup_download_bundle&job_id=' + encodeURIComponent(job.job_id) + '&nonce=' + encodeURIComponent(downloadNonce);
					document.getElementById('wpthistle_backup_job_download_link').href = url;
					row.style.display = '';
				} else {
					row.style.display = 'none';
				}
			}

			function pollJob(jobId) {
				post('wpthistle_backup_advance_job', { job_id: jobId }).then(function(res){
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
					document.getElementById('wpthistle_backup_job_status').textContent = 'Network error polling for status -- the backup may still be running via WP-Cron. Retrying...';
					setTimeout(function(){ pollJob(jobId); }, 2000);
				});
			}

			function renderRestoreJob(job) {
				currentRestoreJobId = job.job_id || currentRestoreJobId;
				renderJobInto('wpthistle_backup_restore_status', 'wpthistle_backup_restore_log', job);
			}

			function pollRestoreJob(jobId) {
				post('wpthistle_backup_advance_job', { job_id: jobId }).then(function(res){
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
					document.getElementById('wpthistle_backup_restore_status').textContent = 'Network error polling for status -- the restore may still be running via WP-Cron. Retrying...';
					setTimeout(function(){ pollRestoreJob(jobId); }, 2000);
				});
			}

			function startBackup(destination) {
				document.getElementById('wpthistle_backup_job_download_row').style.display = 'none';
				post('wpthistle_backup_start_backup', { destination: destination }).then(function(res){
					if (res.success) {
						pollJob(res.data.job_id);
					} else {
						document.getElementById('wpthistle_backup_job_status').textContent = res.data.message;
					}
				});
			}
			document.getElementById('wpthistle_backup_backup_now').addEventListener('click', function(){
				startBackup('nextcloud');
			});
			document.getElementById('wpthistle_backup_download_now').addEventListener('click', function(){
				startBackup('download');
			});
			document.getElementById('wpthistle_backup_job_download_delete').addEventListener('click', function(){
				if (!currentJobId) { return; }
				if (!window.confirm('Delete this local backup bundle? This cannot be undone.')) { return; }
				post('wpthistle_backup_delete_download_bundle', { job_id: currentJobId }).then(function(res){
					document.getElementById('wpthistle_backup_job_download_row').style.display = 'none';
					document.getElementById('wpthistle_backup_job_status').textContent = res.data.message;
				});
			});

			document.getElementById('wpthistle_backup_cancel_job').addEventListener('click', function(){
				if (!window.confirm('Cancel the current/stuck backup job? This marks it failed so a new backup can start. Already-uploaded parts on Nextcloud are left untouched. This cannot be undone.')) {
					return;
				}
				post('wpthistle_backup_cancel_job', currentJobId ? { job_id: currentJobId } : {}).then(function(res){
					if (res.success) {
						renderJob(res.data);
					} else {
						document.getElementById('wpthistle_backup_job_status').textContent = res.data.message;
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
			post('wpthistle_backup_job_status', {}).then(function(res){
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
				var checked = document.querySelector('input[name="wpthistle_backup_restore_source"]:checked');
				return checked ? checked.value : 'nextcloud';
			}

			function loadRestorableBackups() {
				var select = document.getElementById('wpthistle_backup_restore_backup_list');
				post('wpthistle_backup_list_restorable_backups', {}).then(function(res){
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

			document.getElementById('wpthistle_backup_restore_upload_btn').addEventListener('click', function(){
				var fileInput = document.getElementById('wpthistle_backup_restore_upload_file');
				if (!fileInput.files.length) {
					document.getElementById('wpthistle_backup_restore_upload_result').textContent = 'Choose a .zip file first.';
					return;
				}
				var body = new FormData();
				body.append('action', 'wpthistle_backup_upload_restore_bundle');
				body.append('nonce', nonce);
				body.append('bundle', fileInput.files[0]);
				document.getElementById('wpthistle_backup_restore_upload_result').textContent = 'Uploading...';
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function(r){ return r.json(); })
					.then(function(res){
						document.getElementById('wpthistle_backup_restore_upload_result').textContent = res.data.message;
						if (res.success) {
							currentUploadToken = res.data.upload_token;
						}
					});
			});

			function updateRestoreButtonState() {
				var confirmed = document.getElementById('wpthistle_backup_restore_confirm_text').value.trim() === 'RESTORE';
				document.getElementById('wpthistle_backup_restore_start').disabled = !confirmed;
			}
			document.getElementById('wpthistle_backup_restore_confirm_text').addEventListener('input', updateRestoreButtonState);

			document.getElementById('wpthistle_backup_restore_start').addEventListener('click', function(){
				var source = selectedRestoreSource();
				var payload = {
					source: source,
					confirm_url_mismatch: document.getElementById('wpthistle_backup_restore_confirm_url').checked ? '1' : ''
				};
				if (source === 'nextcloud') {
					var backupId = document.getElementById('wpthistle_backup_restore_backup_list').value;
					if (!backupId) {
						document.getElementById('wpthistle_backup_restore_status').textContent = 'Choose a backup first.';
						return;
					}
					payload.backup_id = backupId;
				} else {
					if (!currentUploadToken) {
						document.getElementById('wpthistle_backup_restore_status').textContent = 'Upload a backup bundle first.';
						return;
					}
					payload.upload_token = currentUploadToken;
				}
				if (!window.confirm('This will overwrite this site\'s current content and database with the chosen backup, in place. Continue?')) {
					return;
				}
				post('wpthistle_backup_start_restore', payload).then(function(res){
					if (res.success) {
						pollRestoreJob(res.data.job_id);
					} else {
						document.getElementById('wpthistle_backup_restore_status').textContent = res.data.message;
					}
				});
			});
		})();
		</script>
		<?php
	}
}
