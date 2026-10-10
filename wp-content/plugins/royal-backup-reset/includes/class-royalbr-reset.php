<?php
/**
 * Reset Operations Handler
 *
 * Manages complete WordPress database reset with optional reactivation.
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database Reset Manager
 *
 * Provides complete site reset functionality using singleton pattern, maintaining admin access throughout.
 *
 * @since 1.0.0
 */
class ROYALBR_Reset {

	/**
	 * Holds the single class instance.
	 *
	 * @since 1.0.0
	 * @var   ROYALBR_Reset
	 */
	protected static $instance = null;

	/**
	 * Current plugin version number.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	public $version = '';

	/**
	 * Absolute filesystem path to plugin root.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	public $plugin_dir = '';

	/**
	 * Base URL for plugin assets and resources.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	public $plugin_url = '';

	/**
	 * Standard WordPress table names excluding database prefix.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	public $core_tables = array( 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'term_relationships', 'term_taxonomy', 'termmeta', 'terms', 'usermeta', 'users' );

	/**
	 * Cached configuration data from database options.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	protected $options = array();

	/**
	 * Tracks whether WP_Filesystem has been loaded.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	protected $filesystem_initialized = false;

	/**
	 * Running total of removed files and directories.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	protected $delete_count = 0;

	/**
	 * Retrieves or creates the single class instance.
	 *
	 * @since  1.0.0
	 * @return ROYALBR_Reset
	 */
	public static function get_instance() {
		if ( ! is_a( self::$instance, 'ROYALBR_Reset' ) ) {
			self::$instance = new ROYALBR_Reset();
		}

		return self::$instance;
	}

	/**
	 * Sets up plugin paths, version, and prepares core table list.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		global $wpdb;

		$this->version    = defined( 'ROYALBR_VERSION' ) ? ROYALBR_VERSION : '1.0';
		$this->plugin_dir = defined( 'ROYALBR_PLUGIN_DIR' ) ? ROYALBR_PLUGIN_DIR : plugin_dir_path( __FILE__ );
		$this->plugin_url = defined( 'ROYALBR_PLUGIN_URL' ) ? ROYALBR_PLUGIN_URL : plugin_dir_url( __FILE__ );

		$this->load_options();

		// Prepend database prefix to each table name.
		$this->core_tables = array_map(
			function ( $tbl ) use ( $wpdb ) {
				return $wpdb->prefix . $tbl;
			},
			$this->core_tables
		);
	}

	/**
	 * Initializes options from database with default structure.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	private function load_options() {
		$options = get_option( 'royalbr-reset', array() );
		$change  = false;

		if ( ! isset( $options['meta'] ) ) {
			$options['meta'] = array(
				'first_version'  => $this->version,
				'first_install'  => current_time( 'timestamp', true ),
				'reset_count'    => 0,
			);
			$change          = true;
		}

		if ( ! isset( $options['options'] ) ) {
			$options['options'] = array();
			$change             = true;
		}

		if ( $change ) {
			update_option( 'royalbr-reset', $options, true );
		}

		$this->options = $options;
		return $options;
	}

	/**
	 * Returns metadata section of stored configuration.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public function get_meta() {
		return $this->options['meta'];
	}

	/**
	 * Saves data to a specific configuration section.
	 *
	 * @since  1.0.0
	 * @param  string $key   Option key (meta, options).
	 * @param  mixed  $data  Data to save.
	 * @return bool
	 */
	public function update_options( $key, $data ) {
		if ( false === in_array( $key, array( 'meta', 'options' ), true ) ) {
			user_error( 'Unknown options key.', E_USER_ERROR );
			return false;
		}

		$this->options[ $key ] = $data;
		$tmp                   = update_option( 'royalbr-reset', $this->options );

		return $tmp;
	}

	/**
	 * Fetches the active theme's display name.
	 *
	 * @since  1.0.0
	 * @return string Active theme name.
	 */
	public function get_active_theme_name() {
		$theme = wp_get_theme();
		return $theme->get( 'Name' );
	}

	/**
	 * Builds array of currently active plugin display names.
	 *
	 * @since  1.0.0
	 * @return array Array of active plugin names.
	 */
	public function get_active_plugins_list() {
		$active_plugins = get_option( 'active_plugins', array() );
		$plugin_names   = array();

		foreach ( $active_plugins as $plugin_file ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
			if ( ! empty( $plugin_data['Name'] ) ) {
				$plugin_names[] = $plugin_data['Name'];
			}
		}

		return $plugin_names;
	}

