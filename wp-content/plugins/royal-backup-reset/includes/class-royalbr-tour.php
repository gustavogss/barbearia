<?php
/**
 * Tour Manager Class
 *
 * Adds the guided tour when activating the plugin for the first time.
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ROYALBR_Tour
 *
 * Manages the welcome modal and guided tour functionality.
 */
class ROYALBR_Tour {

	/**
	 * The class instance
	 *
	 * @var object
	 */
	protected static $instance;

	/**
	 * Get the instance
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
	}

	/**
	 * Sets up the notices, security and loads assets for the admin page
	 */
	public function init() {
		// Add plugin action link for "Take Tour"
		// add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );

		// Only init and load assets if the tour hasn't been canceled
		if ( isset( $_REQUEST['royalbr_tour'] ) && 0 === (int) $_REQUEST['royalbr_tour'] ) {
			$this->set_tour_status( array( 'current_step' => 'start' ) );
			update_option( 'royalbr_tour_cancelled_on', time() );
			return;
		}

		// If backups already exist and tour was not explicitly requested
		if ( $this->royalbr_was_already_installed() && ! isset( $_REQUEST['royalbr_tour'] ) ) {
			return;
		}

		// If 'Take tour' link was used, reset tour
		if ( isset( $_REQUEST['royalbr_tour'] ) && 1 === (int) $_REQUEST['royalbr_tour'] ) {
			$this->reset_tour_status();
		}

		if ( ! get_option( 'royalbr_tour_cancelled_on' ) || isset( $_REQUEST['royalbr_tour'] ) ) {
			// add_action( 'admin_enqueue_scripts', array( $this, 'load_tour' ) );
		}
	}

