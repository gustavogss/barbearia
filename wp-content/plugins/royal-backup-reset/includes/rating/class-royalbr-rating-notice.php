<?php
/**
 * Rating Notice Class
 *
 * Displays a rating prompt to administrators after 3 backups exist in history
 * or after the first successful restore.
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RoyalBR_Rating_Notice
 *
 * Handles the display and dismissal of plugin rating notices.
 *
 * @since 1.0.0
 */
class RoyalBR_Rating_Notice {

	/**
	 * Constructor.
	 *
	 * Sets up hooks for rating notice functionality.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'init_rating_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'royalbr_restore_completed', array( $this, 'mark_restore_completed' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_royalbr_rating_dismiss', array( $this, 'dismiss_notice' ) );
		add_action( 'wp_ajax_royalbr_rating_maybe_later', array( $this, 'maybe_later' ) );
		add_action( 'wp_ajax_royalbr_rating_already_rated', array( $this, 'already_rated' ) );
	}

	/**
	 * Initialize rating notice on admin_init when user functions are available.
	 *
	 * @since 1.0.0
	 */
	public function init_rating_notice() {
		if ( ! current_user_can( 'administrator' ) ) {
			return;
		}

		if ( ! empty( get_option( 'royalbr_rating_dismissed', false ) ) || ! empty( get_option( 'royalbr_already_rated', false ) ) ) {
			return;
		}

		$has_restored   = ! empty( get_option( 'royalbr_has_restored', false ) );
		$backup_history = get_option( 'royalbr_backup_history', array() );
		$backups        = isset( $backup_history['backups'] ) ? $backup_history['backups'] : array();

		if ( ! $has_restored && count( $backups ) < 3 ) {
			return;
		}

		$this->check_display_conditions();
	}

	/**
	 * Set flag when a restore completes successfully.
	 *
	 * @since 1.0.0
	 */
	public function mark_restore_completed() {
		update_option( 'royalbr_has_restored', true );
	}

	/**
	 * Check if conditions are met to display the rating notice.
	 *
	 * @since 1.0.0
	 */
	public function check_display_conditions() {
		$maybe_later_time = get_option( 'royalbr_maybe_later_time' );

		if ( false === $maybe_later_time ) {
			add_action( 'admin_notices', array( $this, 'render_notice' ) );
		} elseif ( strtotime( '-7 days' ) >= $maybe_later_time ) {
			add_action( 'admin_notices', array( $this, 'render_notice' ) );
		}
	}

	/**
	 * Handle "Maybe Later" button click.
	 *
	 * @since 1.0.0
	 */
	public function maybe_later() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'royalbr_rating_notice' ) ) {
			wp_die();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		update_option( 'royalbr_maybe_later_time', strtotime( 'now' ) );
		wp_die();
	}

	/**
	 * Handle "Already Rated" button click.
	 *
	 * @since 1.0.0
	 */
	public function already_rated() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'royalbr_rating_notice' ) ) {
			wp_die();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		update_option( 'royalbr_already_rated', true );
		wp_die();
	}

	/**
	 * Handle notice dismiss button click.
	 *
	 * @since 1.0.0
	 */
	public function dismiss_notice() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'royalbr_rating_notice' ) ) {
			wp_die();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		update_option( 'royalbr_rating_dismissed', true );
		wp_die();
	}

	/**
	 * Render the rating notice HTML.
	 *
	 * @since 1.0.0
	 */
	public function render_notice() {
		if ( ! is_admin() ) {
			return;
		}

		$review_url = 'https://wordpress.org/support/plugin/royal-backup-reset/reviews/#new-post';
		$logo_url   = ROYALBR_ASSETS_URL . 'images/logo.png';
		?>
		<div class="notice royalbr-rating-notice is-dismissible" style="border-left-color: #7A75FF!important;">
			<div class="royalbr-rating-notice-logo">
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
			</div>
			<div class="royalbr-rating-notice-content">
				<h3><?php esc_html_e( 'Thank you for using Royal Backup & Reset!', 'royal-backup-reset' ); ?></h3>
				<p><?php esc_html_e( 'Could you please do us a BIG favor and give it a 5-star rating on WordPress? Just to help us spread the word and boost our motivation.', 'royal-backup-reset' ); ?></p>
				<p class="royalbr-rating-notice-buttons">
					<a href="<?php echo esc_url( $review_url ); ?>" target="_blank" class="button button-primary royalbr-rate-now">
						<?php esc_html_e( 'OK, you deserve it!', 'royal-backup-reset' ); ?>
					</a>
					<a href="#" class="royalbr-maybe-later">
						<span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Maybe Later', 'royal-backup-reset' ); ?>
					</a>
					<a href="#" class="royalbr-already-rated">
						<span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'I Already did', 'royal-backup-reset' ); ?>
					</a>
				</p>
			</div>
			<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'royal-backup-reset' ); ?></span></button>
		</div>
		<?php
	}

	/**
	 * Enqueue rating notice scripts.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		if ( ! current_user_can( 'administrator' ) ) {
			return;
		}

		if ( ! empty( get_option( 'royalbr_rating_dismissed', false ) ) || ! empty( get_option( 'royalbr_already_rated', false ) ) ) {
			return;
		}

		wp_enqueue_script(
			'royalbr-rating-notice',
			ROYALBR_ASSETS_URL . 'rating-notice.js',
			array( 'jquery' ),
			ROYALBR_VERSION,
			true
		);

		wp_localize_script(
			'royalbr-rating-notice',
			'royalbrRatingNotice',
			array(
				'nonce' => wp_create_nonce( 'royalbr_rating_notice' ),
			)
		);
	}
}

new RoyalBR_Rating_Notice();