	/**
	 * Executes complete site reset with optional reactivation features.
	 *
	 * @since  1.0.0
	 * @param  array $options Reset options (reactivate_theme, reactivate_plugins, keep_royalbr_active).
	 * @return array Result array with success status and message.
	 */
	public function reset_database( $options = array() ) {
		global $current_user, $wpdb, $royalbr_instance;

		// Verify user has administrative privileges.
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'You do not have permission to perform this action.', 'royal-backup-reset' ),
			);
		}

		// Load WordPress installation utilities if needed.
		if ( ! function_exists( 'wp_install' ) ) {
			require ABSPATH . '/wp-admin/includes/upgrade.php';
		}

		// Log initial stage to progress tracking.
		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
			$royalbr_instance->log_restore_update( array(
				'type'  => 'state',
				'stage' => 'reset_preparing',
				'data'  => array( 'message' => __( 'Preparing reset...', 'royal-backup-reset' ) )
			) );
		}

		// Capture essential settings before database wipe.
		$blogname    = get_option( 'blogname' );
		$blog_public = get_option( 'blog_public' );
		$wplang      = get_option( 'wplang' );
		$siteurl     = get_option( 'siteurl' );
		$home        = get_option( 'home' );

		// Store plugin-specific data for restoration.
		$backup_history            = get_option( 'royalbr_backup_history', array() );
		$backup_display_names      = get_option( 'royalbr_backup_display_names', array() );
		$migration_upload_nonces   = get_option( 'royalbr_migration_upload_nonces', array() );

		// Cache all user preference settings for later restoration.
		$royalbr_backup_include_db     = get_option( 'royalbr_backup_include_db' );
		$royalbr_backup_include_files  = get_option( 'royalbr_backup_include_files' );
		$royalbr_backup_include_wpcore = get_option( 'royalbr_backup_include_wpcore' );
		$royalbr_restore_db            = get_option( 'royalbr_restore_db' );
		$royalbr_restore_plugins      = get_option( 'royalbr_restore_plugins' );
		$royalbr_restore_themes       = get_option( 'royalbr_restore_themes' );
		$royalbr_restore_uploads      = get_option( 'royalbr_restore_uploads' );
		$royalbr_restore_others       = get_option( 'royalbr_restore_others' );
		$royalbr_reactivate_theme     = get_option( 'royalbr_reactivate_theme' );
		$royalbr_reactivate_plugins   = get_option( 'royalbr_reactivate_plugins' );
		$royalbr_keep_royalbr_active      = get_option( 'royalbr_keep_royalbr_active' );
		$royalbr_clear_uploads        = get_option( 'royalbr_clear_uploads' );
		$royalbr_clear_media          = get_option( 'royalbr_clear_media' );
		$royalbr_reminder_popup_mode  = get_option( 'royalbr_reminder_popup_mode' );
		$royalbr_interval_files       = get_option( 'royalbr_interval_files' );
		$royalbr_interval_database    = get_option( 'royalbr_interval_database' );
		$royalbr_retain_files         = get_option( 'royalbr_retain_files' );
		$royalbr_retain_db            = get_option( 'royalbr_retain_db' );

		// Preserve Freemius options to maintain license and opt-in state.
		// Note: fs_active_plugins is NOT preserved - it contains runtime SDK path data
		// that Freemius auto-regenerates, and restoring it can cause free/premium version conflicts.
		$fs_accounts  = get_option( 'fs_accounts' );
		$fs_gdpr      = get_option( 'fs_gdpr' );
		$fs_api_cache = get_option( 'fs_api_cache' );
		$fs_options   = get_option( 'fs_options' );

		// Preserve rating notice options.
		$royalbr_activation_time   = get_option( 'royalbr_activation_time' );
		$royalbr_maybe_later_time  = get_option( 'royalbr_maybe_later_time' );
		$royalbr_rating_dismissed  = get_option( 'royalbr_rating_dismissed' );
		$royalbr_already_rated     = get_option( 'royalbr_already_rated' );
		$royalbr_has_restored      = get_option( 'royalbr_has_restored' );

		// Preserve backup reminder banner options.
		$royalbr_backup_reminder_banner_dismissed  = get_option( 'royalbr_backup_reminder_banner_dismissed' );
		$royalbr_backup_reminder_banner_later_time = get_option( 'royalbr_backup_reminder_banner_later_time' );

		// Preserve backup location options.
		$royalbr_backup_loc_local     = get_option( 'royalbr_backup_loc_local' );
		$royalbr_backup_loc_gdrive    = get_option( 'royalbr_backup_loc_gdrive' );
		$royalbr_backup_loc_dropbox   = get_option( 'royalbr_backup_loc_dropbox' );
		$royalbr_backup_loc_s3        = get_option( 'royalbr_backup_loc_s3' );
		$royalbr_gdrive_folder_name   = get_option( 'royalbr_gdrive_folder_name' );
		$royalbr_gdrive_refresh_token = get_option( 'royalbr_gdrive_refresh_token' );

		// Preserve S3 credentials.
		$royalbr_s3_access_key = get_option( 'royalbr_s3_access_key' );
		$royalbr_s3_secret_key = get_option( 'royalbr_s3_secret_key' );
		$royalbr_s3_location   = get_option( 'royalbr_s3_location' );
		$royalbr_s3_bucket     = get_option( 'royalbr_s3_bucket' );
		$royalbr_s3_region     = get_option( 'royalbr_s3_region' );
		$royalbr_s3_path       = get_option( 'royalbr_s3_path' );

		// Preserve Dropbox credentials.
		$royalbr_dropbox_auth_code  = get_option( 'royalbr_dropbox_auth_code' );
		$royalbr_dropbox_site_token = get_option( 'royalbr_dropbox_site_token' );

		// Retrieve temporarily stored list of active plugins and theme.
		$active_plugins = get_transient( 'royalbr_active_plugins' );
		$active_theme   = wp_get_theme();

		// Ensure logged-in user data is accessible.
		if ( ! $current_user->ID ) {
			return array(
				'success' => false,
				'error'   => __( 'Reset failed. Unable to find current user.', 'royal-backup-reset' ),
			);
		}

		// Report table removal stage to progress log.
		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
			$royalbr_instance->log_restore_update( array(
				'type'  => 'state',
				'stage' => 'reset_dropping',
				'data'  => array( 'message' => __( 'Dropping database tables...', 'royal-backup-reset' ) )
			) );
		}

		// Remove all tables matching the WordPress prefix.
		$prefix = str_replace( '_', '\_', $wpdb->prefix );
		// SHOW TABLES is a MySQL command with no WordPress equivalent; caching is inappropriate as table list changes during reset.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', array( $prefix . '%' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $tables as $table ) {
			$wpdb->royalbr_table = $table;
			$wpdb->query( 'DROP TABLE ' . $wpdb->royalbr_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

			// Send individual table progress to log.
			if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
				$royalbr_instance->log_restore_update( array(
					'type'  => 'state',
					'stage' => 'reset_dropping',
					'data'  => array( 'table' => $table )
				) );
			}
		}

		// Preserve existing password hash for restoration.
		$old_user_pass = $current_user->user_pass;

		// Report database recreation stage to progress log.
		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
			$royalbr_instance->log_restore_update( array(
				'type'  => 'state',
				'stage' => 'reset_creating',
				'data'  => array( 'message' => __( 'Creating fresh database...', 'royal-backup-reset' ) )
			) );
		}

		// Execute fresh WordPress installation with error suppression.
		$result = @wp_install( $blogname, $current_user->user_login, $current_user->user_email, $blog_public, '', md5( wp_rand() ), $wplang );

		// Handle installation failures.
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				/* translators: %s: Error message describing why the database reset failed */
				'error'   => sprintf( __( 'Database reset failed: %s', 'royal-backup-reset' ), $result->get_error_message() ),
			);
		}

		$user_id = $result['user_id'];

		// Reapply original password to maintain login session.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->users} SET user_pass = %s, user_activation_key = %s WHERE ID = %d LIMIT 1", array( $old_user_pass, '', $user_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current_user->user_pass = $old_user_pass;

		// Reapply core WordPress and plugin configurations.
		update_option( 'siteurl', $siteurl );
		update_option( 'home', $home );
		update_option( 'royalbr-reset', $this->options );
		update_option( 'royalbr_backup_history', $backup_history );
		update_option( 'royalbr_backup_display_names', $backup_display_names );
		update_option( 'royalbr_migration_upload_nonces', $migration_upload_nonces );

		// Restore each user preference if previously set.
		if ( false !== $royalbr_backup_include_db ) {
			update_option( 'royalbr_backup_include_db', $royalbr_backup_include_db );
		}
		if ( false !== $royalbr_backup_include_files ) {
			update_option( 'royalbr_backup_include_files', $royalbr_backup_include_files );
		}
		if ( false !== $royalbr_backup_include_wpcore ) {
			update_option( 'royalbr_backup_include_wpcore', $royalbr_backup_include_wpcore );
		}
		if ( false !== $royalbr_restore_db ) {
			update_option( 'royalbr_restore_db', $royalbr_restore_db );
		}
		if ( false !== $royalbr_restore_plugins ) {
			update_option( 'royalbr_restore_plugins', $royalbr_restore_plugins );
		}
		if ( false !== $royalbr_restore_themes ) {
			update_option( 'royalbr_restore_themes', $royalbr_restore_themes );
		}
		if ( false !== $royalbr_restore_uploads ) {
			update_option( 'royalbr_restore_uploads', $royalbr_restore_uploads );
		}
		if ( false !== $royalbr_restore_others ) {
			update_option( 'royalbr_restore_others', $royalbr_restore_others );
		}
		if ( false !== $royalbr_reactivate_theme ) {
			update_option( 'royalbr_reactivate_theme', $royalbr_reactivate_theme );
		}
		if ( false !== $royalbr_reactivate_plugins ) {
			update_option( 'royalbr_reactivate_plugins', $royalbr_reactivate_plugins );
		}
		if ( false !== $royalbr_keep_royalbr_active ) {
			update_option( 'royalbr_keep_royalbr_active', $royalbr_keep_royalbr_active );
		}
		if ( false !== $royalbr_clear_uploads ) {
			update_option( 'royalbr_clear_uploads', $royalbr_clear_uploads );
		}
		if ( false !== $royalbr_clear_media ) {
			update_option( 'royalbr_clear_media', $royalbr_clear_media );
		}
		if ( false !== $royalbr_reminder_popup_mode ) {
			update_option( 'royalbr_reminder_popup_mode', $royalbr_reminder_popup_mode );
		}
		if ( false !== $royalbr_interval_files ) {
			update_option( 'royalbr_interval_files', $royalbr_interval_files );
		}
		if ( false !== $royalbr_interval_database ) {
			update_option( 'royalbr_interval_database', $royalbr_interval_database );
		}
		if ( false !== $royalbr_retain_files ) {
			update_option( 'royalbr_retain_files', $royalbr_retain_files );
		}
		if ( false !== $royalbr_retain_db ) {
			update_option( 'royalbr_retain_db', $royalbr_retain_db );
		}

		// Disable default password change notification.
		if ( get_user_meta( $user_id, 'default_password_nag' ) ) {
			update_user_meta( $user_id, 'default_password_nag', false );
		}
		if ( get_user_meta( $user_id, $wpdb->prefix . 'default_password_nag' ) ) {
			update_user_meta( $user_id, $wpdb->prefix . 'default_password_nag', false );
		}

		// Track total number of resets performed.
		$meta = $this->get_meta();
		$meta['reset_count']++;
		$this->update_options( 'meta', $meta );

		// Restore previously active theme if requested.
		if ( ! empty( $options['reactivate_theme'] ) ) {
			// Report theme reactivation to progress log.
			if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
				$royalbr_instance->log_restore_update( array(
					'type'  => 'state',
					'stage' => 'reset_reactivating',
					'data'  => array( 'message' => __( 'Reactivating theme...', 'royal-backup-reset' ) )
				) );
			}
			switch_theme( $active_theme->get_stylesheet() );
		}

		// Ensure this plugin remains active if configured.
		if ( ! empty( $options['keep_royalbr_active'] ) ) {
			activate_plugin( plugin_basename( ROYALBR_PLUGIN_DIR . 'royal-backup-reset.php' ) );
		}

		// Restore previously active plugins if requested.
		if ( ! empty( $options['reactivate_plugins'] ) && ! empty( $active_plugins ) ) {
			// Report plugin reactivation to progress log.
			if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
				$royalbr_instance->log_restore_update( array(
					'type'  => 'state',
					'stage' => 'reset_reactivating',
					'data'  => array( 'message' => __( 'Reactivating plugins...', 'royal-backup-reset' ) )
				) );
			}
			foreach ( $active_plugins as $plugin_file ) {
				// Avoid duplicate activation of this plugin.
				if ( ! empty( $options['keep_royalbr_active'] ) && strpos( $plugin_file, 'royal-backup-reset.php' ) !== false ) {
					continue;
				}
				activate_plugin( $plugin_file );
			}
		}

		// Restore Freemius options AFTER plugin activation to prevent SDK from resetting them.
		// The Freemius SDK activation hook can reset anonymous mode when re-activating the same
		// plugin version, so we restore our captured values after all activations are complete.
		// Note: fs_active_plugins is NOT restored - see comment at capture point above.
		if ( false !== $fs_accounts ) {
			update_option( 'fs_accounts', $fs_accounts );
		}
		if ( false !== $fs_gdpr ) {
			update_option( 'fs_gdpr', $fs_gdpr );
		}
		if ( false !== $fs_api_cache ) {
			update_option( 'fs_api_cache', $fs_api_cache );
		}
		if ( false !== $fs_options ) {
			update_option( 'fs_options', $fs_options );
		}

		// Restore rating notice options.
		if ( false !== $royalbr_activation_time ) {
			update_option( 'royalbr_activation_time', $royalbr_activation_time );
		}
		if ( false !== $royalbr_maybe_later_time ) {
			update_option( 'royalbr_maybe_later_time', $royalbr_maybe_later_time );
		}
		if ( false !== $royalbr_rating_dismissed ) {
			update_option( 'royalbr_rating_dismissed', $royalbr_rating_dismissed );
		}
		if ( false !== $royalbr_already_rated ) {
			update_option( 'royalbr_already_rated', $royalbr_already_rated );
		}
		if ( false !== $royalbr_has_restored ) {
			update_option( 'royalbr_has_restored', $royalbr_has_restored );
		}

		// Restore backup reminder banner options.
		if ( false !== $royalbr_backup_reminder_banner_dismissed ) {
			update_option( 'royalbr_backup_reminder_banner_dismissed', $royalbr_backup_reminder_banner_dismissed );
		}
		if ( false !== $royalbr_backup_reminder_banner_later_time ) {
			update_option( 'royalbr_backup_reminder_banner_later_time', $royalbr_backup_reminder_banner_later_time );
		}

		// Restore backup location options.
		if ( false !== $royalbr_backup_loc_local ) {
			update_option( 'royalbr_backup_loc_local', $royalbr_backup_loc_local );
		}
		if ( false !== $royalbr_backup_loc_gdrive ) {
			update_option( 'royalbr_backup_loc_gdrive', $royalbr_backup_loc_gdrive );
		}
		if ( false !== $royalbr_backup_loc_dropbox ) {
			update_option( 'royalbr_backup_loc_dropbox', $royalbr_backup_loc_dropbox );
		}
		if ( false !== $royalbr_backup_loc_s3 ) {
			update_option( 'royalbr_backup_loc_s3', $royalbr_backup_loc_s3 );
		}
		if ( false !== $royalbr_gdrive_folder_name ) {
			update_option( 'royalbr_gdrive_folder_name', $royalbr_gdrive_folder_name );
		}
		if ( false !== $royalbr_gdrive_refresh_token ) {
			update_option( 'royalbr_gdrive_refresh_token', $royalbr_gdrive_refresh_token );
		}

		// Restore S3 credentials.
		if ( false !== $royalbr_s3_access_key ) {
			update_option( 'royalbr_s3_access_key', $royalbr_s3_access_key );
		}
		if ( false !== $royalbr_s3_secret_key ) {
			update_option( 'royalbr_s3_secret_key', $royalbr_s3_secret_key );
		}
		if ( false !== $royalbr_s3_location ) {
			update_option( 'royalbr_s3_location', $royalbr_s3_location );
		}
		if ( false !== $royalbr_s3_bucket ) {
			update_option( 'royalbr_s3_bucket', $royalbr_s3_bucket );
		}
		if ( false !== $royalbr_s3_region ) {
			update_option( 'royalbr_s3_region', $royalbr_s3_region );
		}
		if ( false !== $royalbr_s3_path ) {
			update_option( 'royalbr_s3_path', $royalbr_s3_path );
		}

		// Restore Dropbox credentials.
		if ( false !== $royalbr_dropbox_auth_code ) {
			update_option( 'royalbr_dropbox_auth_code', $royalbr_dropbox_auth_code );
		}
		if ( false !== $royalbr_dropbox_site_token ) {
			update_option( 'royalbr_dropbox_site_token', $royalbr_dropbox_site_token );
		}

		// Delete uploads directory contents based on selected options.
		$is_premium = function_exists( 'royalbr_fs' ) && royalbr_fs()->can_use_premium_code();

		// Pro option: Clean entire uploads directory.
		if ( ! empty( $options['clear_uploads'] ) && $is_premium ) {
			// Report uploads cleanup to progress log.
			if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
				$royalbr_instance->log_restore_update( array(
					'type'  => 'state',
					'stage' => 'reset_cleaning',
					'data'  => array( 'message' => __( 'Clearing uploads folder...', 'royal-backup-reset' ) )
				) );
			}
			$this->do_delete_uploads();
		} elseif ( ! empty( $options['clear_media'] ) ) {
			// Free option: Clear only media folders (YYYY/MM pattern).
			if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
				$royalbr_instance->log_restore_update( array(
					'type'  => 'state',
					'stage' => 'reset_cleaning',
					'data'  => array( 'message' => __( 'Clearing media files...', 'royal-backup-reset' ) )
				) );
			}
			$this->do_delete_media_uploads();
		}

		// Regenerate authentication cookies for the session.
		wp_clear_auth_cookie();
		wp_set_auth_cookie( $user_id );

		// Report completion to progress log.
		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
			$royalbr_instance->log_restore_update( array(
				'type'  => 'state',
				'stage' => 'reset_finished',
				'data'  => array( 'message' => __( 'Reset complete!', 'royal-backup-reset' ) )
			) );
		}

		// Flush all cached data and opcodes.
		$this->clear_all_caches();

		// Send success response to caller.
		return array(
			'success' => true,
			'message' => __( 'Database reset completed successfully. You are still logged in as the administrator.', 'royal-backup-reset' ),
		);
	}

	/**
	 * Purges all WordPress and plugin caching layers.
	 *
	 * @since 1.0.0
	 */
	private function clear_all_caches() {
		// Flush WordPress internal object cache.
		wp_cache_flush();

		// Regenerate permalink rewrite rules.
		flush_rewrite_rules();

		// Reset PHP opcode cache if available.
		if ( function_exists( 'opcache_reset' ) ) {
			opcache_reset();
		}

		// Trigger cache clearing for popular caching plugins.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// Trigger .htaccess regeneration on next load.
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Loads WP_Filesystem API for file operations.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	private function wp_init_filesystem() {
		if ( ! $this->filesystem_initialized ) {
			if ( ! class_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			WP_Filesystem();
			$this->filesystem_initialized = true;
		}

		return true;
	}

	/**
	 * Removes all contents from the uploads directory.
	 *
	 * @since  1.0.0
	 * @return int Number of deleted files and folders.
	 */
	public function do_delete_uploads() {
		global $wp_filesystem;
		$this->wp_init_filesystem();

		$upload_dir         = wp_get_upload_dir();
		$this->delete_count = 0;

		if ( $wp_filesystem->is_dir( $upload_dir['basedir'] ) ) {

			// Retrieve directory listing for deletion.
			$files = $wp_filesystem->dirlist( $upload_dir['basedir'] );

			// Recursively remove each file and subdirectory.
			foreach ( $files as $file => $details ) {
				$file_path = trailingslashit( $upload_dir['basedir'] ) . $file;
				$wp_filesystem->delete( $file_path, true );
				$this->delete_count++;
			}
		}

		do_action( 'royalbr_delete_uploads', $this->delete_count );

		return $this->delete_count;
	}

	/**
	 * Removes only WordPress media folders (YYYY/MM pattern) from the uploads directory.
	 * Preserves plugin folders like wpcode/, elementor/, etc.
	 *
	 * @since  1.0.0
	 * @return int Number of deleted year folders.
	 */
	public function do_delete_media_uploads() {
		global $wp_filesystem;
		$this->wp_init_filesystem();

		$upload_dir         = wp_get_upload_dir();
		$this->delete_count = 0;

		if ( $wp_filesystem->is_dir( $upload_dir['basedir'] ) ) {

			// Retrieve directory listing.
			$items = $wp_filesystem->dirlist( $upload_dir['basedir'] );

			// Only delete year folders (4-digit directories like 2024, 2025).
			foreach ( $items as $item => $details ) {
				if ( 'd' === $details['type'] && preg_match( '/^\d{4}$/', $item ) ) {
					$file_path = trailingslashit( $upload_dir['basedir'] ) . $item;
					$wp_filesystem->delete( $file_path, true );
					$this->delete_count++;
				}
			}
		}

		do_action( 'royalbr_delete_media_uploads', $this->delete_count );

		return $this->delete_count;
	}
}
