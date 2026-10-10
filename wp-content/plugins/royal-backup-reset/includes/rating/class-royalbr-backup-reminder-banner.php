<?php
/**
 * Backup Reminder Banner Class
 *
 * Displays a reminder banner to administrators when no backups exist.
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RoyalBR_Backup_Reminder_Banner
 *
 * Handles the display and dismissal of backup reminder banner.
 *
 * @since 1.0.0
 */
class RoyalBR_Backup_Reminder_Banner {

	/**
	 * Constructor.
	 *
	 * Sets up hooks for backup reminder banner functionality.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'init_backup_reminder_banner' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_royalbr_backup_reminder_banner_dismiss', array( $this, 'dismiss_notice' ) );
		add_action( 'wp_ajax_royalbr_backup_reminder_banner_later', array( $this, 'maybe_later' ) );
	}

	/**
	 * Initialize backup reminder banner on admin_init when user functions are available.
	 *
	 * @since 1.0.0
	 */
	public function init_backup_reminder_banner() {
		if ( ! current_user_can( 'administrator' ) ) {
			return;
		}

		if ( ! empty( get_option( 'royalbr_backup_reminder_banner_dismissed', false ) ) ) {
			return;
		}

		// Don't show on the plugin's own page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && 'royal-backup-reset' === $_GET['page'] ) {
			return;
		}

		// Wait at least 3 days after plugin activation before showing.
		$activation_time = get_option( 'royalbr_activation_time' );
		if ( false === $activation_time || strtotime( '-3 days' ) < $activation_time ) {
			return;
		}

		$backup_history = get_option( 'royalbr_backup_history', array() );
		$backups        = isset( $backup_history['backups'] ) ? $backup_history['backups'] : array();

		$should_show = false;

		if ( empty( $backups ) ) {
			// No backups at all — show banner.
			$should_show = true;
		} else {
			// Has backups — show if newest backup is older than 2 weeks.
			$newest_timestamp = 0;
			foreach ( $backups as $backup ) {
				if ( isset( $backup['timestamp'] ) && $backup['timestamp'] > $newest_timestamp ) {
					$newest_timestamp = $backup['timestamp'];
				}
			}

			if ( 0 === $newest_timestamp || $newest_timestamp < strtotime( '-2 weeks' ) ) {
				$should_show = true;
			}
		}

		if ( ! $should_show ) {
			return;
		}

		$this->check_display_conditions();
	}

	/**
	 * Check if conditions are met to display the backup reminder banner.
	 *
	 * @since 1.0.0
	 */
	public function check_display_conditions() {
		$reminder_later_time = get_option( 'royalbr_backup_reminder_banner_later_time' );

		if ( false === $reminder_later_time ) {
			add_action( 'admin_notices', array( $this, 'render_notice' ) );
		} elseif ( strtotime( '-3 days' ) >= $reminder_later_time ) {
			add_action( 'admin_notices', array( $this, 'render_notice' ) );
		}
	}

	/**
	 * Handle "Remind Me Later" button click.
	 *
	 * @since 1.0.0
	 */
	public function maybe_later() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'royalbr_backup_reminder_banner' ) ) {
			wp_die();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		update_option( 'royalbr_backup_reminder_banner_later_time', strtotime( 'now' ) );
		wp_die();
	}

	/**
	 * Handle notice dismiss button click.
	 *
	 * @since 1.0.0
	 */
	public function dismiss_notice() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'royalbr_backup_reminder_banner' ) ) {
			wp_die();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		update_option( 'royalbr_backup_reminder_banner_dismissed', true );
		wp_die();
	}

	/**
	 * Render the backup reminder banner HTML.
	 *
	 * @since 1.0.0
	 */
	public function render_notice() {
		if ( ! is_admin() ) {
			return;
		}

		$backup_url = admin_url( 'admin.php?page=royal-backup-reset' );
		$logo_url   = ROYALBR_ASSETS_URL . 'images/logo.png';
		?>
		<div class="notice royalbr-backup-reminder-banner is-dismissible">
			<div class="royalbr-backup-reminder-banner-logo">
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
			</div>
			<div class="royalbr-backup-reminder-banner-content">
				<h3><?php esc_html_e( 'Protect Your Website — Create a Backup!', 'royal-backup-reset' ); ?></h3>
				<p><?php esc_html_e( 'You haven\'t backed up your site recently. A quick backup takes less than a minute and could save you hours of work.', 'royal-backup-reset' ); ?></p>
				<p class="royalbr-backup-reminder-banner-buttons">
					<a href="<?php echo esc_url( $backup_url ); ?>" class="button button-primary royalbr-backup-now">
						<?php esc_html_e( 'Backup Now', 'royal-backup-reset' ); ?>
					</a>
					<a href="#" class="royalbr-backup-reminder-banner-later">
						<span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Remind Me Later', 'royal-backup-reset' ); ?>
					</a>
				</p>
			</div>
			<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'royal-backup-reset' ); ?></span></button>
		</div>
		<?php
	}

	/**
	 * Enqueue backup reminder banner scripts.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		if ( ! current_user_can( 'administrator' ) ) {
			return;
		}

		if ( ! empty( get_option( 'royalbr_backup_reminder_banner_dismissed', false ) ) ) {
			return;
		}

		wp_enqueue_script(
			'royalbr-backup-reminder-banner',
			ROYALBR_ASSETS_URL . 'backup-reminder-banner.js',
			array( 'jquery' ),
			ROYALBR_VERSION,
			true
		);

		wp_localize_script(
			'royalbr-backup-reminder-banner',
			'royalbrBackupReminderBanner',
			array(
				'nonce' => wp_create_nonce( 'royalbr_backup_reminder_banner' ),
			)
		);
	}
}

new RoyalBR_Backup_Reminder_Banner();
