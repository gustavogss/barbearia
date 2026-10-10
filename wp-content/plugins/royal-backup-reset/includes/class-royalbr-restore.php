<?php
/**
 * Handles complete backup restoration process including database and file recovery
 *
 * @package RoyalBackupReset
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ROYALBR_INCLUDES_DIR . 'database/class-royalbr-database-utility.php';

/**
 * Custom upgrader skin for silent operations with activity logging
 *
 * @since 2.0.0
 */
if ( ! class_exists( 'WP_Upgrader_Skin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
}

class ROYALBR_Silent_Skin extends WP_Upgrader_Skin {

	// @codingStandardsIgnoreStart
	public function header() {}
	public function footer() {}
	public function bulk_header() {}
	public function bulk_footer() {}
	// @codingStandardsIgnoreEnd

	/**
	 * Logs error messages to restoration activity log
	 *
	 * @param string|WP_Error $error Error details as string or WP_Error object
	 * @return void
	 */
	public function error( $error ) {
		if ( ! $error ) {
			return;
		}
		$royalbr = $GLOBALS['royalbr_instance'];
		if ( is_wp_error( $error ) ) {
			$royalbr->log_e( $error );
		} elseif ( is_string( $error ) ) {
			$royalbr->log( $error );
			$royalbr->log( $error, 'notice-progress' );
		}
	}

	/**
	 * Sends feedback messages to restoration activity log with sprintf support
	 *
	 * @param string $string     Message text or translation key
	 * @param mixed  ...$args    Format arguments for sprintf
	 * @return void
	 */
	public function feedback( $string, ...$args ) {
		// Translate message key to actual string if available
		if ( isset( $this->upgrader->strings[ $string ] ) ) {
			$string = $this->upgrader->strings[ $string ];
		}

		// Process sprintf arguments if placeholders exist
		if ( false !== strpos( $string, '%' ) ) {
			if ( $args ) {
				$args = array_map( 'strip_tags', $args );
				$args = array_map( 'esc_html', $args );
				$string = vsprintf( $string, $args );
			}
		}
		if ( empty( $string ) ) {
			return;
		}

		// Send to main plugin instance log
		$royalbr = $GLOBALS['royalbr_instance'];
		$royalbr->log_e( $string );
	}
}

/**
 * Extended wpdb class providing direct access to underlying database connection handle
 *
 * @since 2.0.0
 */
class ROYALBR_WPDB extends wpdb {

	/**
	 * Retrieves raw database connection handle for direct query operations
	 *
	 * @return mysqli|resource Native database connection object or resource
	 */
	public function royalbr_get_database_handle() {
		return $this->dbh;
	}

	/**
	 * Determines whether mysqli extension is being used instead of legacy mysql
	 *
	 * @return bool True if mysqli, false for legacy mysql
	 */
	public function royalbr_use_mysqli() {
		return $this->dbh instanceof mysqli;
	}
}

/**
 * Main restoration handler managing complete backup recovery operations
 *
 * @since 2.0.0
 */
class ROYALBR_Restore {

	// ========================================================================
	// PROPERTIES
	// ========================================================================

	/**
	 * Main plugin instance reference used for logging restoration activities
	 *
	 * @var Royal_Backup_Reset
	 */
	private $royalbr_instance;

	/**
	 * Cached multisite status check result
	 *
	 * @var bool
	 */
	private $is_multisite;

	/**
	 * Registry of successfully restored entities for multi-archive backup sets
	 *
	 * @var array
	 */
	public $been_restored = array();

	/**
	 * Collection of table names that have been dropped during restoration
	 *
	 * @var array
	 */
	private $tables_been_dropped = array();

	/**
	 * Flag controlling automatic archive deletion after successful restoration
	 *
	 * @var bool
	 */
	private $delete_archives_upon_restoration = false;

	/**
	 * Running total of errors encountered during restoration
	 *
	 * @var int
	 */
	private $errors;

	/**
	 * Plugin version identifier from backup creation
	 *
	 * @var string|false
	 */
	private $created_by_version = false;

	/**
	 * Indicator whether backup originated from multisite installation
	 *
	 * @var int
	 */
	public $royalbr_backup_is_multisite = -1;

	/**
	 * Controls selective restoration of individual multisite subsites
	 *
	 * @var bool|int
	 */
	public $royalbr_multisite_selective_restore = false;

	/**
	 * Metadata describing current backup set under restoration
	 *
	 * @var array|null
	 */
	private $royalbr_backup_set;

	/**
	 * Temporary directory path for processing foreign backups
	 *
	 * @var string
	 */
	private $ud_foreign_working_dir;

	/**
	 * Path to backup package from external backup plugin
	 *
	 * @var string
	 */
	private $royalbr_foreign_package;

	/**
	 * Identifies backup origin plugin type or false for native backups
	 *
	 * @var string|false
	 */
	public $royalbr_foreign;

	/**
	 * Number of files extracted from backup archives
	 *
	 * @var int
	 */
	private $royalbr_extract_count;

	/**
	 * Current working directory path for restoration process
	 *
	 * @var string
	 */
	private $royalbr_working_dir;

	/**
	 * Target directory path for archive extraction operations
	 *
	 * @var string
	 */
	private $royalbr_extract_dir;

	/**
	 * List of newly created directories during restoration
	 *
	 * @var array
	 */
	private $royalbr_made_dirs;

	/**
	 * Collection of database tables successfully restored
	 *
	 * @var array
	 */
	public $restored_table_names = array();

	/**
	 * Test mode flag for validating database permissions without actual restoration
	 *
	 * @var bool
	 */
	public $is_dummy_db_restore = false;

	/**
	 * Custom database object instance or false to use WordPress global wpdb
	 *
	 * @var ROYALBR_WPDB|false
	 */
	private $wpdb_obj = false;

	/**
	 * Raw mysqli or legacy mysql connection resource for direct operations
	 *
	 * @var mysqli|resource|false
	 */
	private $mysql_dbh = false;

	/**
	 * Flag indicating mysqli extension usage versus legacy mysql functions
	 *
	 * @var bool
	 */
	private $use_mysqli = false;

	/**
	 * Track most recent SQL line number logged to prevent duplicate entries
	 *
	 * @var int
	 */
	private $line_last_logged = 0;

	/**
	 * Active WordPress site URL without trailing slash
	 *
	 * @var string
	 */
	private $our_siteurl;

	/**
	 * Critical WordPress configuration options preserved during database restoration
	 *
	 * @var array
	 */
	private $configuration_bundle = array();

	/**
	 * User-specified restoration settings and preferences
	 *
	 * @var array
	 */
	public $restore_options;

	/**
	 * Multisite subsite selection filter for selective restoration
	 *
	 * @var array
	 */
	private $restore_this_site = array();

	/**
	 * Cached decisions for table inclusion during restoration process
	 *
	 * @var array
	 */
	private $restore_this_table = array();

	/**
	 * Name of database table currently being restored
	 *
	 * @var string
	 */
	private $restoring_table = '';

	/**
	 * Current position line counter in SQL backup file
	 *
	 * @var int
	 */
	private $line = 0;

	/**
	 * Total number of SQL statements executed during restoration
	 *
	 * @var int
	 */
	private $statements_run = 0;

	/**
	 * Database access method selector: wpdb wrapper or direct connection
	 *
	 * @var bool|null
	 */
	private $use_wpdb = null;

	/**
	 * Temporary table prefix used during atomic restoration operations
	 *
	 * @var string|null
	 */
	private $import_table_prefix = null;

	/**
	 * Target table prefix for completed restoration
	 *
	 * @var string|null
	 */
	private $final_import_table_prefix = null;

	/**
	 * Override flag to disable atomic restoration for specific table
	 *
	 * @var bool
	 */
	private $disable_atomic_on_current_table = false;

	/**
	 * Storage engine type for current table being processed
	 *
	 * @var string
	 */
	private $table_engine = '';

	/**
	 * Original table name from backup file being processed
	 *
	 * @var string
	 */
	private $table_name = '';

	/**
	 * Transformed table name with updated prefix applied
	 *
	 * @var string
	 */
	private $new_table_name = '';

	/**
	 * Untransformed table name before any prefix modifications
	 *
	 * @var string
	 */
	private $original_table_name = '';

	/**
	 * Counter tracking successfully created database tables
	 *
	 * @var int
	 */
	private $tables_created = 0;

	/**
	 * Collection of database view names encountered in backup
	 *
	 * @var array
	 */
	private $view_names = array();

	/**
	 * State information enabling restoration resumption after interruption
	 *
	 * @var array|null
	 */
	private $continuation_data;

	/**
	 * Position indicator within multi-file backup archive set
	 *
	 * @var int
	 */
	private $current_index = 0;

	/**
	 * Entity category currently under restoration (db, plugins, themes, uploads, others)
	 *
	 * @var string
	 */
	private $current_type = '';

	/**
	 * Previously processed table name used to avoid duplicate log entries
	 *
	 * @var string
	 */
	private $previous_table_name = '';

	/**
	 * Default behavior for tables not explicitly listed in restoration filters
	 *
	 * @var bool
	 */
	private $include_unspecified_tables = false;

	/**
	 * Default behavior for plugins not explicitly listed in restoration filters
	 *
	 * @var bool
	 */
	private $include_unspecified_plugins = false;

	/**
	 * Default behavior for themes not explicitly listed in restoration filters
	 *
	 * @var bool
	 */
	private $include_unspecified_themes = false;

	/**
	 * Whitelist of specific table names selected for restoration
	 *
	 * @var array
	 */
	private $tables_to_restore = array();

	/**
	 * Whitelist of specific plugin slugs selected for restoration
	 *
	 * @var array
	 */
	private $plugins_to_restore = array();

	/**
	 * Whitelist of specific theme slugs selected for restoration
	 *
	 * @var array
	 */
	private $themes_to_restore = array();

	/**
	 * Database stored routine support detection result
	 *
	 * @var bool|null
	 */
	private $stored_routine_supported = null;

	/**
	 * Blacklist of table names excluded from restoration
	 *
	 * @var array
	 */
	private $tables_to_skip = array();

	/**
	 * Blacklist of plugin slugs excluded from restoration
	 *
	 * @var array
	 */
	private $plugins_to_skip = array();

	/**
	 * Blacklist of theme slugs excluded from restoration
	 *
	 * @var array
	 */
	private $themes_to_skip = array();

	/**
	 * Search and replace handler instance for URL transformations
	 *
	 * @var object|null
	 */
	public $search_replace_obj = null;

	// File operation mode constants for backup restoration
	const MOVEIN_OVERWRITE_NO_BACKUP = 0;
	const MOVEIN_MAKE_BACKUP_OF_EXISTING = 1;
	const MOVEIN_DO_NOTHING_IF_EXISTING = 2;
	const MOVEIN_COPY_IN_CONTENTS = 3;

	/**
	 * WordPress upgrader instance for plugin and theme restoration
	 *
	 * @var WP_Upgrader
	 */
	private $wp_upgrader;

	/**
	 * Upgrader feedback skin maintained for compatibility purposes
	 *
	 * @var object|null
	 */
	public $skin = null;

	/**
	 * Translatable message strings for restoration operations
	 *
	 * @var array
	 */
	public $strings = array();

	/**
	 * Metadata registry for MySQL generated column handling
	 *
	 * @var array
	 */
	private $generated_columns = array();

	/**
	 * Storage engines capable of supporting generated columns
	 *
	 * @var array
	 */
	private $supported_generated_column_engines = array();

	/**
	 * Tracking whether current SQL statement contains generated columns
	 *
	 * @var array
	 */
	private $generated_columns_exist_in_the_statement = array();

	/**
	 * Flag to prevent duplicate new table prefix logging
	 *
	 * @var bool
	 */
	private $printed_new_table_prefix = false;

	/**
	 * Original WordPress site URL extracted from backup metadata
	 *
	 * @var string
	 */
	private $old_siteurl = '';

	/**
	 * Original home URL extracted from backup metadata
	 *
	 * @var string
	 */
	private $old_home = '';

	/**
	 * Original wp-content URL extracted from backup metadata
	 *
	 * @var string
	 */
	private $old_content = '';

	/**
	 * Original uploads directory path from backup metadata
	 *
	 * @var string
	 */
	private $old_uploads = '';

	/**
	 * Original database table prefix from backup
	 *
	 * @var string|null
	 */
	private $old_table_prefix = null;

	/**
	 * Complete site configuration data from backup metadata
	 *
	 * @var array
	 */
	public $old_siteinfo = array();

	/**
	 * Original WordPress absolute path from backup
	 *
	 * @var string
	 */
	private $old_abspath = '';

	/**
	 * Plugin directory name in original backup installation
	 *
	 * @var string
	 */
	public $old_royalbr_plugin_slug = '';

	/**
	 * Writability status of update directory before restoration begins
	 *
	 * @var bool
	 */
	private $pre_restore_updatedir_writable;

	/**
	 * WordPress root directory as WP_Filesystem formatted path
	 *
	 * @var string
	 */
	private $abspath;

	/**
	 * Database operations denied due to insufficient user privileges
	 *
	 * @var array
	 */
	private $db_permissons_forbidden = array();

	/**
	 * Previous upload_path option value before restoration
	 *
	 * @var string
	 */
	private $prior_upload_path;

	/**
	 * Count of INSERT statements successfully executed
	 *
	 * @var int
	 */
	private $insert_statements_run;

	/**
	 * Timestamp marking restoration process initiation
	 *
	 * @var float
	 */
	private $start_time;

	/**
	 * Most recent database error message text
	 *
	 * @var string
	 */
	private $last_error;

	/**
	 * Most recent database error code number
	 *
	 * @var int
	 */
	private $last_error_no;

	/**
	 * MySQL server maximum packet size in bytes
	 *
	 * @var int
	 */
	private $max_allowed_packet;

	/**
	 * Pre-restore user data stored in memory for re-authentication.
	 *
	 * Stored in-memory (not DB) so it survives database replacement during migration.
	 *
	 * @var array|null
	 */
	private $pre_restore_user = null;

	/**
	 * One-time auto-login token for re-authentication after streaming restore.
	 *
	 * @var string|null
	 */
	public $auto_login_token = null;

	/**
	 * Character set specified via SET NAMES command
	 *
	 * @var string
	 */
	private $set_names;

	/**
	 * Absolute filesystem path to restoration log file
	 *
	 * @var string
	 */
	private $restore_log_file = '';

	/**
	 * Open file handle for writing restoration logs
	 *
	 * @var resource|false
	 */
	private $restore_log_handle = false;

	/**
	 * Store the last PHP error message for inclusion in WP_Error
	 *
	 * Captured by php_error() handler and included in error messages
	 * to provide specific error details (e.g., "No space left on device")
	 *
	 * @var string
	 */
	private $last_php_error = '';

	// ========================================================================
	// CONSTRUCTOR & INITIALIZATION
	// ========================================================================

	/**
	 * Initializes restoration handler with configuration and state data
	 *
	 * @param object|null $skin               Upgrader skin for compatibility
	 * @param array|null  $backup_set         Backup metadata and file information
	 * @param bool        $short_init         Skip full initialization for lightweight operations
	 * @param array       $restore_options    User restoration preferences
	 * @param array|null  $continuation_data  State for resuming interrupted restorations
	 */
	public function __construct( $skin = null, $backup_set = null, $short_init = false, $restore_options = array(), $continuation_data = null ) {

		// Store main plugin instance for logging access
		$this->royalbr_instance = $GLOBALS['royalbr_instance'];

		$this->our_siteurl = untrailingslashit( site_url() );

		$this->continuation_data = $continuation_data;

		$this->setup_database_objects();

		// $this->search_replace_obj = new ROYALBR_Search_Replace();

		if ( $short_init ) {
			return;
		}

		// Incremental restore pruning skipped for simplified architecture

		// Multi-file path handling unnecessary for single-archive structure

		if ( isset( $restore_options['include_unspecified_tables'] ) ) {
			$this->include_unspecified_tables = $restore_options['include_unspecified_tables'];
		}
		if ( isset( $restore_options['tables_to_restore'] ) ) {
			$this->tables_to_restore = $restore_options['tables_to_restore'];
		}
		if ( isset( $restore_options['tables_to_skip'] ) ) {
			$this->tables_to_skip = $restore_options['tables_to_skip'];
		}

		if ( isset( $restore_options['include_unspecified_plugins'] ) ) {
			$this->include_unspecified_plugins = $restore_options['include_unspecified_plugins'];
		}
		if ( isset( $restore_options['plugins_to_restore'] ) ) {
			$this->plugins_to_restore = $restore_options['plugins_to_restore'];
		}
		if ( isset( $restore_options['plugins_to_skip'] ) ) {
			$this->plugins_to_skip = (array) $restore_options['plugins_to_skip'];
		}

		if ( isset( $restore_options['include_unspecified_themes'] ) ) {
			$this->include_unspecified_themes = $restore_options['include_unspecified_themes'];
		}
		if ( isset( $restore_options['themes_to_restore'] ) ) {
			$this->themes_to_restore = $restore_options['themes_to_restore'];
		}
		if ( isset( $restore_options['themes_to_skip'] ) ) {
			$this->themes_to_skip = (array) $restore_options['themes_to_skip'];
		}

		// Backup set sorting unnecessary for simplified structure

		$this->royalbr_backup_set = $backup_set;

		// Custom filter and action hooks can be added when needed

		$this->royalbr_multisite_selective_restore = false; // Single-site only
		$this->restore_options = $restore_options;

		do_action( 'royalbr_restorer_restore_options', $this->restore_options );

		$this->royalbr_foreign = false; // Foreign backup support not implemented
		$this->royalbr_backup_is_multisite = -1;
		if ( isset( $backup_set['created_by_version'] ) ) {
			$this->created_by_version = $backup_set['created_by_version'];
		}

		$this->backup_strings();

		$this->is_multisite = is_multisite();


		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		$this->skin = $skin;
		$this->wp_upgrader = new WP_Upgrader( $skin );
		$this->wp_upgrader->init();
	}

	/**
	 * Performs cleanup operations when script terminates
	 *
	 * @return void
	 */
	public function on_shutdown() {
		// Revert log_bin_trust_function_creators to original value if modified
		if ( ! empty( $this->stored_routine_supported ) && is_array( $this->stored_routine_supported ) && $this->stored_routine_supported['is_binary_logging_enabled'] ) {

			$old_log_bin_trust_function_creators = null;

			if ( isset( $this->continuation_data['old_log_bin_trust_function_creators'] ) ) {
				$old_log_bin_trust_function_creators = $this->continuation_data['old_log_bin_trust_function_creators'];
			}

			if ( is_string( $old_log_bin_trust_function_creators ) && '' !== $old_log_bin_trust_function_creators ) {
				$this->set_log_bin_trust_function_creators( $old_log_bin_trust_function_creators );
			}
		}
	}

	// ========================================================================
	// DATABASE CONNECTION MANAGEMENT
	// ========================================================================

	/**
	 * Configures database connection objects for restoration operations
	 *
	 * @param bool $reconnect_wpdb Force wpdb reconnection if true
	 */
	private function setup_database_objects( $reconnect_wpdb = false ) {
		global $wpdb;

		if ( $reconnect_wpdb ) {
			$wpdb->db_connect( true );
		}

		// Create lightweight database wrapper bypassing standard wpdb overhead
		if ( ! $this->use_wpdb() ) {
			// Initialize custom optimized database object
			$wpdb_obj = new ROYALBR_WPDB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			// Verify successful connection establishment
			if ( ! $wpdb_obj->is_mysql || ! $wpdb_obj->ready ) {
				$this->use_wpdb = true;
			} else {
				$this->wpdb_obj = $wpdb_obj;
				$this->mysql_dbh = $wpdb_obj->royalbr_get_database_handle();
				$this->use_mysqli = $wpdb_obj->royalbr_use_mysqli();
			}
		}
	}

	/**
	 * Attempts to re-establish lost database connection
	 *
	 * @return bool True when connection successfully restored, false otherwise
	 */
	private function restore_database_connection() {
		global $wpdb;

		$wpdb_connected = $this->check_db_connection( $wpdb, false, false, true );

		if ( false === $wpdb_connected || -1 === $wpdb_connected ) {
			sleep( 10 );
			$this->setup_database_objects( true );
			return false;
		}

		return true;
	}

	/**
	 * Determines whether to use wpdb wrapper or direct database connection
	 *
	 * @return bool True for wpdb wrapper, false for direct connection
	 */
	private function use_wpdb() {
		// Initialize on first access with direct connection preference
		if ( null === $this->use_wpdb ) {
			// Prefer direct connection for performance unless unavailable
			$this->use_wpdb = false;
		}
		return $this->use_wpdb;
	}

	/**
	 * Public accessor for use_wpdb state (used by search-replace engine)
	 *
	 * @return bool True for wpdb wrapper, false for direct connection
	 */
	public function get_use_wpdb() {
		return $this->use_wpdb();
	}

	/**
	 * Public accessor for the custom database object (used by search-replace engine)
	 *
	 * @return object|null Database object or null
	 */
	public function get_db_object() {
		return isset( $this->wpdb_obj ) ? $this->wpdb_obj : null;
	}

	/**
	 * Validates database connection health with simple query test
	 *
	 * @param wpdb|object $handle      Database handle to validate
	 * @param bool        $log_it      Enable logging of check results
	 * @param bool        $allow_bail  Permit early termination on failure
	 * @param bool        $force_check Bypass recent check cache
	 * @return bool|int True for healthy connection, -1 for failure
	 */
	private function check_db_connection( $handle = false, $log_it = false, $allow_bail = false, $force_check = false ) {
		global $wpdb;

		if ( false === $handle ) {
			$handle = $wpdb;
		}

		// Try a simple query
		$result = $handle->query( 'SELECT 1' );

		if ( false === $result ) {
			if ( $log_it ) {
				$this->royalbr_instance->log_e( 'Database connection check failed' );
			}
			return -1;
		}

		return true;
	}

	/**
	 * Check if available disk space is at least the specified number of bytes.
	 *
	 * @since  1.0.0
	 * @param  int $space Number of bytes required.
	 * @return int|bool True if enough space, false if not, -1 if unknown.
	 */
	private function disk_space_check( $space ) {
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors on hosts that disable this function.
		$disk_free_space = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false;
		// == rather than === is deliberate; 0 can be returned when the real result should be false.
		if ( false == $disk_free_space ) {
			return -1;
		}
		return ( $disk_free_space > $space ) ? true : false;
	}

	// ========================================================================
	// LOG FILE MANAGEMENT
	// ========================================================================

	/**
	 * Initialize restore log file
	 *
	 * Creates a new log file for this restore operation.
	 * log.$task_id.txt in backups directory
	 *
	 * @param string $task_id The restore task ID (nonce)
	 * @return bool True on success, false on failure
	 */
	private function init_restore_log( $task_id ) {
		// Get backup directory
		$backup_dir = defined( 'ROYALBR_BACKUP_DIR' ) ? ROYALBR_BACKUP_DIR : ( WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'royal-backup-reset' . DIRECTORY_SEPARATOR );

		// Sanitize task ID for filename
		$safe_task_id = sanitize_file_name( $task_id );

		// Create log file path: restore-log-{task_id}.txt
		$this->restore_log_file = $backup_dir . 'restore-log-' . $safe_task_id . '.txt';

		// Open file handle for appending
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file operation needed for logging
		$this->restore_log_handle = fopen( $this->restore_log_file, 'a' );

		if ( false === $this->restore_log_handle ) {
			$this->royalbr_instance->log( 'Failed to open restore log file: ' . $this->restore_log_file );
			return false;
		}

		// Write header to log file
		$header = "===========================================\n";
		$header .= "Royal Backup & Reset - Restore Log\n";
		$header .= 'Started: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$header .= 'Task ID: ' . $task_id . "\n";
		$header .= "===========================================\n\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file operation needed for logging
		fwrite( $this->restore_log_handle, $header );

		return true;
	}

	/**
	 * Write line to restore log file
	 *
	 * Called by the royalbr_logline filter to write each log line to file.
	 * Timestamp (R) [level] message
	 *
	 * @param string $line  The log line to write
	 * @param string $level The log level (notice, warning, error)
	 * @return void
	 */
	public function write_to_restore_log( $line, $level = 'notice' ) {
		if ( empty( $this->restore_log_handle ) ) {
			return;
		}

		// Calculate relative time since restore started
		$rtime = microtime( true ) - $this->royalbr_instance->task_time_ms;

		// Format: "00000.123 (R) [level] message\n"
		$formatted_line = sprintf( '%08.03f', round( $rtime, 3 ) ) . ' (R) [' . $level . '] ' . $line . "\n";

		// Write to file
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file operation needed for logging
		fwrite( $this->restore_log_handle, $formatted_line );
	}

	/**
	 * Close restore log file
	 *
	 * Writes footer and closes the file handle.
	 *
	 * @param bool $success Whether restore was successful
	 * @return void
	 */
	private function close_restore_log( $success = true ) {
		if ( empty( $this->restore_log_handle ) ) {
			return;
		}

		// Write footer
		$footer = "\n===========================================\n";
		$footer .= 'Finished: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$footer .= 'Status: ' . ( $success ? 'SUCCESS' : 'FAILED' ) . "\n";
		$footer .= "===========================================\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file operation needed for logging
		fwrite( $this->restore_log_handle, $footer );

		// Close file handle
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct file operation needed for logging
		fclose( $this->restore_log_handle );

		$this->restore_log_handle = false;
	}

	/**
	 * Get restore log file path
	 *
	 * Returns the path to the current restore log file.
	 *
	 * @return string Log file path
	 */
	public function get_restore_log_file() {
		return $this->restore_log_file;
	}

	// ========================================================================
	// USER-FACING STRINGS
	
	// ========================================================================

	/**
	 * Initialize user-facing strings
	
	 */
	private function backup_strings() {
		$this->strings = array(
			'unpack_package'         => __( 'Unpacking backup', 'royal-backup-reset' ),
			'decrypt_database'       => __( 'Decrypting database', 'royal-backup-reset' ),
			'decrypted_database'     => __( 'Database decrypted', 'royal-backup-reset' ),
			'copy_failed'            => __( 'Copy failed', 'royal-backup-reset' ),
			'restore_database'       => __( 'Restoring database', 'royal-backup-reset' ),
			'not_possible'           => __( 'Restoration not possible', 'royal-backup-reset' ),
			'moving_old'             => __( 'Moving old data', 'royal-backup-reset' ),
			'old_move_failed'        => __( 'Failed to move old data', 'royal-backup-reset' ),
			'old_delete_failed'      => __( 'Failed to delete old data', 'royal-backup-reset' ),
			'move_failed'            => __( 'Move failed', 'royal-backup-reset' ),
			'new_move_failed'        => __( 'Failed to move new data', 'royal-backup-reset' ),
			'manifest_not_found'     => __( 'Manifest not found', 'royal-backup-reset' ),
			'read_manifest_failed'   => __( 'Failed to read manifest', 'royal-backup-reset' ),
			'read_working_dir_failed' => __( 'Failed to read working directory', 'royal-backup-reset' ),
		);
	}

	// ========================================================================
	// BATCH 1: PERMISSION & UTILITY HELPERS
	
	// ========================================================================

	/**
	 * Search for a folder recursively
	
	 *
	 * @param string $folder   Folder name to search for
	 * @param string $startat  Starting directory
	 * @return string|false    Full path to folder if found, false otherwise
	 */
	private function search_for_folder( $folder, $startat ) {
		if ( ! is_dir( $startat ) ) {
			return false;
		}

		// Exists in this folder?
		if ( is_dir( $startat . '/' . $folder ) ) {
			return trailingslashit( $startat ) . $folder;
		}

		// Does not - search subdirectories
		if ( $handle = opendir( $startat ) ) {
			while ( ( $file = readdir( $handle ) ) !== false ) {
				if ( '.' != $file && '..' != $file && is_dir( $startat . '/' . $file ) ) {
					$ss = $this->search_for_folder( $folder, trailingslashit( $startat ) . $file );
					if ( is_string( $ss ) ) {
						return $ss;
					}
				}
			}
			closedir( $handle );
		}

		return false;
	}

	/**
	 * Get current chmod permissions
	
	 *
	 * @param string              $file File path
	 * @param WP_Filesystem|false $wpfs WP_Filesystem object
	 * @return string Octal string (not octal number)
	 */
	private function get_current_chmod( $file, $wpfs = false ) {
		if ( false == $wpfs ) {
			global $wp_filesystem;
			$wpfs = $wp_filesystem;
		}

		// getchmod() is broken at least as recently as WP3.8 - see: https://core.trac.wordpress.org/ticket/26598
		return ( is_a( $wpfs, 'WP_Filesystem_Direct' ) )
			? substr( sprintf( '%06d', decoct( @fileperms( $file ) ) ), 3 )
			: $wpfs->getchmod( $file );
	}

	/**
	 * Calculate additive chmod in octal format
	
	 *
	 * @param string $old_chmod Old chmod value
	 * @param string $new_chmod New chmod value (octal, what you'd pass to chmod())
	 * @return string Octal string
	 */
	private function calculate_additive_chmod_oct( $old_chmod, $new_chmod ) {
		// chmod() expects octal form, which means a preceding zero - see http://php.net/chmod
		$old_chmod = sprintf( '%04d', $old_chmod );
		$new_chmod = sprintf( '%04d', decoct( $new_chmod ) );

		for ( $i = 1; $i <= 3; $i++ ) {
			$oldbit = substr( $old_chmod, $i, 1 );
			$newbit = substr( $new_chmod, $i, 1 );
			for ( $j = 0; $j <= 2; $j++ ) {
				if ( ( $oldbit & ( 1 << $j ) ) && ! ( $newbit & ( 1 << $j ) ) ) {
					$newbit = (string) ( $newbit | 1 << $j );
					$new_chmod = sprintf( '%04d', substr( $new_chmod, 0, $i ) . $newbit . substr( $new_chmod, $i + 1 ) );
				}
			}
		}

		return $new_chmod;
	}

	/**
	 * Chmod if needed (only make permissions MORE permissive, never tighten)
	
	 *
	 * @param string  $dir       WP_Filesystem path
	 * @param string  $chmod     Octal chmod value
	 * @param bool    $recursive Recursive chmod
	 * @param mixed   $wpfs      WP_Filesystem object or false
	 * @param bool    $suppress  Suppress PHP errors
	 * @return bool Success status
	 */
	private function chmod_if_needed( $dir, $chmod, $recursive = false, $wpfs = false, $suppress = true ) {
		// Do nothing on Windows
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			return true;
		}

		if ( false == $wpfs ) {
			global $wp_filesystem;
			$wpfs = $wp_filesystem;
		}

		$old_chmod = $this->get_current_chmod( $dir, $wpfs );

		// Sanity check
		if ( strlen( $old_chmod ) < 3 ) {
			return false;
		}

		$new_chmod = $this->calculate_additive_chmod_oct( $old_chmod, $chmod );

		// Don't fix what isn't broken
		if ( ! $recursive && $new_chmod == $old_chmod ) {
			return true;
		}

		$new_chmod = octdec( $new_chmod );

		if ( $suppress ) {
			return @$wpfs->chmod( $dir, $new_chmod, $recursive );
		} else {
			return $wpfs->chmod( $dir, $new_chmod, $recursive );
		}
	}

	/**
	 * Log permission failure message during restore
	
	 *
	 * @param string $path                            Full path
	 * @param string $log_message_prefix              Action being performed
	 * @param string $directory_prefix_in_log_message Directory prefix (Parent/Destination)
	 */
	private function restore_log_permission_failure_message( $path, $log_message_prefix, $directory_prefix_in_log_message = 'Parent' ) {
		// Create a simple error message directly

		$parent_dir = dirname( $path );
		$permissions = is_dir( $parent_dir ) ? substr( sprintf( '%o', fileperms( $parent_dir ) ), -4 ) : 'unknown';

		$log_message = sprintf(
			'%s failed. %s directory (%s) permissions: %s',
			$log_message_prefix,
			$directory_prefix_in_log_message,
			$parent_dir,
			$permissions
		);

		$this->royalbr_instance->log_e( $log_message );
	}

	/**
	 * Enter or leave maintenance mode
	
	 *
	 * @param bool $active Whether to activate (true) or deactivate (false) maintenance mode
	 */
	private function maintenance_mode( $active ) {
		// ROYALBR: Skip filter for simplicity, just call maintenance_mode directly
		// Suppress WP_Upgrader's "Enabling/Disabling Maintenance mode..." output during AJAX restore
		ob_start();
		$this->wp_upgrader->maintenance_mode( $active );
		ob_end_clean();
	}

	// ========================================================================
	// BATCH 2: CACHE CLEARING FUNCTIONS
	
	// ========================================================================

	/**
	 * Clear all caches after restore
	
	 */
	private function clear_caches() {
		// Functions called here need to not assume that the relevant plugin actually exists
		$methods = array(
			'clear_cache_wpsupercache',
			'clear_avada_fusion_cache',
			'clear_elementor_cache',
			'clear_divi_cache',
		);

		foreach ( $methods as $method ) {
			try {
				call_user_func( array( $this, $method ) );
			} catch ( Exception $e ) {
				$log_message = 'Exception (' . get_class( $e ) . ") occurred when cleaning up third-party cache ($method) during post-restore: " . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				$this->royalbr_instance->log_e( $log_message );
			} catch ( Error $e ) {
				// Error class exists in PHP 7+
				$log_message = 'Error (' . get_class( $e ) . ") occurred when cleaning up third-party cache ($method) during post-restore: " . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
				$this->royalbr_instance->log_e( $log_message );
			}
		}

		// Purge standard cache directories
		$cache_sub_directories = array( 'cache', 'wphb-cache', 'endurance-page-cache' );
		foreach ( $cache_sub_directories as $sub_dir ) {
			if ( ! is_dir( WP_CONTENT_DIR . '/' . $sub_dir ) ) {
				continue;
			}
			$this->royalbr_instance->log_e( 'Purging cache directory: %s', WP_CONTENT_DIR . '/' . $sub_dir );
			$this->remove_local_directory( WP_CONTENT_DIR . '/' . $sub_dir, true );
		}
	}

	/**
	 * Clear Avada/Fusion theme's dynamic CSS cache
	
	 */
	private function clear_avada_fusion_cache() {
		$upload_dir = wp_upload_dir();
		$fusion_css_dir = realpath( $upload_dir['basedir'] ) . DIRECTORY_SEPARATOR . 'fusion-styles';
		if ( is_dir( $fusion_css_dir ) ) {
			$this->royalbr_instance->log_e( "Avada/Fusion's dynamic CSS folder exists, and will be emptied" );
			$this->remove_local_directory( $fusion_css_dir, true );
		}
	}

	/**
	 * Clear Divi theme's dynamic CSS cache
	
	 */
	private function clear_divi_cache() {
		$clear_cache = true;
		$divi_cache_dir = ( defined( 'ET_CORE_CACHE_DIR' ) ) ? ET_CORE_CACHE_DIR : WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'et-cache';

		// Try direct clear method if available
		if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
			$this->royalbr_instance->log_e( "Divi's clear cache method exists and will be executed" );
			ET_Core_PageResource::remove_static_resources( 'all', 'all' );
			$clear_cache = false;
		}

		// Manual clear if needed
		if ( $clear_cache && is_dir( $divi_cache_dir ) ) {
			$this->royalbr_instance->log_e( "Divi's cache directory exists, and will be emptied" );
			$this->remove_local_directory( $divi_cache_dir, true );
		}
	}

	/**
	 * Clear Elementor's CSS cache
	
	 */
	private function clear_elementor_cache() {
		$cache_uncleared = true;

		// Try direct clear method
		if ( class_exists( '\Elementor\Plugin' ) ) {
			if ( ! class_exists( 'ROYALBR_Elementor_Plugin' ) ) {
				class_alias( '\Elementor\Plugin', 'ROYALBR_Elementor_Plugin' );
			}
			if ( class_exists( 'ROYALBR_Elementor_Plugin' ) && isset( ROYALBR_Elementor_Plugin::$instance ) && isset( ROYALBR_Elementor_Plugin::$instance->files_manager ) && is_object( ROYALBR_Elementor_Plugin::$instance->files_manager ) && method_exists( ROYALBR_Elementor_Plugin::$instance->files_manager, 'clear_cache' ) ) {
				$this->royalbr_instance->log_e( "Elementor's clear cache method exists and will be executed" );
				ROYALBR_Elementor_Plugin::$instance->files_manager->clear_cache();
				$cache_uncleared = false;
			}
		}

		// Manual clear if needed
		if ( $cache_uncleared ) {
			$upload_dir = wp_upload_dir();
			$elementor_css_dir = realpath( $upload_dir['basedir'] ) . DIRECTORY_SEPARATOR . 'elementor' . DIRECTORY_SEPARATOR . 'css';
			if ( is_dir( $elementor_css_dir ) ) {
				$this->royalbr_instance->log_e( "Elementor's CSS directory exists, and will be emptied" );
				$this->remove_local_directory( $elementor_css_dir, true );
			}
			// Delete meta and options to force regeneration
			delete_post_meta_by_key( '_elementor_css' );
			delete_option( '_elementor_global_css' );
			delete_option( 'elementor-custom-breakpoints-files' );
		}
	}

	/**
	 * Clear WP Super Cache
	
	 *
	 * @return bool
	 */
	private function clear_cache_wpsupercache() {
		$all = true;

		// These are WP Super Cache plugin globals, not defined by this plugin.
		global $cache_path, $wp_cache_object_cache;

		if ( $wp_cache_object_cache && function_exists( 'reset_oc_version' ) ) {
			reset_oc_version();
		}

		if ( true == $all && function_exists( 'prune_super_cache' ) ) {
			if ( ! empty( $cache_path ) ) {
				$this->royalbr_instance->log_e( 'Clearing WP Super Cache cached pages' );
				prune_super_cache( $cache_path, true );
			}
			return true;
		}

		return false;
	}

	/**
	 * Helper: Remove local directory recursively
	 *
	 * @param string $dir          Directory path
	 * @param bool   $contents_only Remove contents only, not the directory itself
	 * @return bool Success status
	 */
	private function remove_local_directory( $dir, $contents_only = false ) {
		global $wp_filesystem;

		if ( ! is_dir( $dir ) ) {
			return false;
		}

		// Initialize WP_Filesystem if not already done
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			if ( is_dir( $path ) ) {
				$this->remove_local_directory( $path, false );
			} else {
				@wp_delete_file( $path );
			}
		}

		if ( ! $contents_only ) {
			return @$wp_filesystem->rmdir( $dir );
		}

		return true;
	}

	// ========================================================================
	// BATCH 3: CONFIGURATION & DATABASE HELPERS
	
	// ========================================================================

	/**
	 * Save configuration bundle before restore
	
	 */
	private function save_configuration_bundle() {
		$this->configuration_bundle = array();

		// Always preserve restore in progress flag and site URLs (critical for migration).
		$keys_to_save = array( 'royalbr_restore_in_progress', 'siteurl', 'home' );

		// Preserve all plugin configuration, backup history, and Freemius options
		$keys_to_save = array_merge( $keys_to_save, array(
			'royalbr_backup_history',
			'royalbr_backup_display_names',
			'royalbr_migration_upload_nonces',
			'royalbr_backup_include_db',
			'royalbr_backup_include_files',
			'royalbr_backup_include_wpcore',
			'royalbr_restore_db',
			'royalbr_restore_plugins',
			'royalbr_restore_themes',
			'royalbr_restore_uploads',
			'royalbr_restore_others',
			'royalbr_reactivate_theme',
			'royalbr_reactivate_plugins',
			'royalbr_keep_royalbr_active',
			'royalbr_clear_uploads',
			'royalbr_clear_media',
			'royalbr_reminder_popup_mode',
			'royalbr_interval_files',
			'royalbr_interval_database',
			'royalbr_retain_files',
			'royalbr_retain_db',
			'fs_accounts',
			'fs_gdpr',
			'fs_api_cache',
			'fs_options',
			// Rating notice options.
			'royalbr_activation_time',
			'royalbr_maybe_later_time',
			'royalbr_rating_dismissed',
			'royalbr_already_rated',
			'royalbr_has_restored',
			// Backup reminder banner options.
			'royalbr_backup_reminder_banner_dismissed',
			'royalbr_backup_reminder_banner_later_time',
			// Backup location options.
			'royalbr_backup_loc_local',
			'royalbr_backup_loc_gdrive',
			'royalbr_backup_loc_dropbox',
			'royalbr_backup_loc_s3',
			'royalbr_gdrive_folder_name',
			'royalbr_gdrive_refresh_token',
			// S3 credentials.
			'royalbr_s3_access_key',
			'royalbr_s3_secret_key',
			'royalbr_s3_location',
			'royalbr_s3_bucket',
			'royalbr_s3_region',
			'royalbr_s3_path',
			// Dropbox credentials.
			'royalbr_dropbox_auth_code',
			'royalbr_dropbox_site_token',
			// Remote restore cleanup transients (files downloaded from GDrive for restore).
			'_transient_royalbr_restore_downloaded_files',
			'_transient_timeout_royalbr_restore_downloaded_files',
			'_transient_royalbr_restore_backup_nonce',
			'_transient_timeout_royalbr_restore_backup_nonce',
		) );

		global $wpdb;
		foreach ( $keys_to_save as $key ) {
			// Direct database check - correctly detects false values.
			// WordPress get_option() returns default for boolean false, breaking sentinel pattern.
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			) );

			if ( null !== $row ) {
				$value = maybe_unserialize( $row->option_value );
				$this->configuration_bundle[ $key ] = $value;
				// Verbose logging to debug config preservation
				if ( is_bool( $value ) ) {
					$log_value = $value ? 'true' : 'false';
				} elseif ( is_array( $value ) || is_object( $value ) ) {
					$log_value = '(complex: ' . gettype( $value ) . ')';
				} else {
					$log_value = (string) $value;
				}
				$this->royalbr_instance->log_e( 'Config bundle saved: %s = %s', $key, $log_value );
			}
		}

		$this->royalbr_instance->log_e( 'Saved configuration bundle: %d options', count( $this->configuration_bundle ) );
	}

	/**
	 * Restore configuration bundle after options table is restored
	 *
	 * @param string $table Table name (for logging)
	 */
	private function restore_configuration_bundle( $table ) {
		if ( ! is_array( $this->configuration_bundle ) || empty( $this->configuration_bundle ) ) {
			$this->royalbr_instance->log_e( 'No configuration bundle to restore (table: %s)', $table );
			return;
		}

		// Get current remote-only backups BEFORE we overwrite the options.
		$remote_only_backups = $this->get_current_remote_only_backups();

		$this->royalbr_instance->log_e( 'Restoring prior configuration (table: %s; keys: %d)', $table, count( $this->configuration_bundle ) );
		foreach ( $this->configuration_bundle as $key => $value ) {
			// Special handling for backup history - merge remote-only backups.
			if ( 'royalbr_backup_history' === $key && ! empty( $remote_only_backups ) ) {
				$value = $this->merge_remote_backups_into_history( $value, $remote_only_backups );
			}

			delete_option( $key );
			// Use add_option for empty/false values - update_option fails for these after delete_option.
			$result = add_option( $key, $value, '', 'yes' );
			// Verbose logging to debug config preservation
			if ( is_bool( $value ) ) {
				$log_value = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				$log_value = '(complex: ' . gettype( $value ) . ')';
			} else {
				$log_value = (string) $value;
			}
			$this->royalbr_instance->log_e( 'Config bundle restored: %s = %s (result: %s)', $key, $log_value, $result ? 'success' : 'failed' );
		}

		// Remove remote storage options that weren't on this site before restore.
		// Prevents backup's invalid credentials from leaking through on migration.
		$remote_storage_keys = array(
			'royalbr_backup_loc_gdrive',
			'royalbr_backup_loc_dropbox',
			'royalbr_backup_loc_s3',
			'royalbr_gdrive_folder_name',
			'royalbr_gdrive_refresh_token',
			'royalbr_dropbox_auth_code',
			'royalbr_dropbox_site_token',
			'royalbr_s3_access_key',
			'royalbr_s3_secret_key',
			'royalbr_s3_location',
			'royalbr_s3_bucket',
			'royalbr_s3_region',
			'royalbr_s3_path',
		);
		foreach ( $remote_storage_keys as $remote_key ) {
			if ( ! array_key_exists( $remote_key, $this->configuration_bundle ) ) {
				delete_option( $remote_key );
			}
		}

		// Reset LiteSpeed server warning on migration
		if ( ! empty( $this->restore_options['royalbr_restorer_replacesiteurl'] ) ) {
			if ( isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== strpos( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ), 'LiteSpeed' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local .htaccess for LiteSpeed check
				if ( ! is_file( ABSPATH . '.htaccess' ) || ! preg_match( '/noabort/i', file_get_contents( ABSPATH . '.htaccess' ) ) ) {
					delete_option( 'royalbr_dismiss_admin_warning_litespeed' );
				}
			}
		}
	}

	/**
	 * Get remote-only backups from current backup history.
	 *
	 * Remote-only backups are those stored only in remote storage (e.g., Google Drive)
	 * without local files. These need to be preserved during database restore.
	 *
	 * @return array Array of remote-only backup entries keyed by nonce.
	 */
	private function get_current_remote_only_backups() {
		$current_history = get_option( 'royalbr_backup_history', array() );
		$remote_only     = array();

		if ( isset( $current_history['backups'] ) && is_array( $current_history['backups'] ) ) {
			foreach ( $current_history['backups'] as $nonce => $backup_data ) {
				$storage = isset( $backup_data['storage_locations'] ) ? $backup_data['storage_locations'] : array();
				// Remote-only = has storage locations but 'local' is NOT in the list.
				if ( ! empty( $storage ) && ! in_array( 'local', $storage, true ) ) {
					$remote_only[ $nonce ] = $backup_data;
					$this->royalbr_instance->log_e( 'Preserving remote-only backup: %s', $nonce );
				}
			}
		}

		return $remote_only;
	}

	/**
	 * Merge remote-only backups into restored backup history.
	 *
	 * @param array $restored_history    Backup history from the restore bundle.
	 * @param array $remote_only_backups Remote-only backups to preserve.
	 * @return array Merged backup history.
	 */
	private function merge_remote_backups_into_history( $restored_history, $remote_only_backups ) {
		if ( ! is_array( $restored_history ) ) {
			$restored_history = array();
		}
		if ( ! isset( $restored_history['backups'] ) ) {
			$restored_history['backups'] = array();
		}
		if ( ! isset( $restored_history['index']['by_timestamp'] ) ) {
			$restored_history['index']['by_timestamp'] = array();
		}

		foreach ( $remote_only_backups as $nonce => $backup_data ) {
			// Only add if not already in restored history.
			if ( ! isset( $restored_history['backups'][ $nonce ] ) ) {
				$restored_history['backups'][ $nonce ] = $backup_data;

				// Update timestamp index.
				if ( isset( $backup_data['timestamp'] ) ) {
					$restored_history['index']['by_timestamp'][ $backup_data['timestamp'] ] = $nonce;
				}
			}
		}

		$this->royalbr_instance->log_e( 'Merged %d remote-only backups into restored history', count( $remote_only_backups ) );
		return $restored_history;
	}

	/**
	 * Set MySQL log_bin_trust_function_creators variable
	
	 *
	 * @param string $value ON or OFF
	 * @return string|WP_Error Previous value or error
	 */
	private function set_log_bin_trust_function_creators( $value ) {
		global $wpdb;

		static $saved_value = null;
		static $initial_value = null;

		$old_val = $wpdb->suppress_errors();
		try {
			// Get initial value
			if ( is_null( $initial_value ) || is_wp_error( $initial_value ) ) {
				$creators_val = $wpdb->get_var( 'SELECT @@GLOBAL.log_bin_trust_function_creators' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( is_null( $creators_val ) ) {
					throw new Exception(
						sprintf(
							/* translators: 1: MySQL error message, 2: MySQL query that failed */
							__( 'An error occurred while attempting to retrieve the MySQL global log_bin_trust_function_creators variable (%1$s - %2$s)', 'royal-backup-reset' ),
							$wpdb->last_error,
							$wpdb->last_query
						),
						0
					);
				}
				$initial_value = ( '1' === $creators_val || 'on' === strtolower( $creators_val ) ) ? 'ON' : 'OFF';
			}

			// Set new value if needed
			if ( is_null( $saved_value ) || ( $saved_value != $value ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $value is validated as 'ON' or 'OFF', MySQL global variable cannot use prepare()
				$res = $wpdb->query( 'SET GLOBAL log_bin_trust_function_creators = ' . $value ); 
				if ( false === $res ) {
					$saved_value = null;
					throw new Exception(
						sprintf(
							/* translators: 1: MySQL error message, 2: MySQL query that failed */
							__( 'An error occurred while attempting to set a new value to the MySQL global log_bin_trust_function_creators variable (%1$s - %2$s)', 'royal-backup-reset' ),
							$wpdb->last_error,
							$wpdb->last_query
						),
						0
					);
				}
				if ( ! is_null( $saved_value ) ) {
					$initial_value = $saved_value;
				}
				$saved_value = $value;
			}
		} catch ( Exception $ex ) {
			$initial_value = new WP_Error( 'log_bin_trust_function_creators', $ex->getMessage() );
		}
		$wpdb->suppress_errors( $old_val );

		return $initial_value;
	}

	/**
	 * Log oversized SQL packet
	
	 *
	 * @param string $sql_line The SQL line
	 */
	private function log_oversized_packet( $sql_line ) {
		$logit = substr( $sql_line, 0, 100 );
		/* translators: %s: SQL line details including length and preview */
		$this->royalbr_instance->log_e( 'An SQL line that is larger than the maximum packet size and cannot be split was found: %s', '(' . strlen( $sql_line ) . ', ' . $logit . ' ...)' );
		/* translators: %s: SQL line details including length, max packet size, and preview */
		$this->royalbr_instance->log_e( __( 'Warning:', 'royal-backup-reset' ) . ' ' . sprintf( __( 'An SQL line that is larger than the maximum packet size and cannot be split was found; this line will not be processed, but will be dropped: %s', 'royal-backup-reset' ), '(' . strlen( $sql_line ) . ', ' . $this->max_allowed_packet . ', ' . $logit . ' ...)' ) );
	}

	/**
	 * Remove database tables safely

	 *
	 * @param array $tables Table names to drop
	 */
	private function remove_database_tables( $tables ) {
		foreach ( $tables as $table ) {
			$this->execute_sql_statement( 'DROP TABLE IF EXISTS ' . ROYALBR_Database_Utility::backquote( $table ), 1, '', false );
		}
	}

	/**
	 * Rename a database table
	
	 *
	 * @param string $current_table_name Current table name
	 * @param string $new_table_name     New table name
	 * @return bool|int True on success, error number on failure
	 */
	private function rename_table( $current_table_name, $new_table_name ) {
		$current_table_name = ROYALBR_Database_Utility::backquote( $current_table_name );
		$new_table_name = ROYALBR_Database_Utility::backquote( $new_table_name );

		return $this->execute_sql_statement( "ALTER TABLE $current_table_name RENAME TO $new_table_name;", 14, '', false );
	}

	/**
	 * Lock a database table
	
	 *
	 * @param string $table Table name
	 * @return bool|int True on success, error code on failure
	 */
	private function lock_table( $table ) {
		// Not yet working
		return true;

		// This code is left here for future implementation
		/*
		global $wpdb;
		$table = ROYALBR_Database_Utility::backquote( $table );

		if ( $this->use_wpdb() ) {
			$req = $wpdb->query( "LOCK TABLES $table WRITE;" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			if ( $this->use_mysqli ) {
				$req = mysqli_query( $this->mysql_dbh, "LOCK TABLES $table WRITE;" );
			} else {
				$req = mysql_unbuffered_query( "LOCK TABLES $table WRITE;", $this->mysql_dbh );
			}
			if ( ! $req ) {
				$lock_error_no = $this->use_mysqli ? mysqli_errno( $this->mysql_dbh ) : mysql_errno( $this->mysql_dbh );
			}
		}
		if ( ! $req && ( $this->use_wpdb() || 1142 === $lock_error_no ) ) {
			// Permission denied
			return 1142;
		}
		return true;
		*/
	}

	/**
	 * Unlock tables
	
	 *
	 * @return void
	 */
	public function unlock_tables() {
		return;

		// Left for future implementation
		/*
		if ( $this->use_wpdb() ) {
			$wpdb->query( "UNLOCK TABLES;" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} elseif ( $this->use_mysqli ) {
			mysqli_query( $this->mysql_dbh, "UNLOCK TABLES;" );
		} else {
			mysql_unbuffered_query( "UNLOCK TABLES;" );
		}
		*/
	}

	/**
	 * Get max allowed packet size

	 *
	 * @return int Max packet size in bytes
	 */
	private function get_max_packet_size() {
		global $wpdb;

		$mp = (int) $wpdb->get_var( "SELECT @@session.max_allowed_packet" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Default to 1MB if we can't determine
		return ( $mp > 0 ) ? $mp : 1048576;
	}

	/**
	 * Prepare SQL execution - validate and prepare table prefix

	 *
	 * @param string $import_table_prefix Table prefix to use
	 * @return string|WP_Error|bool Modified prefix, or error
	 */
	private function prepare_sql_execution( $import_table_prefix ) {
		// $import_table_prefix = apply_filters( 'royalbr_restore_set_table_prefix', $import_table_prefix, $this->royalbr_backup_is_multisite );

		if ( ! is_string( $import_table_prefix ) ) {
			$this->maintenance_mode( false );
			if ( false === $import_table_prefix ) {
				$this->royalbr_instance->log_e( __( 'Please supply the requested information, and then continue.', 'royal-backup-reset' ) );
				return false;
			} elseif ( is_wp_error( $import_table_prefix ) ) {
				return $import_table_prefix;
			} else {
				return new WP_Error( 'invalid_table_prefix', __( 'Error:', 'royal-backup-reset' ) . ' ' . serialize( $import_table_prefix ) );
			}
		}

		$this->royalbr_instance->log_e( 'New table prefix: %s', $import_table_prefix );

		return $import_table_prefix;
	}

	/**
	 * Execute SQL statement during restoration

	 *
	 * SQL Types:
	 * 1 DROP, 2 CREATE, 3 INSERT, 4 LOCK, 5 UPDATE, 6 WPB2D CREATE/DROP,
	 * 7 WPB2D USE, 8 SET NAMES, 9 TRIGGER, 10 DELIMITER, 11 CREATE VIEW,
	 * 12 ROUTINE, 13 DROP FUNCTION|PROCEDURE, 14 ALTER, 15 UNLOCK,
	 * 16 DROP VIEW, 17 SET GLOBAL.GTID_PURGED, 18 SET SQL_MODE
	 *
	 * @param string $sql_line          SQL statement to execute
	 * @param int    $sql_type          Type of SQL statement (see above)
	 * @param string $import_table_prefix Import table prefix (for logging)
	 * @param bool   $check_skipping    Check if table should be skipped
	 * @return bool|WP_Error|int True on success, WP_Error on failure, int for specific errors
	 */
	public function execute_sql_statement( $sql_line, $sql_type, $import_table_prefix = '', $check_skipping = true ) {
		global $wpdb;

		if ( $check_skipping && ! empty( $this->table_name ) && ! $this->restore_this_table( $this->table_name ) ) {
			return;
		}

		$ignore_errors = false;

		// Type 2 = CREATE TABLE
		if ( 2 == $sql_type && ! empty( $this->db_permissons_forbidden['create'] ) ) {
			$this->royalbr_instance->log_e( 'Cannot create new tables, so skipping this command (%s...)', htmlspecialchars( substr( $sql_line, 0, 100 ) ) );
			$req = true;
		} else {

			// If CREATE TABLE and we can DROP, ensure table is dropped first
			if ( 2 == $sql_type && empty( $this->db_permissons_forbidden['drop'] ) ) {
				if ( ! in_array( $this->new_table_name, $this->tables_been_dropped ) ) {
					$this->royalbr_instance->log_e( 'Table to be implicitly dropped: %s', $this->new_table_name );
					$this->execute_sql_statement( 'DROP TABLE IF EXISTS ' . ROYALBR_Database_Utility::backquote( $this->new_table_name ), 1, '', false );
					$this->tables_been_dropped[] = $this->new_table_name;
				}
			}

			// Type 1 = DROP TABLE
			if ( 1 == $sql_type ) {
				if ( ! empty( $this->db_permissons_forbidden['drop'] ) ) {
					$sql_line = 'DELETE FROM ' . ROYALBR_Database_Utility::backquote( $this->new_table_name );
					$this->royalbr_instance->log_e( 'Cannot drop tables, so deleting instead (%s)', $sql_line );
					$ignore_errors = true;
				}
			}

			// Check for oversized INSERT
			if ( 3 == $sql_type && $sql_line && strlen( $sql_line ) > $this->max_allowed_packet ) {
				$this->log_oversized_packet( $sql_line );
				$this->errors++;
				if ( 0 == $this->insert_statements_run && $this->new_table_name && $this->new_table_name == $import_table_prefix . 'options' ) {
					$this->royalbr_instance->log_e( 'Leaving maintenance mode' );
					$this->maintenance_mode( false );
					return new WP_Error( 'initial_db_error', 'An error occurred on the first INSERT (options) - aborting run' );
				}
				return false;
			}

			// Log first TRIGGER / STORED ROUTINE
			static $first_trigger = true;
			if ( 9 == $sql_type && $first_trigger ) {
				$first_trigger = false;
				$this->royalbr_instance->log_e( 'Restoring TRIGGERs...' );
			}

			static $first_stored_routine = true;
			if ( 12 == $sql_type && $first_stored_routine ) {
				$first_stored_routine = false;
				$this->royalbr_instance->log_e( 'Restoring STORED ROUTINES...' );
			}

			// Execute the query
			if ( $this->use_wpdb() ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql_line is raw SQL from database backup file, cannot use prepare() for restore operations
				$req = $wpdb->query( $sql_line );
				// WPDB returns row count for some queries; 0 is success for others
				if ( 0 === $req ) $req = true;
				if ( ! $req ) $this->last_error = $wpdb->last_error;
			} else {
				if ( $this->use_mysqli ) {
					$req = mysqli_query( $this->mysql_dbh, $sql_line ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- DDL operations require mysqli
					if ( ! $req ) $this->last_error = mysqli_error( $this->mysql_dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_error -- Error handling for mysqli
				} else {
					// @codingStandardsIgnoreLine
					$req = mysql_unbuffered_query( $sql_line, $this->mysql_dbh );
					// @codingStandardsIgnoreLine
					if ( ! $req ) $this->last_error = mysql_error( $this->mysql_dbh );
				}
			}

			if ( 3 == $sql_type ) $this->insert_statements_run++;
			if ( 1 == $sql_type ) $this->tables_been_dropped[] = $this->new_table_name;
			$this->statements_run++;
		}

		// Handle errors
		if ( ! $req ) {
			if ( ! $ignore_errors ) $this->errors++;
			$print_err = ( strlen( $sql_line ) > 100 ) ? substr( $sql_line, 0, 100 ) . ' ...' : $sql_line;
			$this->royalbr_instance->log_e( 'An error (%d) occurred: %s - SQL query was (type=%d): %s', $this->errors, $this->last_error, $sql_type, substr( $sql_line, 0, 200 ) );

			// Handle "MySQL server has gone away"
			if ( 'MySQL server has gone away' == $this->last_error || 'Connection was killed' == $this->last_error ) {
				$restored = false;
				for ( $i = 0; $i < 3; $i++ ) {
					if ( $this->restore_database_connection() ) {
						$restored = true;
						break;
					}
				}

				if ( ! $restored ) {
					$this->royalbr_instance->log_e( 'The Database connection has been closed and cannot be reopened.' );
					$this->royalbr_instance->log_e( 'Leaving maintenance mode' );
					$this->maintenance_mode( false );
					return new WP_Error( 'db_connection_closed', 'The Database connection has been closed and cannot be reopened.' );
				}
				return $this->execute_sql_statement( $sql_line, $sql_type, $import_table_prefix, $check_skipping );
			}

			// Handle first command errors (critical)
			if ( 1 == $this->errors && 2 == $sql_type && 0 == $this->tables_created ) {
				if ( ! empty( $this->db_permissons_forbidden['drop'] ) ) {
					$this->royalbr_instance->log_e( 'Create table failed - probably because there is no permission to drop tables and the table already exists; will continue' );
				} else {
					$this->royalbr_instance->log_e( 'Leaving maintenance mode' );
					$this->maintenance_mode( false );
					return new WP_Error( 'initial_db_error', 'An error occurred on the first CREATE TABLE - aborting run' );
				}
			} elseif ( 2 == $sql_type && 0 == $this->tables_created && ! empty( $this->db_permissons_forbidden['drop'] ) ) {
				// Decrease error counter for expected errors
				if ( ! $ignore_errors ) $this->errors--;
			} elseif ( 3 == $sql_type && false !== strpos( $this->last_error, 'Duplicate entry' ) && false !== strpos( $sql_line, 'INSERT' ) ) {
				// Retry with INSERT IGNORE for duplicate entries
				$sql_line = $this->replace_first_occurrence( 'INSERT', 'INSERT IGNORE', $sql_line );
				$this->royalbr_instance->log_e( 'Retrying SQL query with INSERT IGNORE' );
				$this->execute_sql_statement( $sql_line, $sql_type, $import_table_prefix, $check_skipping );
			} elseif ( 8 == $sql_type && 1 == $this->errors ) {
				// SET NAMES failure is critical
				$this->royalbr_instance->log_e( 'Aborted: SET NAMES %s failed: leaving maintenance mode', $this->set_names );
				$this->maintenance_mode( false );
				$dbv = $wpdb->db_version();
				$extra_msg = '';
				if ( 'utf8mb4' == strtolower( $this->set_names ) && $dbv && version_compare( $dbv, '5.2.0', '<=' ) ) {
					$extra_msg = ' This database needs to be deployed on MySQL version 5.5 or later.';
				}
				return new WP_Error( 'initial_db_error', 'An error occurred on the first SET NAMES - aborting run. To use this backup, your database server needs to support the ' . $this->set_names . ' character set.' . $extra_msg );
			} elseif ( 12 == $sql_type ) {
				// Stored routine errors are non-fatal
				$req = true;
			} elseif ( 14 == $sql_type && 1 == $this->errors ) {
				// ALTER TABLE error code
				return 1142;
			}

			// Too many errors - abort
			if ( $this->errors >= 50 ) {
				$this->maintenance_mode( false );
				return new WP_Error( 'too_many_db_errors', 'Too many database errors have occurred - aborting' );
			}

		} elseif ( 2 == $sql_type ) {
			// Successful CREATE TABLE
			if ( empty( $this->db_permissons_forbidden['lock'] ) ) $this->lock_table( $this->new_table_name );
			$this->tables_created++;
		}

		// Log progress periodically
		if ( $this->line > 0 && 0 == $this->line % 50 ) {
			if ( $this->line > $this->line_last_logged && ( 0 == $this->line % 250 || $this->line < 250 ) ) {
				$this->line_last_logged = $this->line;
				$time_taken = microtime( true ) - $this->start_time;
				$this->royalbr_instance->log_e( 'Database queries processed: %d in %.2f seconds', $this->line, $time_taken );
			}
		}

		return $req;
	}

	// ========================================================================
	// BATCH 4: TABLE PROCESSING FUNCTIONS
	
	// ========================================================================

	/**
	 * Check if a table should be skipped during restore
	
	 *
	 * @param string $table_name Table name to check
	 * @return bool True if should skip, false otherwise
	 */
	private function table_should_be_skipped( $table_name ) {
		$skip_table = false;
		$last_table = isset( $this->continuation_data['last_processed_db_table'] ) ? $this->continuation_data['last_processed_db_table'] : '';

		$table_should_be_skipped = false;

		if ( ! empty( $this->tables_to_skip ) && in_array( $table_name, $this->tables_to_skip ) ) {
			$table_should_be_skipped = true;
		} elseif ( ! empty( $this->tables_to_restore ) && ! in_array( $table_name, $this->tables_to_restore ) && ! $this->include_unspecified_tables ) {
			$table_should_be_skipped = true;
		}

		if ( $table_should_be_skipped ) {
			if ( empty( $this->previous_table_name ) || $table_name != $this->previous_table_name ) {
				$this->royalbr_instance->log_e( 'Skipping table %s: user has chosen not to restore this table', $table_name );
			}
			$skip_table = true;
		} elseif ( ! empty( $last_table ) && ! empty( $table_name ) && $table_name != $last_table ) {
			// Skip tables until we reach the last processed table (for continuation/resume)
			if ( empty( $this->previous_table_name ) || $table_name != $this->previous_table_name ) {
				$this->royalbr_instance->log_e( 'Skipping table %s: already restored on a prior run; next table to restore: %s', $table_name, $last_table );
			}
			$skip_table = true;
		} elseif ( ! empty( $last_table ) && ! empty( $table_name ) && $table_name == $last_table ) {
			// Found the last processed table, resume from here
			unset( $this->continuation_data['last_processed_db_table'] );
			$skip_table = false;
		}

		$this->previous_table_name = $table_name;

		if ( ! $skip_table ) {
			// ROYALBR: Skip restore update logging (simplified)
			// In full implementation, this would save last_processed_db_table for resume capability
			if ( is_array( $this->continuation_data ) ) {
				$this->continuation_data['last_processed_db_table'] = $table_name;
			}
		}

		return $skip_table;
	}

	/**
	 * Check if we should restore this specific table
	
	 *
	 * @param string $table_name Table name to check
	 * @return bool True if should restore, false otherwise
	 */
	private function restore_this_table( $table_name ) {
		$unprefixed_table_name = substr( $table_name, strlen( $this->old_table_prefix ) );

		// ROYALBR: Skip multisite selective restore (ROYALBR is single-site only)
		// This section handles restoring specific multisite subsites

		// Check the table specifically
		if ( ! isset( $this->restore_this_table[ $table_name ] ) ) {

			// ROYALBR: Skip filter, just default to true
			// ROYALBR applies filter here: royalbr_restore_this_table (not implemented yet)
			$this->restore_this_table[ $table_name ] = true;

			if ( false === $this->restore_this_table[ $table_name ] ) {
				// The first time it's looked into, it gets logged
				$this->royalbr_instance->log_e( 'Skipping table %s: this table will not be restored', $table_name );
				$this->restore_this_table[ $table_name ] = 0;
			}
		}

		return $this->restore_this_table[ $table_name ];
	}

	/**
	 * Maybe perform atomic restore by renaming temporary table to final name
	
	 *
	 * @return string Final table name
	 */
	private function maybe_rename_restored_table() {
		// If this is set then we do not want to attempt an atomic restore
		if ( $this->disable_atomic_on_current_table ) {
			$this->disable_atomic_on_current_table = false;
			return $this->original_table_name;
		}

		// If the table names are the same then we do not want to attempt an atomic restore
		if ( $this->original_table_name == $this->restoring_table ) {
			return $this->original_table_name;
		}

		// If we have skipped this table then we don't want to attempt the atomic restore
		if ( ! $this->restore_this_table( $this->original_table_name ) ) {
			return $this->original_table_name;
		}

		if ( empty( $this->db_permissons_forbidden['rename'] ) ) {
			$this->royalbr_instance->log_e( 'Atomic restore: dropping original table (%s)', $this->original_table_name );
			$this->remove_database_tables( array( $this->original_table_name ) );
			$this->royalbr_instance->log_e( 'Atomic restore: renaming new table (%s) to final table name (%s)', $this->restoring_table, $this->original_table_name );
			$this->rename_table( $this->restoring_table, $this->original_table_name );
		}

		return $this->original_table_name;
	}

	// ========================================================================
	// BATCH 5: REWRITE RULES & OPTION FILTERS
	// ========================================================================

	/**
	 * Custom Flush rewrite rules after restore
	 */
	private function custom_flush_rewrite_rules() {

		$filter_these = array( 'permalink_structure', 'rewrite_rules', 'page_on_front' );

		foreach ( $filter_these as $opt ) {
			add_filter( 'pre_option_' . $opt, array( $this, 'option_filter_' . $opt ) );
		}

		global $wp_rewrite;
		$wp_rewrite->init();

		if ( function_exists( 'save_mod_rewrite_rules' ) ) {
			save_mod_rewrite_rules();
		}
		if ( function_exists( 'iis7_save_url_rewrite_rules' ) ) {
			iis7_save_url_rewrite_rules();
		}

		foreach ( $filter_these as $opt ) {
			remove_filter( 'pre_option_' . $opt, array( $this, 'option_filter_' . $opt ) );
		}

	}

	/**
	 * Option filter for permalink_structure
	
	 *
	 * @param mixed $val Unused
	 * @return mixed
	 */
	public function option_filter_permalink_structure( $val ) {
		return $this->option_filter_get( 'permalink_structure' );
	}

	/**
	 * Option filter for page_on_front
	
	 *
	 * @param mixed $val Unused
	 * @return mixed
	 */
	public function option_filter_page_on_front( $val ) {
		return $this->option_filter_get( 'page_on_front' );
	}

	/**
	 * Option filter for rewrite_rules
	
	 *
	 * @param mixed $val Unused
	 * @return mixed
	 */
	public function option_filter_rewrite_rules( $val ) {
		return $this->option_filter_get( 'rewrite_rules' );
	}

	/**
	 * BATCH 10: Theme/stylesheet option filters
	
	 */

	/**
	 * WordPress option filter for template
	
	 *
	 * @param mixed $val Pre-filter value
	 * @return mixed Filtered value
	 */
	public function option_filter_template( $val ) {
		return $this->option_filter_get( 'template' );
	}

	/**
	 * WordPress option filter for stylesheet
	
	 *
	 * @param mixed $val Pre-filter value
	 * @return mixed Filtered value
	 */
	public function option_filter_stylesheet( $val ) {
		return $this->option_filter_get( 'stylesheet' );
	}

	/**
	 * WordPress option filter for template_root
	
	 *
	 * @param mixed $val Pre-filter value
	 * @return mixed Filtered value
	 */
	public function option_filter_template_root( $val ) {
		return $this->option_filter_get( 'template_root' );
	}

	/**
	 * WordPress option filter for stylesheet_root
	
	 *
	 * @param mixed $val Pre-filter value
	 * @return mixed Filtered value
	 */
	public function option_filter_stylesheet_root( $val ) {
		return $this->option_filter_get( 'stylesheet_root' );
	}

	/**
	 * Get option value during restore (from restored table, not current)
	 * ROYALBR option_filter_get pattern
	 *
	 * @param string $option_name Option name
	 * @return mixed Option value
	 */
	private function option_filter_get( $option_name ) {
		global $wpdb;

		// Use $wpdb->options which always points to the correct options table,
		// even after atomic rename operations
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $option_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Has to be get_row instead of get_var because of funkiness with 0, false, null values
		return ( is_object( $row ) ) ? $row->option_value : false;
	}

	// ========================================================================
	// BATCH 6: PLUGIN VALIDATION FUNCTIONS
	
	// ========================================================================

	/**
	 * Check active plugins and deactivate any that are missing
	
	 *
	 * @param string $import_table_prefix Table prefix being used for import
	 */
	private function check_active_plugins( $import_table_prefix ) {
		global $wpdb;

		// Single site handling (ROYALBR is single-site only)
		$plugins = $wpdb->get_row( "SELECT option_value FROM {$import_table_prefix}options WHERE option_name = 'active_plugins'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $plugins->option_value ) ) {
			return;
		}

		// Deactivate missing plugins
		$plugins = $this->deactivate_missing_plugins( $plugins->option_value );
		$plugins_array = @unserialize( $plugins ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( ! is_array( $plugins_array ) ) {
			$plugins_array = array();
		}

		// Get current Royal Backup plugin slug (the one running the restore)
		$current_royalbr_slug = plugin_basename( ROYALBR_PLUGIN_DIR . 'royal-backup-reset.php' );

		// Remove any Royal Backup version from backup's list, then add current version
		// This handles free→pro and pro→free scenarios correctly
		$plugins_array = array_values(
			array_filter(
				$plugins_array,
				function ( $plugin ) {
					return strpos( $plugin, 'royal-backup-reset' ) === false;
				}
			)
		);

		// Add current Royal Backup version to ensure it stays active
		$plugins_array[] = $current_royalbr_slug;

		$plugins = serialize( $plugins_array ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$wpdb->query( $wpdb->prepare( "UPDATE {$import_table_prefix}options SET option_value=%s WHERE option_name='active_plugins'", $plugins ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deactivate plugins that are no longer installed
	
	 *
	 * @param string $plugins Serialized active plugins list
	 * @return string Filtered serialized plugins list
	 */
	private function deactivate_missing_plugins( $plugins ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Clear plugin cache to ensure fresh list after file restoration
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache();
		}

		$installed_plugins = array_keys( get_plugins() );
		$plugins_array = @unserialize( $plugins ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

		foreach ( $plugins_array as $key => $path ) {
			// Skip Royal Backup - it's running the restore, no need to check if installed
			if ( strpos( $path, 'royal-backup-reset' ) !== false ) {
				continue;
			}

			// Single site and multisite have a different array structure
			// In single site the path is the array value, in multisite the path is the array key
			if ( ! in_array( $key, $installed_plugins ) && ! in_array( $path, $installed_plugins ) ) {
				$log_path = $this->is_multisite ? $key : $path;
				$this->royalbr_instance->log_e( 'Plugin path %s not found: de-activating.', $log_path );
				unset( $plugins_array[ $key ] );
			}
		}

		$plugins = serialize( $plugins_array ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		return $plugins;
	}

	// ========================================================================
	// BATCH 7: prepare_create_table() - THE COMPLEX ONE
	
	// This is ~325 lines, simplified to ~200 lines for ROYALBR
	// ========================================================================

	/**
	 * Prepare CREATE TABLE statement before execution
	
	 *
	 * This function handles:
	 * - Table prefix replacement
	 * - Engine compatibility
	 * - Charset/collation compatibility
	 * - Generated columns (MySQL 5.7+/MariaDB 10.2+)
	 * - Foreign key constraints
	 * - Atomic restore table naming
	 *
	 * @param string $create_table_statement CREATE TABLE statement
	 * @param string $import_table_prefix    Import table prefix
	 * @param array  $supported_engines      Supported DB engines
	 * @param array  $supported_charsets     Supported charsets
	 * @param array  $supported_collations   Supported collations
	 * @return string Modified CREATE TABLE statement
	 */
	private function prepare_create_table( $create_table_statement, $import_table_prefix, $supported_engines, $supported_charsets, $supported_collations ) {
		global $wpdb;

		$royalbr_restorer_collate = isset( $this->restore_options['royalbr_restorer_collate'] ) ? $this->restore_options['royalbr_restorer_collate'] : '';

		$non_wp_table = false;

		if ( null === $this->old_table_prefix && preg_match( '/^([a-z0-9]+)_.*$/i', $this->table_name, $tmatches ) ) {
			$this->old_table_prefix = $tmatches[1] . '_';
			$this->royalbr_instance->log_e( 'Old table prefix: %s', $this->old_table_prefix );
			$this->royalbr_instance->log_e( 'Old table prefix (detected from creating first table): %s', $this->old_table_prefix );
		}

		$create_table_statement = $this->replace_last_occurrence( 'TYPE=', 'ENGINE=', $create_table_statement );

		if ( '' === $this->old_table_prefix ) {
			$this->new_table_name = $import_table_prefix . $this->table_name;
		} else {
			$this->new_table_name = $this->old_table_prefix ? $this->replace_first_occurrence( $this->old_table_prefix, $import_table_prefix, $this->table_name ) : $this->table_name;
			// If table name unchanged after prefix replacement, it's a non-WP table
			if ( empty( $this->db_permissons_forbidden['rename'] ) && $this->old_table_prefix && $this->new_table_name == $this->table_name ) {
				$non_wp_table = true;
				$this->new_table_name = $import_table_prefix . $this->table_name;
			}
		}

		if ( $this->restoring_table ) {
			// Attempt to reconnect if DB connection dropped
			$this->check_db_connection( $this->wpdb_obj );

			// After restoring options table, capture old_siteurl, old_home, old_content
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $import_table_prefix is dynamic during restore operations, table prefix cannot use prepare()
			if ( $this->restoring_table == $import_table_prefix . 'options' ) {
				if ( '' == $this->old_siteurl || '' == $this->old_home || '' == $this->old_content ) {
					if ( '' == $this->old_siteurl ) {
						$result = $wpdb->get_row( "SELECT option_value FROM " . $import_table_prefix . 'options' . " WHERE option_name='siteurl'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$this->old_siteurl = $result ? untrailingslashit( $result->option_value ) : '';
					}
					if ( '' == $this->old_home ) {
						$result = $wpdb->get_row( "SELECT option_value FROM " . $import_table_prefix . 'options' . " WHERE option_name='home'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$this->old_home = $result ? untrailingslashit( $result->option_value ) : '';
					}
					if ( '' == $this->old_content ) {
						$this->old_content = $this->old_siteurl . '/wp-content';
					}
				}
			}
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

			// Complete previous table restore
			if ( '' !== $this->restoring_table && $this->restoring_table != $this->new_table_name ) {
				$final_table_name = $this->maybe_rename_restored_table();
				$this->restored_table( $final_table_name, $this->final_import_table_prefix, $this->old_table_prefix, $this->table_engine );
			}
		}

		$constraint_found = false;
		if ( preg_match_all( '/CONSTRAINT ([\a-zA-Z0-9_\']+) FOREIGN KEY \([a-zA-z0-9_\', ]+\) REFERENCES \'?([a-zA-z0-9_]+)\'? /i', $create_table_statement, $constraint_matches ) ) {
			$constraint_found = true;
		} elseif ( preg_match_all( '/ FOREIGN KEY \([a-zA-z0-9_\', ]+\) REFERENCES \'?([a-zA-z0-9_]+)\'? /i', $create_table_statement, $constraint_matches ) ) {
			$constraint_found = true;
		}

		// Disable atomic restore for tables with constraints
		if ( $constraint_found && empty( $this->db_permissons_forbidden['rename'] ) && ! $this->is_dummy_db_restore ) {
			$import_table_prefix = $this->final_import_table_prefix;
			$this->disable_atomic_on_current_table = true;

			if ( '' === $this->old_table_prefix ) {
				$this->new_table_name = $import_table_prefix . $this->table_name;
			} else {
				$this->new_table_name = $this->old_table_prefix ? $this->replace_first_occurrence( $this->old_table_prefix, $import_table_prefix, $this->table_name ) : $this->table_name;
			}

			$this->royalbr_instance->log_e( 'Constraints found, will disable atomic restore for current table (%s)', $this->table_name );
			$this->remove_database_tables( array( $this->new_table_name ) );
		}

		$this->table_engine = '(?)';
		$engine_change_message = '';
		if ( preg_match( '/ENGINE=([^\s;]+)/', $create_table_statement, $eng_match ) ) {
			$this->table_engine = $eng_match[1];
			if ( isset( $supported_engines[ strtolower( $this->table_engine ) ] ) ) {
				// Engine is supported
				if ( 'myisam' == strtolower( $this->table_engine ) ) {
					$create_table_statement = preg_replace( '/PAGE_CHECKSUM=\d\s?/', '', $create_table_statement, 1 );
				}
			} else {
				// Engine not supported, change to MyISAM
				$engine_change_message = sprintf( 'Requested table engine (%s) is not present - changing to MyISAM.', $this->table_engine );
				$create_table_statement = $this->replace_last_occurrence( "ENGINE=$this->table_engine", 'ENGINE=MyISAM', $create_table_statement );
				$this->table_engine = 'MyISAM';
				// Remove MariaDB/Aria options
				$create_table_statement = preg_replace( '/PAGE_CHECKSUM=\d\s?/', '', $create_table_statement, 1 );
				$create_table_statement = preg_replace( '/TRANSACTIONAL=\d\s?/', '', $create_table_statement, 1 );
			}
		}

		$charset_change_message = '';
		if ( preg_match_all( '/\b(CHARSET|CHARACTER SET)(\s*=?\s*)([^\s;,]+)/i', $create_table_statement, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$charset_original = $match[3];
				if ( ! isset( $supported_charsets[ strtolower( $charset_original ) ] ) ) {
					// Charset not supported, remove it
					$charset_change_message = sprintf( 'Requested character set (%s) is not present - removing.', $charset_original );
					$create_table_statement = str_replace( $match[0], '', $create_table_statement );
				}
			}
		}

		$collate_change_message = '';
		if ( ! empty( $royalbr_restorer_collate ) && preg_match_all( '/COLLATE[\s=]*([a-zA-Z0-9._-]+)/i', $create_table_statement, $collate_matches ) ) {
			foreach ( $collate_matches[1] as $collate ) {
				if ( ! isset( $supported_collations[ strtolower( $collate ) ] ) ) {
					if ( 'choose_a_default_for_each_table' == $royalbr_restorer_collate ) {
						$create_table_statement = str_ireplace( "COLLATE $collate", '', $create_table_statement );
					} else {
						$create_table_statement = str_ireplace( "COLLATE $collate", "COLLATE $royalbr_restorer_collate", $create_table_statement );
					}
					$collate_change_message = sprintf( 'Requested table collation (%s) is not present - changing.', $collate );
				}
			}
		}

		$logline = 'Processing table (' . $this->table_engine . '): ' . $this->table_name;
		if ( null !== $this->old_table_prefix && $import_table_prefix != $this->old_table_prefix ) {
			if ( $this->restore_this_table( $this->table_name ) ) {
				$logline .= ' - will restore as: ' . $this->new_table_name;
			} else {
				$logline .= ' - skipping';
			}
			if ( '' === $this->old_table_prefix || $non_wp_table ) {
				$create_table_statement = $this->replace_first_occurrence( $this->table_name, $this->new_table_name, $create_table_statement );
			} else {
				$create_table_statement = $this->replace_first_occurrence( $this->old_table_prefix, $import_table_prefix, $create_table_statement );
			}

			$this->restored_table_names[] = $this->new_table_name;
		}

		// ROYALBR: SKIPPING this complex section for now - it's ~80 lines of very complex MySQL 5.7+/MariaDB 10.2+ logic
		// This handles virtual/stored/persistent columns which are advanced features
		// We can add this later if needed

		$this->royalbr_instance->log_e( $logline );
		if ( $non_wp_table ) {
			$this->original_table_name = $this->replace_first_occurrence( $this->import_table_prefix, '', $this->new_table_name );
		} else {
			$this->original_table_name = $this->replace_first_occurrence( $this->import_table_prefix, $this->final_import_table_prefix, $this->new_table_name );
		}
		$this->restoring_table = $this->new_table_name;

		// Progress update: DB table restore (
		global $royalbr_instance;
		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
			$royalbr_instance->log_restore_update(
				array(
					'type'  => 'state',
					'stage' => 'db',
					'data'  => array(
						'stage' => 'table',
						'table' => $this->original_table_name,
					),
				)
			);
		}

		if ( $charset_change_message ) {
			$this->royalbr_instance->log_e( $charset_change_message );
		}
		if ( $collate_change_message ) {
			$this->royalbr_instance->log_e( $collate_change_message );
		}
		if ( $engine_change_message ) {
			$this->royalbr_instance->log_e( $engine_change_message );
		}

		return $create_table_statement;
	}

	/**
	 * Helper: String replace (last occurrence)
	 *
	 * @param string $search  String to search for
	 * @param string $replace Replacement string
	 * @param string $subject Subject string
	 * @return string Modified string
	 */
	private function replace_last_occurrence( $search, $replace, $subject ) {
		$pos = strrpos( $subject, $search );
		if ( false !== $pos ) {
			$subject = substr_replace( $subject, $replace, $pos, strlen( $search ) );
		}
		return $subject;
	}

	/**
	 * Helper: String replace (first occurrence)
	 *
	 * @param string $search  String to search for
	 * @param string $replace Replacement string
	 * @param string $subject Subject string
	 * @return string Modified string
	 */
	private function replace_first_occurrence( $search, $replace, $subject ) {
		// PHP 8+ compatibility: handle null values
		if ( null === $replace ) {
			$replace = '';
		}
		if ( null === $subject ) {
			$subject = '';
		}

		$pos = strpos( $subject, $search );
		if ( false !== $pos ) {
			$subject = substr_replace( $subject, $replace, $pos, strlen( $search ) );
		}
		return $subject;
	}

	/**
	 * BATCH 8: restore_database_backup - THE MASSIVE ONE
	 * Restore the database backup (main database restoration logic)
	 *
	 * @param string $working_dir           Working directory path
	 * @param string $working_dir_localpath Local filesystem path to working directory
	 * @param string $import_table_prefix   Table prefix to use during import
	 * @param string $db_basename           Database filename (optional, defaults to auto-detect)
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	private function restore_database_backup( $working_dir, $working_dir_localpath, $import_table_prefix, $db_basename = null ) {

		global $wpdb;

		do_action( 'royalbr_restore_db_pre' );

		// Save configuration bundle BEFORE any database operations
		$this->save_configuration_bundle();

		// Legacy option for upload path handling
		$this->prior_upload_path = get_option( 'upload_path' );

		// Check for safe mode
		// @codingStandardsIgnoreLine
		if ( @ini_get( 'safe_mode' ) && 'off' != strtolower( @ini_get( 'safe_mode' ) ) ) {
			$this->royalbr_instance->log_e( 'Warning - PHP safe_mode is active. Timeouts are much more likely.' );
		}

		// Determine database file basename
		if ( null === $db_basename ) {
			// Auto-detect (legacy behavior)
			$db_basename = 'backup.db.gz';

			// For ROYALBR, we always use backup.db.gz format
			// Check if file exists, if not try backup.db
			if ( ! file_exists( $working_dir_localpath . '/' . $db_basename ) && file_exists( $working_dir_localpath . '/backup.db' ) ) {
				$db_basename = 'backup.db';
			}
		}

		// Handle foreign backups (backups from other plugins)
		if ( ! empty( $this->royalbr_foreign ) ) {
			$plugins = apply_filters( 'royalbr_accept_archivename', array() );

			if ( empty( $plugins[ $this->royalbr_foreign ] ) ) {
				return new WP_Error( 'unknown', sprintf( 'Backup created by unknown source (%s) - cannot be restored.', $this->royalbr_foreign ) );
			}

			// Try alternative filenames for foreign backups
			if ( ! file_exists( $working_dir_localpath . '/' . $db_basename ) && file_exists( $working_dir_localpath . '/backup.db' ) ) {
				$db_basename = 'backup.db';
			} elseif ( ! file_exists( $working_dir_localpath . '/' . $db_basename ) && file_exists( $working_dir_localpath . '/backup.db.bz2' ) ) {
				$db_basename = 'backup.db.bz2';
			}

			// Allow plugins to filter the database filename
			if ( ! file_exists( $working_dir_localpath . '/' . $db_basename ) ) {
				$separatedb       = empty( $plugins[ $this->royalbr_foreign ]['separatedb'] ) ? false : true;
				$filtered_db_name = apply_filters( 'royalbr_foreign_dbfilename', false, $this->royalbr_foreign, $this->royalbr_backup_set, $working_dir_localpath, $separatedb );
				if ( is_string( $filtered_db_name ) ) {
					$db_basename = $filtered_db_name;
				}
			}
		}

		// Validate file exists and is readable
		if ( false === $db_basename || ! is_readable( $working_dir_localpath . '/' . $db_basename ) ) {
			return new WP_Error( 'dbopen_failed', 'Failed to find database file (' . $working_dir . '/' . $db_basename . ')' );
		}

		// Provide user feedback
		$this->royalbr_instance->log_e( 'Restoring the database (on a large site this can take a long time - if it times out (which can happen if your web hosting company has configured your hosting to limit resources) then you should use a different method, such as phpMyAdmin)...' );

		// Determine file type
		$is_plain = ( '.db' == substr( $db_basename, -3, 3 ) );
		$is_bz2   = ( '.db.bz2' == substr( $db_basename, -7, 7 ) );

		// Open database file handle
		if ( $is_plain ) {
			$dbhandle = fopen( $working_dir_localpath . '/' . $db_basename, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Required for streaming large SQL files
		} elseif ( $is_bz2 ) {
			if ( ! function_exists( 'bzopen' ) ) {
				$this->royalbr_instance->log_e( 'Your web server\'s PHP installation has these functions disabled: bzopen.' );
				$this->royalbr_instance->log_e( 'Your hosting company must enable these functions before restoration can work.' );
				return new WP_Error( 'bzopen_unavailable', 'bzopen function is not available on this server' );
			}
			$dbhandle = bzopen( $working_dir_localpath . '/' . $db_basename, 'r' );
		} else {
			$dbhandle = gzopen( $working_dir_localpath . '/' . $db_basename, 'r' );
		}

		if ( ! $dbhandle ) {
			return new WP_Error( 'dbopen_failed', 'Failed to open database file' );
		}

		$this->line = 0;

		// Determine if we should use wpdb or direct MySQL
		if ( $this->use_wpdb() ) {
			$this->royalbr_instance->log_e( 'Using wpdb (will be slower)' );
		} else {
			$this->royalbr_instance->log_e( 'Using direct MySQL access; mysqli=%s', ( $this->use_mysqli ? '1' : '0' ) );
			if ( $this->use_mysqli ) {
				@mysqli_query( $this->mysql_dbh, 'SET SESSION query_cache_type = OFF;' ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- DDL operations require mysqli
			} else {
				// @codingStandardsIgnoreLine
				@mysql_query( 'SET SESSION query_cache_type = OFF;', $this->mysql_dbh );
			}
		}

		// Set SQL mode
		ROYALBR_Database_Utility::configure_db_sql_mode(
			array( 'NO_AUTO_VALUE_ON_ZERO' ),
			array(),
			$this->use_wpdb() ? null : $this->mysql_dbh
		);

		// Register shutdown function
		register_shutdown_function( array( $this, 'on_shutdown' ) );

		// Get supported engines, charsets, collations
		$supported_engines = array_change_key_case( (array) $wpdb->get_results( 'SHOW ENGINES', OBJECT_K ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$supported_charsets = array_change_key_case( (array) $wpdb->get_results( 'SHOW CHARACTER SET', OBJECT_K ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$supported_collations = array_change_key_case( (array) $wpdb->get_results( 'SHOW COLLATION', OBJECT_K ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->table_engine = '';

		// Initialize counters
		$this->errors = 0;
		$this->statements_run = 0;
		$this->insert_statements_run = 0;
		$this->tables_created = 0;

		// Initialize SQL parsing variables
		$sql_line = '';
		$sql_type = -1;

		$this->start_time = microtime( true );

		// Initialize restoration tracking variables
		$this->old_siteurl = '';
		$this->old_home = '';
		$this->old_content = '';
		$this->old_uploads = '';
		$this->old_table_prefix = null;
		$this->old_siteinfo = array();
		$gathering_siteinfo = true;

		// Initialize permission tracking
		$this->db_permissons_forbidden['create'] = false;
		$this->db_permissons_forbidden['drop'] = false;
		$this->db_permissons_forbidden['lock'] = false;
		$this->db_permissons_forbidden['rename'] = false;
		$this->db_permissons_forbidden['triggers'] = true; // Will be flipped if confirmed

		$this->last_error = '';
		$random_table_name = 'royalbr_tmp_' . wp_rand( 0, 9999999 ) . md5( microtime( true ) );
		$renamed_random_table_name = 'royalbr_tmp_' . wp_rand( 0, 9999999 ) . md5( microtime( true ) );
		$last_created_generated_columns_table = '';

		// Test database permissions
		if ( $this->use_wpdb() ) {
			$req = $wpdb->query( "CREATE TABLE $random_table_name (test INT)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			if ( 0 === $req ) $req = true;
			if ( ! $req ) $this->last_error = $wpdb->last_error;
			$this->last_error_no = false;

			if ( $req && false !== $wpdb->query( "CREATE TRIGGER test_trigger BEFORE INSERT ON $random_table_name FOR EACH ROW SET @sum = @sum + NEW.test" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$this->db_permissons_forbidden['triggers'] = false;
			}
		} else {
			if ( $this->use_mysqli ) {
				$req = mysqli_query( $this->mysql_dbh, "CREATE TABLE $random_table_name (test INT)" ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- DDL operations require mysqli
			} else {
				// @codingStandardsIgnoreLine
				$req = mysql_unbuffered_query( "CREATE TABLE $random_table_name (test INT)", $this->mysql_dbh );
			}

			if ( ! $req ) {
				// @codingStandardsIgnoreLine
				$this->last_error = $this->use_mysqli ? mysqli_error( $this->mysql_dbh ) : mysql_error( $this->mysql_dbh );
				// @codingStandardsIgnoreLine
				$this->last_error_no = $this->use_mysqli ? mysqli_errno( $this->mysql_dbh ) : mysql_errno( $this->mysql_dbh );
			} else {
				if ( $this->use_mysqli ) {
					$reqtrigger = mysqli_query( $this->mysql_dbh, "CREATE TRIGGER test_trigger BEFORE INSERT ON $random_table_name FOR EACH ROW SET @sum = @sum + NEW.test" ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- DDL operations require mysqli
				} else {
					// @codingStandardsIgnoreLine
					$reqtrigger = mysql_unbuffered_query( "CREATE TRIGGER test_trigger BEFORE INSERT ON $random_table_name FOR EACH ROW SET @sum = @sum + NEW.test", $this->mysql_dbh );
				}
				if ( $reqtrigger ) $this->db_permissons_forbidden['triggers'] = false;
			}
		}

		// Check CREATE permission
		if ( ! $req && ( $this->use_wpdb() || 1142 === $this->last_error_no ) ) {
			$this->db_permissons_forbidden['create'] = true;
			$this->db_permissons_forbidden['drop'] = true;

			if ( $this->is_dummy_db_restore ) {
				return new WP_Error( 'abort_dummy_restore', 'Your database user does not have permission to drop tables' );
			}

			$this->royalbr_instance->log_e( 'WARNING - Database user does not have CREATE permission. Will attempt restore by emptying tables.' );
			$this->royalbr_instance->log_e( 'Error was: %s (%d)', $this->last_error, $this->last_error_no );
		} else {
			// Test RENAME permission
			if ( 1142 === $this->rename_table( $random_table_name, $renamed_random_table_name ) ) {
				$this->db_permissons_forbidden['rename'] = true;
				$this->royalbr_instance->log_e( 'Database user has no RENAME permission - restoration will be non-atomic' );
			} else {
				$random_table_name = $renamed_random_table_name;
			}

			// Test LOCK permission
			if ( 1142 === $this->lock_table( $random_table_name ) ) {
				$this->db_permissons_forbidden['lock'] = true;
				$this->royalbr_instance->log_e( 'Database user has no LOCK permission - will not lock after CREATE' );
			}

			// Test DROP permission
			if ( $this->use_wpdb() ) {
				$req = $wpdb->query( "DROP TABLE $random_table_name" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
				if ( 0 === $req ) $req = true;
				if ( ! $req ) $this->last_error = $wpdb->last_error;
				$this->last_error_no = false;
			} else {
				if ( $this->use_mysqli ) {
					$req = mysqli_query( $this->mysql_dbh, "DROP TABLE $random_table_name" ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- DDL operations require mysqli
				} else {
					// @codingStandardsIgnoreLine
					$req = mysql_unbuffered_query( "DROP TABLE $random_table_name", $this->mysql_dbh );
				}
				if ( ! $req ) {
					// @codingStandardsIgnoreLine
					$this->last_error = $this->use_mysqli ? mysqli_error( $this->mysql_dbh ) : mysql_error( $this->mysql_dbh );
					// @codingStandardsIgnoreLine
					$this->last_error_no = $this->use_mysqli ? mysqli_errno( $this->mysql_dbh ) : mysql_errno( $this->mysql_dbh );
				}
			}

			if ( ! $req && ( $this->use_wpdb() || 1142 === $this->last_error_no ) ) {
				$this->db_permissons_forbidden['drop'] = true;
				$this->db_permissons_forbidden['rename'] = true;

				if ( $this->is_dummy_db_restore ) {
					return new WP_Error( 'abort_dummy_restore', 'Your database user does not have permission to drop tables' );
				}

				$this->royalbr_instance->log_e( 'WARNING - Database user does not have DROP permission. Will attempt restore by emptying tables.' );
			}
		}

		// Determine if atomic restore is possible
		if ( empty( $this->db_permissons_forbidden['rename'] ) && ! $this->is_dummy_db_restore ) {
			// Use temporary random prefix for atomic restore
			$this->import_table_prefix = 'royalbr_tmp_' . wp_rand( 0, 99999 ) . '_';
			$import_table_prefix = $this->import_table_prefix;
		} else {
			$this->db_permissons_forbidden['rename'] = true;
		}

		$this->restoring_table = '';

		$this->max_allowed_packet = $this->get_max_packet_size();

		// Enter maintenance mode
		$this->royalbr_instance->log_e( 'Entering maintenance mode' );
		$this->maintenance_mode( true );

		// SQL parsing variables
		$delimiter = ';';
		$delimiter_regex = ';';
		$virtual_columns_exist = false;

		// Handle continuation data for stored routines (resumption support)
		if ( isset( $this->continuation_data['old_log_bin_trust_function_creators'] ) ) {
			// It's a resumption
			$old_log_bin_trust_function_creators = $this->continuation_data['old_log_bin_trust_function_creators'];
		} else {
			// Check taskdata
			$old_log_bin_trust_function_creators = null;
			// Initialize to null
		}

		// Check stored routine support
		if ( is_null( $this->stored_routine_supported ) ) {
			$this->stored_routine_supported = ROYALBR_Database_Utility::detect_routine_support();
		}
		if ( is_wp_error( $this->stored_routine_supported ) ) {
			$this->royalbr_instance->log_e( 'Stored routine check error: %s', $this->stored_routine_supported->get_error_message() );
		}

		global $royalbr_instance;
		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
			$royalbr_instance->log_restore_update( array(
				'type'  => 'state',
				'stage' => 'db',
				'data'  => array( 'stage' => 'begun', 'table' => '' )
			) );
		}

		// Main parsing loop - read SQL file line by line
		while ( ( $is_plain && ! feof( $dbhandle ) ) || ( ! $is_plain && ( ( $is_bz2 ) || ( ! $is_bz2 && ! gzeof( $dbhandle ) ) ) ) ) {

			// Read next line (up to 1MB)
			if ( $is_plain ) {
				$buffer = rtrim( fgets( $dbhandle, 1048576 ) );
			} elseif ( $is_bz2 ) {
				if ( ! isset( $bz2_buffer ) ) {
					$bz2_buffer = '';
				}
				$buffer = '';
				if ( strlen( $bz2_buffer ) < 524288 ) {
					$bz2_buffer .= bzread( $dbhandle, 1048576 );
				}
				if ( bzerrno( $dbhandle ) !== 0 ) {
					$this->royalbr_instance->log_e( 'bz2 error: %s (code: %d)', bzerrstr( $dbhandle ), bzerrno( $dbhandle ) );
					break;
				}
				if ( false !== $bz2_buffer && '' !== $bz2_buffer ) {
					if ( false !== ( $p = strpos( $bz2_buffer, "\n" ) ) ) {
						$buffer     .= substr( $bz2_buffer, 0, $p + 1 );
						$bz2_buffer  = substr( $bz2_buffer, $p + 1 );
					} else {
						$buffer     .= $bz2_buffer;
						$bz2_buffer  = '';
					}
				} else {
					break;
				}
				$buffer = rtrim( $buffer );
			} else {
				$buffer = rtrim( gzgets( $dbhandle, 1048576 ) );
			}

			// Skip comments and parse metadata
			if ( empty( $buffer ) || '#' == substr( $buffer, 0, 1 ) || preg_match( '/^--(\s|$)/', substr( $buffer, 0, 3 ) ) ) {
				// Parse metadata from comments
				if ( '' == $this->old_siteurl && preg_match( '/^\# Backup of: (http(.*))$/', $buffer, $matches ) ) {
					$this->old_siteurl = untrailingslashit( $matches[1] );
					$this->royalbr_instance->log_e( 'Backup of: %s', $this->old_siteurl );
					do_action( 'royalbr_restore_db_record_old_siteurl', $this->old_siteurl );

				} elseif ( false === $this->created_by_version && preg_match( '/^\# Created by Royal Backup & Reset/', $buffer ) ) {
					$this->created_by_version = true;
					$this->royalbr_instance->log_e( 'Backup created by: %s', 'Royal Backup & Reset' );

				} elseif ( '' == $this->old_home && preg_match( '/^\# Home URL: (http(.*))$/', $buffer, $matches ) ) {
					$this->old_home = untrailingslashit( $matches[1] );
					if ( $this->old_siteurl && $this->old_home != $this->old_siteurl ) {
						$this->royalbr_instance->log_e( 'Site home: %s', $this->old_home );
					}
					do_action( 'royalbr_restore_db_record_old_home', $this->old_home );

				} elseif ( '' == $this->old_content && preg_match( '/^\# Content URL: (http(.*))$/', $buffer, $matches ) ) {
					$this->old_content = untrailingslashit( $matches[1] );
					$this->royalbr_instance->log_e( 'Content URL: %s', $this->old_content );
					do_action( 'royalbr_restore_db_record_old_content', $this->old_content );

				} elseif ( '' == $this->old_uploads && preg_match( '/^\# Uploads URL: (http(.*))$/', $buffer, $matches ) ) {
					$this->old_uploads = untrailingslashit( $matches[1] );
					$this->royalbr_instance->log_e( 'Uploads URL: %s', $this->old_uploads );
					do_action( 'royalbr_restore_db_record_old_uploads', $this->old_uploads );

				// Also support backwpup style: -- Table Prefix: wp_
				} elseif ( null === $this->old_table_prefix && ( preg_match( '/^\# Table prefix: ?(\S*)$/', $buffer, $matches ) || preg_match( '/^-- Table Prefix: ?(\S*)$/i', $buffer, $matches ) ) ) {
					$this->old_table_prefix = $matches[1];
					$this->royalbr_instance->log_e( 'Old table prefix: %s', $this->old_table_prefix );

				} elseif ( preg_match( '/^\# Skipped tables: (.*)$/', $buffer, $matches ) ) {
					$this->royalbr_instance->log_e( 'Skipped tables: %s', $matches[1] );

				// Site info block (multiline metadata)
				} elseif ( $gathering_siteinfo && preg_match( '/^\# Site info: (\S+)$/', $buffer, $matches ) ) {
					if ( 'end' == $matches[1] ) {
						$gathering_siteinfo = false;
						// Sanity check: single-site → multisite not supported
						if ( isset( $this->old_siteinfo['multisite'] ) && ! $this->old_siteinfo['multisite'] && is_multisite() ) {
							return new WP_Error( 'missing_addons', 'To import an ordinary WordPress site into a multisite installation is not supported.' );
						}
					} elseif ( preg_match( '/^([^=]+)=(.*)$/', $matches[1], $kvmatches ) ) {
						$key = $kvmatches[1];
						$val = $kvmatches[2];
						$this->royalbr_instance->log_e( 'Site information: %s = %s', $key, $val );
						if ( 'multisite_data' == $key ) {
							$this->old_siteinfo[ $key ] = json_decode( $val, true );
						} else {
							$this->old_siteinfo[ $key ] = $val;
						}
						if ( 'multisite' == $key ) {
							$this->royalbr_backup_is_multisite = ( $val ) ? 1 : 0;
						}
					}

				} elseif ( '' == $this->old_abspath && preg_match( '/^\# ABSPATH: ?(.*)$/', $buffer, $matches ) ) {
					if ( ABSPATH != $matches[1] && '/' != $matches[1] ) {
						$this->old_abspath = $matches[1];
						$this->royalbr_instance->log_e( 'Old ABSPATH: %s', $this->old_abspath );
						do_action( 'royalbr_restore_db_record_old_abspath', $this->old_abspath );
					}

				} elseif ( '' == $this->old_royalbr_plugin_slug && preg_match( '/^\# ROYALBR plugin slug: ?(.*)$/', $buffer, $matches ) ) {
					$this->old_royalbr_plugin_slug = $matches[1];
					$this->royalbr_instance->log_e( 'ROYALBR plugin slug: %s', $this->old_royalbr_plugin_slug );
				}
				continue;
			}

			// Detect INSERT early for splitting/combining
			if ( preg_match( '/^\s*(insert\s\s*into(?:\s*`(.+?)`|[^\(]+)(?:\s*\(.+?\))?\s*(?:values|\())/i', $sql_line . $buffer, $matches ) ) {
				$this->table_name = $matches[2];
				$sql_type = 3;
				$insert_prefix = $matches[1];

				// Handle generated columns if present
				if ( ! empty( $this->generated_columns[ $this->table_name ] ) ) {
					$this->generated_columns_exist_in_the_statement[ $this->table_name ] = ROYALBR_Database_Utility::check_insert_has_generated_cols(
						$sql_line . $buffer,
						$this->generated_columns[ $this->table_name ]['column_names']
					);

					if ( $this->table_name != $last_created_generated_columns_table ) {
						$create_statement = $this->prepare_create_table(
							$this->generated_columns[ $this->table_name ]['create_statement'],
							$import_table_prefix,
							$supported_engines,
							$supported_charsets,
							$supported_collations
						);
						$do_exec = $this->execute_sql_statement( $create_statement, 2, $import_table_prefix );
						$last_created_generated_columns_table = $this->table_name;
						if ( is_wp_error( $do_exec ) ) return $do_exec;
					}

					// Add INSERT IGNORE for generated columns
					$sql_line = preg_replace( '/^(\s*insert\s\s*into)(.+)$/is', 'insert ignore into$2', $sql_line );
					$insert_prefix = preg_replace( '/^(\s*insert\s\s*into)(.+)$/is', 'insert ignore into$2', $matches[0] );
				}

			} elseif ( preg_match( '/^\s*delimiter (\S+)\s*$/i', $sql_line . $buffer, $matches ) ) {
				$sql_type = 10;
				$delimiter = $matches[1];
				$delimiter_regex = str_replace( array( '$', '#', '/' ), array( '\$', '\#', '\/' ), $delimiter );

			} elseif ( preg_match( '/^\s*create trigger /i', $sql_line . $buffer ) ) {
				$sql_type = 9;
				$buffer = $buffer . "\n";

			} elseif ( preg_match( "/^\s*CREATE\s\s*(?:DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|'.{1,17}'@'[^\s]+'\s*|[^\s]+?\s))?(?:AGGREGATE\s\s*)?(?:PROCEDURE|FUNCTION)((?:\s\s*[^\(`]+|\s*`(?:[^`]|``)+`))\s*\(/is", $sql_line . $buffer ) ) {
				$sql_type = 12;
				$buffer = $buffer . "\n";

			} elseif ( preg_match( '/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $sql_line . $buffer, $matches ) ) {
				$sql_type = 2;
				$this->table_name = $matches[1];

				// Check for generated columns
				$generated_column_info = ROYALBR_Database_Utility::parse_generated_col_definition( $buffer, strlen( $sql_line ) );
				if ( $generated_column_info ) {
					if ( ! isset( $this->generated_columns[ $this->table_name ] ) ) {
						$this->generated_columns[ $this->table_name ] = array();
					}
					if ( ! $virtual_columns_exist ) $virtual_columns_exist = $generated_column_info['is_virtual'];
					$this->generated_columns[ $this->table_name ]['columns'][] = $generated_column_info;
					$this->generated_columns[ $this->table_name ]['column_names'][] = $generated_column_info['column_name'];
				}

				if ( ! empty( $this->generated_columns[ $this->table_name ] ) && substr( $sql_line . $buffer, -strlen( $delimiter ), strlen( $delimiter ) ) == $delimiter ) {
					$this->generated_columns[ $this->table_name ]['create_statement'] = $sql_line . $buffer;
					$this->generated_columns[ $this->table_name ]['virtual_columns_exist'] = $virtual_columns_exist;
					$virtual_columns_exist = false;
				}
			}

			// Check if line would exceed max_allowed_packet - must split
			if ( 3 == $sql_type && $sql_line && strlen( $sql_line . $buffer ) > ( $this->max_allowed_packet - 100 ) && preg_match( '/,\s*$/', $sql_line ) && preg_match( '/^\s*\(/', $buffer ) ) {

				if ( $this->table_should_be_skipped( $this->table_name ) ) {
					$sql_line = $insert_prefix . ' ';
					continue;
				}

				// Remove final comma; replace with delimiter
				$sql_line = substr( rtrim( $sql_line ), 0, strlen( $sql_line ) - 1 ) . ';';
				if ( $import_table_prefix != $this->old_table_prefix ) {
					if ( '' != $this->old_table_prefix ) {
						$sql_line = $this->replace_first_occurrence( $this->old_table_prefix, $import_table_prefix, $sql_line );
					} else {
						$sql_line = $this->replace_first_occurrence( $this->table_name, $this->table_prefix . $this->table_name, $sql_line );
					}
				}

				// Execute split line
				$this->line++;
				$this->royalbr_instance->log_e( 'Split line to avoid exceeding max packet size (%d + %d : %d)', strlen( $sql_line ), strlen( $buffer ), $this->max_allowed_packet );
				$do_exec = $this->execute_sql_statement( $sql_line, $sql_type, $import_table_prefix );
				if ( is_wp_error( $do_exec ) ) return $do_exec;

				// Reset and continue
				$sql_line = $insert_prefix . ' ';
			}

			$sql_line .= ( 9 == $sql_type && '' != $sql_line ) ? ' ' . $buffer : $buffer;

			// Check if we have a complete SQL statement
			if ( ( 3 == $sql_type && ! preg_match( '/\)\s*' . $delimiter_regex . '$/', substr( $sql_line, -5, 5 ) ) ) ||
				 ( ! in_array( $sql_type, array( 3, 9, 10, 12 ) ) && substr( $sql_line, -strlen( $delimiter ), strlen( $delimiter ) ) != $delimiter ) ||
				 ( 9 == $sql_type && ! preg_match( '/(?:END)?\s*' . $delimiter_regex . '\s*$/', $sql_line ) ) ) {
				continue;
			}

			$this->line++;

			// We now have a complete statement - process it

			// Check if INSERT is oversized
			if ( 3 == $sql_type && $sql_line && strlen( $sql_line ) > $this->max_allowed_packet ) {
				$this->log_oversized_packet( $sql_line );
				$sql_line = '';
				$sql_type = -1;

				if ( 0 == $this->insert_statements_run && $this->restoring_table && $this->restoring_table == $import_table_prefix . 'options' ) {
					$this->royalbr_instance->log_e( 'Leaving maintenance mode' );
					$this->maintenance_mode( false );
					return new WP_Error( 'initial_db_error', 'An error occurred on the first INSERT (options) - aborting run' );
				}
				continue;
			}

			// Detect DROP VIEW
			if ( preg_match( '/^\s*drop view (if exists )?\`?([^\`]*)\`?\s*' . $delimiter_regex . '/i', $sql_line, $matches ) ) {
				$sql_type = 16;
				$this->view_names[] = $matches[2];
				$this->view_names = array_unique( $this->view_names );

			// Detect DROP TABLE
			} elseif ( preg_match( '/^\s*drop table (if exists )?\`?([^\`]*)\`?\s*' . $delimiter_regex . '/i', $sql_line, $matches ) ) {
				$sql_type = 1;

				if ( ! $this->printed_new_table_prefix ) {
					$import_table_prefix = $this->prepare_sql_execution( $import_table_prefix );
					if ( false === $import_table_prefix || is_wp_error( $import_table_prefix ) ) return $import_table_prefix;
					$this->printed_new_table_prefix = true;
				}

				$this->table_name = $matches[2];
				if ( $this->table_should_be_skipped( $this->table_name ) ) {
					$sql_line = '';
					$sql_type = -1;
					continue;
				}

				// Detect old table prefix
				if ( null === $this->old_table_prefix && preg_match( '/^([a-z0-9]+)_.*$/i', $this->table_name, $tmatches ) ) {
					$this->old_table_prefix = $tmatches[1] . '_';
					$this->royalbr_instance->log_e( 'Old table prefix (detected from first table): %s', $this->old_table_prefix );
				}

				$this->new_table_name = $this->old_table_prefix ? $this->replace_first_occurrence( $this->old_table_prefix, $import_table_prefix, $this->table_name ) : $this->table_name;

				$non_wp_table = false;
				if ( empty( $this->db_permissons_forbidden['rename'] ) && $this->old_table_prefix && $this->new_table_name == $this->table_name ) {
					$non_wp_table = true;
					$this->new_table_name = $import_table_prefix . $this->table_name;
				}

				if ( $import_table_prefix != $this->old_table_prefix ) {
					if ( '' === $this->old_table_prefix || $non_wp_table ) {
						$sql_line = $this->replace_first_occurrence( $this->table_name, $this->new_table_name, $sql_line );
					} else {
						$sql_line = $this->replace_first_occurrence( $this->old_table_prefix, $import_table_prefix, $sql_line );
					}
				}

				if ( empty( $matches[1] ) ) {
					$sql_line = preg_replace( '/drop table/i', 'drop table if exists', $sql_line, 1 );
				}

				$this->tables_been_dropped[] = $this->new_table_name;

			// Detect CREATE TABLE
			} elseif ( preg_match( '/^\s*create table \`?([^\`\(]*)\`?\s*\(/i', $sql_line, $matches ) ) {
				$sql_type = 2;
				$this->insert_statements_run = 0;
				$this->table_name = $matches[1];

				if ( $this->table_should_be_skipped( $this->table_name ) || ! empty( $this->generated_columns[ $this->table_name ] ) ) {
					$sql_line = '';
					$sql_type = -1;
					continue;
				}

				if ( ! $this->printed_new_table_prefix ) {
					$import_table_prefix = $this->prepare_sql_execution( $import_table_prefix );
					if ( false === $import_table_prefix || is_wp_error( $import_table_prefix ) ) return $import_table_prefix;
					$this->printed_new_table_prefix = true;
				}

				$sql_line = $this->prepare_create_table( $sql_line, $import_table_prefix, $supported_engines, $supported_charsets, $supported_collations );

			// Detect INSERT
			} elseif ( preg_match( '/^\s*insert(?:\s\s*ignore)?\s\s*into(?:\s*`(.+?)`|[^\(]+)(?:\s*\(.+?\))?\s*(?:values|\()/i', $sql_line, $matches ) ) {
				$sql_type = 3;
				$this->table_name = $matches[1];

				if ( $this->table_should_be_skipped( $this->table_name ) ) {
					$sql_line = '';
					$sql_type = -1;
					continue;
				}

				$non_wp_table = false;
				$this->new_table_name = $this->old_table_prefix ? $this->replace_first_occurrence( $this->old_table_prefix, $import_table_prefix, $this->table_name ) : $this->table_name;

				if ( empty( $this->db_permissons_forbidden['rename'] ) && $this->old_table_prefix && $this->new_table_name == $this->table_name ) {
					$non_wp_table = true;
					$this->new_table_name = $import_table_prefix . $this->table_name;
				}

				$temp_insert_table_prefix = $this->disable_atomic_on_current_table ? $this->final_import_table_prefix : $import_table_prefix;
				if ( $temp_insert_table_prefix != $this->old_table_prefix ) {
					if ( '' === $this->old_table_prefix || $non_wp_table ) {
						$sql_line = $this->replace_first_occurrence( $this->table_name, $this->new_table_name, $sql_line );
					} else {
						$sql_line = $this->replace_first_occurrence( $this->old_table_prefix, $temp_insert_table_prefix, $sql_line );
					}
				}

			// Detect ALTER/LOCK TABLE
			} elseif ( preg_match( '/^\s*(\/\*\!40000 )?(alter|lock) tables? \`?([^\`\(]*)\`?\s+(write|disable|enable)/i', $sql_line, $matches ) ) {
				$sql_type = 4;
				if ( ! empty( $matches[3] ) && $this->table_should_be_skipped( $matches[3] ) ) {
					$sql_line = '';
					$sql_type = -1;
					continue;
				}
				$temp_insert_table_prefix = $this->disable_atomic_on_current_table ? $this->final_import_table_prefix : $import_table_prefix;
				if ( $temp_insert_table_prefix != $this->old_table_prefix ) {
					if ( '' === $this->old_table_prefix || $non_wp_table ) {
						$sql_line = $this->replace_first_occurrence( $this->table_name, $this->new_table_name, $sql_line );
					} else {
						$sql_line = $this->replace_first_occurrence( $this->old_table_prefix, $temp_insert_table_prefix, $sql_line );
					}
				}

			// Detect UNLOCK TABLES
			} elseif ( preg_match( '/^(un)?lock tables/i', $sql_line ) ) {
				$sql_type = 15;

			// Detect CREATE/DROP DATABASE
			} elseif ( preg_match( '/^(create|drop) database /i', $sql_line ) ) {
				$sql_type = 6;

			// Detect USE
			} elseif ( preg_match( '/^use /i', $sql_line ) ) {
				$sql_type = 7;

			// Detect SET NAMES
			} elseif ( preg_match( '#^\s*/\*\!40\d+ (SET NAMES) (.*)\*\/#i', $sql_line, $smatches ) ) {
				$sql_type = 8;
				$charset = rtrim( $smatches[2] );
				$this->set_names = $charset;
				if ( ! isset( $supported_charsets[ strtolower( $charset ) ] ) ) {
					$sql_line = $this->replace_last_occurrence( $smatches[1] . ' ' . $charset, 'SET NAMES utf8', $sql_line );
					$this->royalbr_instance->log_e( 'SET NAMES - requested charset %s not present, changing to utf8', $charset );
				}

			// Detect CREATE TRIGGER
			} elseif ( preg_match( '/^\s*create trigger /i', $sql_line ) ) {
				$sql_type = 9;
				if ( ! preg_match( '/(?:END)?\s*' . $delimiter_regex . '\s*$/', $sql_line ) ) continue;
				if ( preg_match( '/(?:--|#).+?' . $delimiter_regex . '\s*$/i', $buffer ) ) continue;

				if ( $import_table_prefix != $this->old_table_prefix ) {
					if ( '' != $this->old_table_prefix ) {
						$sql_line = $this->replace_first_occurrence( $this->old_table_prefix, $import_table_prefix, $sql_line );
					} else {
						$sql_line = $this->replace_first_occurrence( $this->table_name, $this->new_table_name, $sql_line );
					}
				}

				if ( ';' !== $delimiter ) {
					$sql_line = preg_replace( '/END\s*' . $delimiter_regex . '\s*$/', 'END', $sql_line );
					$sql_line = preg_replace( '/\s*' . $delimiter_regex . '\s*$/', '', $sql_line );
				}

				if ( ! empty( $this->db_permissons_forbidden['triggers'] ) ) {
					$this->royalbr_instance->log_e( 'Database user lacks permission to create triggers; statement skipped' );
				}

			// Detect DROP TRIGGER
			} elseif ( preg_match( '/^\s*drop trigger /i', $sql_line ) ) {
				if ( ';' !== $delimiter ) $sql_line = preg_replace( '/' . $delimiter_regex . '\s*$/', '', $sql_line );

			// Detect CREATE PROCEDURE/FUNCTION (stored routines)
			} elseif ( preg_match( "/^\s*CREATE\s\s*(?:OR\s\s*REPLACE\s\s*)?(?:DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|'.{1,17}'@'[^\s]+'\s*|[^\s]+?\s))?(?:AGGREGATE\s\s*)?(?:PROCEDURE|FUNCTION)((?:\s\s*[^\(`]+|\s*`(?:[^`]|``)+`))\s*\(/is", $sql_line, $routine_matches ) ) {
				$sql_type = 12;

				if ( ! preg_match( '/END\s*(?:\*\/)?' . $delimiter_regex . '\s*$/is', rtrim( $sql_line ) ) &&
					 ! preg_match( '/\;\s*' . $delimiter_regex . '\s*$/is', rtrim( $sql_line ) ) &&
					 ! preg_match( '/\s*(?:\*\/)?'  . $delimiter_regex . '\s*$/is', rtrim( $sql_line ) ) ) {
					continue;
				}

				if ( preg_match( '/(?:--|#).+?END\s*' . $delimiter_regex . '\s*$/i', rtrim( $sql_line ) ) &&
					 preg_match( '/(?:--|#).+?' . $delimiter_regex . '\s*$/i', rtrim( $sql_line ) ) ) {
					continue;
				}

				if ( is_array( $this->stored_routine_supported ) && ! empty( $this->stored_routine_supported ) && ! is_wp_error( $old_log_bin_trust_function_creators ) ) {

					// Handle log_bin_trust_function_creators
					if ( $this->stored_routine_supported['is_binary_logging_enabled'] &&
						 ! $this->stored_routine_supported['is_function_creators_trusted'] &&
						 is_null( $old_log_bin_trust_function_creators ) ) {
						$old_log_bin_trust_function_creators = $this->set_log_bin_trust_function_creators( 'ON' );
						if ( ! is_wp_error( $old_log_bin_trust_function_creators ) ) {
							$this->royalbr_instance->log_e( 'log_bin_trust_function_creators set to ON' );
						}
					}

					// Remove DEFINER clause
					$sql_line = preg_replace( "/^\s*(CREATE(?:\s\s*OR\s\s*REPLACE)?)\s\s*DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|'.{1,17}'@'[^\s]+'\s*|[^\s]+?\s)((?:AGGREGATE\s\s*)?(?:PROCEDURE|FUNCTION))/is", "$1 $2", $sql_line );

					// Add SQL SECURITY INVOKER
					if ( preg_match( '/^\s*CREATE(?:\s\s*OR\s\s*REPLACE)?\s\s*(?:DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|\'.{1,17}\'@\'[^\s]+\'\s*|[^\s]+?\s))?PROCEDURE(?:\s*`(?:[^`]|``)+`\s*|\s[^\(]+)(?\'params\'(?:[^()]+|\((?1)*\)))(?:(.*?)COMMENT\s\s*\'[^\']+\'|COMMENT\s\s*\'[^\']+\'(.*?)|(.*?))(?:(.*?)BEGIN|([^\'"]+))/is', $sql_line, $sql_security_matches, PREG_OFFSET_CAPTURE ) ||
						 preg_match( '/^\s*CREATE(?:\s\s*OR\s\s*REPLACE)?\s\s*(?:DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|\'.{1,17}\'@\'[^\s]+\'\s*|[^\s]+?\s))?(?:AGGREGATE\s\s*)?FUNCTION(?:\s*`(?:[^`]|``)+`\s*|\s[^\(]+)(?\'params\'(?:[^()]+|\((?1)*\)))\s*RETURNS\s[\w]+(?:\(.*?\))?\s*(?:CHARSET\s\s*[^\s]+\s\s*)?(?:COLLATE\s\s*[^\s]+\s\s*)?(?:(.*?)COMMENT\s\s*\'[^\']+\'|COMMENT\s\s*\'[^\']+\'(.*?)|(.*?))(?:(.*?)BEGIN|(.*?)RETURN)/is', $sql_line, $sql_security_matches, PREG_OFFSET_CAPTURE ) ) {

						$is_last_index_replaced = false;
						$sql_security_matches = array_reverse( $sql_security_matches );
						foreach ( $sql_security_matches as $key => $match ) {
							if ( (int) $match[1] <= 0 || 'params' === $key ) continue;
							$length = strlen( $match[0] );
							$match[0] = preg_replace( '/SQL\s\s*SECURITY\s\s*(?:DEFINER|INVOKER)/is', ' ', $match[0] );
							if ( ! $is_last_index_replaced ) {
								$match[0] = ' SQL SECURITY INVOKER ' . $match[0];
								$is_last_index_replaced = true;
							}
							$sql_line = substr_replace( $sql_line, $match[0], $match[1], max( 0, $length ) );
						}
					}

					// Handle charset in routine parameters/variables
					if ( preg_match_all( '/(\s(?:long|medium|tiny)?text\s*(?:\([0-9]+\))?|\s(?:var)?char\s*(?:\([0-9]+\))?).*?charset\s([^;,\)\s]+).*?(?:,|;|\))/is', $sql_line, $charset_matches ) ) {
						foreach ( (array) $charset_matches[2] as $key => $charset ) {
							$replaced_charset_declaration = $charset_matches[0][ $key ];
							if ( ! empty( $charset ) && ! isset( $supported_charsets[ strtolower( trim( $charset ) ) ] ) ) {
								$replaced_charset_declaration = str_ireplace( array( 'charset', $charset ), array( '', '' ), $replaced_charset_declaration );
								$sql_line = str_ireplace( $charset_matches[0][ $key ], $replaced_charset_declaration, $sql_line );
							}
						}
					}
				} else {
					$this->royalbr_instance->log_e( 'Stored routine %s neglected - not supported', $routine_matches[1] );
					$sql_line = '';
					$sql_type = -1;
					continue;
				}

			// Detect DROP FUNCTION/PROCEDURE
			} elseif ( preg_match( '/^.*?drop\s\s*(?:function|procedure)\s\s*(?:if\s\s*exists\s\s*)?/i', $sql_line ) ) {
				$sql_type = 13;

			// Detect DELIMITER
			} elseif ( preg_match( '/^\s*delimiter (\S+)\s*$/i', $sql_line, $matches ) ) {
				$sql_type = 10;

			// Detect CREATE VIEW
			} elseif ( preg_match( '/^CREATE(\s+ALGORITHM=\S+)?(\s+DEFINER=\S+)?(\s+SQL SECURITY (\S+))?\s+VIEW/i', $sql_line, $matches ) ) {
				$sql_type = 11;

				// Remove DEFINER and add SQL SECURITY INVOKER
				$sql_line = preg_replace( '/^(\s*CREATE\s\s*(?\'or_replace\'OR\s\s*REPLACE\s\s*)?(?\'algorithm\'ALGORITHM\s*=\s*[^\s]+\s\s*)?)(?\'definer\'DEFINER\s*=\s*(?:`.{1,17}`@`[^\s]+`\s*|\'.{1,17}\'@\'[^\s]+\'\s*|[^\s]+?\s\s*))?(?\'sql_security\'SQL\s\s*SECURITY\s\s*[^\s]+?\s\s*)?(VIEW(?:\s\s*IF\s\s*NOT\s\s*EXISTS)?(?:\s*`(?:[^`]|``)+`\s*|\s\s*[^\s]+\s\s*)AS)/is', "$1 SQL SECURITY INVOKER $6", $sql_line );

				if ( null !== $this->old_table_prefix ) {
					foreach ( array_keys( $this->restore_this_table ) as $table_name ) {
						if ( in_array( $table_name, $this->view_names ) ) continue;
						if ( false !== strpos( $sql_line, $table_name ) ) {
							$new_table_name = ( '' == $this->old_table_prefix ) ? $import_table_prefix . $table_name : $this->replace_first_occurrence( $this->old_table_prefix, empty( $this->db_permissons_forbidden['rename'] ) ? $this->final_import_table_prefix : $import_table_prefix, $table_name );
							$sql_line = str_replace( $table_name, $new_table_name, $sql_line );
						}
					}

					// Rename last restored table before creating view
					if ( $this->restoring_table ) {
						$final_table_name = $this->maybe_rename_restored_table();
						$this->restored_table( $final_table_name, $this->final_import_table_prefix, $this->old_table_prefix, $this->table_engine );
						$this->restoring_table = '';
					}
				}

			// Detect SET @@GLOBAL.GTID_PURGED
			} elseif ( preg_match( '/^SET @@GLOBAL.GTID_PURGED/i', $sql_line ) ) {
				$sql_type = 17;

			// Detect SET SQL_MODE
			} elseif ( preg_match( '/^\/\*\!\d+\s+SET\s+(?:[^,].*)?(?=SQL_MODE\s*=)/i', $sql_line ) ) {
				$sql_type = 18;

			} else {
				$sql_type = 0;
			}

			// Execute SQL (skip USE, CREATE/DROP DATABASE, DELIMITER, GTID_PURGED, SQL_MODE)
			if ( ! in_array( $sql_type, array( 6, 7, 10, 17, 18 ) ) && ( 9 != $sql_type || empty( $this->db_permissons_forbidden['triggers'] ) ) ) {
				if ( 2 == $sql_type && ! empty( $this->new_table_name ) ) {
					if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
						$royalbr_instance->log_restore_update( array(
							'type'  => 'state',
							'stage' => 'db',
							'data'  => array( 'stage' => 'table', 'table' => $this->new_table_name )
						) );
					}
				}

				$do_exec = $this->execute_sql_statement( $sql_line, $sql_type );
				if ( is_wp_error( $do_exec ) ) return $do_exec;
			} else {
				$this->royalbr_instance->log_e( 'Skipped SQL statement (type=%d): %s', $sql_type, substr( $sql_line, 0, 100 ) );
			}

			// Handle generated columns - change back to STORED type
			if ( 3 == $sql_type && ! empty( $this->generated_columns[ $this->table_name ] ) ) {
				if ( ! isset( $this->supported_generated_column_engines[ strtolower( $this->table_engine ) ] ) ) {
					$this->supported_generated_column_engines[ strtolower( $this->table_engine ) ] = ROYALBR_Database_Utility::detect_generated_col_support( $this->table_engine );
				}

				if ( ( $generated_column_db_info = $this->supported_generated_column_engines[ strtolower( $this->table_engine ) ] ) &&
					 ! $generated_column_db_info['can_insert_ignore_to_generated_column'] &&
					 isset( $this->generated_columns_exist_in_the_statement[ $this->table_name ] ) &&
					 true === $this->generated_columns_exist_in_the_statement[ $this->table_name ] ) {

					foreach ( (array) $this->generated_columns[ $this->table_name ]['columns'] as $generated_column ) {
						$new_data_type_definition = "`{$generated_column['column_name']}`";
						foreach ( (array) $generated_column['column_data_type_definition'] as $key => $data_type_definition ) {
							if ( empty( $data_type_definition ) || 0 === strlen( trim( $data_type_definition[0] ) ) ) continue;
							if ( in_array( $key, array( 'DATA_TYPE_TOKEN', 'GENERATED_ALWAYS_TOKEN', 'COMMENT_TOKEN' ) ) ) {
								$new_data_type_definition .= ' ' . $data_type_definition[0];
								continue;
							}
							$new_data_type_definition .= $generated_column_db_info['is_not_null_supported'] ? $data_type_definition[0] : preg_replace( '/\b(?:not\s+null|null)\b/i', '', $data_type_definition[0] );
							if ( ! $generated_column['is_virtual'] ) {
								$new_data_type_definition = $generated_column_db_info['is_persistent_supported'] ? $new_data_type_definition : preg_replace( '/\bpersistent\b/i', 'STORED', $new_data_type_definition );
							}
						}
						$new_data_type_definition = preg_replace( '/\bvirtual\b/i', 'STORED', $new_data_type_definition );

						$do_exec = $this->execute_sql_statement( sprintf( "alter table `%s` change `%s` %s", $this->new_table_name, $generated_column['column_name'], $new_data_type_definition ), -1 );
						if ( is_wp_error( $do_exec ) ) return $do_exec;
					}
				}
			}

			// Reset for next statement
			$sql_line = '';
			$sql_type = -1;
		}

		// Reset log_bin_trust_function_creators to original value
		if ( is_array( $this->stored_routine_supported ) && $this->stored_routine_supported['is_binary_logging_enabled'] ) {
			if ( is_string( $old_log_bin_trust_function_creators ) && '' !== $old_log_bin_trust_function_creators ) {
				$this->set_log_bin_trust_function_creators( $old_log_bin_trust_function_creators );
				$this->royalbr_instance->log_e( 'log_bin_trust_function_creators variable has been reset: %s', $old_log_bin_trust_function_creators );
				// Unset from continuation_data
				if ( isset( $this->continuation_data['old_log_bin_trust_function_creators'] ) ) {
					unset( $this->continuation_data['old_log_bin_trust_function_creators'] );
				}
				$this->royalbr_instance->log_e( 'log_bin_trust_function_creators variable has successfully been removed from continuation data' );
			}
		}

		// Rebuild backup history after database restore
		if ( ! empty( $this->royalbr_backup_set['db'] ) && ! empty( $this->royalbr_backup_set['service'] ) &&
			 ( 'none' !== $this->royalbr_backup_set['service'] && 'email' !== $this->royalbr_backup_set['service'] &&
			   array( '' ) !== $this->royalbr_backup_set['service'] && array( 'none' ) !== $this->royalbr_backup_set['service'] &&
			   array( 'email' ) !== $this->royalbr_backup_set['service'] ) ) {
			// Remote storage was used
			$only_add_this_file = array( 'file' => $this->royalbr_backup_set['db'] );
			ROYALBR_Backup_History::rebuild( true, $only_add_this_file );
		} else {
			// Local storage only
			ROYALBR_Backup_History::rebuild();
		}

		// Leave maintenance mode
		if ( ! empty( $this->db_permissons_forbidden['lock'] ) ) {
			$this->royalbr_instance->log_e( 'Leaving maintenance mode' );
		} else {
			$this->royalbr_instance->log_e( 'Unlocking database and leaving maintenance mode' );
			$this->unlock_tables();
		}
		$this->maintenance_mode( false );

		// Rename final table if still restoring
		if ( $this->restoring_table ) {
			$final_table_name = $this->maybe_rename_restored_table();
			$this->restored_table( $final_table_name, $this->final_import_table_prefix, $this->old_table_prefix, $this->table_engine );
		}

		// Fire migration/search-replace hook after all DB tables are restored
		$db_restore_info = array(
			'expected_oldsiteurl' => $this->old_siteurl,
			'expected_oldhome'    => $this->old_home,
		);
		do_action( 'royalbr_restored_db', $db_restore_info, $this->final_import_table_prefix );

		// Drop dummy restored tables
		if ( $this->is_dummy_db_restore ) {
			$this->remove_database_tables( $this->restored_table_names );
		}

		// Log completion stats
		$time_taken = microtime( true ) - $this->start_time;
		$this->royalbr_instance->log_e( 'Finished: lines processed: %d in %.2f seconds', $this->line, $time_taken );

		// Close file handle
		if ( $is_plain ) {
			fclose( $dbhandle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing file handle from streaming operation
		} elseif ( $is_bz2 ) {
			bzclose( $dbhandle );
		} else {
			gzclose( $dbhandle );
		}

		// Delete database file after successful restore
		global $wp_filesystem;
		if ( ! $wp_filesystem->delete( $working_dir . '/' . $db_basename, false, 'f' ) ) {
			$this->royalbr_instance->log_e( 'Failed to delete database file: %s', $working_dir . '/' . $db_basename );
		}

		// Clear any stale backup progress state from the restored database.
		// This prevents fake progress bar from appearing after restore.
		$restored_task_id = get_option( 'royalbr_oneshotnonce', false );
		if ( false !== $restored_task_id ) {
			delete_option( 'royalbr_taskdata_' . $restored_task_id );
			delete_option( 'royalbr_oneshotnonce' );
		}
		delete_option( 'royalbr_backup_error' );

		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log' ) ) {
			$royalbr_instance->log( 'Database successfully restored', 'notice-progress' );
		}

		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
			$royalbr_instance->log_restore_update( array(
				'type'  => 'state',
				'stage' => 'db',
				'data'  => array( 'stage' => 'finished', 'table' => '' )
			) );
		}

		return true;
	}

	/**
	 * BATCH 9: restored_table - Post-table restoration processing
	 * Called after each table is successfully restored
	
	 *
	 * @param string $table              The full table name that was restored
	 * @param string $import_table_prefix The table prefix used during import
	 * @param string $old_table_prefix   The original table prefix from backup
	 * @param string $engine             The database engine (if known)
	 * @return void
	 */
	private function restored_table( $table, $import_table_prefix, $old_table_prefix, $engine = '' ) {

		// PHP 8+ compatibility: ensure $import_table_prefix is not null
		$import_table_prefix = $import_table_prefix ?? '';
		$table_without_prefix = substr( $table, strlen( $import_table_prefix ) );

		if ( isset( $this->restore_this_table[ $old_table_prefix . $table_without_prefix ] ) && ! $this->restore_this_table[ $old_table_prefix . $table_without_prefix ] ) {
			return;
		}

		global $wpdb;

		if ( $table == $import_table_prefix . 'options' ) {
			// WP 4.5+ requires cache flush for options to work correctly
			wp_cache_flush();
			$this->restore_configuration_bundle( $table );
		}

		// ROYALBR: Skip multisite sitemeta handling (ROYALBR is single-site only)

		// Handle options table restoration
		if ( preg_match( '/^([\d+]_)?options$/', substr( $table, strlen( $import_table_prefix ) ), $matches ) ) {

			// ROYALBR: Single-site only, so skip multisite check
			if ( $table == $import_table_prefix . 'options' ) {

				// ROYALBR: Skip wipe_state_data() - simplified for ROYALBR

				$mprefix = empty( $matches[1] ) ? '' : $matches[1];
				$new_table_name = $import_table_prefix . $mprefix . 'options';

				// WordPress has an option name predicated upon the table prefix
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table prefixes and names during restore, cannot use prepare() for identifiers
				if ( $import_table_prefix != $old_table_prefix ) {
					$this->royalbr_instance->log_e( 'Table prefix has changed: changing options table field(s) accordingly (%soptions)', $mprefix );

					if ( false === $wpdb->query( "UPDATE $new_table_name SET option_name='{$import_table_prefix}" . $mprefix . "user_roles' WHERE option_name='{$old_table_prefix}" . $mprefix . "user_roles' LIMIT 1" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$this->royalbr_instance->log_e( 'Error when changing options table fields: %s', $wpdb->last_error );
					} else {
						$this->royalbr_instance->log_e( 'Options table fields changed OK' );
					}
				}

				// Handle upload_path option
				$new_upload_path = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$import_table_prefix}" . $mprefix . "options WHERE option_name = %s LIMIT 1", 'upload_path' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$new_upload_path = ( is_object( $new_upload_path ) ) ? $new_upload_path->option_value : '';

				// Check if upload_path is absolute and might need resetting
				if ( ! empty( $new_upload_path ) && $new_upload_path != $this->prior_upload_path && ( strpos( $new_upload_path, '/' ) === 0 || preg_match( '#^[A-Za-z]:[/\\\]#', $new_upload_path ) ) ) {

					if ( ! file_exists( $new_upload_path ) || $this->old_siteurl != $this->our_siteurl ) {

						if ( ! file_exists( $new_upload_path ) ) {
							$this->royalbr_instance->log_e( 'Uploads path (%s) does not exist - resetting (%s)', $new_upload_path, $this->prior_upload_path );
						} else {
							$this->royalbr_instance->log_e( 'Uploads path (%s) has changed during a migration - resetting (to: %s)', $new_upload_path, $this->prior_upload_path );
						}

						if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$import_table_prefix}" . $mprefix . "options SET option_value=%s WHERE option_name='upload_path' LIMIT 1", array( $this->prior_upload_path ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							$this->royalbr_instance->log_e( 'Error when changing upload path: %s', $wpdb->last_error );
						}
					}
				}

				// Elegant Themes builder plugin fix
				if ( $table == $import_table_prefix . $mprefix . 'options' ) {
					$elegant_data = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM $new_table_name WHERE option_name = %s LIMIT 1", 'et_images_temp_folder' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( ! empty( $elegant_data->option_value ) ) {
						$dbase = basename( $elegant_data->option_value );
						$wp_upload_dir = wp_upload_dir();
						$edir = $wp_upload_dir['basedir'];
						$new_dir = $edir . '/' . $dbase;
						if ( ! is_dir( $new_dir ) ) @wp_mkdir_p( $new_dir );
						$this->royalbr_instance->log_e( 'Elegant themes theme builder plugin data detected: resetting temporary folder' );
						$wpdb->update( $new_table_name, array( 'option_value' => $new_dir ), array( 'option_name' => 'et_images_temp_folder' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					}

					// WP Rocket CDN fix (migration only)
					// ROYALBR: Skip for now - simplified restoration
				}

				// Gantry menu plugin transient cleanup
				$wpdb->query( "DELETE FROM $new_table_name WHERE option_name LIKE '_transient_gantry-menu%' OR option_name LIKE '_transient_timeout_gantry-menu%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// Jetpack cleanup on migration
				if ( $this->old_siteurl != $this->our_siteurl ) {
					$wpdb->query( "DELETE FROM $new_table_name WHERE option_name = 'jetpack_options'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}

				// ROYALBR: Skip multisite import cleanup (ROYALBR is single-site only)

			}

		} elseif ( preg_match( '/^([\d+]_)?usermeta$/', substr( $table, strlen( $import_table_prefix ) ), $matches ) ) {
			// Handle usermeta table

			// Store current user data in memory for re-authentication after restore.
			// Uses class property instead of transient because DB is replaced during migration.
			$current_user_id = get_current_user_id();
			if ( $current_user_id ) {
				$current_user = wp_get_current_user();
				if ( $current_user && $current_user->user_email ) {
					$this->pre_restore_user = array(
						'email' => $current_user->user_email,
						'login' => $current_user->user_login,
						'id'    => $current_user_id,
					);
					$this->royalbr_instance->log_e( 'Stored current user data for re-authentication: ID=%d, login=%s', $current_user_id, $current_user->user_login );
				}
			}

			// Update meta_keys if table prefix has changed
			if ( $import_table_prefix != $old_table_prefix ) {
				$this->royalbr_instance->log_e( 'Table prefix has changed: changing usermeta table field(s) accordingly' );

				$errors_occurred = false;

				if ( false === strpos( $old_table_prefix, '_' ) ) {
					// Old, slow way: row-by-row (when old prefix has no underscore)
					$old_prefix_length = strlen( $old_table_prefix );

					$um_sql = "SELECT umeta_id, meta_key
						FROM {$import_table_prefix}usermeta
						WHERE meta_key
						LIKE '" . $wpdb->esc_like( $old_table_prefix ) . "%'";
					$meta_keys = $wpdb->get_results( $um_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

					foreach ( $meta_keys as $meta_key ) {
						$new_meta_key = $import_table_prefix . substr( $meta_key->meta_key, $old_prefix_length );

						$query = "UPDATE " . $import_table_prefix . "usermeta
							SET meta_key='" . $new_meta_key . "'
							WHERE umeta_id=" . $meta_key->umeta_id;

						if ( false === $wpdb->query( $query ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
							$errors_occurred = true;
						}
					}
				} else {
					// New, fast way: single query
					$sql = "UPDATE {$import_table_prefix}usermeta SET meta_key = REPLACE(meta_key, '$old_table_prefix', '{$import_table_prefix}') WHERE meta_key LIKE '" . $wpdb->esc_like( $old_table_prefix ) . "%';";
					if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
						$errors_occurred = true;
					}
				}

				if ( $errors_occurred ) {
					$this->royalbr_instance->log_e( 'Error when changing usermeta table fields' );
				} else {
					$this->royalbr_instance->log_e( 'Usermeta table fields changed OK' );
				}
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

		}

		do_action( 'royalbr_restored_db_table', $table, $import_table_prefix, $engine );

		// Re-generate permalinks after options table restored
		if ( $table == $import_table_prefix . 'options' ) {
			$this->custom_flush_rewrite_rules();

			// BATCH 11: Call special case functions after options table restored
			// Initialize WordPress filesystem
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();

			// Fix auto_prepend_file directives (Wordfence, etc.)
			$this->update_php_auto_prepend_config ();
		}

	}

	/**
	 * BATCH 11: update_php_auto_prepend_config  - Fix auto_prepend_file directives
	
	 * Fixes absolute paths in .htaccess/.user.ini for plugins like Wordfence
	 *
	 * @return void
	 */
	private function update_php_auto_prepend_config () {
		global $wp_filesystem;

		$external_plugins = array(
			'wordfence' => array(
				'filename' => 'wordfence-waf.php',
				'callback' => 'adjust_root_path_for_wordfencewaff',
			)
		);

		// ROYALBR: Get server config files (.htaccess, .user.ini, etc.)
		$server_config_files = array( '.htaccess', '.user.ini', 'php.ini' );

		foreach ( $server_config_files as $server_config_file ) {
			if ( empty( $server_config_file ) ) continue;

			$file_path = ABSPATH . $server_config_file;
			if ( file_exists( $file_path ) ) {
				$this->royalbr_instance->log_e( '%s configuration file detected during restoration. Checking for fixes.', $server_config_file );

				$server_config_file_content = file_get_contents( $file_path );
				if ( false !== $server_config_file_content ) {

					foreach ( $external_plugins as $data ) {
						$file_pattern = str_replace( array( '/', '.', "'", '"' ), array( '\/', '\.', "\'", '\"' ), $data['filename'] );

						if ( file_exists( ABSPATH . $data['filename'] ) ) {
							// Fix the path to current ABSPATH
							$fixed_content = preg_replace(
								'/((?:php_value\s\s*)?auto_prepend_file(?:\s*=)?\s*(?:\'|")).+?' . $file_pattern . '(\'|")/is',
								'$1' . ABSPATH . $data['filename'] . '$2',
								$server_config_file_content
							);

							if ( ! $wp_filesystem->put_contents( $file_path, $fixed_content ) ) {
								$this->royalbr_instance->log_e( 'Could not write fix into the %s file', $server_config_file );
							}

							// Call callback if exists
							if ( isset( $data['callback'] ) && method_exists( $this, $data['callback'] ) ) {
								call_user_func( array( $this, $data['callback'] ) );
							}
						} else {
							// Plugin file missing - remove the directive to prevent fatal error
							$fixed_content = preg_replace(
								'/((?:php_value\s\s*)?auto_prepend_file(?:\s*=)?\s*(?:\'|")).+?' . $file_pattern . '(\'|")/is',
								'',
								$server_config_file_content
							);

							if ( ! $wp_filesystem->put_contents( $file_path, $fixed_content ) ) {
								$this->royalbr_instance->log_e( 'The %s file does not exist, could not write fix into %s', $data['filename'], $server_config_file );
							}
						}
					}
				} else {
					$this->royalbr_instance->log_e( 'Failed to read the %s file', $server_config_file );
				}
			}
		}
	}

	/**
	 * BATCH 11: adjust_root_path_for_wordfencewaff - Fix Wordfence WAF root paths
	
	 * Adjusts absolute paths in wordfence-waf.php to current server paths
	 *
	 * @return void
	 */
	private function adjust_root_path_for_wordfencewaff() {
		global $wp_filesystem;

		$waf_file = ABSPATH . 'wordfence-waf.php';

		if ( file_exists( $waf_file ) ) {
			$this->royalbr_instance->log_e( 'Wordfence auto-prepended file detected during restoration. Fixing paths.' );

			$wordfence_waf = file_get_contents( $waf_file );
			if ( false !== $wordfence_waf ) {

				// https://regex101.com/r/VeCwzH/1/
				if ( preg_match_all( '/(?:wp-content[\/\\\]+plugins[\/\\\]+wordfence[\/\\\]+waf[\/\\\]+bootstrap\.php|wp-content[\/\\\]+wflogs[\/\\\]*)((?:\'|"))/is', $wordfence_waf, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {

					$matches = array_reverse( $matches );

					foreach ( $matches as $match ) {
						$enclosure_cnt = 0;
						$start = (int) $match[0][1];
						$enclosure = $match[1][0];
						$offset = -1;

						// Find the start of the path (reverse search for opening quote)
						for ( $i = $start; $i >= 0; $i-- ) {
							if ( $enclosure_cnt > 0 ) {
								if ( '\\' === $wordfence_waf[ $i ] ) {
									$enclosure_cnt--;
								} else {
									$offset = $i + 2;
									break;
								}
							} else {
								if ( $enclosure === $wordfence_waf[ $i ] ) {
									$enclosure_cnt++;
								}
							}
						}

						// Replace the path with current paths
						if ( $offset >= 0 ) {
							if ( false !== stripos( $match[0][0], 'wflogs' ) ) {
								$wordfence_waf = substr_replace( $wordfence_waf, WP_CONTENT_DIR . '/wflogs/', $offset, ( (int) $match[1][1] ) - $offset );
							} else {
								$wordfence_waf = substr_replace( $wordfence_waf, WP_PLUGIN_DIR . '/wordfence/waf/bootstrap.php', $offset, ( (int) $match[1][1] ) - $offset );
							}
						}
					}

					// Write fixed content
					if ( ! $wp_filesystem->put_contents( $waf_file, $wordfence_waf ) ) {
						$this->royalbr_instance->log_e( 'Could not write fixes into the wordfence-waf.php file' );
					}
				}
			} else {
				$this->royalbr_instance->log_e( 'Failed to read the wordfence-waf.php file' );
			}
		}
	}

	// ========================================================================
	// PUBLIC ENTRY POINTS
	// These maintain compatibility with existing admin interface
	// ========================================================================

	/**
	 * Run restoration process - main restore orchestration method
	 *
	 * @param array $entities_to_restore Array of entities to restore (db, plugins, themes, uploads, others)
	 * @param array $restore_options     Restore options from taskdata
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function run_restoration_process( $entities_to_restore, $restore_options = array() ) {
		global $wp_filesystem;

		$this->royalbr_instance->log_e( 'perform_restore() started. Entities: %s', implode( ', ', array_keys( $entities_to_restore ) ) );

		$this->royalbr_instance->log_e( 'Restore task started. Entities to restore: %s. Restore options: %s', implode( ', ', array_keys( $entities_to_restore ) ), wp_json_encode( $restore_options ) );

		$backup_set = $this->royalbr_backup_set;
		if ( empty( $backup_set ) ) {
			return new WP_Error( 'no_backup_set', __( 'Backup set information not found', 'royal-backup-reset' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( HOUR_IN_SECONDS * 2 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for long-running restore operations (2 hours)
		}

		global $royalbr_instance;
		$backupable_entities = $royalbr_instance->get_backupable_file_entities( true, true );

		foreach ( $entities_to_restore as $type => $files ) {
			$this->current_type = $type;

			$info = isset( $backupable_entities[ $type ] ) ? $backupable_entities[ $type ] : array();

			if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log' ) ) {
				$royalbr_instance->log( 'Entity: ' . $type, 'notice-progress' );
			}

			// Progress update: Stage change (
			if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
				$royalbr_instance->log_restore_update( array(
					'type'  => 'state_change',
					'stage' => $type,
					'data'  => array()
				) );
			}

			if ( is_string( $files ) ) {
				$files = array( $files );
			}

			// Sort files to handle incremental/chunked zips correctly
			ksort( $files );

			// Log chunk count for this entity
			if ( count( $files ) > 1 ) {
				$this->royalbr_instance->log_e( 'Processing %d chunks for %s entity', count( $files ), $type );
			}

			foreach ( $files as $fkey => $file ) {
				$this->current_index = $fkey;
				$last_entity = ( $fkey === array_key_last( $files ) );

				try {
					$restore_result = $this->execute_backup_restoration( $file, $type, $info, false, $last_entity );
				} catch ( Exception $e ) {
					$log_message = 'Exception (' . get_class( $e ) . ') occurred during restore: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ', line ' . $e->getLine() . ' in ' . $e->getFile() . ')';
					$this->royalbr_instance->log_e( $log_message );
					return new WP_Error( 'restore_exception', $log_message );
				}

				if ( is_wp_error( $restore_result ) ) {
					$this->royalbr_instance->log_e( 'Restore error for %s: %s', $type, $restore_result->get_error_message() );
					return $restore_result;
				}

				// Mark as restored
				$this->been_restored[ $type ] = true;
			}
		}

		// If the database was restored, check active plugins and make sure they all exist
		// Called AFTER all entities are restored so get_plugins() returns complete list
		if ( null !== $this->final_import_table_prefix ) {
			$this->check_active_plugins( $this->final_import_table_prefix );
		}

		$this->royalbr_instance->log_e( 'perform_restore() completed successfully' );
		return true;
	}

	/**
	 * Post-restore cleanup
	 * Clears caches, validates theme, runs cleanup actions
	 *
	 * @param bool|WP_Error $successful Restore result
	 * @return void
	 */
	public function post_restore_clean_up( $successful = true ) {
		$this->royalbr_instance->log_e( 'post_restore_clean_up() started' );

		if ( is_wp_error( $successful ) ) {
			$this->royalbr_instance->log_e( 'Restore had errors: %s', $successful->get_error_message() );
			$successful = false;
		}

		if ( $successful ) {
			$this->royalbr_instance->log_e( 'Restore was successful, performing cleanup' );

			// Re-authenticate user after database restore to prevent logout
			$this->reauthenticate_user_after_restore();

			$this->clear_caches();

			$template = get_option( 'template' );
			if ( ! empty( $template ) && WP_DEFAULT_THEME !== $template && strtolower( $template ) !== $template ) {
				$theme_root = get_theme_root( $template );

				if ( ! file_exists( "$theme_root/$template/style.css" ) && file_exists( "$theme_root/" . strtolower( $template ) . '/style.css' ) ) {
					$this->royalbr_instance->log_e( 'Theme directory case mismatch, fixing' );
					update_option( 'template', strtolower( $template ) );
					update_option( 'stylesheet', strtolower( $template ) );
				}
			}

			if ( ! function_exists( 'validate_current_theme' ) ) {
				require_once ABSPATH . WPINC . '/theme.php';
			}

			if ( ! validate_current_theme() ) {
				$this->royalbr_instance->log_e( 'Current theme not found, reverting to default' );
				switch_theme( WP_DEFAULT_THEME );
			}

			do_action( 'royalbr_restore_completed' );
		}

		$this->royalbr_instance->log_e( 'post_restore_clean_up() completed' );
	}

	/**
	 * Re-authenticate user after database restore to prevent logout.
	 *
	 * When database is restored, session tokens in wp_usermeta are replaced with
	 * those from the backup, invalidating the current user's session.
	 * This method retrieves stored user data and regenerates auth cookies.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function reauthenticate_user_after_restore() {
		if ( empty( $this->pre_restore_user ) ) {
			$this->royalbr_instance->log_e( 'No stored user data found for re-authentication' );
			return;
		}

		$stored_email = $this->pre_restore_user['email'];
		$stored_login = $this->pre_restore_user['login'];
		$stored_id    = $this->pre_restore_user['id'];

		// First try: look up user by original ID (same-site restore).
		$user = get_user_by( 'id', $stored_id );

		// Second try: look up by email (migration — user IDs may differ).
		if ( ! $user ) {
			$user = get_user_by( 'email', $stored_email );
			if ( $user ) {
				$this->royalbr_instance->log_e( 'User ID changed during migration: old=%d, new=%d (matched by email)', $stored_id, $user->ID );
			}
		}

		// Third try: look up by login name.
		if ( ! $user ) {
			$user = get_user_by( 'login', $stored_login );
			if ( $user ) {
				$this->royalbr_instance->log_e( 'User matched by login: %s (ID=%d)', $stored_login, $user->ID );
			}
		}

		// Last resort: get first administrator.
		if ( ! $user ) {
			$admins = get_users( array(
				'role'   => 'administrator',
				'number' => 1,
			) );
			if ( ! empty( $admins ) ) {
				$user = $admins[0];
				$this->royalbr_instance->log_e( 'No matching user found, falling back to first admin: ID=%d, login=%s', $user->ID, $user->user_login );
			}
		}

		if ( $user ) {
			// Generate one-time auto-login token. Cookies cannot be set during
			// streaming output because HTTP headers have already been sent.
			$token = wp_generate_password( 40, false );
			update_option( 'royalbr_auto_login_token', array(
				'token'   => wp_hash( $token ),
				'user_id' => $user->ID,
				'expires' => time() + 120,
			), false );
			$this->auto_login_token = $token;

			// Set current user so subsequent nonce checks work within this request.
			wp_set_current_user( $user->ID, $user->user_login );
			if ( defined( 'LOGGED_IN_COOKIE' ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Setting auth cookie for current request
				$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user->ID, time() + DAY_IN_SECONDS, 'logged_in' );
			}

			$this->royalbr_instance->log_e( 'Re-authenticated user after restore: ID=%d, login=%s', $user->ID, $user->user_login );
		} else {
			$this->royalbr_instance->log_e( 'Could not find any admin user for re-authentication' );
		}

		$this->pre_restore_user = null;
	}

	/**
	 * Get entities to restore from backup set

	 *
	 * @param array $backup_set  Backup set from history.
	 * @param array $components  Components to restore (db, plugins, themes, uploads, others).
	 * @return array Entities array with component => file path mappings.
	 */
	private function get_entities_to_restore( $backup_set, $components ) {
		$entities = array();
		$nonce    = isset( $backup_set['nonce'] ) ? $backup_set['nonce'] : '';

		$this->royalbr_instance->log_e( 'get_entities_to_restore() - backup_set keys: %s', implode( ', ', array_keys( $backup_set ) ) );
		$this->royalbr_instance->log_e( 'get_entities_to_restore() - requested components: %s', implode( ', ', $components ) );

		foreach ( $components as $component ) {
			$files = array();

			// Check v2 structure (components array)
			if ( isset( $backup_set['components'][ $component ]['file'] ) ) {
				$file_data = $backup_set['components'][ $component ]['file'];
				// Handle both array (chunked) and string (single file) formats
				$files = is_array( $file_data ) ? $file_data : array( $file_data );
			}
			// Fallback to v1 structure (direct component key) for backward compatibility
			elseif ( isset( $backup_set[ $component ] ) && ! empty( $backup_set[ $component ] ) ) {
				$file_data = $backup_set[ $component ];
				$files     = is_array( $file_data ) ? $file_data : array( $file_data );
			}

			// If we only found one file but this might be a chunked backup, try to discover all chunks
			if ( count( $files ) === 1 && ! empty( $nonce ) ) {
				$discovered = ROYALBR_Backup_History::discover_chunks( $nonce, $component );
				if ( count( $discovered ) > 1 ) {
					$files = $discovered;
					$this->royalbr_instance->log_e( 'Discovered %d chunks for %s via filesystem scan', count( $files ), $component );
				}
			}

			// Validate files exist and add to entities
			if ( ! empty( $files ) ) {
				$valid_files = array();
				foreach ( $files as $filename ) {
					$file_path = trailingslashit( ROYALBR_BACKUP_DIR ) . $filename;
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_exists -- Required for backup file validation before restore
					if ( file_exists( $file_path ) ) {
						$valid_files[] = $filename;
					} else {
						$this->royalbr_instance->log_e( 'Chunk file missing, skipping: %s (expected at: %s)', $filename, $file_path );
					}
				}

				if ( ! empty( $valid_files ) ) {
					// Sort files to ensure correct order (file.zip, file2.zip, file3.zip)
					sort( $valid_files );
					$entities[ $component ] = $valid_files;
					$this->royalbr_instance->log_e( 'Added entity: %s => %d file(s): %s', $component, count( $valid_files ), implode( ', ', $valid_files ) );
				}
			} else {
				$this->royalbr_instance->log_e( 'Component not found in backup set: %s', $component );
			}
		}

		$this->royalbr_instance->log_e( 'get_entities_to_restore() returning %d entities', count( $entities ) );

		/**
		 * Filter entities before restore - allows premium features to download remote files.
		 *
		 * @since 1.0.12
		 * @param array  $entities   Entities to restore (component => files array).
		 * @param array  $backup_set Full backup set data from history.
		 * @param string $nonce      Backup nonce identifier.
		 * @param array  $components Requested components to restore.
		 */
		$entities = apply_filters( 'royalbr_pre_restore_files', $entities, $backup_set, $nonce, $components );

		// Handle WP_Error from filter (e.g., download failed).
		if ( is_wp_error( $entities ) ) {
			$this->royalbr_instance->log_e( 'Pre-restore filter returned error: %s', $entities->get_error_message() );
			return array();
		}

		return $entities;
	}

	/**
	 * PHP error handler for restore process.
	 *
	 * Captures PHP errors during restore operations and stores
	 * the message for inclusion in WP_Error responses to the UI.
	 *
	 * @param int    $errno   Error number.
	 * @param string $errstr  Error message.
	 * @param string $errfile File where error occurred.
	 * @param int    $errline Line number where error occurred.
	 * @return bool False to continue normal error handling.
	 */
	public function php_error( $errno, $errstr, $errfile, $errline ) {
		// Log the error
		$log_message = "PHP Error ($errno): $errstr in $errfile on line $errline";
		$this->royalbr_instance->log_e( $log_message );

		// Store the error message for inclusion in WP_Error creation
		// This allows specific errors (e.g., "No space left on device") to be shown to user
		$this->last_php_error = $errstr;

		// Let WordPress handle it normally
		return false;
	}

	/**
	 * Get and clear the last captured PHP error.
	 *
	 * Returns the last PHP error message captured by php_error() handler
	 * and clears it for subsequent operations.
	 *
	 * @return string Error detail suffix (e.g., ": No space left on device") or empty string.
	 */
	private function get_php_error_detail() {
		if ( empty( $this->last_php_error ) ) {
			return '';
		}
		$error_detail      = ' (' . $this->last_php_error . ')';
		$this->last_php_error = '';
		return $error_detail;
	}

	/**
	 * Restore a backup session
	 * Main public entry point for restore operations
	 *
	 * @param string $timestamp  Backup timestamp (YYYY-MM-DD-HHMM format).
	 * @param array  $components Components to restore (db, plugins, themes, uploads, others).
	 * @return array Array with success/error status.
	 */
	public function restore_backup_session( $timestamp, $components = array(), $restore_options = array() ) {
		global $royalbr_instance;

		// Default to restoring all components if none specified
		if ( empty( $components ) ) {
			$components = array( 'db', 'plugins', 'themes', 'uploads', 'others', 'wpcore' );
		}

		// Merge any passed restore options
		if ( ! empty( $restore_options ) ) {
			$this->restore_options = array_merge( $this->restore_options, $restore_options );
			do_action( 'royalbr_restorer_restore_options', $this->restore_options );
		}

		$this->royalbr_instance->log_e( 'restore_backup_session() called for timestamp: %s', $timestamp );

		// Progress update: Started
		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
			$royalbr_instance->log_restore_update( array(
				'type'  => 'state',
				'stage' => 'started',
				'data'  => array()
			) );
		}

		// Get backup set from history
		$backup_set = ROYALBR_Backup_History::get_history( $timestamp );

		// Check if backup exists
		if ( empty( $backup_set ) ) {
			$this->royalbr_instance->log_e( 'This backup does not exist in the backup history - restoration aborted. Timestamp: %s', $timestamp );
			return array(
				'success' => false,
				'error'   => __( 'This backup does not exist in the backup history - restoration aborted.', 'royal-backup-reset' ) . ' ' . __( 'Timestamp:', 'royal-backup-reset' ) . ' ' . $timestamp
			);
		}

		$backup_set['timestamp'] = $timestamp;

		$this->royalbr_instance->log_e( 'Ensuring WP_Filesystem is setup for a restore' );

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$this->royalbr_instance->log_e( 'WP_Filesystem is setup and ready for a restore' );

		$entities_to_restore = $this->get_entities_to_restore( $backup_set, $components );

		if ( empty( $entities_to_restore ) ) {
			$this->royalbr_instance->log_e( 'ABORT: Could not find the information on which entities to restore.' );
			return array(
				'success' => false,
				'error'   => __( 'ABORT: Could not find the information on which entities to restore.', 'royal-backup-reset' )
			);
		}

		// Progress update: Verifying
		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
			$royalbr_instance->log_restore_update( array(
				'type'  => 'state',
				'stage' => 'verifying',
				'data'  => implode( ', ', array_keys( $entities_to_restore ) )
			) );
		}

		// Calculate total size of backup files to restore.
		$total_backup_size = 0;
		$backup_dir = trailingslashit( ROYALBR_BACKUP_DIR );
		foreach ( $entities_to_restore as $component => $files ) {
			foreach ( $files as $filename ) {
				$file_path = $backup_dir . $filename;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize, WordPress.WP.AlternativeFunctions.file_system_operations_file_exists -- Required for backup size calculation
				if ( file_exists( $file_path ) ) {
					$total_backup_size += filesize( $file_path );
				}
			}
		}

		// Check disk space before proceeding.
		// Require 2.1x the backup size (for extraction + copy with 10% buffer), minimum 50MB.
		$required_space = max( $total_backup_size * 2.1, 1048576 * 50 );
		$disk_check = $this->disk_space_check( $required_space );

		// Handle unknown disk space
		if ( -1 === $disk_check ) {
			$this->royalbr_instance->log_e( 'Warning: Could not determine free disk space. Proceeding with restore.' );
		}

		if ( false === $disk_check ) {
			$required_mb = round( $required_space / 1048576, 1 );
			$this->royalbr_instance->log_e( 'ABORT: Insufficient disk space for restore. Required: %s MB', $required_mb );

			// Send error stage to UI - mark verifying as failed.
			if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
				$royalbr_instance->log_restore_update( array(
					'type'  => 'state',
					'stage' => 'verifying',
					'error' => true,
					'data'  => array(
						/* translators: %s: Required disk space in MB */
						'message' => sprintf( __( 'Insufficient disk space. Required: %s MB', 'royal-backup-reset' ), $required_mb ),
					)
				) );
			}

			return array(
				'success' => false,
				/* translators: %s: Required disk space in MB */
				'error'   => sprintf( __( 'Insufficient disk space. Please free up at least %s MB of disk space before restoring this backup.', 'royal-backup-reset' ), $required_mb )
			);
		}

		// Set error handler
		$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
		// This will be removed by restore_error_handler() - required for production error handling during restore
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Necessary for capturing and logging PHP errors during restore operations
		set_error_handler( array( $this, 'php_error' ), $error_levels );

		// Store backup_set for perform_restore
		$this->royalbr_backup_set = $backup_set;

		// Call perform_restore
		$this->royalbr_instance->log_e( 'Calling perform_restore() with entities: %s', implode( ', ', array_keys( $entities_to_restore ) ) );
		$restore_result = $this->run_restoration_process( $entities_to_restore, $this->restore_options );

		// Call post_restore_clean_up
		$this->post_restore_clean_up( $restore_result );

		// Send 'finished' stage message
		$sval = ( true === $restore_result ) ? 1 : 0;

		// Flush object cache so site_url/home_url return updated values after migration search/replace.
		wp_cache_flush();

		// Get some page URLs for the frontend
		$pages = get_pages( array( 'number' => 2 ) );
		$page_urls = array(
			'home' => get_home_url(),
		);

		foreach ( $pages as $page_info ) {
			$page_urls[ $page_info->post_name ] = get_page_link( $page_info->ID );
		}

		// Send finished stage update
		if ( isset( $royalbr_instance ) && method_exists( $royalbr_instance, 'log_restore_update' ) ) {
			$royalbr_instance->log_restore_update(
				array(
					'type' => 'state',
					'stage' => 'finished',
					'data' => array(
						'actions' => array(
							__( 'Return to Royal Backup & Reset', 'royal-backup-reset' ) => admin_url( 'admin.php?page=royal-backup-reset&restore_success=' . $sval )
						),
						'urls' => $page_urls,
					)
				)
			);
		}

		// Restore error handler
		restore_error_handler();

		// Fix: Return both 'message' and 'error' keys for consistency with AJAX handlers
		return array(
			'success' => ( true === $restore_result ),
			'message' => ( true === $restore_result )
				? __( 'Restore completed successfully', 'royal-backup-reset' )
				: '',
			'error'   => ( true === $restore_result )
				? ''
				: __( 'Restore failed', 'royal-backup-reset' ) . ( is_wp_error( $restore_result ) ? ': ' . $restore_result->get_error_message() : '' )
		);
	}

	/**
	 * Unpack a database package to the upgrade directory
	 *
	 * @param string $package Package filename (relative to backup directory)
	 * @return string|WP_Error Working directory path on success, WP_Error on failure
	 */
	private function unpack_package_database( $package ) {
		global $wp_filesystem;

		// Initialize WP_Filesystem if needed
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$backup_dir = trailingslashit( ROYALBR_BACKUP_DIR );
		$backup_dir_wpfs = $wp_filesystem->find_folder( $backup_dir );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 1800 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for long-running database restore operations (30 minutes)
		}

		$packsize = round( filesize( $backup_dir . $package ) / 1048576, 1 ) . ' MB';
		$this->royalbr_instance->log_e( 'Unpacking database package: %s (%s)', basename( $package ), $packsize );

		$upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';
		if ( ! $wp_filesystem->is_dir( $upgrade_folder ) ) {
			$wp_filesystem->mkdir( $upgrade_folder, FS_CHMOD_DIR );
		}

		// Clean up upgrade directory
		$upgrade_files = $wp_filesystem->dirlist( $upgrade_folder );
		if ( ! empty( $upgrade_files ) ) {
			foreach ( $upgrade_files as $file ) {
				$wp_filesystem->delete( $upgrade_folder . $file['name'], true );
			}
		}

		// Create working directory
		$working_dir = $upgrade_folder . basename( $package, '.crypt' );

		if ( $wp_filesystem->is_dir( $working_dir ) ) {
			$wp_filesystem->delete( $working_dir, true );
		}

		if ( ! $wp_filesystem->mkdir( $working_dir, FS_CHMOD_DIR ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Failed to create a temporary directory', 'royal-backup-reset' ) . ' (' . $working_dir . ')' );
		}

		// Copy database file to working directory
		// Handle different formats: .sql, .sql.gz, .sql.bz2, .db.gz
		if ( preg_match( '/\.sql$/i', $package ) ) {
			$success = $wp_filesystem->copy( $backup_dir_wpfs . $package, $working_dir . '/backup.db' );
		} elseif ( preg_match( '/\.bz2$/i', $package ) ) {
			$success = $wp_filesystem->copy( $backup_dir_wpfs . $package, $working_dir . '/backup.db.bz2' );
		} else {
			$success = $wp_filesystem->copy( $backup_dir_wpfs . $package, $working_dir . '/backup.db.gz' );
		}

		if ( ! $success ) {
			return new WP_Error( 'copy_failed', __( 'Failed to copy database file', 'royal-backup-reset' ) . $this->get_php_error_detail() );
		}

		$this->royalbr_instance->log_e( 'Database successfully unpacked to: %s', $working_dir );

		return $working_dir;
	}

	/**
	 * Restore a backup file (database or files)
	 *
	 * @param string $backup_file Backup filename
	 * @param string $type        Entity type (db, plugins, themes, uploads, others)
	 * @param array  $info        Entity info
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	private function restore_backup_entity( $backup_file, $type, $info = array() ) {
		global $wp_filesystem, $wpdb;

		$this->royalbr_instance->log_e( 'restore_backup_entity( backup_file=%s, type=%s )', $backup_file, $type );

		// Initialize WP_Filesystem if needed
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		// For database, unpack and restore
		if ( 'db' == $type ) {

			// Pass basename to unpack_package_database, not full path
			// unpack_package_database() expects filename and will add backup_dir itself
			$working_dir = $this->unpack_package_database( basename( $backup_file ) );

			if ( is_wp_error( $working_dir ) ) {
				return $working_dir;
			}

			// Get local filesystem path
			$working_dir_localpath = WP_CONTENT_DIR . '/upgrade/' . basename( $working_dir );

			// Get import table prefix
			$import_table_prefix = apply_filters( 'royalbr_restore_table_prefix', $wpdb->prefix );
			$this->import_table_prefix = $import_table_prefix;
			$this->final_import_table_prefix = $wpdb->prefix;

			// Call the restore_backup_db()
			$this->royalbr_instance->log_e( 'Calling restore_backup_db with working_dir=%s', $working_dir_localpath );
			$restored_db = $this->restore_database_backup( $working_dir, $working_dir_localpath, $import_table_prefix );

			if ( false === $restored_db || is_wp_error( $restored_db ) ) {
				return $restored_db;
			}

			$this->royalbr_instance->log_e( 'Database restore completed successfully' );
			return true;
		}

		// For other entity types, we're not implementing them in this fix
		// (files are already working via restore_file_component)
		$this->royalbr_instance->log_e( 'Non-database entity type not handled by restore_backup_entity: %s', $type );
		return true;
	}

	/**
	 * Execute backup restoration for a single file - main entity restoration method
	 *
	 *
	 * @param string $backup_file     Full path to backup file OR just filename
	 * @param string $type            Entity type (db, plugins, themes, uploads, others)
	 * @param array  $info            Entity information (description, etc.)
	 * @param bool   $last_one        Whether this is the last entity overall
	 * @param bool   $last_entity     Whether this is the last file for this entity type
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	public function execute_backup_restoration( $backup_file, $type, $info = array(), $last_one = false, $last_entity = false ) {
		global $wp_filesystem;

		$this->royalbr_instance->log_e( 'restore_backup( file=%s, type=%s, last_entity=%s )', basename( $backup_file ), $type, ( $last_entity ? 'yes' : 'no' ) );

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		// Ensure we have full path to backup file
		if ( false === strpos( $backup_file, ROYALBR_BACKUP_DIR ) ) {
			$backup_file = trailingslashit( ROYALBR_BACKUP_DIR ) . $backup_file;
		}

		if ( ! file_exists( $backup_file ) ) {
			$this->royalbr_instance->log_e( 'Backup file not found: %s', $backup_file );
			return new WP_Error( 'not_found', __( 'Backup file not found', 'royal-backup-reset' ) . ': ' . basename( $backup_file ) );
		}

		if ( 'db' === $type ) {
			// Database restore - use existing restore_backup_entity flow
			$this->royalbr_instance->log_e( 'Calling restore_backup_entity for database' );
			return $this->restore_backup_entity( $backup_file, 'db', $info );
		} elseif ( 'others' === $type ) {
			// The 'others' backup has a flat structure (files at zip root), not a subdirectory
			$this->royalbr_instance->log_e( 'Restoring file entity: others (special case)' );

			$working_dir = $this->unpack_package_archive( basename( $backup_file ), $type );

			if ( is_wp_error( $working_dir ) ) {
				$this->royalbr_instance->log_e( 'Failed to unpack archive: %s', $working_dir->get_error_message() );
				return $working_dir;
			}

			$entity_path = isset( $info['path'] ) ? $info['path'] : '';
			$path = $entity_path;
			$get_dir = empty( $path ) ? '' : $path;

			$wp_filesystem_dir = $this->get_wp_filesystem_dir( $get_dir );
			if ( false === $wp_filesystem_dir ) {
				return new WP_Error( 'invalid_dir', __( 'Could not determine target directory', 'royal-backup-reset' ) );
			}

			// The backup contents are not in a folder, so we use the unpacked directory as-is
			$move_from = $working_dir;

			$this->royalbr_instance->log_e( 'Moving from %s to %s', $move_from, $wp_filesystem_dir );

			// Exclude standard entities (plugins, themes, uploads, upgrade) to avoid conflicts
			$preserve_existing = self::MOVEIN_COPY_IN_CONTENTS;
			$do_not_overwrite = array( 'plugins', 'themes', 'uploads', 'upgrade' );

			$move_result = $this->move_backup_in(
				$move_from,
				trailingslashit( $wp_filesystem_dir ),
				$preserve_existing,
				$do_not_overwrite,
				$type
			);

			if ( is_wp_error( $move_result ) ) {
				$this->royalbr_instance->log_e( 'Failed to move files for %s: %s', $type, $move_result->get_error_message() );
				return $move_result;
			}

			if ( ! $move_result ) {
				return new WP_Error( 'move_failed', __( 'Failed to move files into place', 'royal-backup-reset' ) . $this->get_php_error_detail() );
			}

			if ( $last_entity && is_dir( $working_dir ) ) {
				$wp_filesystem->delete( $working_dir, true );
				$this->royalbr_instance->log_e( 'Cleaned up working directory: %s', $working_dir );
			}

			$this->been_restored[ $type ] = true;

			$this->royalbr_instance->log_e( 'Successfully restored file entity: %s', $type );
			return true;
		} elseif ( 'wpcore' === $type ) {
			// WP core backup has a flat structure (wp-admin/, wp-includes/, root files at zip root)
			$this->royalbr_instance->log_e( 'Restoring file entity: wpcore' );

			$working_dir = $this->unpack_package_archive( basename( $backup_file ), $type );

			if ( is_wp_error( $working_dir ) ) {
				$this->royalbr_instance->log_e( 'Failed to unpack archive: %s', $working_dir->get_error_message() );
				return $working_dir;
			}

			$wp_filesystem_dir = $this->get_wp_filesystem_dir( '' );
			if ( false === $wp_filesystem_dir ) {
				return new WP_Error( 'invalid_dir', __( 'Could not determine target directory', 'royal-backup-reset' ) );
			}

			// Use the unpacked directory directly (flat structure, no subdirectory)
			$move_from = $working_dir;

			$this->royalbr_instance->log_e( 'Moving from %s to %s', $move_from, $wp_filesystem_dir );

			// Copy contents in place, protect wp-content from being overwritten
			$preserve_existing = self::MOVEIN_COPY_IN_CONTENTS;
			$do_not_overwrite  = array( 'wp-content' );

			$move_result = $this->move_backup_in(
				$move_from,
				trailingslashit( $wp_filesystem_dir ),
				$preserve_existing,
				$do_not_overwrite,
				$type
			);

			if ( is_wp_error( $move_result ) ) {
				$this->royalbr_instance->log_e( 'Failed to move files for %s: %s', $type, $move_result->get_error_message() );
				return $move_result;
			}

			if ( ! $move_result ) {
				return new WP_Error( 'move_failed', __( 'Failed to move files into place', 'royal-backup-reset' ) . $this->get_php_error_detail() );
			}

			if ( $last_entity && is_dir( $working_dir ) ) {
				$wp_filesystem->delete( $working_dir, true );
				$this->royalbr_instance->log_e( 'Cleaned up working directory: %s', $working_dir );
			}

			$this->been_restored['wpcore'] = true;

			$this->royalbr_instance->log_e( 'Successfully restored file entity: wpcore' );
			return true;
		} else {
			// File entity restore (plugins, themes, uploads)
			$this->royalbr_instance->log_e( 'Restoring file entity: %s', $type );

			$working_dir = $this->unpack_package_archive( basename( $backup_file ), $type );

			if ( is_wp_error( $working_dir ) ) {
				$this->royalbr_instance->log_e( 'Failed to unpack archive: %s', $working_dir->get_error_message() );
				return $working_dir;
			}

			$entity_path = isset( $info['path'] ) ? $info['path'] : '';
			$path = $entity_path; // In future: apply_filters('royalbr_restore_path', $entity_path, $backup_file, $this->royalbr_backup_set, $type);
			$get_dir = empty( $path ) ? '' : $path;

			$wp_filesystem_dir = $this->get_wp_filesystem_dir( $get_dir );
			if ( false === $wp_filesystem_dir ) {
				return new WP_Error( 'invalid_dir', __( 'Could not determine target directory', 'royal-backup-reset' ) );
			}

			$move_from = $this->get_first_directory( $working_dir, array( basename( $path ), $type ) );

			if ( false === $move_from ) {
				return new WP_Error( 'no_source', __( 'Could not find source directory in backup', 'royal-backup-reset' ) );
			}

			$this->royalbr_instance->log_e( 'Moving from %s to %s', $move_from, $wp_filesystem_dir );

			// This is what removes newly-added plugins/themes that weren't in the backup
			if ( ! isset( $this->been_restored[ $type ] ) ) {
				$this->delete_existing_files( $type, $get_dir, $wp_filesystem, $wp_filesystem_dir );
			}

			// ROYALBR: Build do_not_overwrite array to prevent self-deletion
			$do_not_overwrite = array();
			if ( 'plugins' == $type ) {
				// Prevent ROYALBR plugin from being overwritten (both free and pro versions)
				$do_not_overwrite[] = 'royal-backup-reset';
				$do_not_overwrite[] = 'royal-backup-reset-pro';
			}

			$move_result = $this->move_backup_in(
				$move_from,
				trailingslashit( $wp_filesystem_dir ),
				self::MOVEIN_COPY_IN_CONTENTS,
				$do_not_overwrite,
				$type
			);

			if ( is_wp_error( $move_result ) ) {
				$this->royalbr_instance->log_e( 'Failed to move files for %s: %s', $type, $move_result->get_error_message() );
				return $move_result;
			}

			if ( ! $move_result ) {
				return new WP_Error( 'move_failed', __( 'Failed to move files into place', 'royal-backup-reset' ) . $this->get_php_error_detail() );
			}

			if ( ! $wp_filesystem->rmdir( $move_from ) ) {
				$this->royalbr_instance->log_e( 'Warning: Could not remove source directory: %s', $move_from );
			}

			if ( $last_entity && is_dir( $working_dir ) ) {
				$wp_filesystem->delete( $working_dir, true );
				$this->royalbr_instance->log_e( 'Cleaned up working directory: %s', $working_dir );
			}

			$this->been_restored[ $type ] = true;

			$this->royalbr_instance->log_e( 'Successfully restored file entity: %s', $type );
			return true;
		}
	}

	/**
	 * Unpack a backup archive to the upgrade directory
	
	 *
	 * @param string $package Package filename (relative to backup directory)
	 * @param string $type    Entity type (plugins, themes, uploads, others)
	 * @return string|WP_Error Working directory path on success, WP_Error on failure
	 */
	private function unpack_package_archive( $package, $type = false ) {
		global $wp_filesystem;

		$package = trailingslashit( ROYALBR_BACKUP_DIR ) . $package;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Required for archive size calculation
		$package_size_mb = round( filesize( $package ) / 1048576, 1 );
		$this->royalbr_instance->log_e( 'Unpacking %s (%s MB)', basename( $package ), $package_size_mb );

		$upgrade_folder = $wp_filesystem->wp_content_dir() . 'upgrade/';

		$working_dir = $upgrade_folder . substr( md5( $package ), 0, 8 );

		$upgrade_files = $wp_filesystem->dirlist( $upgrade_folder );
		if ( ! empty( $upgrade_files ) ) {
			foreach ( $upgrade_files as $file ) {
				if ( ! $wp_filesystem->delete( $upgrade_folder . $file['name'], true ) ) {
					$this->royalbr_instance->log_e( 'Warning: Could not delete %s', $upgrade_folder . $file['name'] );
				}
			}
		}

		if ( $wp_filesystem->is_dir( $working_dir ) ) {
			if ( ! $wp_filesystem->delete( $working_dir, true ) ) {
				$this->royalbr_instance->log_e( 'Warning: Could not delete working directory: %s', $working_dir );
			}
		}

		if ( preg_match( '#\.zip$#i', $package ) ) {
			// For large files (>100MB), use ZipArchive directly to avoid memory issues
			// WordPress's unzip_file() uses PclZip which loads entire file into memory
			if ( $package_size_mb > 100 && class_exists( 'ZipArchive' ) ) {
				$result = $this->unzip_with_ziparchive( $package, $working_dir );
			} else {
				// Use WordPress core unzip_file function for smaller files
				$result = unzip_file( $package, $working_dir );
			}
		} else {
			return new WP_Error( 'unsupported_format', __( 'Unsupported archive format', 'royal-backup-reset' ) );
		}

		if ( is_wp_error( $result ) ) {
			$wp_filesystem->delete( $working_dir, true );
			$this->royalbr_instance->log_e( 'Unzip failed: %s', $result->get_error_message() );
			return $result;
		}

		$this->royalbr_instance->log_e( 'Successfully unpacked to %s', $working_dir );
		return $working_dir;
	}

	/**
	 * Unzip a file using ZipArchive (more memory-efficient for large files).
	 *
	 * Unlike WordPress's unzip_file() which uses PclZip and loads entire archive
	 * into memory, ZipArchive extracts files one at a time.
	 *
	 * @param string $file        Path to the zip file.
	 * @param string $destination Path to extract to.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function unzip_with_ziparchive( $file, $destination ) {
		$this->royalbr_instance->log_e( 'Using ZipArchive for memory-efficient extraction' );

		$zip = new ZipArchive();
		$result = $zip->open( $file );

		if ( true !== $result ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not open zip file', 'royal-backup-reset' ) . ': ' . $this->get_ziparchive_error( $result ) );
		}

		// Create destination directory if it doesn't exist
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Required for archive extraction
		if ( ! is_dir( $destination ) && ! mkdir( $destination, 0755, true ) ) {
			$zip->close();
			return new WP_Error( 'mkdir_failed', __( 'Could not create destination directory', 'royal-backup-reset' ) );
		}

		$num_files = $zip->numFiles;
		$this->royalbr_instance->log_e( 'Extracting %d files from archive', $num_files );

		// Extract files one at a time to minimize memory usage
		for ( $i = 0; $i < $num_files; $i++ ) {
			$filename = $zip->getNameIndex( $i );

			// Skip directory entries (they end with /)
			if ( '/' === substr( $filename, -1 ) ) {
				// Create directory
				$dir_path = $destination . '/' . $filename;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Required for archive extraction
				if ( ! is_dir( $dir_path ) ) {
					mkdir( $dir_path, 0755, true );
				}
				continue;
			}

			// Ensure parent directory exists
			$file_dir = dirname( $destination . '/' . $filename );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Required for archive extraction
			if ( ! is_dir( $file_dir ) ) {
				mkdir( $file_dir, 0755, true );
			}

			// Extract file using stream to avoid loading entire file into memory
			$extracted = $zip->extractTo( $destination, $filename );
			if ( ! $extracted ) {
				$this->royalbr_instance->log_e( 'Warning: Failed to extract %s', $filename );
			}

			// Log progress every 100 files
			if ( 0 === $i % 100 && $i > 0 ) {
				$this->royalbr_instance->log_e( 'Extracted %d / %d files', $i, $num_files );
			}
		}

		$zip->close();
		$this->royalbr_instance->log_e( 'ZipArchive extraction complete' );

		return true;
	}

	/**
	 * Get human-readable ZipArchive error message.
	 *
	 * @param int $error_code ZipArchive error code.
	 * @return string Error message.
	 */
	private function get_ziparchive_error( $error_code ) {
		$errors = array(
			ZipArchive::ER_EXISTS => 'File already exists',
			ZipArchive::ER_INCONS => 'Zip archive inconsistent',
			ZipArchive::ER_INVAL  => 'Invalid argument',
			ZipArchive::ER_MEMORY => 'Memory allocation failure',
			ZipArchive::ER_NOENT  => 'No such file',
			ZipArchive::ER_NOZIP  => 'Not a zip archive',
			ZipArchive::ER_OPEN   => 'Cannot open file',
			ZipArchive::ER_READ   => 'Read error',
			ZipArchive::ER_SEEK   => 'Seek error',
		);

		return isset( $errors[ $error_code ] ) ? $errors[ $error_code ] : 'Unknown error (' . $error_code . ')';
	}

	/**
	 * Get WP_Filesystem directory path for a given path
	
	 *
	 * @param string $path Path to convert
	 * @return string|false WP_Filesystem directory path or false on failure
	 */
	private function get_wp_filesystem_dir( $path ) {
		global $wp_filesystem;

		switch ( $path ) {
			case ABSPATH:
			case '':
				$wp_filesystem_dir = $wp_filesystem->abspath();
				break;
			case WP_CONTENT_DIR:
				$wp_filesystem_dir = $wp_filesystem->wp_content_dir();
				break;
			case WP_PLUGIN_DIR:
				$wp_filesystem_dir = $wp_filesystem->wp_plugins_dir();
				break;
			case WP_CONTENT_DIR . '/themes':
				$wp_filesystem_dir = $wp_filesystem->wp_themes_dir();
				break;
			default:
				$wp_filesystem_dir = $wp_filesystem->find_folder( $path );
				break;
		}

		if ( ! $wp_filesystem_dir ) {
			return false;
		}
		return untrailingslashit( $wp_filesystem_dir );
	}

	/**
	 * Get first directory from extracted backup
	
	 *
	 * @param string $working_dir Working directory with extracted files
	 * @param array  $dirnames    Preferred directory names to look for
	 * @return string|false Path to first directory or false on failure
	 */
	private function get_first_directory( $working_dir, $dirnames ) {
		global $wp_filesystem;

		$fdirnames = array_flip( $dirnames );

		$dirlist = $wp_filesystem->dirlist( $working_dir, true, false );

		if ( is_array( $dirlist ) ) {
			$move_from = false;
			$first_entry = null;

			foreach ( $dirlist as $name => $struct ) {
				if ( isset( $struct['type'] ) && 'd' != $struct['type'] ) {
					continue;
				}

				if ( false === $move_from ) {
					// Check if this is a preferred directory name
					if ( isset( $fdirnames[ $name ] ) ) {
						$move_from = $working_dir . '/' . $name;
					} elseif ( preg_match( '/^([^\.].*)$/', $name, $fmatch ) ) {
						// Store first non-hidden directory as fallback
						if ( is_null( $first_entry ) ) {
							$first_entry = $working_dir . '/' . $fmatch[1];
						}
					}
				}
			}

			if ( false === $move_from && ! is_null( $first_entry ) ) {
				$this->royalbr_instance->log_e( 'Using directory from backup: %s', basename( $first_entry ) );
				$move_from = $first_entry;
			}
		} else {
			$move_from = $working_dir . '/' . $dirnames[0];
		}

		return $move_from;
	}

	/**
	 * Create upgrade directory for unpacking files
	
	 *
	 * @return string|WP_Error Path to upgrade directory or error
	 */
	private function create_upgrade_directory() {
		$upgrade_dir = WP_CONTENT_DIR . '/upgrade';

		if ( ! is_dir( $upgrade_dir ) ) {
			if ( ! wp_mkdir_p( $upgrade_dir ) ) {
				return new WP_Error( 'mkdir_failed', __( 'Failed to create upgrade directory', 'royal-backup-reset' ) );
			}
		}

		// Create unique directory for this restore
		$unique_dir = $upgrade_dir . '/royalbr-restore-' . time() . '-' . wp_rand( 1000, 9999 );
		if ( ! wp_mkdir_p( $unique_dir ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Failed to create working directory', 'royal-backup-reset' ) );
		}

		$this->royalbr_instance->log_e( 'Created working directory: %s', $unique_dir );
		return $unique_dir;
	}

	/**
	 * Get target directory for entity type
	
	 *
	 * @param string $type Entity type
	 * @return string|WP_Error Target directory path or error
	 */
	private function get_target_directory_for_entity( $type ) {
		switch ( $type ) {
			case 'plugins':
				return WP_PLUGIN_DIR;
			case 'themes':
				return WP_CONTENT_DIR . '/themes';
			case 'uploads':
				$upload_dir = wp_upload_dir();
				return $upload_dir['basedir'];
			case 'others':
				return WP_CONTENT_DIR;
			case 'wpcore':
				return untrailingslashit( ABSPATH );
			default:
				return new WP_Error( 'unknown_type', __( 'Unknown entity type', 'royal-backup-reset' ) . ': ' . $type );
		}
	}

	/**
	 * Move backup files from working directory to target directory
	
	 *
	 * @param string $working_dir       Working directory with extracted files
	 * @param string $dest_dir          Destination directory
	 * @param int    $preserve_existing Preservation mode (use class constants)
	 * @param array  $do_not_overwrite  Files/directories to skip
	 * @param string $type              Entity type
	 * @return bool|WP_Error True on success, error on failure
	 */
	private function move_backup_in( $working_dir, $dest_dir, $preserve_existing = 1, $do_not_overwrite = array(), $type = 'not-others' ) {
		global $wp_filesystem;

		$this->royalbr_instance->log_e( 'move_backup_in( working=%s, dest=%s, preserve=%s, type=%s )', $working_dir, $dest_dir, $preserve_existing, $type );

		$recursive = ( self::MOVEIN_COPY_IN_CONTENTS === $preserve_existing ) ? true : false;
		$upgrade_files = $wp_filesystem->dirlist( $working_dir, true, $recursive );

		if ( empty( $upgrade_files ) ) {
			return true;
		}

		if ( ! $wp_filesystem->is_dir( $dest_dir ) ) {
			if ( $wp_filesystem->is_dir( dirname( $dest_dir ) ) ) {
				if ( ! $wp_filesystem->mkdir( $dest_dir, FS_CHMOD_DIR ) ) {
					return new WP_Error( 'mkdir_failed', __( 'The directory does not exist, and the attempt to create it failed', 'royal-backup-reset' ) . ' (' . $dest_dir . ')' );
				}
				$this->royalbr_instance->log_e( 'Destination directory did not exist, but was successfully created (%s)', $dest_dir );
			} else {
				return new WP_Error( 'no_such_dir', __( 'The directory does not exist', 'royal-backup-reset' ) . ' (' . $dest_dir . ')' );
			}
		}

		if ( 'plugins' == $type || 'themes' == $type ) {
			$upgrade_files_log = implode( ', ', array_keys( $upgrade_files ) );
			$this->royalbr_instance->log_e( 'Top-level entities being moved: %s', $upgrade_files_log );
		}

		foreach ( $upgrade_files as $file => $filestruc ) {

			if ( empty( $file ) ) {
				continue;
			}

			// Skip ROYALBR plugin directory to prevent self-deletion (both free and pro versions)
			if ( 'plugins' == $type && ( 'royal-backup-reset' == $file || 'royal-backup-reset-pro' == $file ) ) {
				$this->royalbr_instance->log_e( 'Skipping royal-backup-reset directory to prevent self-deletion' );
				continue;
			}

			// Skip protected hosting provider directories entirely (Kinsta, WP Engine, etc.)
			// These are managed by the hosting infrastructure and should NEVER be restored from backup
			if ( $this->is_protected_hosting_path( $file ) ) {
				$this->royalbr_instance->log_e( 'Skipping protected hosting directory: %s (managed by hosting provider)', $file );
				// Clean up the source directory
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort cleanup
				@$wp_filesystem->delete( trailingslashit( $working_dir ) . $file, true );
				continue;
			}

			if ( in_array( $file, $do_not_overwrite ) ) {
				continue;
			}

			$source_path = trailingslashit( $working_dir ) . $file;
			$target_path = trailingslashit( $dest_dir ) . $file;

			$this->royalbr_instance->log_e( 'Moving: %s -> %s', $file, $dest_dir );

			$is_dir = isset( $filestruc['type'] ) && 'd' === $filestruc['type'];

			if ( $wp_filesystem->exists( $target_path ) ) {

				if ( self::MOVEIN_MAKE_BACKUP_OF_EXISTING == $preserve_existing ) {
					if ( ! $wp_filesystem->move( $target_path, $target_path . '-old', true ) ) {
						$this->royalbr_instance->log_e( 'Warning: Could not move old file out of the way (%s-old)', $file );
					} else {
						$this->royalbr_instance->log_e( 'Moved existing file out of the way (%s-old)', $file );
					}
				} elseif ( self::MOVEIN_DO_NOTHING_IF_EXISTING == $preserve_existing ) {
					continue;
				}

				// Only delete if NOT using MOVEIN_COPY_IN_CONTENTS for directories.
				// For MOVEIN_COPY_IN_CONTENTS with existing directories, skip deletion
				// so the merge logic below can properly combine contents from multiple chunks.
				if ( self::MOVEIN_COPY_IN_CONTENTS != $preserve_existing || ! $is_dir ) {
					if ( $wp_filesystem->exists( $target_path ) ) {
						if ( ! $wp_filesystem->delete( $target_path, true ) ) {
							$this->royalbr_instance->log_e( 'Warning: Could not delete existing file: %s', $file );
							// Continue anyway - the move might overwrite
						}
					}
				}
			}

			if ( self::MOVEIN_COPY_IN_CONTENTS == $preserve_existing && $is_dir && $wp_filesystem->exists( $target_path ) && ! empty( $filestruc['files'] ) ) {
				// Directory exists and we want to copy-in contents (merge)
				$this->royalbr_instance->log_e( 'Using MOVEIN_COPY_IN_CONTENTS for directory: %s', $file );

				// Get chmod from parent directory
				$chmod = false;
				if ( $wp_filesystem->exists( $dest_dir ) ) {
					$chmod_str = $wp_filesystem->getchmod( $dest_dir );
					if ( $chmod_str ) {
						$chmod = octdec( sprintf( '%04d', $chmod_str ) );
					}
				}

				$delete_root = ( 'others' == $type || 'wpcore' == $type ) ? false : true;

				$copy_in = $this->copy_files_in( $source_path, $target_path, $filestruc['files'], $chmod, $delete_root );

				if ( ! empty( $chmod ) && $wp_filesystem->exists( $target_path ) ) {
					$wp_filesystem->chmod( $target_path, $chmod, false );
				}

				if ( is_wp_error( $copy_in ) || ! $copy_in ) {
					$this->royalbr_instance->log_e( 'Failed to copy in contents for: %s', $file );
					if ( is_wp_error( $copy_in ) ) {
						return $copy_in;
					}
					return new WP_Error( 'copy_in_failed', __( 'Failed to copy directory contents', 'royal-backup-reset' ) . ': ' . $file . $this->get_php_error_detail() );
				}

				if ( ! $wp_filesystem->rmdir( $source_path ) ) {
					$this->royalbr_instance->log_e( 'Warning: Could not remove source directory: %s', $source_path );
				}
			} elseif ( self::MOVEIN_COPY_IN_CONTENTS != $preserve_existing || ! $wp_filesystem->exists( $target_path ) || ! $is_dir ) {
				if ( ! $wp_filesystem->move( $source_path, $target_path, true ) ) {
					// Try copy + delete as fallback
					if ( $is_dir ) {
						// For directories, we'd need recursive copy - skip for now
						return new WP_Error( 'move_failed', __( 'Failed to move directory', 'royal-backup-reset' ) . ': ' . $file . $this->get_php_error_detail() );
					} else {
						if ( ! $wp_filesystem->copy( $source_path, $target_path, true ) ) {
							return new WP_Error( 'move_failed', __( 'Failed to move file', 'royal-backup-reset' ) . ': ' . $file . $this->get_php_error_detail() );
						}
						$wp_filesystem->delete( $source_path, false );
					}
				}
			}
		}

		$this->royalbr_instance->log_e( 'Successfully moved all files for %s', $type );
		return true;
	}

	/**
	 * Delete existing files before restore
	 *
	 * Removes current files from target directory to prepare for restore.
	 * ROYALBR plugin is automatically skipped to prevent self-deletion.
	 *
	 * @param string $type               Entity type (plugins, themes, uploads, etc.)
	 * @param string $get_dir            Source directory path (unused but kept for signature)
	 * @param object $wp_filesystem      WP_Filesystem instance
	 * @param string $wp_filesystem_dir  Target WP Filesystem directory path
	 * @return void
	 */
	private function delete_existing_files( $type, $get_dir, $wp_filesystem, $wp_filesystem_dir ) {

		$this->royalbr_instance->log_e( 'Deleting existing %s files from %s', $type, $wp_filesystem_dir );

		if ( ! empty( $this->skin ) ) {
			$this->skin->feedback( 'Removing existing files...' );
		}

		// Get list of files to delete.
		$del_files = $wp_filesystem->dirlist( $wp_filesystem_dir, true, false );
		if ( empty( $del_files ) ) {
			$del_files = array();
		}

		// Delete each file/directory.
		foreach ( $del_files as $file => $filestruc ) {
			if ( empty( $file ) ) {
				continue;
			}

			// Skip ROYALBR plugin directory to prevent self-deletion (both free and pro versions).
			if ( 'plugins' === $type && ( 'royal-backup-reset' === $file || 'royal-backup-reset-pro' === $file ) ) {
				$this->royalbr_instance->log_e( 'Skipping royal-backup-reset directory to prevent self-deletion' );
				continue;
			}

			$this->royalbr_instance->log_e( 'Deleting: %s', $file );

			if ( ! $wp_filesystem->delete( $wp_filesystem_dir . '/' . $file, true ) ) {
				$this->restore_log_permission_failure_message( $wp_filesystem_dir, 'Delete ' . $wp_filesystem_dir . '/' . $file );
			}
		}

		$this->royalbr_instance->log_e( 'Finished deleting existing %s files', $type );
	}

	/**
	 * Recursively copy files using WP_Filesystem API
	
	 *
	 * @param string $source_dir    Source directory
	 * @param string $dest_dir      Destination directory (must already exist)
	 * @param array  $files         Files to copy (recursive array structure from dirlist)
	 * @param mixed  $chmod         Chmod value or false
	 * @param bool   $delete_source Whether to delete source after successful copy
	 * @return true|WP_Error True on success, WP_Error on failure
	 */
	private function copy_files_in( $source_dir, $dest_dir, $files, $chmod = false, $delete_source = false ) {
		global $wp_filesystem;

		foreach ( $files as $rname => $rfile ) {
			if ( 'd' != $rfile['type'] ) {
				// File - move it with overwrite enabled
				if ( ! $wp_filesystem->move( $source_dir . '/' . $rname, $dest_dir . '/' . $rname, true ) ) {
					$source_path = $source_dir . '/' . $rname;
					$dest_path   = $dest_dir . '/' . $rname;

					// Check if files are identical using hash comparison 
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- File may not exist
					if ( @file_exists( $dest_path ) && @file_exists( $source_path ) &&
						 @md5_file( $source_path ) === @md5_file( $dest_path ) ) {
						$this->royalbr_instance->log_e(
							'Warning: Could not overwrite %s (permission denied), but file content is identical - continuing',
							$dest_path
						);
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort cleanup
						@$wp_filesystem->delete( $source_path, false );
						continue;
					}

					// Check if this is a known protected hosting provider path
					if ( $this->is_protected_hosting_path( $dest_path ) ) {
						$this->royalbr_instance->log_e(
							'Warning: Skipping protected hosting file: %s (permission denied)',
							$dest_path
						);
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort cleanup
						@$wp_filesystem->delete( $source_path, false );
						continue;
					}

					// Genuine failure - return error
					$this->royalbr_instance->log_e( 'Failed to move file: %s/%s -> %s/%s', $source_dir, $rname, $dest_dir, $rname );
					return new WP_Error( 'copy_failed', __( 'Could not copy file.', 'royal-backup-reset' ) . ': ' . $rname . $this->get_php_error_detail() );
				}
			} else {
				// Directory

				// Skip protected hosting provider directories entirely
				if ( $this->is_protected_hosting_path( $rname ) ) {
					$this->royalbr_instance->log_e(
						'Skipping protected hosting directory: %s (managed by hosting provider)',
						$dest_dir . '/' . $rname
					);
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort cleanup
					@$wp_filesystem->delete( $source_dir . '/' . $rname, true );
					continue;
				}

				if ( $wp_filesystem->is_file( $dest_dir . '/' . $rname ) ) {
					@$wp_filesystem->delete( $dest_dir . '/' . $rname, false );
				}

				if ( $wp_filesystem->exists( $dest_dir . '/' . $rname ) && ! $wp_filesystem->is_dir( $dest_dir . '/' . $rname ) && ! $wp_filesystem->move( $source_dir . '/' . $rname, $dest_dir . '/' . $rname, false ) ) {
					$this->royalbr_instance->log_e( 'Failed to move directory: %s/%s -> %s/%s', $source_dir, $rname, $dest_dir, $rname );
					return new WP_Error( 'copy_failed', __( 'Could not copy file.', 'royal-backup-reset' ) . ': ' . $rname . $this->get_php_error_detail() );
				} elseif ( ! empty( $rfile['files'] ) ) {
					if ( ! $wp_filesystem->exists( $dest_dir . '/' . $rname ) ) {
						$wp_filesystem->mkdir( $dest_dir . '/' . $rname, $chmod );
					}

					// Recursively copy in subdirectory contents
					$do_copy = $this->copy_files_in( $source_dir . '/' . $rname, $dest_dir . '/' . $rname, $rfile['files'], $chmod, false );

					if ( is_wp_error( $do_copy ) || false === $do_copy ) {
						return $do_copy;
					}
				} else {
					@$wp_filesystem->rmdir( $source_dir . '/' . $rname );
				}
			}
		}

		if ( $delete_source || false !== strpos( $source_dir, '/' ) ) {
			if ( ! $wp_filesystem->rmdir( $source_dir, false ) ) {
				$this->royalbr_instance->log_e( 'Warning: Could not delete source directory: %s', $source_dir );
			}
		}

		return true;
	}

	/**
	 * Check if a path is a known protected hosting provider path
	 *
	 * Some managed WordPress hosts (Kinsta, WP Engine, Pantheon, etc.) have protected
	 * mu-plugins directories that cannot be modified by PHP. This method detects these
	 * paths so we can skip them gracefully during restore instead of failing.
	 *
	 * @param string $path File or directory path to check
	 * @return bool True if path is a known protected hosting path
	 */
	private function is_protected_hosting_path( $path ) {
		$protected_patterns = array(
			'kinsta-mu-plugins',   // Kinsta hosting
			'wpengine-security',   // WP Engine
			'mu-plugin.php',       // WP Engine single file
			'slt-force-strong-passwords.php', // WP Engine
			'pantheon.php',        // Pantheon
			'pantheon-mu-plugin',  // Pantheon
			'gd-system-plugin',    // GoDaddy
		);

		// Allow filtering for additional hosting providers
		$protected_patterns = apply_filters( 'royalbr_protected_hosting_paths', $protected_patterns );

		foreach ( $protected_patterns as $pattern ) {
			if ( false !== strpos( $path, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete a backup file
	 *
	 * @param string $backup_filename Backup filename
	 * @return bool|WP_Error
	 */
	public function delete_backup( $backup_filename ) {
		$backup_dir = rtrim( ROYALBR_BACKUP_DIR, '/\\' ) . DIRECTORY_SEPARATOR;
		$file_path = $backup_dir . $backup_filename;

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Backup file not found', 'royal-backup-reset' ) );
		}

		if ( wp_delete_file( $file_path ) ) {
			$this->royalbr_instance->log_e( 'Deleted backup file: %s', $backup_filename );
			return true;
		}

		return new WP_Error( 'delete_failed', __( 'Failed to delete backup file', 'royal-backup-reset' ) );
	}

	// ========================================================================
	// HELPER METHODS FOR ROYALBR API
	// ========================================================================

	/**
	 * Get all backup files for a specific timestamp
	 * Supports both nonce-based and legacy filenames
	 *
	 * @param string $timestamp Backup timestamp (YYYY-MM-DD-HHMM)
	 * @return array Array of backup files grouped by component
	 */
	private function get_session_files( $timestamp ) {
		$files = array();
		$backup_dir = rtrim( ROYALBR_BACKUP_DIR, '/\\' ) . DIRECTORY_SEPARATOR;

		$this->royalbr_instance->log_e( 'get_session_files: Looking for timestamp=%s in dir=%s', $timestamp, $backup_dir );

		if ( ! is_dir( $backup_dir ) ) {
			$this->royalbr_instance->log_e( 'get_session_files: Backup directory does not exist!' );
			return $files;
		}

		$all_files = scandir( $backup_dir );
		$this->royalbr_instance->log_e( 'get_session_files: Found %d total files in directory', count( $all_files ) );

		foreach ( $all_files as $file ) {
			$ext = pathinfo( $file, PATHINFO_EXTENSION );
			if ( 'gz' !== $ext && 'zip' !== $ext ) {
				continue;
			}

			$this->royalbr_instance->log_e( 'get_session_files: Checking file: %s', $file );

			$file_timestamp = null;
			$component = null;
			$part_number = '';

			// Try NEW pattern with nonce: backup_2025-01-15-1430_mysite-com_abc123def456-db.gz
			if ( preg_match( '/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_(.+)_([a-f0-9]{12})-([^0-9.]+)(\d+)?\.(gz|zip)$/', $file, $matches ) ) {
				$file_timestamp = $matches[1];
				$component = $matches[4]; // db, plugins, themes, etc.
				$part_number = isset( $matches[5] ) ? $matches[5] : '';
				$this->royalbr_instance->log_e( 'get_session_files: Matched NEW pattern - timestamp=%s, component=%s', $file_timestamp, $component );
			}
			// Legacy pattern without nonce: backup_2025-01-15-1430_mysite-com-db.gz
			elseif ( preg_match( '/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_(.+)-([^0-9.]+)(\d+)?\.(gz|zip)$/', $file, $matches ) ) {
				$file_timestamp = $matches[1];
				$component = $matches[3]; // plugins, themes, etc.
				$part_number = isset( $matches[4] ) ? $matches[4] : '';
				$this->royalbr_instance->log_e( 'get_session_files: Matched LEGACY pattern - timestamp=%s, component=%s', $file_timestamp, $component );
			} else {
				$this->royalbr_instance->log_e( 'get_session_files: File did NOT match any pattern: %s', $file );
			}

			// Check if this file belongs to the requested timestamp
			if ( $file_timestamp === $timestamp && $component ) {
				// Handle database files specially - never convert to array
				if ( $component === 'db' ) {
					$files[ $component ] = $file;
				} else {
					// Group split files under same component for non-db files
					if ( $part_number ) {
						// This is a split file (plugins2, themes2, etc.)
						if ( ! isset( $files[ $component ] ) ) {
							$files[ $component ] = array();
						}
						if ( ! is_array( $files[ $component ] ) ) {
							$files[ $component ] = array( $files[ $component ] );
						}
						$files[ $component ][] = $file;
					} else {
						// This is a single file or first file of a series
						if ( ! isset( $files[ $component ] ) ) {
							$files[ $component ] = $file;
						} else {
							// Convert to array if we already have a file for this component
							if ( ! is_array( $files[ $component ] ) ) {
								$files[ $component ] = array( $files[ $component ] );
							}
							$files[ $component ][] = $file;
						}
					}
				}
			}
		}

		// Sort split files by name to ensure proper order
		foreach ( $files as $component => $file_data ) {
			if ( is_array( $file_data ) ) {
				sort( $file_data );
				$files[ $component ] = $file_data;
			}
		}

		$this->royalbr_instance->log_e( 'get_session_files: Returning %d components: %s', count( $files ), implode( ', ', array_keys( $files ) ) );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Used for production logging of complex data structures (matches reference implementation)
		$this->royalbr_instance->log_e( 'get_session_files: Full result: %s', print_r( $files, true ) );

		return $files;
	}

	/**
	 * Clear WordPress caches and restore state
	 *
	 * @return void
	 */
	private function restore_wordpress_state() {
		// Clear option cache
		wp_cache_flush();

		// Clear all known caches
		$this->clear_all_caches();

		// Have seen a case where the current theme in the DB began with a capital, but not on disk
		$template = get_option( 'template' );
		if ( ! empty( $template ) && WP_DEFAULT_THEME != $template && strtolower( $template ) != $template ) {
			$theme_root = get_theme_root( $template );
			$theme_dir = $theme_root . '/' . $template;
			if ( ! file_exists( $theme_dir ) ) {
				$lower_case_template = strtolower( $template );
				if ( file_exists( $theme_root . '/' . $lower_case_template ) ) {
					$this->royalbr_instance->log_e( 'Theme directory has different case - updating option' );
					update_option( 'template', $lower_case_template );
					update_option( 'stylesheet', $lower_case_template );
				}
			}
		}

		$this->royalbr_instance->log_e( 'WordPress state restored' );
	}

	/**
	 * Clear all known caches
	 *
	 * @return void
	 */
	private function clear_all_caches() {
		// WordPress core cache
		wp_cache_flush();

		// Clear transients
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->royalbr_instance->log_e( 'All caches cleared' );
	}

} // End class ROYALBR_Restore