	/**
	 * Loads in tour assets
	 *
	 * @param string $hook Current page.
	 */
	public function load_tour( $hook ) {

		$pages = array( 'toplevel_page_royal-backup-reset', 'plugins.php' );

		if ( ! in_array( $hook, $pages, true ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script( 'royalbr-tether-js', ROYALBR_ASSETS_URL . 'tether/tether.min.js', array(), ROYALBR_VERSION, true );
		wp_enqueue_script( 'royalbr-shepherd-js', ROYALBR_ASSETS_URL . 'tether-shepherd/shepherd.min.js', array( 'royalbr-tether-js' ), ROYALBR_VERSION, true );
		wp_enqueue_style( 'royalbr-shepherd-css', ROYALBR_ASSETS_URL . 'shepherd-theme-arrows-plain-buttons.min.css', false, ROYALBR_VERSION );
		wp_enqueue_style( 'royalbr-tour-css', ROYALBR_ASSETS_URL . 'tour.css', false, ROYALBR_VERSION );
		wp_register_script( 'royalbr-tour-js', ROYALBR_ASSETS_URL . 'tour.js', array( 'royalbr-tether-js' ), ROYALBR_VERSION, true );

		$tour_data = array(
			'nonce'              => wp_create_nonce( 'royalbr-tour-nonce' ),
			'next'               => esc_html__( 'Next', 'royal-backup-reset' ),
			'back'               => esc_html__( 'Back', 'royal-backup-reset' ),
			'skip'               => esc_html__( 'Skip this step', 'royal-backup-reset' ),
			'end_tour'           => esc_html__( 'End tour', 'royal-backup-reset' ),
			'close'              => esc_html__( 'Close', 'royal-backup-reset' ),
			'finish'             => esc_html__( 'Finish', 'royal-backup-reset' ),
			'plugins_page'       => array(
				'title'  => esc_html__( 'Royal Backup, Restore & Reset settings', 'royal-backup-reset' ),
				'text'   => '<div class="royalbr-welcome-logo"><strong>' . esc_html__( 'Welcome to Royal Backup, Restore & Reset', 'royal-backup-reset' ) . '</strong>, ' . esc_html__( 'your complete backup, restore and reset solution!', 'royal-backup-reset' ) . '</div>',
				'button' => array(
					'url'  => admin_url( 'admin.php?page=royal-backup-reset' ),
					'text' => esc_html__( 'Press here to start!', 'royal-backup-reset' ),
				),
			),
			'backup_now'         => array(
				'title' => esc_html__( 'Your first backup', 'royal-backup-reset' ),
				'text'  => esc_html__( 'Click here to create your first backup and protect your website!', 'royal-backup-reset' ),
			),
			'restore_tab'        => array(
				'title' => esc_html__( 'Restore your website', 'royal-backup-reset' ),
				'text'  => esc_html__( 'Use this tab to restore your website from a previous backup.', 'royal-backup-reset' ),
			),
			'reset_tab'          => array(
				'title' => esc_html__( 'Reset database', 'royal-backup-reset' ),
				'text'  => esc_html__( 'This tab allows you to reset your database to a fresh state.', 'royal-backup-reset' ),
			),
			'reset_button'       => array(
				'title' => esc_html__( 'Reset database button', 'royal-backup-reset' ),
				'text'  => esc_html__( 'Click here to reset your database. Be careful - this action cannot be undone!', 'royal-backup-reset' ),
			),
			'settings_tab'       => array(
				'title' => esc_html__( 'Plugin settings', 'royal-backup-reset' ),
				'text'  => esc_html__( 'Configure your backup and reset preferences in the settings tab.', 'royal-backup-reset' ),
			),
			'admin_bar_backup'   => array(
				'title' => esc_html__( 'Quick backup', 'royal-backup-reset' ),
				'text'  => esc_html__( 'Use this backup icon in the admin bar for quick access to create backups from anywhere!', 'royal-backup-reset' ),
			),
			'admin_bar_reset'    => array(
				'title' => esc_html__( 'Quick reset', 'royal-backup-reset' ),
				'text'  => esc_html__( 'Use this reset icon in the admin bar for quick access to reset your database from anywhere!', 'royal-backup-reset' ),
			),
		);

		wp_localize_script( 'royalbr-tour-js', 'royalbr_tour_i18n', $tour_data );
		wp_enqueue_script( 'royalbr-tour-js' );
	}

	/**
	 * Adds "Take Tour" link to plugin action links on Plugins page.
	 *
	 * @param  array  $links Set of links for the plugin, before being filtered.
	 * @param  string $file  File name (relative to the plugin directory).
	 * @return array  Filtered results.
	 */
	public function plugin_action_links( $links, $file ) {
		if ( is_array( $links ) && plugin_basename( ROYALBR_PLUGIN_FILE ) === $file ) {
			$links['royalbr_tour'] = '<a href="' . esc_url( admin_url( 'admin.php?page=royal-backup-reset&royalbr_tour=1' ) ) . '" class="js-royalbr-tour">' . esc_html__( 'Take Tour', 'royal-backup-reset' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Checks if plugin was newly installed.
	 *
	 * Checks if there are backups, and if there are more than 1,
	 * checks if the folder is older than 1 day old
	 *
	 * @return bool
	 */
	public function royalbr_was_already_installed() {
		// If backups already exist
		$backup_history = ROYALBR_Backup_History::get_history();

		// No backup history
		if ( ! $backup_history || ! is_array( $backup_history ) ) {
			return false;
		}

		// Check if backups exist in the structure
		if ( ! isset( $backup_history['backups'] ) || ! is_array( $backup_history['backups'] ) ) {
			return false;
		}

		if ( 0 === count( $backup_history['backups'] ) ) {
			return false;
		}

		// If there is at least 1 backup, check if the oldest is older than 1 day
		if ( isset( $backup_history['index']['by_timestamp'] ) && is_array( $backup_history['index']['by_timestamp'] ) ) {
			$timestamps = array_keys( $backup_history['index']['by_timestamp'] );
			if ( ! empty( $timestamps ) ) {
				// Get the last timestamp (oldest)
				$last_timestamp = end( $timestamps );
				// Ensure it's numeric before doing arithmetic
				if ( is_numeric( $last_timestamp ) ) {
					$last_backup_age = time() - (int) $last_timestamp;
					if ( DAY_IN_SECONDS < $last_backup_age ) {
						// The oldest backup is older than 1 day old, so it's likely that the plugin was already installed
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Sets tour status option
	 *
	 * @param array $status Tour status data.
	 */
	private function set_tour_status( $status ) {
		update_option( 'royalbr_tour_status', $status );
	}

	/**
	 * Resets tour status
	 */
	private function reset_tour_status() {
		delete_option( 'royalbr_tour_cancelled_on' );
		delete_option( 'royalbr_tour_status' );
	}
}

add_action( 'admin_init', array( ROYALBR_Tour::get_instance(), 'init' ) );
