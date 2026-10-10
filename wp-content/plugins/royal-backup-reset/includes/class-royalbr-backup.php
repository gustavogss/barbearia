<?php
/**
 * Backup Engine - Database and File Operations
 *
 * Orchestrates complete WordPress backup creation including database exports
 * and file archival with split-zip support for large sites.
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}

require_once ROYALBR_INCLUDES_DIR . 'database/class-royalbr-database-utility.php';
require_once ROYALBR_INCLUDES_DIR . 'class-royalbr-binzip.php';

/**
 * Core backup engine for WordPress database and filesystem archival.
 *
 * Manages incremental and full backups with automatic split-archive handling
 * for shared hosting environments with resource constraints.
 *
 * @since 1.0.0
 */
class ROYALBR_Backup {

	// === ARCHIVE MANAGEMENT PROPERTIES ===

	/**
	 * Split archive sequence number for current entity being processed
	 *
	 * @var int
	 */
	private $index = 0;

	/**
	 * Path to manifest file tracking multi-part archives
	 *
	 * @var string
	 */
	private $manifest_path;

	/**
	 * Count of files written to current archive since creation
	 *
	 * @var int
	 */
	private $archive_file_count;

	/**
	 * Number of files processed in current batch operation
	 *
	 * @var int
	 */
	private $files_processed_current_batch = 0;

	/**
	 * Queue of directory paths pending addition to archive
	 *
	 * @var array
	 */
	public $directories_queue;

	/**
	 * Queue of file paths pending addition to archive with their storage names
	 *
	 * @var array
	 */
	public $files_queue;

	/**
	 * Files excluded from incremental backup due to unchanged modification time
	 *
	 * @var array
	 */
	public $unchanged_files_skipped;

	/**
	 * Map of symlinked directories to their original paths for recursion detection
	 *
	 * @var array
	 */
	private $symlink_reversals = array();

	/**
	 * Accumulated uncompressed byte size of files queued for next batch write
	 *
	 * @var int
	 */
	private $current_batch_size_bytes;

	/**
	 * Maximum single archive size before automatic split (370MB for shared hosting compatibility)
	 *
	 * @var int
	 */
	private $archive_max_size = 387973120; // 370MB - Optimized for shared hosting stability

	/**
	 * Observed compression efficiency from previous batch to predict split points
	 *
	 * @var float
	 */
	private $zip_last_ratio = 1;

	/**
	 * Entity type currently being archived (plugins, themes, uploads, others)
	 *
	 * @var string
	 */
	private $whichone;

	/**
	 * Base filesystem path for archive files without sequence suffix or extension
	 *
	 * @var string
	 */
	private $archive_base_path = '';

	/**
	 * Timestamp of last successful write operation to archive file
	 *
	 * @var int
	 */
	private $last_zip_write_timestamp;

	/**
	 * System binary zip utility detection state (0=unchecked, false=unavailable, string=path)
	 *
	 * @var int|bool|string
	 */
	public $binzip = 0;

	// === DATABASE EXPORT PROPERTIES ===

	/**
	 * Active file handle for SQL export stream (gzipped or plain text)
	 *
	 * @var resource
	 */
	private $db_file_handle;

	/**
	 * Flag indicating whether database export uses gzip compression
	 *
	 * @var bool
	 */
	private $db_compression_enabled;

	/**
	 * Database connection identifier ('wp' for WordPress default or custom name)
	 *
	 * @var string
	 */
	private $database_identifier;

	/**
	 * Filename suffix appended to database backup file (empty for main WordPress database)
	 *
	 * @var string
	 */
	private $database_file_suffix;

	/**
	 * Incremental backup cutoff timestamps indexed by entity type
	 *
	 * @var int|array
	 */
	private $modified_after = -1;

	/**
	 * Unix timestamp threshold for filtering files in incremental backups
	 *
	 * @var int
	 */
	private $incremental_backup_timestamp = -1;

	// === FILE EXCLUSION PROPERTIES ===

	/**
	 * Lowercase file extensions to skip during archive creation
	 *
	 * @var bool|array
	 */
	private $excluded_extensions = false;

	/**
	 * Wildcard patterns for path-based exclusion rules
	 *
	 * @var bool|array
	 */
	private $excluded_wildcards = false;

	/**
	 * Filename prefixes that trigger automatic exclusion
	 *
	 * @var bool|array
	 */
	private $excluded_prefixes = false;

	// === BACKUP ENGINE CONFIGURATION ===

	/**
	 * Archive library class name (hardcoded to ZipArchive for this plugin)
	 *
	 * @var string
	 */
	private $use_zip_object = 'ZipArchive';

	/**
	 * Whether to enable verbose debug logging throughout backup process
	 *
	 * @var bool
	 */
	public $debug = false;

	/**
	 * Absolute path to backup storage directory
	 *
	 * @var string
	 */
	public $royalbr_dir;

	/**
	 * Sanitized site identifier used in backup filenames
	 *
	 * @var string
	 */
	private $site_name;

	/**
	 * WordPress database connection instance for SQL operations
	 *
	 * @var wpdb
	 */
	private $wpdb_obj;

	/**
	 * Entity types configured for current backup task with their split indices
	 *
	 * @var array
	 */
	private $task_file_entities = array();

	/**
	 * First-run indicator for initialization logic
	 *
	 * @var int
	 */
	private $first_run = 0;

	/**
	 * Registry of all backup files created during current operation
	 *
	 * @var array
	 */
	private $backup_files_array = array();

	/**
	 * File extensions that should use STORE mode instead of DEFLATE compression
	 *
	 * @var array
	 */
	private $extensions_to_not_compress = array();

	/**
	 * Database tables excluded from backup by user configuration
	 *
	 * @var array
	 */
	private $skipped_tables;

	/**
	 * Reference to last cloud storage provider used for remote upload
	 *
	 * @var mixed
	 */
	public $last_storage_instance;

	/**
	 * Maximum uncompressed bytes to queue before forcing batch write (200MB)
	 *
	 * @var int
	 */
	private $zip_batch_ceiling;

	/**
	 * Predefined directory and pattern exclusions for known problematic paths
	 *
	 * @var array
	 */
	private $backup_excluded_patterns = array();

	// === FILE ENUMERATION CACHING (Large Site Support) ===

	/**
	 * Base path for file list cache files (without suffix)
	 *
	 * @var string
	 */
	private $cache_file_base = '';

	/**
	 * Whether file lists were loaded from cache instead of scanning
	 *
	 * @var bool
	 */
	private $got_files_from_cache = false;

	/**
	 * Large file warning threshold (250MB) - files larger than this get logged
	 *
	 * @var int
	 */
	private $warn_file_size = 262144000;

	/**
	 * Files larger than this will be skipped (1GB default, can be overridden)
	 *
	 * @var int
	 */
	private $skip_file_over_size = 1073741824;

	// === DATABASE EXPORT STATE ===

	/**
	 * Uncompressed byte count written to current database export file
	 *
	 * @var int
	 */
	private $db_current_raw_bytes = 0;

	/**
	 * Processed table prefix with case-sensitivity handling applied
	 *
	 * @var string
	 */
	private $table_prefix;

	/**
	 * Original unmodified table prefix from WordPress configuration
	 *
	 * @var string
	 */
	private $table_prefix_raw;

	/**
	 * Flag indicating large table warning was already logged
	 *
	 * @var bool
	 */
	private $many_rows_warning = false;

	/**
	 * Row count state for progress tracking (false=unknown, true=counted, int=estimated)
	 *
	 * @var bool|int
	 */
	private $expected_rows = false;

	/**
	 * Whether to attempt table splitting for memory efficiency
	 *
	 * @var bool
	 */
	private $try_split = false;

	/**
	 * High-resolution start time for archive performance measurement
	 *
	 * @var float
	 */
	private $zip_microtime_start;

	/**
	 * Remote service identifier for cloud upload operations
	 *
	 * @var string
	 */
	public $current_service;

	/**
	 * Map of files already present in resume archive
	 *
	 * @var array
	 */
	private $existing_files;

	/**
	 * Total uncompressed size of files already in resume archive
	 *
	 * @var int
	 */
	private $existing_files_rawsize;

	/**
	 * Combined filesystem size of all existing split archives
	 *
	 * @var int
	 */
	private $existing_zipfiles_size;

	/**
	 * Database connection parameters for current export operation
	 *
	 * @var array
	 */
	private $dbinfo;

	/**
	 * Flag indicating table names differ only by case (Windows compatibility issue)
	 *
	 * @var bool
	 */
	private $duplicate_tables_exist = false;

	/**
	 * Starting split index for linked multi-part archives
	 *
	 * @var int
	 */
	private $first_linked_index;

	/**
	 * Unique identifier for current backup task session
	 *
	 * @var string
	 */
	public $current_instance;

	/**
	 * Root source directory for entity currently being archived
	 *
	 * @var string
	 */
	public $create_archive_file_source;

	/**
	 * Unix timestamp when current backup operation began
	 *
	 * @var int
	 */
	private $backup_time;

	/**
	 * Random nonce ensuring backup file uniqueness across concurrent operations
	 *
	 * @var string
	 */
	private $file_nonce;

	/**
	 * Absolute path to current operation's log file
	 *
	 * @var string
	 */
	public $logfile_name = "";

	/**
	 * Open file handle for writing log entries during backup
	 *
	 * @var resource|false
	 */
	public $logfile_handle = false;

	/**
	 * Microtime when log file was opened for session tracking
	 *
	 * @var float
	 */
	public $opened_log_time;

	/**
	 * Microtime when backup task started for timing calculations
	 *
	 * @var float
	 */
	public $task_time_ms;

	/**
	 * Reference to main plugin instance for logging and configuration access
	 *
	 * @var Royal_Backup_Reset
	 */
	private $royalbr;

	/**
	 * Store the last PHP error message for inclusion in error messages
	 *
	 * Captured by php_error() handler and used for specific error details
	 * (e.g., "No space left on device") to display to user
	 *
	 * @var string
	 */
	private $last_php_error = '';

	/**
	 * Store the backup error message to display to user
	 *
	 * This error is stored in taskdata for progress polling to retrieve
	 * and display in the UI when backup fails
	 *
	 * @var string
	 */
	private $backup_error = '';

	/**
	 * Initialize backup engine with directory structure and default configuration.
	 *
	 * Sets up backup storage location, security protections, and optimized
	 * compression settings for various file types.
	 *
	 * @since 1.0.0
	 * @param Royal_Backup_Reset $royalbr Main plugin instance for logging and configuration
	 */
	public function __construct( $royalbr = null ) {
		global $royalbr_instance;

		$this->royalbr = $royalbr ? $royalbr : $royalbr_instance;

		// Extract and sanitize site identifier for filename generation
		$this->site_name = $this->fetch_site_identifier();

		// Normalize backup directory path
		$this->royalbr_dir = rtrim(ROYALBR_BACKUP_DIR, '/\\');

		// Create backup directory with web access protection
		if (!file_exists($this->royalbr_dir)) {
			wp_mkdir_p($this->royalbr_dir);

			// Prevent direct HTTP access to backup files
			if (!file_exists($this->royalbr_dir . DIRECTORY_SEPARATOR . '.htaccess')) {
				file_put_contents($this->royalbr_dir . DIRECTORY_SEPARATOR . '.htaccess', 'deny from all');
			}
			if (!file_exists($this->royalbr_dir . DIRECTORY_SEPARATOR . 'index.php')) {
				file_put_contents($this->royalbr_dir . DIRECTORY_SEPARATOR . 'index.php', '<?php // Silence is golden');
			}
		}

		$this->use_zip_object = 'ZipArchive';

		// Detect binary zip for more reliable archiving on constrained hosts
		if ( 0 === $this->binzip ) {
			$this->log( 'Checking if we have a zip executable available' );
			$binzip = $this->detect_binary_zip();
			if ( is_string( $binzip ) ) {
				$this->log( 'Zip engine: found/will use binary zip: ' . $binzip );
				$this->binzip = $binzip;
				$this->use_zip_object = 'ROYALBR_BinZip';
			}
		}

		// Define pre-compressed formats that waste CPU with additional compression
		$this->extensions_to_not_compress = array(
			'zip', 'gz', 'bz2', '7z', 'rar',
			'jpg', 'jpeg', 'png', 'gif', 'webp',
			'mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv',
			'pdf', 'doc', 'docx', 'ppt', 'pptx'
		);

		// Configure automatic exclusions for known problematic plugin directories
		$this->backup_excluded_patterns = array(
			array(
				// All-in-One WP Migration proprietary backup format
				'directory' => realpath(WP_PLUGIN_DIR) . DIRECTORY_SEPARATOR . 'all-in-one-wp-migration' . DIRECTORY_SEPARATOR . 'storage',
				'regex' => '/.+\.wpress$/is',
			),
		);

		// Configure batch write threshold to balance memory and performance
		$this->zip_batch_ceiling = 200 * 1048576;
	}

	/**
	 * Log message to backup log file and delegate to main plugin instance.
	 *
	 * Writes directly to log file for immediate persistence,
	 * then delegates to main plugin for additional processing (UI updates, filters, etc).
	 *
	 * @since 1.0.0
	 * @param string $line    Message text to record
	 * @param string $level   Severity level (notice, warning, error)
	 * @param mixed  $uniq_id Optional unique ID for log deduplication
	 */
	public function log( $line, $level = 'notice', $uniq_id = false ) {
		// Write directly to backup log file for immediate persistence
		$this->write_to_log( $line, $level );

		// Also delegate to main plugin for UI updates and additional processing
		if ( $this->royalbr && method_exists( $this->royalbr, 'log' ) ) {
			$this->royalbr->log( $line, $level, $uniq_id );
		}
	}

	/**
	 * Detect user-triggered abort by checking for deletion flag file.
	 *
	 * @since 1.0.0
	 * @return bool True if user requested cancellation, false if operation should continue
	 */
	private function check_abort_requested() {
		global $royalbr_instance;

		$backup_dir = ROYALBR_BACKUP_DIR;
		$nonce = $royalbr_instance->file_nonce;
		$deleteflag = $backup_dir . 'deleteflag-' . $nonce . '.txt';

		if (file_exists($deleteflag)) {
			$this->log('User abort requested: halting backup operation immediately');

			@wp_delete_file($deleteflag);

			// Trigger cleanup procedures before terminating
			$royalbr_instance->backup_finish(true);

			return true;
		}

		return false;
	}

	/**
	 * Generate collision-resistant nonce for backup file uniqueness.
	 *
	 * Produces 12-character hash combining timestamp and random data to prevent
	 * conflicts when multiple backup processes run simultaneously.
	 *
	 * @since  1.0.0
	 * @return string 12-character hexadecimal nonce
	 */
	private function generate_nonce() {
		// Combine timestamp and random value for entropy
		return substr(md5(time() . wp_rand()), 20);
	}

	/**
	 * Extract and sanitize site identifier for cross-platform filename compatibility.
	 *
	 * @since  1.0.0
	 * @return string Alphanumeric site identifier safe for FTP, cloud storage, and all filesystems
	 */
	private function fetch_site_identifier() {
		// Strip special characters that cause issues in cloud storage paths
		$site_name = str_replace('__', '_', preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', substr(get_bloginfo(), 0, 32))));

		if (!$site_name || preg_match('#^_+$#', $site_name)) {
			// Fallback to domain-based identifier if site title is empty
			$parsed_url = wp_parse_url(home_url(), PHP_URL_HOST);
			$parsed_subdir = untrailingslashit(wp_parse_url(home_url(), PHP_URL_PATH));
			if ($parsed_subdir && '/' != $parsed_subdir) {
				$parsed_url .= str_replace(array('/', '\\'), '_', $parsed_subdir);
			}
			$site_name = str_replace('__', '_', preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', substr($parsed_url, 0, 32))));
			if (!$site_name || preg_match('#^_+$#', $site_name)) {
				$site_name = 'WordPress_Backup';
			}
		}

		// Allow custom identifier via filter hook
		return apply_filters('royalbr_blog_name', $site_name);
	}

	// ========================================================================
	// DATABASE EXPORT ENGINE - WITH MULTI-RESUMPTION SUPPORT
	// ========================================================================

	/**
	 * Execute complete database export with per-table files and resumption support.
	 *
	 * Uses per-table temporary files that are stitched together at the end.
	 * Supports resumption from any table/row position across WP-Cron executions.
	 *
	 * @since  1.0.0
	 * @param  string $already_done        Task state ('begun' starts export, 'finished'/'encrypted' returns filename)
	 * @param  string $database_identifier Database ID ('wp' for WordPress default)
	 * @param  array  $dbinfo              Connection credentials for external databases
	 * @return string|bool Generated SQL filename on success, false on export failure
	 */
	public function create_database_backup( $already_done = 'begun', $database_identifier = 'wp', $dbinfo = array() ) {
		global $wpdb, $royalbr_instance;

		$this->database_identifier = $database_identifier;
		$this->database_file_suffix = ( 'wp' === $database_identifier ) ? '' : $database_identifier;

		// Configure connection for WordPress default or external database.
		if ( 'wp' === $this->database_identifier ) {
			$this->wpdb_obj       = $wpdb;
			$this->table_prefix   = $wpdb->prefix;
			$this->table_prefix_raw = $wpdb->prefix;
			$dbinfo['host'] = DB_HOST;
			$dbinfo['name'] = DB_NAME;
			$dbinfo['user'] = DB_USER;
			$dbinfo['pass'] = DB_PASSWORD;
		}

		$this->dbinfo = $dbinfo;

		// Prepare database utility with connection and disable problematic SQL modes.
		ROYALBR_Database_Utility::init( $database_identifier, $this->table_prefix_raw, $this->wpdb_obj );
		ROYALBR_Database_Utility::configure_db_sql_mode( array(), array( 'ANSI_QUOTES' ), $this->wpdb_obj );

		$file_base       = $this->generate_backup_filename( $royalbr_instance->backup_time );
		$backup_file_base = rtrim( $this->royalbr_dir, '/\\' ) . DIRECTORY_SEPARATOR . $file_base;
		$backup_final_file = $backup_file_base . '-db' . $this->database_file_suffix . '.gz';

		// Return filename only - for checking completion status.
		if ( 'finished' === $already_done ) {
			return basename( $backup_file_base ) . '-db' . ( ( 'wp' === $database_identifier ) ? '' : $database_identifier ) . '.gz';
		}
		if ( 'encrypted' === $already_done ) {
			return basename( $backup_file_base ) . '-db' . ( ( 'wp' === $database_identifier ) ? '' : $database_identifier ) . '.gz.crypt';
		}

		// Get list of all tables.
		$all_tables = $this->wpdb_obj->get_results( 'SHOW FULL TABLES', ARRAY_N );

		if ( empty( $all_tables ) && ! empty( $this->wpdb_obj->last_error ) ) {
			$all_tables = $this->wpdb_obj->get_results( 'SHOW TABLES', ARRAY_N );
			$all_tables = array_map( array( $this, 'cb_get_name_base_type' ), $all_tables );
		} else {
			$all_tables = array_map( array( $this, 'cb_get_name_type' ), $all_tables );
		}

		if ( 0 === count( $all_tables ) ) {
			$this->log( 'No database tables found' );
			return false;
		}

		// Prioritize critical tables (options, users) for faster restoration.
		usort( $all_tables, array( 'ROYALBR_Database_Utility', 'sort_tables_for_backup' ) );

		$all_table_names = array_map( array( $this, 'cb_get_name' ), $all_tables );

		// Detect case-sensitivity issues on Windows servers.
		$this->duplicate_tables_exist = false;
		foreach ( $all_table_names as $table ) {
			if ( strtolower( $table ) !== $table && in_array( strtolower( $table ), $all_table_names, true ) ) {
				$this->duplicate_tables_exist = true;
				$this->log( "Tables with names differing only by case exist: $table / " . strtolower( $table ) );
			}
		}

		$how_many_tables = count( $all_tables );
		$total_tables    = 0;
		$errors          = 0;
		$stitch_files    = array();

		// Scan for existing per-table files (from previous resumptions).
		$potential_stitch_files = array();
		$table_file_prefix_pattern = $file_base . '-db' . $this->database_file_suffix . '-table-';
		$dir_handle = opendir( $this->royalbr_dir );
		if ( $dir_handle ) {
			while ( false !== ( $entry = readdir( $dir_handle ) ) ) {
				if ( 0 === strpos( $entry, $table_file_prefix_pattern ) && preg_match( '/\.gz$/', $entry ) ) {
					$potential_stitch_files[] = $entry;
				}
			}
			closedir( $dir_handle );
		}

		// Process each table with per-table files for resumption.
		foreach ( $all_tables as $ti ) {
			$table      = $ti['name'];
			$table_type = $ti['type'];

			$stitch_files[ $table ] = array();

			$this->many_rows_warning = false;
			$total_tables++;

			// Increase script execution time-limit for every table.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 900 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			}

			// The table file prefix for per-table temporary files.
			$table_file_prefix = $file_base . '-db' . $this->database_file_suffix . '-table-' . $table . '.table';

			// Check if table is already finished (final .gz file exists).
			if ( file_exists( $this->royalbr_dir . '/' . $table_file_prefix . '.gz' ) ) {
				$stitched  = count( $stitch_files, COUNT_RECURSIVE );
				$skip_log  = ( ( $stitched > 10 && 0 !== $stitched % 20 ) || ( $stitched > 100 && 0 !== $stitched % 100 ) );
				if ( ! $skip_log ) {
					$this->log( "Table $table: file already exists; moving on" );
				}

				// Find any segment files for this table.
				$max_record = false;
				foreach ( $potential_stitch_files as $e ) {
					if ( preg_match( '#' . preg_quote( $table_file_prefix, '#' ) . '\.tmpr?(\d+)\.gz$#', $e, $matches ) ) {
						$stitch_files[ $table ][ $matches[1] ] = $e;
						if ( false === $max_record || $matches[1] > $max_record ) {
							$max_record = $matches[1];
						}
					}
				}
				$stitch_files[ $table ][ $max_record + 1 ] = $table_file_prefix . '.gz';
				continue;
			}

			// Check if table matches our prefix.
			if ( empty( $this->table_prefix ) ||
				( ! $this->duplicate_tables_exist && 0 === stripos( $table, $this->table_prefix ) ) ||
				( $this->duplicate_tables_exist && 0 === strpos( $table, $this->table_prefix ) ) ) {

				$royalbr_instance->save_task_data(
					'dbcreating_substatus',
					array(
						't' => $table,
						'i' => $total_tables,
						'a' => $how_many_tables,
					)
				);

				// Per-table temporary file.
				$db_temp_file = $this->royalbr_dir . '/' . $table_file_prefix . '.tmp.gz';

				// Check for recent modification (activity detection).
				$this->check_recent_modification( $db_temp_file );

				// Open per-table file.
				if ( false === $this->initialize_db_backup_file( $db_temp_file, true ) ) {
					return false;
				}

				// Meaning: false = don't yet know; true = know and have logged it; integer = the expected number.
				$this->expected_rows = false;

				$table_status = $this->wpdb_obj->get_row( $this->wpdb_obj->prepare( 'SHOW TABLE STATUS WHERE Name=%s', $table ) );
				if ( isset( $table_status->Rows ) ) {
					$this->expected_rows = $table_status->Rows;
				}

				// Determine start record from existing segment files.
				$start_record        = true;
				$can_use_primary_key = true;
				foreach ( $potential_stitch_files as $e ) {
					if ( preg_match( '#' . preg_quote( $table_file_prefix, '#' ) . '\.tmp(r)?(\d+)\.gz$#', $e, $matches ) ) {
						$stitch_files[ $table ][ $matches[2] ] = $e;
						if ( true === $start_record || $matches[2] > $start_record ) {
							$start_record = $matches[2];
						}
						// Legacy scheme detection.
						if ( 'r' !== $matches[1] ) {
							$can_use_primary_key = false;
						}
					}
				}

				// Legacy file-naming scheme in use.
				if ( false === $can_use_primary_key && true !== $start_record ) {
					$start_record = ( $start_record + 100 ) * 1000;
				}

				// Export table data with resumption support.
				while ( ! is_array( $start_record ) && ! is_wp_error( $start_record ) ) {
					$start_record = $this->export_table_data( $table, $table_type, $start_record, $can_use_primary_key );

					if ( is_int( $start_record ) || is_array( $start_record ) ) {
						$this->finalize_db_backup_file();

						// Calculate the record marker for file renaming.
						$use_record = is_array( $start_record ) ? ( isset( $start_record['next_record'] ) ? $start_record['next_record'] + 1 : false ) : $start_record;
						if ( ! $can_use_primary_key ) {
							$use_record = ( ceil( $use_record / 100000 ) - 1 ) * 100;
						}

						if ( false !== $use_record ) {
							// Rename with record marker for resumption.
							$rename_base = $table_file_prefix . '.tmp' . ( $can_use_primary_key ? 'r' : '' ) . $use_record . '.gz';
							// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
							rename( $db_temp_file, $this->royalbr_dir . '/' . $rename_base );
							$stitch_files[ $table ][ $use_record ] = $rename_base;
						} elseif ( is_array( $start_record ) && 'view' === strtolower( $table_type ) ) {
							$rename_base = $table_file_prefix . '-view.tmp.gz';
							// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
							rename( $db_temp_file, $this->royalbr_dir . '/' . $rename_base );
							$stitch_files[ $table ][] = $rename_base;
						}

						// Signal progress for scheduling.
						ROYALBR_Task_Scheduler::something_useful_happened();

						// Re-open for next segment.
						if ( false === $this->initialize_db_backup_file( $db_temp_file, true ) ) {
							return false;
						}
					} elseif ( is_wp_error( $start_record ) ) {
						$message = "Error (table=$table, type=$table_type) (" . $start_record->get_error_code() . '): ' . $start_record->get_error_message();
						$this->log( $message );
						$errors++;
					}
				}

				$this->finalize_db_backup_file();

				if ( $errors > 0 ) {
					$this->log( 'Errors occurred during backing up the table; removing open file' );
					@unlink( $db_temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				} else {
					// Rename indicates writing finished.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					rename( $db_temp_file, $this->royalbr_dir . '/' . $table_file_prefix . '.gz' );
					ROYALBR_Task_Scheduler::something_useful_happened();

					$final_stitch_value = empty( $stitch_files[ $table ] ) ? 1 : max( array_keys( $stitch_files[ $table ] ) ) + 1;
					$stitch_files[ $table ][ $final_stitch_value ] = $table_file_prefix . '.gz';

					$this->log( "Table $table: finishing file(s) (" . count( $stitch_files[ $table ] ) . ')' );
				}
			} else {
				$total_tables--;
				$this->log( "Skipping table (lacks our prefix ($this->table_prefix)): $table" );
			}
		}

		if ( $errors > 0 ) {
			$this->log( 'Errors occurred whilst backing up tables; will wait for resumption' );
			return false;
		}

		// Race detection - check if another process is writing the final file.
		$time_now = time();
		$time_mod = (int) @filemtime( $backup_final_file );
		if ( file_exists( $backup_final_file ) && $time_mod > 100 && ( $time_now - $time_mod ) < 30 ) {
			ROYALBR_Task_Scheduler::terminate_due_to_activity( $backup_final_file, $time_now, $time_mod );
		}

		// Stitch all per-table files into final database dump.
		if ( ! function_exists( 'gzopen' ) ) {
			$this->log( 'PHP function gzopen is disabled; cannot stitch database files' );
			return false;
		}

		// Open final file and write header.
		if ( false === $this->initialize_db_backup_file( $backup_final_file, true ) ) {
			return false;
		}
		$this->write_db_backup_header();

		// Close and reopen in binary append mode for stitching.
		$this->finalize_db_backup_file();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$final_handle = fopen( $backup_final_file, 'ab' );
		if ( ! $final_handle ) {
			$this->log( 'Could not open final database file for stitching' );
			return false;
		}

		$unlink_files = array();
		$sind         = 1;

		// Concatenating gz files produces a valid gz file.
		foreach ( $stitch_files as $table => $table_stitch_files ) {
			ksort( $table_stitch_files );
			foreach ( $table_stitch_files as $table_file ) {
				$table_file_path = $this->royalbr_dir . '/' . $table_file;

				if ( filesize( $table_file_path ) < 27 && '.gz' === substr( $table_file, -3, 3 ) ) {
					// Null gzip file - skip.
					$unlink_files[] = $table_file_path;
				} elseif ( ! $handle = fopen( $table_file_path, 'rb' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.CodeAnalysis.AssignmentInCondition.Found
					$this->log( "Error: Failed to open database file for reading: $table_file" );
					$errors++;
				} else {
					while ( ! feof( $handle ) ) {
						$chunk = fread( $handle, 1048576 ); // 1MB chunks.
						fwrite( $final_handle, $chunk );
					}
					fclose( $handle );
					$unlink_files[] = $table_file_path;
				}
				$sind++;

				// Signal progress periodically.
				if ( 0 === $sind % 100 ) {
					ROYALBR_Task_Scheduler::something_useful_happened();
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $final_handle );

		// Re-open in gz append mode to write footer.
		if ( false === $this->initialize_db_backup_file( $backup_final_file, true, true ) ) {
			return false;
		}

		$this->write_db_content( "\n# Complete transaction\n" );
		$this->write_db_content( "COMMIT;\n" );
		$this->write_db_content( "SET AUTOCOMMIT = 1;\n" );
		$this->write_db_content( "SET foreign_key_checks = 1;\n\n" );
		$this->write_db_content( "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n" );
		$this->write_db_content( "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n" );
		$this->write_db_content( "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n" );
		$this->write_db_content( "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n" );

		$this->finalize_db_backup_file();

		$this->log( basename( $backup_final_file ) . ': finished writing out complete database file (' . round( filesize( $backup_final_file ) / 1024, 1 ) . ' KB)' );

		// Clean up per-table files.
		foreach ( $unlink_files as $unlink_file ) {
			@unlink( $unlink_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( $errors > 0 ) {
			return false;
		}

		$royalbr_instance->save_task_data( 'taskstatus', 'dbcreated' . $this->database_file_suffix );

		return basename( $backup_final_file );
	}

	/**
	 * Check if a file was recently modified (indicates another process is active).
	 *
	 * @since 1.0.0
	 * @param string $file File path to check.
	 */
	private function check_recent_modification( $file ) {
		if ( ! file_exists( $file ) ) {
			return;
		}

		$time_now = time();
		$time_mod = (int) @filemtime( $file );

		if ( $time_mod > 100 && ( $time_now - $time_mod ) < 30 ) {
			ROYALBR_Task_Scheduler::terminate_due_to_activity( $file, $time_now, $time_mod );
		}
	}

	/**
	 * Stream table rows to SQL file with intelligent batching and field type handling.
	 *
	 * @since  1.0.0
	 * @param  string $table               Table name to export
	 * @param  string $table_type          Table type ('BASE TABLE' or 'VIEW')
	 * @param  mixed  $start_record        Resume position (true=start, int=last primary key)
	 * @param  bool   $can_use_primary_key Whether primary key pagination is available
	 * @return mixed Completion array or int position for resumable export, WP_Error on SQL failure
	 */
	private function export_table_data($table, $table_type = 'BASE TABLE', $start_record = true, $can_use_primary_key = true) {
		$process_pages = 200; // Maximum SELECT iterations per call (increased from 90 for large tables)
		$max_run_time  = 25; // Maximum seconds before forcing resumption checkpoint
		$original_start_record = $start_record;

		$microtime = microtime(true);
		$total_rows = 0;

		// Normalize table name casing for Windows MySQL servers
		$dump_as_table = (false == $this->duplicate_tables_exist && 0 === stripos($table, $this->table_prefix) && 0 !== strpos($table, $this->table_prefix))
			? $this->table_prefix . substr($table, strlen($this->table_prefix))
			: $table;

		// Fetch column definitions for field type detection
		$table_structure = $this->wpdb_obj->get_results("DESCRIBE " . ROYALBR_Database_Utility::backquote($table));
		if (!$table_structure) {
			$error_message = '';
			if ($this->wpdb_obj->last_error) {
				$error_message .= ' (' . $this->wpdb_obj->last_error . ')';
			}
			return new WP_Error('table_details_error', $error_message);
		}

		// Write CREATE TABLE statement on first call for this table
		if (true === $start_record) {
			$this->write_table_sql_header($table, $dump_as_table, $table_type, $table_structure);
		}

		$table_data = array();
		if ('VIEW' != $table_type) {
			$fields = array();
			$defs = array();
			$integer_fields = array();
			$binary_fields = array();
			$bit_fields = array();
			$bit_field_exists = false;

			$primary_key = false;
			$primary_key_type = false;

			foreach ($table_structure as $struct) {
				if (isset($struct->Key) && 'PRI' == $struct->Key && '' != $struct->Field) {
					$primary_key = (false === $primary_key) ? $struct->Field : null;
					$primary_key_type = $struct->Type;
				}

				// Identify integer columns for unquoted value export
				if ((0 === strpos($struct->Type, 'tinyint')) || (0 === strpos(strtolower($struct->Type), 'smallint')) ||
					(0 === strpos(strtolower($struct->Type), 'mediumint')) || (0 === strpos(strtolower($struct->Type), 'int')) ||
					(0 === strpos(strtolower($struct->Type), 'bigint'))) {
					$defs[strtolower($struct->Field)] = (null === $struct->Default) ? 'NULL' : $struct->Default;
					$integer_fields[strtolower($struct->Field)] = true;
				}

				// Identify binary columns requiring hexadecimal encoding (prevents Elementor/WooCommerce corruption)
				if ((0 === strpos(strtolower($struct->Type), 'binary')) || (0 === strpos(strtolower($struct->Type), 'varbinary')) ||
					(0 === strpos(strtolower($struct->Type), 'tinyblob')) || (0 === strpos(strtolower($struct->Type), 'mediumblob')) ||
					(0 === strpos(strtolower($struct->Type), 'blob')) || (0 === strpos(strtolower($struct->Type), 'longblob'))) {
					$binary_fields[strtolower($struct->Field)] = true;
				}

				// Handle bit fields with CAST for proper binary extraction
				if (preg_match('/^bit(?:\(([0-9]+)\))?$/i', trim($struct->Type), $matches)) {
					if (!$bit_field_exists) $bit_field_exists = true;
					$bit_fields[strtolower($struct->Field)] = !empty($matches[1]) ? max(1, (int) $matches[1]) : 1;
					$struct->Field = "CAST(" . ROYALBR_Database_Utility::backquote(str_replace('`', '``', $struct->Field)) . " AS BINARY) AS " . ROYALBR_Database_Utility::backquote(str_replace('`', '``', $struct->Field));
					$fields[] = $struct->Field;
				} else {
					$fields[] = ROYALBR_Database_Utility::backquote(str_replace('`', '``', $struct->Field));
				}
			}

			$expected_via_count = false;

			$use_primary_key = false;
			if ($can_use_primary_key && is_string($primary_key) && preg_match('#^(small|medium|big)?int(\(| |$)#i', $primary_key_type)) {
				$use_primary_key = true;

				// Pre-count rows for progress display if table appears small
				if (is_bool($this->expected_rows) || $this->expected_rows < 1000) {
					$expected_rows = $this->wpdb_obj->get_var('SELECT COUNT(' . ROYALBR_Database_Utility::backquote($primary_key) . ') FROM ' . ROYALBR_Database_Utility::backquote($table));
					if (!is_bool($expected_rows)) {
						$this->expected_rows = $expected_rows;
						$expected_via_count = true;
					}
				}

				// Determine starting offset based on signed/unsigned primary key
				if (preg_match('# unsigned$#i', $primary_key_type)) {
					if (true === $start_record) $start_record = -1;
				} else {
					if (true === $start_record) {
						$min_value = $this->wpdb_obj->get_var('SELECT MIN(' . ROYALBR_Database_Utility::backquote($primary_key) . ') FROM ' . ROYALBR_Database_Utility::backquote($table));
						$start_record = (is_numeric($min_value) && $min_value) ? (int) $min_value - 1 : -1;
					}
				}
			}

			$search = array("\x00", "\x0a", "\x0d", "\x1a");
			$replace = array('\0', '\n', '\r', '\Z');

			// Calculate optimal batch size for this table's characteristics
			$fetch_rows = $this->number_of_rows_to_fetch($table, $use_primary_key || $start_record < 500000, true === $original_start_record, $this->expected_rows, $expected_via_count);

			if (!is_bool($this->expected_rows)) $this->expected_rows = true;

			$original_fetch_rows = $fetch_rows;
			$select = $bit_field_exists ? implode(', ', $fields) : '*';

			$enough_for_now = false;
			$began_writing_at = time();
			$enough_data_after = 104857600; // 100MB
			$enough_time_after = ($fetch_rows > 250) ? 15 : 9;

			do {
				if (function_exists('set_time_limit')) @set_time_limit(900); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for long-running database backup operations

				// Per-SELECT timeout check: Force resumption if approaching time limit
				// This prevents PHP timeout on tables with millions of rows
				$elapsed_this_run = microtime( true ) - $microtime;
				if ( $elapsed_this_run > $max_run_time ) {
					$this->log( "Table $table: approaching time limit (" . round( $elapsed_this_run, 1 ) . "s) at row $start_record, will resume" );
					$enough_for_now = true;
					break;
				}

				$final_where = '';

				if ($use_primary_key) {
					$final_where = 'WHERE ';
					$final_where .= ROYALBR_Database_Utility::backquote($primary_key) . ((-1 === $start_record) ? ' >= 0' : " > $start_record");
					$limit_statement = sprintf('LIMIT %d', $fetch_rows);
					$order_by = 'ORDER BY ' . ROYALBR_Database_Utility::backquote($primary_key) . ' ASC';
				} else {
					$order_by = '';
					if (true === $start_record) $start_record = 0;
					$limit_statement = sprintf('LIMIT %d, %d', $start_record, $fetch_rows);
				}

				$select_sql = "SELECT $select FROM " . ROYALBR_Database_Utility::backquote($table) . " $final_where $order_by $limit_statement";
				$table_data = $this->wpdb_obj->get_results($select_sql, ARRAY_A);

				if (null === $table_data) {
					$this->log("Database query error: $select_sql");
				}

				if (!$table_data) {
					continue;
				}

				$entries = 'INSERT INTO ' . ROYALBR_Database_Utility::backquote($dump_as_table) . ' VALUES ';

				$this_entry = '';
				foreach ($table_data as $row) {
					$total_rows++;
					if ($this_entry) $this_entry .= ",\n ";
					$this_entry .= '(';
					$key_count = 0;

					foreach ($row as $key => $value) {
						if ($key_count) $this_entry .= ', ';
						$key_count++;

						// Update pagination cursor for resumable exports
						if ($use_primary_key && strtolower($primary_key) == strtolower($key) && $value > $start_record) {
							$start_record = $value;
						}

						// SECURITY-CRITICAL: Field-type-specific escaping prevents SQL injection and data corruption
						if (isset($integer_fields[strtolower($key)])) {
							// Numeric columns: Output unquoted with NULL handling
							$value = (null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
							$value = ('' === $value) ? "''" : $value;
							$this_entry .= $value;
						} elseif (isset($binary_fields[strtolower($key)])) {
							// Binary data: Use hexadecimal notation to preserve Elementor/WooCommerce serialized objects
							if (null === $value) {
								$this_entry .= 'NULL';
							} elseif ('' === $value) {
								$this_entry .= "''";
							} else {
								$this_entry .= "0x" . bin2hex(str_repeat("0", floor(strspn($value, "0") / 4)) . $value);
							}
						} elseif (isset($bit_fields[strtolower($key)])) {
							// Bit columns: Convert to binary string representation
							if (null === $value) {
								$this_entry .= 'NULL';
							} else {
								if (function_exists('mbstring_binary_safe_encoding')) {
									mbstring_binary_safe_encoding();
								}
								$val_len = is_string($value) ? strlen($value) : 0;
								if (function_exists('reset_mbstring_encoding')) {
									reset_mbstring_encoding();
								}
								$hex = '';
								for ($i = 0; $i < $val_len; $i++) {
									$hex .= sprintf('%02X', ord($value[$i]));
								}
								$this_entry .= "b'" . str_pad($this->convert_hex_to_binary($hex), $bit_fields[strtolower($key)], '0', STR_PAD_LEFT) . "'";
							}
						} else {
							// Text/varchar: Triple-layer escaping (backslash, quote, control chars)
							$this_entry .= (null === $value) ? 'NULL' : "'" . str_replace($search, $replace, str_replace('\'', '\\\'', str_replace('\\', '\\\\', $value))) . "'";
						}
					}
					$this_entry .= ')';

					// Write batch when buffer reaches 512KB to prevent memory exhaustion
					if (strlen($this_entry) > 524288) {
						$this_entry .= ';';
						if (strlen($this_entry) > 10485760) {
							// Split writes for extremely large rows (>10MB)
							$this->write_db_content(" \n" . $entries);
							$this->write_db_content($this_entry);
						} else {
							$this->write_db_content(" \n" . $entries . $this_entry);
						}
						$this_entry = '';
						// Enforce time/size limits for resumable progress
						if ($this->db_current_raw_bytes > $enough_data_after || time() - $began_writing_at > $enough_time_after) {
							$enough_for_now = true;
						}
					}
				}

				if ($this_entry) {
					$this_entry .= ';';
					if (strlen($this_entry) > 10485760) {
						$this->write_db_content(" \n" . $entries);
						$this->write_db_content($this_entry);
					} else {
						$this->write_db_content(" \n" . $entries . $this_entry);
					}
				}

				// Update progress every 5000 rows for large table visibility.
				// This allows frontend polling to see progress during long table exports.
				global $royalbr_instance;
				if ( $total_rows > 0 && 0 === $total_rows % 5000 && ! empty( $royalbr_instance ) ) {
					$royalbr_instance->save_task_data(
						'dbcreating_substatus',
						array(
							't' => $table,
							'r' => $total_rows,
						)
					);
				}

				// Advance position for next batch (offset-based or primary-key-based)
				if (!$use_primary_key) {
					$start_record += $fetch_rows;
				}

				if ($process_pages > 0) $process_pages--;

			} while (!$enough_for_now && count($table_data) > 0 && (-1 == $process_pages || $process_pages > 0));
		}

		$fetch_time = max(microtime(true) - $microtime, 0.00001);
		$this->log("Table $table: $total_rows rows in " . sprintf('%.02f', $fetch_time) . ' seconds');

		if (-1 == $process_pages || 0 == count($table_data)) {
			$this->write_db_content("\n# End of data contents of table " . ROYALBR_Database_Utility::backquote($table) . "\n\n");
			return is_numeric($start_record) ? array('next_record' => (int) $start_record) : array();
		}

		return is_numeric($start_record) ? (int) $start_record : $start_record;
	}

	/**
	 * Generate and write DROP/CREATE statements for table schema definition.
	 *
	 * @since 1.0.0
	 * @param string $table           Source table name in database
	 * @param string $dump_as_table   Target table name for restoration (handles case normalization)
	 * @param string $table_type      Object type ('BASE TABLE' or 'VIEW')
	 * @param array  $table_structure Column definitions from DESCRIBE query
	 */
	private function write_table_sql_header($table, $dump_as_table, $table_type, $table_structure) {
		$this->write_db_content("\n# Delete any existing table " . ROYALBR_Database_Utility::backquote($table) . "\n\nDROP TABLE IF EXISTS " . ROYALBR_Database_Utility::backquote($dump_as_table) . ";\n");

		if ('VIEW' == $table_type) {
			$this->write_db_content("DROP VIEW IF EXISTS " . ROYALBR_Database_Utility::backquote($dump_as_table) . ";\n");
		}

		$description = ('VIEW' == $table_type) ? 'view' : 'table';

		$this->write_db_content("\n# Table structure of $description " . ROYALBR_Database_Utility::backquote($table) . "\n\n");

		$create_table = $this->wpdb_obj->get_results("SHOW CREATE TABLE " . ROYALBR_Database_Utility::backquote($table), ARRAY_N);
		if (false === $create_table) {
			$this->write_db_content("#\n# Error with SHOW CREATE TABLE for $table\n#\n");
			return;
		}

		$create_line = ROYALBR_Database_Utility::replace_last_occurrence('TYPE=', 'ENGINE=', $create_table[0][1]);

		if (preg_match('/ENGINE=([^\s;]+)/', $create_line, $eng_match)) {
			$engine = $eng_match[1];
			if ('myisam' == strtolower($engine)) {
				$create_line = preg_replace('/PAGE_CHECKSUM=\d\s?/', '', $create_line, 1);
			}
		}

		if ($dump_as_table !== $table) {
			$create_line = ROYALBR_Database_Utility::replace_first_occurrence($table, $dump_as_table, $create_line);
		}

		$this->write_db_content($create_line . ' ;');

		if (false === $table_structure) {
			$this->write_db_content("#\n# Error getting $description structure of $table\n#\n");
		}

		$this->write_db_content("\n\n# " . sprintf("Data contents of $description %s", ROYALBR_Database_Utility::backquote($table)) . "\n\n");
	}

	/**
	 * Calculate optimal row batch size based on table characteristics and system constraints.
	 *
	 * @since  1.0.0
	 * @param  string $table                    Table name for size heuristics
	 * @param  bool   $allow_further_reductions Whether to consider smaller batches for reliability
	 * @param  bool   $is_first_fetch_for_table Initial query flag for adaptive sizing
	 * @param  mixed  $expected_rows            Known or estimated total row count
	 * @param  bool   $expected_via_count       Whether row count came from SELECT COUNT()
	 * @return int Optimized LIMIT value for next SELECT query
	 */
	private function number_of_rows_to_fetch($table, $allow_further_reductions, $is_first_fetch_for_table, $expected_rows = false, $expected_via_count = false) {
		$fetch_rows_reductions = array(500, 250, 200, 100);

		$default_on_first_fetch = $this->get_rows_on_first_fetch($table);

		$known_bigger_than_table = (!is_bool($expected_rows) && $expected_rows && $expected_via_count && $default_on_first_fetch > 2 * $expected_rows);

		if ($known_bigger_than_table) $allow_further_reductions = true;

		if ($allow_further_reductions) {
			$fetch_rows_reductions = array_merge($fetch_rows_reductions, array(50, 20, 5));
		}

		// Remove reductions far out of range
		if ($known_bigger_than_table) {
			foreach ($fetch_rows_reductions as $k => $reduce_to) {
				if ($reduce_to > $expected_rows) unset($fetch_rows_reductions[$k]);
			}
		}

		$fetch_rows = $default_on_first_fetch;

		return $fetch_rows;
	}

	/**
	 * Determine starting batch size based on known table row characteristics.
	 *
	 * @since  1.0.0
	 * @param  string $table Table name for pattern matching
	 * @return int Initial LIMIT value tailored to typical row sizes
	 */
	private function get_rows_on_first_fetch($table) {
		// term_relationships: Fixed-width table with minimal data per row
		if ($this->table_prefix_raw . 'term_relationships' == $table) {
			$rows = 100000;
		} elseif (preg_match('/meta$/i', $table)) {
			$rows = 4000;
		} else {
			// Safe default for unknown tables with potentially large text columns
			$rows = 1000;
		}

		return $rows;
	}

	/**
	 * Open database export file handle with optional gzip compression.
	 *
	 * @since  1.0.0
	 * @param  string $file     Target SQL file path
	 * @param  bool   $allow_gz Whether to use gzip if available (reduces disk usage ~5-10x)
	 * @param  bool   $append   Resume mode: append to existing file instead of overwriting
	 * @return resource|bool Active file handle on success, false on filesystem error
	 */
	public function initialize_db_backup_file($file, $allow_gz = true, $append = false) {
		$mode = $append ? 'ab' : 'w';

		// Set error handler to capture specific errors (e.g., "No space left on device")
		$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Necessary for capturing PHP errors during file operations
		set_error_handler( array( $this, 'php_error' ), $error_levels );

		if ($allow_gz && function_exists('gzopen')) {
			$this->db_file_handle = gzopen($file, $mode);
			$this->db_compression_enabled = true;
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Required for streaming large database files
			$this->db_file_handle = fopen($file, $mode);
			$this->db_compression_enabled = false;
		}

		// Restore error handler
		restore_error_handler();

		if (false === $this->db_file_handle) {
			$error_detail = $this->get_php_error_detail();
			$error_message = __( 'Could not open database file for writing', 'royal-backup-reset' ) . $error_detail;
			$this->log( "Could not open file for writing: $file" . $error_detail );
			$this->set_backup_error( $error_message );
			return false;
		}

		$this->db_current_raw_bytes = 0;
		return $this->db_file_handle;
	}

	/**
	 * Finalize database backup file.
	 *
	 * @since 1.0.0
	 */
	private function finalize_db_backup_file() {
		if ($this->db_file_handle) {
			if ($this->db_compression_enabled) {
				gzclose($this->db_file_handle);
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing database file handle
				fclose($this->db_file_handle);
			}
		}
	}

	/**
	 * Write SQL content to database backup file.
	 *
	 * @since  1.0.0
	 * @param  string $write_line Line to write
	 * @return int|bool Bytes written or false on failure
	 */
	private function write_db_content($write_line) {
		if ('' === $write_line) return 0;

		// Set error handler to capture specific errors (e.g., "No space left on device")
		$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Necessary for capturing PHP errors during file operations
		set_error_handler( array( $this, 'php_error' ), $error_levels );

		$write_function = $this->db_compression_enabled ? 'gzwrite' : 'fwrite';
		$ret = call_user_func($write_function, $this->db_file_handle, $write_line);

		// Restore error handler
		restore_error_handler();

		if (false == $ret) {
			$error_detail = $this->get_php_error_detail();
			$error_message = __( 'Error writing to database backup file', 'royal-backup-reset' ) . $error_detail;
			$this->log( 'Error writing to database backup file' . $error_detail );
			$this->set_backup_error( $error_message );
		}

		$this->db_current_raw_bytes += strlen($write_line);

		return $ret;
	}

	/**
	 * Write SQL header to database backup.
	 *
	 * @since 1.0.0
	 */
	private function write_db_backup_header() {
		$wp_version = get_bloginfo('version');
		$mysql_version = $this->wpdb_obj->get_var('SELECT VERSION()');
		if ('' == $mysql_version) $mysql_version = $this->wpdb_obj->db_version();

		if ('wp' == $this->database_identifier) {
			$wp_upload_dir = wp_upload_dir();
			$this->write_db_content("# WordPress MySQL database backup\n");
			$this->write_db_content("# Created by Royal Backup & Reset\n");
			$this->write_db_content("# WordPress Version: $wp_version, running on PHP " . phpversion() . ", MySQL $mysql_version\n");
			$this->write_db_content("# Backup of: " . untrailingslashit(site_url()) . "\n");
			$this->write_db_content("# Home URL: " . untrailingslashit(home_url()) . "\n");
			$this->write_db_content("# Content URL: " . untrailingslashit(content_url()) . "\n");
			$this->write_db_content("# Uploads URL: " . untrailingslashit($wp_upload_dir['baseurl']) . "\n");
			$this->write_db_content("# Table prefix: " . $this->table_prefix_raw . "\n");
			$this->write_db_content("# Filtered table prefix: " . $this->table_prefix . "\n");
			$this->write_db_content("# ABSPATH: " . trailingslashit(ABSPATH) . "\n");
			$current_plugin_slug = plugin_basename( ROYALBR_PLUGIN_DIR . 'royal-backup-reset.php' );
			$this->write_db_content( "# ROYALBR plugin slug: " . $current_plugin_slug . "\n" );
			$this->write_db_content("# Site info: multisite=" . (is_multisite() ? '1' : '0') . "\n");
			$this->write_db_content("# Site info: sql_mode=" . $this->wpdb_obj->get_var('SELECT @@SESSION.sql_mode') . "\n");
			$this->write_db_content("# Site info: end\n");
		} else {
			$this->write_db_content("# MySQL database backup (external database " . $this->database_identifier . ")\n");
			$this->write_db_content("# Created by Royal Backup & Reset\n");
			$this->write_db_content("# WordPress Version: $wp_version, running on PHP " . phpversion() . ", MySQL $mysql_version\n");
			$this->write_db_content("# Backup created by: " . untrailingslashit(site_url()) . "\n");
			$this->write_db_content("# Table prefix: " . $this->table_prefix_raw . "\n");
		}

		$this->write_db_content("\n# Generated: " . wp_date("l j. F Y H:i T") . "\n");
		$this->write_db_content("# Hostname: " . $this->dbinfo['host'] . "\n");
		$this->write_db_content("# Database: " . ROYALBR_Database_Utility::backquote($this->dbinfo['name']) . "\n");

		if (!empty($this->skipped_tables[$this->database_identifier])) {
			$this->write_db_content("# Skipped tables: " . implode(', ', $this->skipped_tables[$this->database_identifier]) . "\n");
		}

		$this->write_db_content("# --------------------------------------------------------\n");

		$this->write_db_content("/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
		$this->write_db_content("/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
		$this->write_db_content("/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
		$this->write_db_content("/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n");
		$this->write_db_content("/*!40101 SET NAMES utf8mb4 */;\n");
		$this->write_db_content("/*!40101 SET foreign_key_checks = 0 */;\n\n");
	}

	/**
	 * Convert hexadecimal to binary (for bit fields).
	 *
	 * @since  1.0.0
	 * @param  string $hex Hexadecimal string
	 * @return string Binary string
	 */
	private function convert_hex_to_binary($hex) {
		$table = array(
			'0' => '0000', '1' => '0001', '2' => '0010', '3' => '0011',
			'4' => '0100', '5' => '0101', '6' => '0110', '7' => '0111',
			'8' => '1000', '9' => '1001', 'a' => '1010', 'b' => '1011',
			'c' => '1100', 'd' => '1101', 'e' => '1110', 'f' => '1111'
		);

		$binary_string = '';
		$hex_length = strlen($hex);

		for ($i = 0; $i < $hex_length; $i++) {
			$char = strtolower($hex[$i]);
			if (isset($table[$char])) {
				$binary_string .= $table[$char];
			}
		}

		return $binary_string;
	}

	/**
	 * Extract table name from array (for array_map).
	 *
	 * @since  1.0.0
	 * @param  array $a Array with 'name' key
	 * @return string Table name
	 */
	private function cb_get_name($a) {
		return $a['name'];
	}

	/**
	 * Extract table name and type from array (for SHOW FULL TABLES).
	 *
	 * @since  1.0.0
	 * @param  array $a Array from SHOW FULL TABLES
	 * @return array Array with 'name' and 'type'
	 */
	private function cb_get_name_type($a) {
		return array('name' => $a[0], 'type' => $a[1]);
	}

	/**
	 * Extract table name from array (assume BASE TABLE).
	 *
	 * @since  1.0.0
	 * @param  array $a Array from SHOW TABLES
	 * @return array Array with 'name' and 'type'
	 */
	private function cb_get_name_base_type($a) {
		return array('name' => $a[0], 'type' => 'BASE TABLE');
	}

	// ========================================================================
	// FILE ARCHIVAL - MULTI-PART ZIP CREATION WITH RESUMABILITY
	// ========================================================================

	/**
	 * Orchestrate archive creation with automatic splitting for entity directories.
	 *
	 * @since  1.0.0
	 * @param  string|array $create_from_dir      Source directory path(s) to archive
	 * @param  string       $whichone             Entity type (plugins, themes, uploads, others)
	 * @param  string       $backup_file_basename Base filename without entity suffix
	 * @param  int          $index                Split sequence number (0 for first part)
	 * @param  int|bool     $first_linked_index   Starting index for linked multi-part sets
	 * @return array|bool Associative array of created filenames indexed by split number, false on error
	 */
	public function build_archive($create_from_dir, $whichone, $backup_file_basename, $index, $first_linked_index = false) {
		if (function_exists('set_time_limit')) @set_time_limit(900); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for long-running zip creation operations

		global $royalbr_instance;

		// Load split_every from task data (may have been reduced by try_split on prior runs)
		$split_every_task = (int) $royalbr_instance->retrieve_task_data( 'split_every', 0 );
		if ( $split_every_task > 0 ) {
			$this->archive_max_size = max( $split_every_task, 25 ) * 1048576;
		}

		$original_index = $index;
		$this->index = $index;
		$this->first_linked_index = (false === $first_linked_index) ? 0 : $first_linked_index;
		$this->whichone = $whichone;

		$this->log("Starting archive creation for $whichone (split size: " . round($this->archive_max_size / 1048576, 1) . "MB)");

		if (is_string($create_from_dir) && !file_exists($create_from_dir)) {
			$this->log("Directory not found: $create_from_dir");
			return false;
		}

		$itext = empty($index) ? '' : $index + 1;
		$base_path = $backup_file_basename . '-' . $whichone . $itext . '.zip';
		$full_path = $this->royalbr_dir . '/' . $base_path;
		$time_now = time();

		if (file_exists($full_path)) {
			$files_existing = array();
			while (file_exists($full_path)) {
				$files_existing[] = $base_path;
				$time_mod = (int) @filemtime($full_path);
				$this->log("$base_path: file already created (age: " . round($time_now - $time_mod, 1) . " s)");

				if ($time_mod > 100 && ($time_now - $time_mod) < 30) {
					$this->log("Terminate: another backup appears to be running (file: $base_path, modified $time_mod, now $time_now)");
					return false;
				}

				$index++;
				$base_path = $backup_file_basename . '-' . $whichone . ($index + 1) . '.zip';
				$full_path = $this->royalbr_dir . '/' . $base_path;
			}
		}

		$this->clean_temporary_files('_' . $royalbr_instance->file_nonce . "-$whichone", 600);

		$zip_name = $full_path . '.tmp';
		$time_mod = file_exists($zip_name) ? filemtime($zip_name) : 0;
		if (file_exists($zip_name) && $time_mod > 100 && ($time_now - $time_mod) < 30) {
			$this->log("Terminate: $zip_name is being written to by another process");
			return false;
		}

		if (file_exists($zip_name)) {
			$this->log("$zip_name exists, but not recently modified (assuming old run terminated)");
		}

		// When stuck (2+ failed resumptions), halve split size and finalize current archive
		if ( $this->try_split ) {
			$itext_check = empty( $index ) ? '' : ( $index + 1 );
			$check_zip   = $this->royalbr_dir . '/' . $backup_file_basename . '-' . $whichone . $itext_check . '.zip';
			$check_tmp   = $check_zip . '.tmp';

			$examine_file = false;
			if ( file_exists( $check_zip ) && filesize( $check_zip ) > 0 ) {
				$examine_file = $check_zip;
			} elseif ( file_exists( $check_tmp ) && filesize( $check_tmp ) > 0 ) {
				$examine_file = $check_tmp;
			}

			if ( $examine_file && filesize( $examine_file ) > 50 * 1048576 ) {
				$this->archive_max_size = max(
					(int) ( $this->archive_max_size / 2 ),
					25 * 1048576,
					min( filesize( $examine_file ) - 1048576, $this->archive_max_size )
				);
				$royalbr_instance->save_task_data( 'split_every', (int) ( $this->archive_max_size / 1048576 ) );
				$this->log( 'No check-in on last two runs; reducing zip split to: ' . round( $this->archive_max_size / 1048576, 1 ) . ' MB' );

				// Finalize the .zip.tmp if it exists (rename to .zip)
				if ( file_exists( $check_tmp ) && filesize( $check_tmp ) > 0 ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename required for archive finalization
					@rename( $check_tmp, $check_zip );
					ROYALBR_Task_Scheduler::something_useful_happened();
				}

				// Bump index so next archive starts fresh
				$index++;
				$this->index = $index;

				// Add the finalized file to files_existing
				if ( ! isset( $files_existing ) ) {
					$files_existing = array();
				}
				$files_existing[] = basename( $check_zip );
			}
			$this->try_split = false;
		}

		if (isset($files_existing)) {
			return $files_existing;
		}

		$this->zip_microtime_start = microtime(true);

		$zipcode = $this->initialize_archive_file($create_from_dir, $backup_file_basename, $whichone);

		if (true !== $zipcode) {
			$this->log("ERROR: Zip failure: Could not create $whichone zip (" . $this->index . " / $index)");
			return false;
		}

		$itext = empty($this->index) ? '' : $this->index + 1;
		$full_path = $this->royalbr_dir . '/' . $backup_file_basename . '-' . $whichone . $itext . '.zip';

		if (file_exists($full_path . '.tmp')) {
			if (@filesize($full_path . '.tmp') === 0) {
				$this->log("Did not create $whichone zip (" . $this->index . ") - not needed");
				@wp_delete_file($full_path . '.tmp');
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename required for backup completion
				@rename($full_path . '.tmp', $full_path);
				$timetaken = max(microtime(true) - $this->zip_microtime_start, 0.000001);
				$kbsize = filesize($full_path) / 1024;
				$rate = round($kbsize / $timetaken, 1);
				$this->log("Created $whichone zip (" . $this->index . ") - " . round($kbsize, 1) . " KB in " . round($timetaken, 1) . " s ($rate KB/s)");
			}
		} elseif ($this->index > $original_index) {
			$this->log("Did not create $whichone zip (" . $this->index . ") - not needed (2)");
			$this->index--;
		} else {
			$this->log("Looked-for $whichone zip (" . $this->index . ") was not found");
		}

		$this->clean_temporary_files('_' . $royalbr_instance->file_nonce . "-$whichone", 0);

		$files_existing = array();
		$res_index = $original_index;
		for ($i = $original_index; $i <= $this->index; $i++) {
			$itext = empty($i) ? '' : ($i + 1);
			$full_path = $this->royalbr_dir . '/' . $backup_file_basename . '-' . $whichone . $itext . '.zip';
			if (file_exists($full_path)) {
				$files_existing[$res_index] = $backup_file_basename . '-' . $whichone . $itext . '.zip';
			}
			$res_index++;
		}

		return $files_existing;
	}

	/**
	 * Initialize archive creation by scanning directories and queuing files for compression.
	 *
	 * @since  1.0.0
	 * @param  string|array $source               Source path(s) to recursively scan
	 * @param  string       $backup_file_basename Filename base for generated archives
	 * @param  string       $whichone             Entity type for exclusion rules
	 * @param  bool         $retry_on_error       Retry logic flag (unused in current implementation)
	 * @return bool True on successful queue processing, WP_Error on scanning or I/O failure
	 */
	private function initialize_archive_file($source, $backup_file_basename, $whichone, $retry_on_error = true) {
		global $royalbr_instance;

		$original_index = $this->index;

		$itext = empty($this->index) ? '' : ($this->index + 1);
		$destination_base = $backup_file_basename . '-' . $whichone . $itext . '.zip.tmp';
		$destination = $this->royalbr_dir . '/' . $destination_base;

		$backupable_entities = $royalbr_instance->get_backupable_file_entities(true, false);
		$this->create_archive_file_source = (is_array($source) && isset($backupable_entities[$whichone]))
			? (('uploads' == $whichone) ? dirname($backupable_entities[$whichone]) : $backupable_entities[$whichone])
			: dirname($source);

		$this->archive_file_count = 0;
		$this->files_processed_current_batch = 0;
		$this->directories_queue = array();
		$this->files_queue = array();
		$this->unchanged_files_skipped = array();
		$this->last_zip_write_timestamp = time();
		$this->archive_base_path = $this->royalbr_dir . '/' . $backup_file_basename . '-' . $whichone;

		// Set up cache file base path for large site file list caching
		$this->cache_file_base       = $this->royalbr_dir . '/' . $backup_file_basename . '-cachelist-' . $whichone;
		$this->got_files_from_cache  = false;

		// Per-file resumption: Scan existing zip files to find already-archived files
		// This prevents re-adding files when resuming an interrupted backup
		$existing_count = $this->scan_existing_zips_for_resumption( $this->archive_base_path );
		if ( $existing_count > 0 ) {
			$this->log( "Per-file resumption: Found $existing_count files already in existing archives" );
		}

		$error_occurred = false;

		$this->current_batch_size_bytes = 0;
		if (!is_array($source)) $source = array($source);

		// Diagnostic: Track which directories are being archived
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Used for production logging of complex data structures
		$this->log("initialize_archive_file for $whichone - source elements: " . print_r($source, true));

		$exclude = $this->get_exclude($whichone);
		$this->incremental_backup_timestamp = is_array($this->modified_after) ? (isset($this->modified_after[$whichone]) ? $this->modified_after[$whichone] : -1) : -1;

		// For large sites (uploads/others): Try to restore file lists from cache first
		// This prevents memory exhaustion from re-enumerating millions of files on resumption
		if ( $this->restore_file_lists_from_cache( $whichone ) ) {
			// Successfully loaded from cache - skip enumeration entirely
			$time_counting_began = time();
			$time_counting_done  = time();
		} else {
			// No valid cache - perform full directory enumeration
			$time_counting_began = time();

			$this->excluded_extensions = $this->fetch_excluded_file_types($exclude);
			$this->excluded_prefixes = $this->fetch_excluded_name_prefixes($exclude);
			$this->excluded_wildcards = $this->fetch_excluded_patterns($exclude);

			foreach ($source as $element) {
				if ('uploads' == $whichone) {
					$dirname = dirname($element);
					$basename = $this->basename($element);
					$use_path = basename($dirname) . '/' . $basename;
					$this->log("Adding $whichone element: fullpath=$element, stored_as=$use_path");
					$add_them = $this->append_path_to_archive($element, $use_path, $element, 2, $exclude);
				} else {
					$use_path = $this->basename($element);
					$this->log("Adding $whichone element: fullpath=$element, stored_as=$use_path");
					$add_them = $this->append_path_to_archive($element, $use_path, $element, 1, $exclude);
				}

				if (!$add_them) {
					$this->log("Error during file enumeration for $whichone");
					$error_occurred = true;
				}
			}

			$time_counting_done = time();
			$this->log("File counting for $whichone: " . count($this->files_queue) . " files, " . count($this->directories_queue) . " dirs in " . ($time_counting_done - $time_counting_began) . " seconds");

			// Cache file lists if enumeration took a long time (> 20 seconds)
			// This allows subsequent resumptions to skip enumeration entirely
			if ( ! $error_occurred ) {
				$this->cache_file_lists( $whichone, $time_counting_began, $time_counting_done );
			}
		}

		// Execute batched write operations to physical ZIP archive
		if (!$error_occurred) {
			$add_files_result = $this->handle_archive_batch(true);
			if (is_wp_error($add_files_result)) {
				$this->log("Error adding files to zip: " . $add_files_result->get_error_message());
				return $add_files_result;
			}
			// Also check for false return (zip close failure).
			if ( false === $add_files_result ) {
				$error = $this->get_backup_error();
				if ( ! empty( $error ) ) {
					$this->log( 'Zip creation failed: ' . $error );
					return new WP_Error( 'zip_failed', $error );
				}
			}

			// Clean up cache files after successful completion of this entity
			$this->cleanup_file_list_cache( $whichone );
		}

		return true;
	}

	/**
	 * Recursively traverse filesystem and queue files/directories for archive addition.
	 *
	 * Implements exclusion filtering, symlink resolution, and circular reference detection
	 * to safely handle complex directory structures.
	 *
	 * @since  1.0.0
	 * @param  string $fullpath              Absolute filesystem path to process
	 * @param  string $use_path_when_storing Internal archive path to use when storing
	 * @param  string $original_fullpath     Root path for circular symlink detection
	 * @param  int    $startlevels           Depth level (1 or 2) affecting path construction
	 * @param  array  $exclude               Exclusion pattern array passed by reference
	 * @return bool True on successful traversal, false on abort or fatal error
	 */
	private function append_path_to_archive($fullpath, $use_path_when_storing, $original_fullpath, $startlevels, &$exclude) {
		// Responsive abort checking on every file iteration
		if ($this->check_abort_requested()) {
			$this->log("Abort detected during file processing: $use_path_when_storing");
			return false;
		}

		if (is_link($fullpath) && is_dir($fullpath) && 'others' == $this->whichone) {
			$this->log("Skipping symbolic directory link: $use_path_when_storing -> " . readlink($fullpath));
			return true;
		}

		static $royalbr_dir_realpath;
		$royalbr_dir_realpath = realpath($this->royalbr_dir);

		$fullpath = realpath($fullpath);
		$original_fullpath = realpath($original_fullpath);

		if (($fullpath !== $original_fullpath && strpos($original_fullpath, $fullpath) === 0) ||
			($original_fullpath == $fullpath && ((1 == $startlevels && strpos($use_path_when_storing, '/') !== false) || (2 == $startlevels && substr_count($use_path_when_storing, '/') > 1)))) {
			$this->log("Circular symlink detected: $fullpath references parent directory $original_fullpath");
			return false;
		}

		$stripped_storage_path = (1 == $startlevels) ? $use_path_when_storing : substr($use_path_when_storing, strpos($use_path_when_storing, '/') + 1);
		if (false !== ($fkey = array_search($stripped_storage_path, $exclude))) {
			$this->log("Skipping excluded path per user settings: $stripped_storage_path");
			unset($exclude[$fkey]);
			return true;
		}

		$if_modified_after = $this->incremental_backup_timestamp;

		if (is_file($fullpath)) {
			if (!empty($this->excluded_extensions) && $this->has_excluded_file_type($fullpath)) {
				// Excluded by extension
			} elseif (!empty($this->excluded_prefixes) && $this->has_excluded_prefix($fullpath)) {
				// Excluded by prefix
			} elseif (!empty($this->excluded_wildcards) && $this->matches_exclusion_pattern(basename($fullpath))) {
				// Excluded by wildcard
			} elseif (is_readable($fullpath)) {
				$mtime = filemtime($fullpath);
				$key = ($fullpath == $original_fullpath) ? ((2 == $startlevels) ? $use_path_when_storing : $this->basename($fullpath)) : $use_path_when_storing . '/' . $this->basename($fullpath);
				if ($mtime > 0 && $mtime > $if_modified_after) {
					$this->files_queue[$fullpath] = $key;
					$this->current_batch_size_bytes += @filesize($fullpath);
				} else {
					$this->unchanged_files_skipped[$fullpath] = $key;
				}
			} else {
				$this->log("$fullpath: file cannot be read");
			}
		} elseif (is_dir($fullpath)) {
			if ($fullpath == $royalbr_dir_realpath) {
				$this->log("Skip directory (plugin backup directory): $use_path_when_storing");
				return true;
			}

			// Skip our own plugin directory during backup (both free and pro versions)
			if ('plugins' == $this->whichone && (false !== strpos($fullpath, 'royal-backup-reset' . DIRECTORY_SEPARATOR) || false !== strpos($fullpath, 'royal-backup-reset-pro' . DIRECTORY_SEPARATOR))) {
				$this->log("Skip directory (our plugin): $use_path_when_storing");
				return true;
			}

			if (apply_filters('royalbr_exclude_directory', false, $fullpath, $use_path_when_storing)) {
				$this->log("Skip excluded directory: $use_path_when_storing");
				return true;
			}

			if (file_exists($fullpath . '/.donotbackup')) {
				$this->log("Skip directory (.donotbackup marker found): $use_path_when_storing");
				return true;
			}

			if (!isset($this->existing_files[$use_path_when_storing])) {
				$this->directories_queue[] = $use_path_when_storing;
			}

			if (!$dir_handle = @opendir($fullpath)) {
				$this->log("Unable to open directory: $fullpath");
				return false;
			}

			while (false !== ($e = readdir($dir_handle))) {
				if ('.' == $e || '..' == $e) continue;

				if (is_link($fullpath . '/' . $e)) {
					$deref = realpath($fullpath . '/' . $e);

					if (false === $deref) {
						$this->log("$fullpath/$e: broken or inaccessible link");
					} elseif (is_file($deref)) {
						// Symlinked file
						if (is_readable($deref)) {
							$mtime = filemtime($deref);
							if ($mtime > 0 && $mtime > $if_modified_after) {
								$this->files_queue[$deref] = $use_path_when_storing . '/' . $e;
								$this->current_batch_size_bytes += @filesize($deref);
							} else {
								$this->unchanged_files_skipped[$deref] = $use_path_when_storing . '/' . $e;
							}
						}
					} elseif (is_dir($deref)) {
						$this->symlink_reversals[$deref] = $fullpath . '/' . $e;
						$this->append_path_to_archive($deref, $use_path_when_storing . '/' . $e, $original_fullpath, $startlevels, $exclude);
					}
				} elseif (is_file($fullpath . '/' . $e)) {
					$use_stripped = $stripped_storage_path . '/' . $e;
					if (false !== ($fkey = array_search($use_stripped, $exclude))) {
						unset($exclude[$fkey]);
					} elseif (!empty($this->excluded_extensions) && $this->has_excluded_file_type($e)) {
						// Excluded
					} elseif (!empty($this->excluded_prefixes) && $this->has_excluded_prefix($e)) {
						// Excluded
					} elseif (!empty($this->excluded_wildcards) && $this->matches_exclusion_pattern($use_stripped)) {
						// Excluded
					} elseif (is_readable($fullpath . '/' . $e)) {
						$mtime = filemtime($fullpath . '/' . $e);
						if ($mtime > 0 && $mtime > $if_modified_after) {
							$this->files_queue[$fullpath . '/' . $e] = $use_path_when_storing . '/' . $e;
							$this->current_batch_size_bytes += @filesize($fullpath . '/' . $e);
						} else {
							$this->unchanged_files_skipped[$fullpath . '/' . $e] = $use_path_when_storing . '/' . $e;
						}
					}
				} elseif (is_dir($fullpath . '/' . $e)) {
					$use_stripped = $stripped_storage_path . '/' . $e;
					if (!empty($this->excluded_wildcards) && $this->matches_exclusion_pattern($use_stripped)) {
						// Excluded
					} else {
						$this->append_path_to_archive($fullpath . '/' . $e, $use_path_when_storing . '/' . $e, $original_fullpath, $startlevels, $exclude);
					}
				}
			}
			closedir($dir_handle);
		} else {
			$this->log("Unexpected: $use_path_when_storing is neither file nor directory");
		}

		return true;
	}

	/**
	 * Get basename.
	 *
	 * @since  1.0.0
	 * @param  string $element Path
	 * @return string Basename
	 */
	private function basename($element) {
		return basename($element);
	}

	// ========================================================================
	// FILE ENUMERATION CACHING - Large Site Support
	// ========================================================================

	/**
	 * Attempt to restore file lists from gzipped cache files.
	 *
	 * For large sites (100K+ files), file enumeration can take minutes. This method
	 * restores previously cached file lists to avoid re-scanning on resumption.
	 *
	 * @since  1.0.0
	 * @param  string $whichone Entity type (uploads, others, plugins, themes)
	 * @return bool True if cache was valid and loaded, false if enumeration needed
	 */
	private function restore_file_lists_from_cache( $whichone ) {
		// Only cache uploads and others - these are typically the largest
		if ( ! in_array( $whichone, array( 'uploads', 'others' ), true ) ) {
			return false;
		}

		// Check if gzip functions are available
		if ( ! function_exists( 'gzopen' ) || ! function_exists( 'gzread' ) ) {
			return false;
		}

		$cache_file_base = $this->cache_file_base;

		// Check if all required cache files exist
		$required_files = array(
			$cache_file_base . '-zfd.gz.tmp', // directories
			$cache_file_base . '-zfb.gz.tmp', // files (batched)
			$cache_file_base . '-info.tmp',   // metadata
		);

		foreach ( $required_files as $file ) {
			if ( ! file_exists( $file ) ) {
				return false;
			}
		}

		// Check cache freshness (< 30 minutes old)
		$mtime = filemtime( $cache_file_base . '-zfd.gz.tmp' );
		if ( time() - $mtime >= 1800 ) {
			$this->log( "Cache files too old (" . ( time() - $mtime ) . "s) - will re-enumerate" );
			return false;
		}

		$any_failures = false;

		// Restore directories queue
		$var = $this->unserialize_gz_cache_file( $cache_file_base . '-zfd.gz.tmp' );
		if ( is_array( $var ) ) {
			$this->directories_queue = $var;

			// Restore files queue
			$var = $this->unserialize_gz_cache_file( $cache_file_base . '-zfb.gz.tmp' );
			if ( is_array( $var ) ) {
				$this->files_queue = $var;

				// Restore metadata
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Required for cache deserialization
				$var = unserialize( file_get_contents( $cache_file_base . '-info.tmp' ) );
				if ( is_array( $var ) && isset( $var['current_batch_size_bytes'] ) ) {
					$this->current_batch_size_bytes = $var['current_batch_size_bytes'];

					// Optionally restore skipped files
					if ( file_exists( $cache_file_base . '-zfs.gz.tmp' ) ) {
						$var = $this->unserialize_gz_cache_file( $cache_file_base . '-zfs.gz.tmp' );
						if ( is_array( $var ) ) {
							$this->unchanged_files_skipped = $var;
						} else {
							$any_failures = true;
						}
					} else {
						$this->unchanged_files_skipped = array();
					}
				} else {
					$any_failures = true;
				}
			} else {
				$any_failures = true;
			}
		} else {
			$any_failures = true;
		}

		if ( $any_failures ) {
			$this->log( "Failed to recover file lists from cache files" );
			// Reset everything
			$this->directories_queue        = array();
			$this->files_queue              = array();
			$this->unchanged_files_skipped  = array();
			$this->current_batch_size_bytes = 0;
			return false;
		}

		$this->log( "File lists recovered from cache: " . count( $this->files_queue ) . " files, " . count( $this->directories_queue ) . " dirs, " . count( $this->unchanged_files_skipped ) . " skipped" );
		$this->got_files_from_cache = true;
		return true;
	}

	/**
	 * Read and unserialize a gzipped cache file in chunks.
	 *
	 * Reads 1MB chunks to avoid memory spikes on very large file lists.
	 *
	 * @since  1.0.0
	 * @param  string $file Path to gzipped cache file
	 * @return array|false Unserialized array or false on failure
	 */
	private function unserialize_gz_cache_file( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- gzopen required for compressed cache
		$whandle = gzopen( $file, 'r' );
		if ( ! $whandle ) {
			return false;
		}

		$emptimes = 0;
		$var      = '';

		while ( ! gzeof( $whandle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- gzread required for compressed cache
			$bytes = @gzread( $whandle, 1048576 ); // 1MB chunks
			if ( empty( $bytes ) ) {
				$emptimes++;
				$this->log( "Got empty gzread ($emptimes times)" );
				if ( $emptimes > 2 ) {
					gzclose( $whandle );
					return false;
				}
			} else {
				$var .= $bytes;
			}
		}
		gzclose( $whandle );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Required for cache deserialization
		return unserialize( $var );
	}

	/**
	 * Cache file lists to gzipped files for resumption.
	 *
	 * Creates compressed cache files when file enumeration takes > 20 seconds,
	 * allowing subsequent resumptions to skip the enumeration phase entirely.
	 *
	 * @since  1.0.0
	 * @param  string $whichone            Entity type being cached
	 * @param  int    $time_counting_began Timestamp when enumeration started
	 * @param  int    $time_counting_done  Timestamp when enumeration finished
	 * @return bool True if cache was successfully created
	 */
	private function cache_file_lists( $whichone, $time_counting_began, $time_counting_done ) {
		// Only cache uploads and others - these are typically the largest
		if ( ! in_array( $whichone, array( 'uploads', 'others' ), true ) ) {
			return false;
		}

		// Only cache if enumeration took > 20 seconds
		$enumeration_time = $time_counting_done - $time_counting_began;
		if ( $enumeration_time <= 20 ) {
			return false;
		}

		// Check if gzip functions are available
		if ( ! function_exists( 'gzopen' ) || ! function_exists( 'gzwrite' ) ) {
			return false;
		}

		// Estimate memory needed for serialization (approximately 15% overhead for gzip)
		$memory_needed_estimate = 0;
		foreach ( $this->files_queue as $k => $v ) {
			$memory_needed_estimate += strlen( $k ) + strlen( $v ) + 12;
		}

		// Check if we have enough memory
		if ( ! $this->verify_free_memory( $memory_needed_estimate * 0.15 ) ) {
			$this->log( "Insufficient memory to cache file lists" );
			return false;
		}

		$cache_file_base = $this->cache_file_base;

		$memory_limit  = ini_get( 'memory_limit' );
		$memory_usage  = round( memory_get_usage( false ) / 1048576, 1 );
		$memory_usage2 = round( memory_get_usage( true ) / 1048576, 1 );

		$this->log( "File counting took {$enumeration_time}s; caching results (memory_limit: $memory_limit, used: {$memory_usage}M | {$memory_usage2}M, est. bytes: " . round( $memory_needed_estimate / 1024, 1 ) . " KB)" );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- gzopen required for compressed cache
		$whandle = gzopen( $cache_file_base . '-zfb.gz.tmp', 'w' );
		if ( ! $whandle ) {
			return false;
		}

		// Serialize files queue in chunks
		$buf = 'a:' . count( $this->files_queue ) . ':{';
		foreach ( $this->files_queue as $file => $add_as ) {
			$k    = addslashes( $file );
			$v    = addslashes( $add_as );
			$buf .= 's:' . strlen( $k ) . ':"' . $k . '";s:' . strlen( $v ) . ':"' . $v . '";';

			// Write in 1MB chunks to avoid memory spikes
			if ( strlen( $buf ) > 1048576 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- gzwrite required for compressed cache
				gzwrite( $whandle, $buf, strlen( $buf ) );
				$buf = '';
			}
		}
		$buf  .= '}';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- gzwrite required for compressed cache
		$final = gzwrite( $whandle, $buf );
		unset( $buf );

		if ( ! $final ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct unlink needed for temp cache file
			@unlink( $cache_file_base . '-zfb.gz.tmp' );
			@gzclose( $whandle );
			return false;
		}

		gzclose( $whandle );

		// Cache skipped files if any exist
		$aborted_on_skipped = false;
		if ( ! empty( $this->unchanged_files_skipped ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- gzopen required for compressed cache
			$shandle = gzopen( $cache_file_base . '-zfs.gz.tmp', 'w' );
			if ( $shandle ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Required for cache serialization
				if ( ! gzwrite( $shandle, serialize( $this->unchanged_files_skipped ) ) ) {
					$aborted_on_skipped = true;
				}
				gzclose( $shandle );
			} else {
				$aborted_on_skipped = true;
			}
		}

		if ( $aborted_on_skipped ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct unlink needed for temp cache file
			@unlink( $cache_file_base . '-zfs.gz.tmp' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct unlink needed for temp cache file
			@unlink( $cache_file_base . '-zfb.gz.tmp' );
			return false;
		}

		// Write info file with metadata
		$info_array = array(
			'current_batch_size_bytes' => $this->current_batch_size_bytes,
			'files_count'              => count( $this->files_queue ),
			'dirs_count'               => count( $this->directories_queue ),
			'cached_at'                => time(),
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Required for cache metadata
		if ( ! file_put_contents( $cache_file_base . '-info.tmp', serialize( $info_array ) ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct unlink needed for temp cache files
			@unlink( $cache_file_base . '-zfs.gz.tmp' );
			@unlink( $cache_file_base . '-zfb.gz.tmp' );
			// phpcs:enable
			return false;
		}

		// Cache directories queue
		$aborted_on_dirbatched = false;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- gzopen required for compressed cache
		$dhandle = gzopen( $cache_file_base . '-zfd.gz.tmp', 'w' );
		if ( $dhandle ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Required for cache serialization
			if ( ! gzwrite( $dhandle, serialize( $this->directories_queue ) ) ) {
				$aborted_on_dirbatched = true;
			}
			gzclose( $dhandle );
		} else {
			$aborted_on_dirbatched = true;
		}

		if ( $aborted_on_dirbatched ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct unlink needed for temp cache files
			@unlink( $cache_file_base . '-zfs.gz.tmp' );
			@unlink( $cache_file_base . '-zfd.gz.tmp' );
			@unlink( $cache_file_base . '-zfb.gz.tmp' );
			@unlink( $cache_file_base . '-info.tmp' );
			// phpcs:enable
			return false;
		}

		$this->log( "File lists cached successfully" );
		return true;
	}

	/**
	 * Clean up file list cache files after backup completion.
	 *
	 * @since 1.0.0
	 * @param string $whichone Entity type whose cache should be cleaned
	 */
	private function cleanup_file_list_cache( $whichone ) {
		if ( empty( $this->cache_file_base ) ) {
			return;
		}

		$suffixes = array( '-zfd.gz.tmp', '-zfb.gz.tmp', '-zfs.gz.tmp', '-info.tmp' );
		foreach ( $suffixes as $suffix ) {
			$cache_file = $this->cache_file_base . $suffix;
			if ( file_exists( $cache_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct unlink needed for temp cache files
				@unlink( $cache_file );
			}
		}
	}

	// ========================================================================
	// PER-FILE RESUMPTION - Scan Existing Zips
	// ========================================================================

	/**
	 * Populate existing_files array from contents of existing zip archive(s).
	 *
	 * When resuming a backup that was interrupted mid-entity, this method scans
	 * any existing zip files to build a list of files already included. This
	 * prevents re-adding files and enables true per-file resumption.
	 *
	 * @since  1.0.0
	 * @param  string $zip_path Path to zip file to examine
	 * @return bool True if zip was successfully scanned, false on failure
	 */
	private function populate_existing_files_from_zip( $zip_path ) {
		if ( ! file_exists( $zip_path ) || ! is_readable( $zip_path ) ) {
			return false;
		}

		$zip_size = filesize( $zip_path );
		if ( $zip_size <= 0 ) {
			$this->log( "Zip file exists but is empty - will remove: " . basename( $zip_path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct unlink needed for empty temp file
			@unlink( $zip_path );
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			$this->log( "Could not open zip file to examine; will remove: " . basename( $zip_path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct unlink needed for corrupt temp file
			@unlink( $zip_path );
			return false;
		}

		$this->existing_zipfiles_size += $zip_size;

		// Get number of files in zip
		$numfiles = $zip->numFiles;
		if ( false === $numfiles ) {
			$this->log( "Could not read file count from zip: " . basename( $zip_path ) );
			$zip->close();
			return false;
		}

		// Iterate through all files in the zip and record their sizes
		for ( $i = 0; $i < $numfiles; $i++ ) {
			$si = $zip->statIndex( $i );
			if ( false === $si ) {
				continue;
			}

			$name = $si['name'];

			// Skip directories (end with /)
			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}

			// Only record if not already recorded (supports multiple zip parts)
			if ( ! isset( $this->existing_files[ $name ] ) ) {
				$this->existing_files[ $name ]   = $si['size'];
				$this->existing_files_rawsize   += $si['size'];
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- ZipArchive::close() required
		@$zip->close();

		$this->log( basename( $zip_path ) . ": Scanned existing zip - " . count( $this->existing_files ) . " files already archived" );
		return true;
	}

	/**
	 * Scan all existing zip files for an entity to enable per-file resumption.
	 *
	 * Checks for both .zip and .zip.tmp files across all split parts (index 0, 1, 2, etc.)
	 * and populates the existing_files array to prevent re-adding files.
	 *
	 * @since  1.0.0
	 * @param  string $base_path Base path without index suffix (e.g., /path/backup-uploads)
	 * @return int Number of existing files found across all zip parts
	 */
	private function scan_existing_zips_for_resumption( $base_path ) {
		$this->existing_files         = array();
		$this->existing_files_rawsize = 0;
		$this->existing_zipfiles_size = 0;

		// Check main zip and all split parts (index 0, 1, 2, ...)
		for ( $j = 0; $j <= $this->index; $j++ ) {
			$itext = ( 0 === $j ) ? '' : ( $j + 1 );

			// Check both completed (.zip) and in-progress (.zip.tmp) files
			$examine_zip_tmp = $base_path . $itext . '.zip.tmp';
			$examine_zip     = $base_path . $itext . '.zip';

			if ( file_exists( $examine_zip ) && filesize( $examine_zip ) > 0 ) {
				$this->populate_existing_files_from_zip( $examine_zip );
			} elseif ( file_exists( $examine_zip_tmp ) && filesize( $examine_zip_tmp ) > 0 ) {
				$this->populate_existing_files_from_zip( $examine_zip_tmp );
			}
		}

		// Update compression ratio based on existing data
		if ( $this->existing_files_rawsize > 0 ) {
			$this->zip_last_ratio = $this->existing_zipfiles_size / $this->existing_files_rawsize;
		}

		return count( $this->existing_files );
	}

	// ========================================================================
	// ARCHIVE BATCH PROCESSING - ZIPARCHIVE WRITE OPERATIONS
	// ========================================================================

	/**
	 * Write queued files to ZIP with automatic split detection and resume capability.
	 *
	 * Manages ZipArchive instances, monitors size limits, and triggers multi-part
	 * archive creation when approaching configured thresholds.
	 *
	 * @since  1.0.0
	 * @param  bool $warn_on_failures Whether to log individual file addition failures
	 * @return bool|WP_Error True on batch completion, WP_Error on unrecoverable ZIP library failure
	 */
	private function handle_archive_batch($warn_on_failures) {
		global $royalbr_instance;

		$bump_index = false;
		$ret = true;

		$zipfile = $this->archive_base_path . ((0 == $this->index) ? '' : ($this->index + 1)) . '.zip.tmp';

		// Get maxzipbatch from taskdata (allows dynamic reduction on slow servers).
		// Default 22MB: Balance between write frequency and memory footprint.
		$maxzipbatch = 23068672;
		if ( ! empty( $royalbr_instance ) && method_exists( $royalbr_instance, 'retrieve_task_data' ) ) {
			$maxzipbatch = $royalbr_instance->retrieve_task_data( 'maxzipbatch', 23068672 );
		}

		if (0 == count($this->directories_queue) && 0 == count($this->files_queue)) {
			return true;
		}

		$this->log( sprintf(
			'Starting zip batch: %d files, %d dirs queued (memory: %s)',
			count( $this->files_queue ),
			count( $this->directories_queue ),
			size_format( memory_get_usage( true ) )
		) );

		$data_added_since_reopen = 0;
		$files_zipadded_since_open = array();
		$use_binzip = ( 'ROYALBR_BinZip' === $this->use_zip_object );

		if ( $use_binzip ) {
			$zip = new ROYALBR_BinZip( $this->binzip, $this->build_binzip_opts() );
			$zip->set_log_callback( array( $this, 'log' ) );
			$opencode = $zip->open( $zipfile );
			$original_size = file_exists( $zipfile ) ? filesize( $zipfile ) : 0;
		} else {
			$zip = new ZipArchive;
			if (file_exists($zipfile)) {
				$original_size = filesize($zipfile);
				if ($original_size > 0) {
					$opencode = $zip->open($zipfile);
					clearstatcache();
				} elseif (0 === $original_size) {
					wp_delete_file($zipfile);
					$opencode = false;
				} else {
					$opencode = false;
				}
			} else {
				$original_size = 0;
			}

			if (0 === $original_size) {
				$create_code = (version_compare(PHP_VERSION, '5.2.12', '>') && defined('ZIPARCHIVE::CREATE')) ? ZIPARCHIVE::CREATE : 1;
				$opencode = $zip->open($zipfile, $create_code);
			}
		}

		if (true !== $opencode) {
			return new WP_Error('no_open', sprintf('Failed to open zip file: %s', $zipfile));
		}

		while ($dir = array_pop($this->directories_queue)) {
			$zip->addEmptyDir($dir);
		}

		$this->log( 'Zip opened, directories added, beginning file addition' );

		$batch_file_count = 0;

		foreach ($this->files_queue as $file => $add_as) {
			if (!file_exists($file)) {
				$this->log("File vanished: $add_as");
				continue;
			}

			$fsize = filesize($file);

			// Large file handling - skip files that exceed the maximum size limit
			if ( $fsize > $this->skip_file_over_size ) {
				$this->log( "Skipping file larger than limit (" . round( $this->skip_file_over_size / 1048576, 1 ) . " MB): $add_as (" . round( $fsize / 1048576, 1 ) . " MB)" );
				continue;
			}

			// Warn about large files that may cause issues
			if ( $fsize > $this->warn_file_size ) {
				$this->log( "Warning: Large file encountered: $add_as (" . round( $fsize / 1048576, 1 ) . " MB) - may require significant memory", 'warning' );
			}

			if (!isset($this->existing_files[$add_as]) || $this->existing_files[$add_as] != $fsize) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Required to update zip file timestamp
				@touch($zipfile);
				$zip->addFile($file, $add_as);
				$batch_file_count++;

				if (method_exists($zip, 'setCompressionName') && $this->requires_uncompressed_storage($add_as)) {
					if (false == $zip->setCompressionName($add_as, ZipArchive::CM_STORE)) {
						$this->log("Failed to set compression for: $add_as");
					}
				}

				$this->files_processed_current_batch++;
				$files_zipadded_since_open[] = array('file' => $file, 'addas' => $add_as);
				$data_added_since_reopen += $fsize;

				// Predict final size using observed compression ratio with safety buffer
				$reaching_split_limit = ($this->zip_last_ratio > 0 && $original_size > 0 && ($original_size + 1.1 * $data_added_since_reopen * $this->zip_last_ratio) > $this->archive_max_size) ? true : false;

				// Force immediate close/reopen after very large individual files (>100MB)
				// This flushes ZipArchive internal buffers to prevent memory accumulation
				$force_close_for_large_file = ( $fsize > 104857600 ); // 100MB

				if ($batch_file_count > 500 || $reaching_split_limit || $data_added_since_reopen > $maxzipbatch || (time() - $this->last_zip_write_timestamp) > 2 || $force_close_for_large_file) {
					if (function_exists('set_time_limit')) @set_time_limit(900); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for long-running zip batch processing

					$this->log("Batch commit to archive: " . $batch_file_count . " files, " . round($data_added_since_reopen / 1048576, 1) . " MB");

					// Signal progress for resumption scheduling.
					ROYALBR_Task_Scheduler::something_useful_happened();

					// ZipArchive::close() can require significant memory to finalize compression.
					$memory_needed = $data_added_since_reopen * 0.1; // Estimate 10% of data size needed.
					if ( ! $this->verify_free_memory( $memory_needed ) ) {
						$this->log( 'Warning: Low memory detected before zip close (' . size_format( memory_get_usage( true ) ) . ' used)' );
					}

					// Set error handler before zip close to capture disk space errors
					$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Necessary for capturing PHP errors during file operations
					set_error_handler( array( $this, 'php_error' ), $error_levels );

					$this->log( sprintf( 'Calling zip close (files: %d, data: %s, memory: %s)', $batch_file_count, size_format( $data_added_since_reopen ), size_format( memory_get_usage( true ) ) ) );
					$close_result = $zip->close();
					restore_error_handler();

					// Check for write errors captured by error handler (ZipArchive returns true even on write failures).
					if ( ! empty( $this->last_php_error ) && $this->is_disk_write_error( $this->last_php_error ) ) {
						$this->record_zip_error( $files_zipadded_since_open, $this->last_php_error );
						$this->last_php_error = '';
						$this->log( 'Aborting backup due to error: ' . $this->get_backup_error() );
						return new WP_Error( 'disk_write_error', $this->get_backup_error() );
					} elseif ( ! $close_result ) {
						$error_info = $this->last_php_error;
						if ( $use_binzip && ! empty( $zip->last_error ) ) {
							$error_info = $zip->last_error . ( $error_info ? ' | ' . $error_info : '' );
						}
						$this->record_zip_error( $files_zipadded_since_open, $error_info );
						$this->last_php_error = '';
						$this->log( 'Aborting backup due to error: ' . $this->get_backup_error() );
						return new WP_Error( 'zip_close_error', $this->get_backup_error() );
					}

					// Suspiciously small files (<90 bytes) may indicate ZipArchive failure.
					clearstatcache();
					$zip_size = file_exists( $zipfile ) ? filesize( $zipfile ) : 0;
					if ( $zip_size > 0 && $zip_size < 90 ) {
						$this->log( 'Warning: Zip file suspiciously small (' . $zip_size . ' bytes) - possible corruption' );
					}

					$batch_file_count = 0;
					unset($zip);
					$files_zipadded_since_open = array();

					if (filesize($zipfile) > $original_size) {
						$this->zip_last_ratio = ($data_added_since_reopen > 0) ? min((filesize($zipfile) - $original_size) / $data_added_since_reopen, 1) : 1;
						$original_size = filesize($zipfile);

						if ($reaching_split_limit || filesize($zipfile) > $this->archive_max_size) {
							$bump_index = true;
							$bumped_at = round(filesize($zipfile) / 1048576, 1);
						}
					}

					$this->last_zip_write_timestamp = time();
					$data_added_since_reopen = 0;

					// Proactive disk space check after successful close (detect low space before next batch).
					// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
					$disk_free = function_exists( 'disk_free_space' ) ? @disk_free_space( dirname( $zipfile ) ) : false;
					if ( false !== $disk_free && $disk_free < 10485760 ) { // 10MB threshold.
						$this->log( 'Disk space critically low after zip close: ' . size_format( $disk_free ) );
						$error_message = esc_html__( 'Insufficient disk space to continue backup', 'royal-backup-reset' ) .
										' (' . size_format( $disk_free ) . ' ' . esc_html__( 'remaining', 'royal-backup-reset' ) . ')';
						$this->set_backup_error( $error_message );
						// Direct error for frontend - picked up immediately by progress polling.
						update_option( 'royalbr_backup_error', $error_message, false );
						return new WP_Error( 'disk_space', $error_message );
					}

					if ($bump_index) {
						$this->log("Zip size at limit ($bumped_at MB) - incrementing part");
						$this->increment_archive_index();
						$bump_index = false;

						// Initialize next split archive
						$zipfile = $this->archive_base_path . ($this->index + 1) . '.zip.tmp';
						$original_size = 0;
					}

					if ( $use_binzip ) {
						$zip = new ROYALBR_BinZip( $this->binzip, $this->build_binzip_opts() );
						$zip->set_log_callback( array( $this, 'log' ) );
						$opencode = $zip->open( $zipfile );
					} else {
						$zip = new ZipArchive;
						if (file_exists($zipfile) && filesize($zipfile) > 0) {
							$opencode = $zip->open($zipfile);
						} else {
							// Delete empty file if exists to prevent deprecation warning.
							if ( file_exists( $zipfile ) && 0 === filesize( $zipfile ) ) {
								wp_delete_file( $zipfile );
							}
							$opencode = $zip->open($zipfile, ZipArchive::CREATE);
						}
					}

					if (true !== $opencode) {
						return new WP_Error('no_reopen', 'Failed to re-open zip file');
					}
				}
			}
		}

		if (isset($zip)) {
			// Memory check before final close.
			if ( ! $this->verify_free_memory( 1048576 ) ) { // 1MB safety buffer.
				$this->log( 'Warning: Low memory detected before final zip close (' . size_format( memory_get_usage( true ) ) . ' used)' );
			}

			// Set error handler before final zip close to capture disk space errors
			$error_levels = version_compare( PHP_VERSION, '8.4.0', '>=' ) ? E_ALL : E_ALL & ~E_STRICT;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Necessary for capturing PHP errors during file operations
			set_error_handler( array( $this, 'php_error' ), $error_levels );

			$close_result = $zip->close();
			restore_error_handler();

			// Check for write errors captured by error handler (ZipArchive returns true even on write failures).
			if ( ! empty( $this->last_php_error ) && $this->is_disk_write_error( $this->last_php_error ) ) {
				$this->record_zip_error( $files_zipadded_since_open, $this->last_php_error );
				$this->last_php_error = '';
				$this->log( 'Zip creation failed: ' . $this->get_backup_error() );
				return new WP_Error( 'disk_write_error', $this->get_backup_error() );
			} elseif ( ! $close_result ) {
				$error_info = $this->last_php_error;
				if ( $use_binzip && ! empty( $zip->last_error ) ) {
					$error_info = $zip->last_error . ( $error_info ? ' | ' . $error_info : '' );
				}
				$this->record_zip_error( $files_zipadded_since_open, $error_info );
				$this->last_php_error = '';
				$this->log( 'Zip creation failed: ' . $this->get_backup_error() );
				return new WP_Error( 'zip_close_error', $this->get_backup_error() );
			}

			// Validate final zip file.
			clearstatcache();
			$final_zip_size = file_exists( $zipfile ) ? filesize( $zipfile ) : 0;
			if ( $final_zip_size > 0 && $final_zip_size < 90 ) {
				$this->log( 'Warning: Final zip file suspiciously small (' . $final_zip_size . ' bytes) - possible corruption' );
			}
		}

		return $ret;
	}

	/**
	 * Finalize current archive part and prepare for next split sequence.
	 *
	 * @since 1.0.0
	 */
	private function increment_archive_index() {
		$youwhat = $this->whichone;
		$timetaken = max(microtime(true) - $this->zip_microtime_start, 0.000001);

		$itext = (0 == $this->index) ? '' : ($this->index + 1);
		$full_path = $this->archive_base_path . $itext . '.zip';

		$next_full_path = $this->archive_base_path . ($this->index + 2) . '.zip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Required to update zip file timestamp
		touch($next_full_path . '.tmp');

		if (file_exists($full_path . '.tmp') && filesize($full_path . '.tmp') > 0) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename required for backup completion
			if (!rename($full_path . '.tmp', $full_path)) {
				$this->log("Failed to rename $full_path.tmp");
			}
		}

		$kbsize = filesize($full_path) / 1024;
		$rate = round($kbsize / $timetaken, 1);
		$this->log("Created " . $this->whichone . " zip (" . $this->index . ") - " . round($kbsize, 1) . " KB in " . round($timetaken, 1) . " s ($rate KB/s)");

		$this->zip_microtime_start = microtime(true);

		$this->index++;
	}

	/**
	 * Determine if file should bypass compression due to pre-compressed format.
	 *
	 * @since  1.0.0
	 * @param  string $file Filename or path to analyze
	 * @return bool True if file should use STORE mode instead of DEFLATE compression
	 */
	private function requires_uncompressed_storage($file) {
		$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		return in_array($ext, $this->extensions_to_not_compress);
	}

	/**
	 * Build command-line options string for binary zip no-compress extensions.
	 *
	 * Converts the extensions_to_not_compress array into the -n flag format
	 * that the zip binary uses to skip compression on already-compressed formats.
	 *
	 * @since  1.5.0
	 * @return string Options string (e.g., "-n .jpg:.JPG:.png:.PNG:...")
	 */
	private function build_binzip_opts() {
		if ( empty( $this->extensions_to_not_compress ) || ! is_array( $this->extensions_to_not_compress ) ) {
			return '';
		}

		$opts = '';
		foreach ( $this->extensions_to_not_compress as $ext ) {
			$ext_with_dot    = '.' . $ext;
			$ext_upper_dot   = '.' . strtoupper( $ext );
			if ( empty( $opts ) ) {
				$opts = '-n ' . $ext_with_dot . ':' . $ext_upper_dot;
			} else {
				$opts .= ':' . $ext_with_dot . ':' . $ext_upper_dot;
			}
		}

		return $opts;
	}

	/**
	 * Find a working binary zip executable on the system.
	 *
	 * Tests common zip binary paths for availability and compatibility.
	 * Performs two-stage verification: basic zip creation with popen,
	 * then stdin mode (-@) with proc_open, and finally validates the
	 * resulting archive integrity.
	 *
	 * @since  1.5.0
	 * @return string|false Path to working zip binary, or false if none found
	 */
	private function detect_binary_zip() {
		global $royalbr_instance;

		// Requires popen, proc_open, proc_close, escapeshellarg functions
		if ( ! function_exists( 'popen' ) || ! function_exists( 'proc_open' ) || ! function_exists( 'proc_close' ) || ! function_exists( 'escapeshellarg' ) ) {
			return false;
		}

		// Binary zip is only supported on Linux/Unix systems
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return false;
		}

		// Check for cached result from previous resumption
		if ( ! empty( $royalbr_instance ) && method_exists( $royalbr_instance, 'retrieve_task_data' ) ) {
			$existing = $royalbr_instance->retrieve_task_data( 'binzip', null );
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors from is_executable
			if ( null !== $existing && ( ! is_string( $existing ) || @is_executable( $existing ) ) ) {
				return $existing;
			}
		}

		$zip_paths = array(
			'/usr/bin/zip',
			'/bin/zip',
			'/usr/local/bin/zip',
			'/usr/sfw/bin/zip',
			'/usr/xdg4/bin/zip',
			'/opt/bin/zip',
		);

		foreach ( $zip_paths as $potzip ) {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors from is_executable
			if ( ! @is_executable( $potzip ) ) {
				continue;
			}

			$this->log( 'Testing potential zip binary: ' . $potzip );

			// Create test directory structure
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors from mkdir
			@mkdir( $this->royalbr_dir . '/binziptest/subdir1/subdir2', 0777, true );

			if ( ! file_exists( $this->royalbr_dir . '/binziptest/subdir1/subdir2' ) ) {
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test file for zip verification
			file_put_contents( $this->royalbr_dir . '/binziptest/subdir1/subdir2/test.html', '<html><body><a href="https://example.com">Royal Backup test file for binary zip verification.</a></body></html>' );

			if ( file_exists( $this->royalbr_dir . '/binziptest/test.zip' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup test file
				unlink( $this->royalbr_dir . '/binziptest/test.zip' );
			}

			$all_ok = true;

			if ( is_file( $this->royalbr_dir . '/binziptest/subdir1/subdir2/test.html' ) ) {

				// Test 1: Basic zip creation with popen
				$exec = 'cd ' . escapeshellarg( $this->royalbr_dir ) . '; ' . $potzip . ' -v -u -r binziptest/test.zip binziptest/subdir1';

				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- popen may fail
				$handle = ( function_exists( 'popen' ) && function_exists( 'pclose' ) ) ? popen( $exec, 'r' ) : false;
				if ( $handle ) {
					while ( ! feof( $handle ) ) {
						$w = fgets( $handle );
						if ( $w ) {
							$this->log( 'Output: ' . trim( $w ) );
						}
					}
					$ret = pclose( $handle );
					if ( 0 !== $ret ) {
						$this->log( 'Binary zip: error (code: ' . $ret . ')' );
						$all_ok = false;
					}
				} else {
					$this->log( 'Error: popen failed' );
					$all_ok = false;
				}

				// Test 2: stdin mode (-@) with proc_open
				if ( $all_ok ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test file for zip verification
					file_put_contents( $this->royalbr_dir . '/binziptest/subdir1/subdir2/test2.html', '<html><body><a href="https://example.com">Royal Backup second test file for binary zip stdin mode.</a></body></html>' );

					$exec2 = $potzip . ' -v -@ binziptest/test.zip';

					$descriptorspec = array(
						0 => array( 'pipe', 'r' ),
						1 => array( 'pipe', 'w' ),
						2 => array( 'pipe', 'w' ),
					);

					$handle = proc_open( $exec2, $descriptorspec, $pipes, $this->royalbr_dir );
					if ( is_resource( $handle ) ) {
						if ( ! fwrite( $pipes[0], "binziptest/subdir1/subdir2/test2.html\n" ) ) {
							// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Cleanup pipes on failure
							@fclose( $pipes[0] );
							@fclose( $pipes[1] );
							@fclose( $pipes[2] );
							$all_ok = false;
						} else {
							fclose( $pipes[0] );
							while ( ! feof( $pipes[1] ) ) {
								$w = fgets( $pipes[1] );
								if ( $w ) {
									$this->log( 'Output: ' . trim( $w ) );
								}
							}
							fclose( $pipes[1] );

							while ( ! feof( $pipes[2] ) ) {
								$stderr_line = fgets( $pipes[2] );
								if ( ! empty( $stderr_line ) ) {
									$this->log( 'Stderr output: ' . trim( $stderr_line ) );
								}
							}
							fclose( $pipes[2] );

							$ret = function_exists( 'proc_close' ) ? proc_close( $handle ) : -1;
							if ( 0 !== $ret ) {
								$this->log( 'Binary zip: error (code: ' . $ret . ')' );
								$all_ok = false;
							}
						}
					} else {
						$this->log( 'Error: proc_open failed' );
						$all_ok = false;
					}
				}

				// Test 3: Verify archive integrity
				$found_first  = false;
				$found_second = false;
				if ( $all_ok && file_exists( $this->royalbr_dir . '/binziptest/test.zip' ) ) {
					if ( function_exists( 'gzopen' ) ) {
						if ( ! class_exists( 'PclZip' ) ) {
							include_once ABSPATH . '/wp-admin/includes/class-pclzip.php';
						}
						$zip = new PclZip( $this->royalbr_dir . '/binziptest/test.zip' );
						$list = $zip->listContent();
						if ( 0 !== $list ) {
							foreach ( $list as $obj ) {
								if ( ! empty( $obj['stored_filename'] ) && 'binziptest/subdir1/subdir2/test.html' === $obj['stored_filename'] ) {
									$found_first = true;
								}
								if ( ! empty( $obj['stored_filename'] ) && 'binziptest/subdir1/subdir2/test2.html' === $obj['stored_filename'] ) {
									$found_second = true;
								}
							}
						}
					} else {
						$this->log( 'gzopen function not found; will assume binary zip works if we have a non-zero file' );
						if ( filesize( $this->royalbr_dir . '/binziptest/test.zip' ) > 0 ) {
							$found_first  = true;
							$found_second = true;
						}
					}
				}

				$this->remove_binzip_test_files();
				if ( $found_first && $found_second ) {
					$this->log( 'Working binary zip found: ' . $potzip );
					if ( ! empty( $royalbr_instance ) && method_exists( $royalbr_instance, 'save_task_data' ) ) {
						$royalbr_instance->save_task_data( 'binzip', $potzip );
					}
					return $potzip;
				}
			}

			$this->remove_binzip_test_files();
		}

		if ( ! empty( $royalbr_instance ) && method_exists( $royalbr_instance, 'save_task_data' ) ) {
			$royalbr_instance->save_task_data( 'binzip', false );
		}
		return false;
	}

	/**
	 * Remove test files created during binary zip detection.
	 *
	 * @since 1.5.0
	 */
	private function remove_binzip_test_files() {
		// phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged -- Cleanup may fail if files don't exist
		@unlink( $this->royalbr_dir . '/binziptest/subdir1/subdir2/test.html' );
		@unlink( $this->royalbr_dir . '/binziptest/subdir1/subdir2/test2.html' );
		@rmdir( $this->royalbr_dir . '/binziptest/subdir1/subdir2' );
		@rmdir( $this->royalbr_dir . '/binziptest/subdir1' );
		@unlink( $this->royalbr_dir . '/binziptest/test.zip' );
		@rmdir( $this->royalbr_dir . '/binziptest' );
		// phpcs:enable
	}

	/**
	 * Extract file extension exclusions from combined exclusion configuration.
	 *
	 * @since  1.0.0
	 * @param  array $exclude Mixed exclusion array with ext: prefixed entries
	 * @return array Normalized lowercase extension list without dots
	 */
	private function fetch_excluded_file_types($exclude) {
		if (!is_array($exclude)) $exclude = array();
		$exclude_extensions = array();
		foreach ($exclude as $ex) {
			if (preg_match('/^ext:(.+)$/i', $ex, $matches)) {
				$exclude_extensions[] = strtolower($matches[1]);
			}
		}
		return $exclude_extensions;
	}

	/**
	 * Extract filename prefix exclusions from combined exclusion configuration.
	 *
	 * @since  1.0.0
	 * @param  array $exclude Mixed exclusion array with prefix: prefixed entries
	 * @return array Normalized lowercase prefix list for matching
	 */
	private function fetch_excluded_name_prefixes($exclude) {
		if (!is_array($exclude)) $exclude = array();
		$exclude_prefixes = array();
		foreach ($exclude as $pref) {
			if (preg_match('/^prefix:(.+)$/i', $pref, $matches)) {
				$exclude_prefixes[] = strtolower($matches[1]);
			}
		}
		return $exclude_prefixes;
	}

	/**
	 * Parse wildcard patterns from exclusion configuration into structured format.
	 *
	 * @since  1.0.0
	 * @param  array $exclude Mixed exclusion array with wildcard patterns
	 * @return array Structured patterns with separate directory_path and pattern components
	 */
	private function fetch_excluded_patterns($exclude) {
		if (!is_array($exclude)) $exclude = array();
		$excluded_wildcards = array();
		foreach ($exclude as $wch) {
			if (preg_match('#(.*(?<!\\\)/)?(.*?(?<!\\\)\*.*)#i', $wch, $matches)) {
				$excluded_wildcards[] = array(
					'directory_path' => preg_replace(array('/^[\/\s]*/', '/\/\/*/', '/[\/\s]*$/'), array('', '/', ''), $matches[1]),
					'pattern' => $matches[2]
				);
			}
		}
		return $excluded_wildcards;
	}

	/**
	 * Test whether file path matches any configured wildcard exclusion patterns.
	 *
	 * @since  1.0.0
	 * @param  string $entity Relative path within archive to test
	 * @return bool True if path matches exclusion pattern and should be skipped
	 */
	private function matches_exclusion_pattern($entity) {
		$entity_basename = untrailingslashit($entity);
		$entity_basename = substr_replace($entity_basename, '', 0, (false === strrpos($entity_basename, '/') ? 0 : strrpos($entity_basename, '/') + 1));

		foreach ($this->excluded_wildcards as $wch) {
			if (!is_array($wch) || empty($wch)) continue;
			if (substr_replace($entity, '', (int) strrpos($entity, '/'), strlen($entity) - (int) strrpos($entity, '/')) !== $wch['directory_path']) continue;

			if ('*' == substr($wch['pattern'], -1, 1) && '*' == substr($wch['pattern'], 0, 1) && strlen($wch['pattern']) > 2) {
				// *pattern* - contains
				$wch['pattern'] = substr($wch['pattern'], 1, strlen($wch['pattern']) - 2);
				$wch['pattern'] = str_replace('\*', '*', $wch['pattern']);
				if (strpos($entity_basename, $wch['pattern']) !== false) return true;
			} elseif ('*' == substr($wch['pattern'], -1, 1) && strlen($wch['pattern']) > 1) {
				// pattern* - starts with
				$wch['pattern'] = substr($wch['pattern'], 0, strlen($wch['pattern']) - 1);
				$wch['pattern'] = str_replace('\*', '*', $wch['pattern']);
				if (substr($entity_basename, 0, strlen($wch['pattern'])) == $wch['pattern']) return true;
			} elseif ('*' == substr($wch['pattern'], 0, 1) && strlen($wch['pattern']) > 1) {
				// *pattern - ends with
				$wch['pattern'] = substr($wch['pattern'], 1);
				$wch['pattern'] = str_replace('\*', '*', $wch['pattern']);
				if (strlen($entity_basename) >= strlen($wch['pattern']) && substr($entity_basename, strlen($wch['pattern']) * -1) == $wch['pattern']) return true;
			}
		}
		return false;
	}

	/**
	 * Check if entity has excluded file type.
	 *
	 * @since  1.0.0
	 * @param  string $entity Entity path/name
	 * @return bool True if excluded
	 */
	private function has_excluded_file_type($entity) {
		foreach ($this->excluded_extensions as $ext) {
			if (!$ext) continue;
			$eln = strlen($ext);
			if (strtolower(substr($entity, -$eln, $eln)) == $ext) return true;
		}
		return false;
	}

	/**
	 * Check if entity has excluded prefix.
	 *
	 * @since  1.0.0
	 * @param  string $entity Entity path/name
	 * @return bool True if excluded
	 */
	private function has_excluded_prefix($entity) {
		$entity = basename($entity);
		foreach ($this->excluded_prefixes as $pref) {
			if (!$pref) continue;
			$eln = strlen($pref);
			if (strtolower(substr($entity, 0, $eln)) == $pref) return true;
		}
		return false;
	}

	/**
	 * Remove stale temporary files from backup directory to prevent disk bloat.
	 *
	 * @since  1.0.0
	 * @param  string $match   Filename pattern to match (regex fragment)
	 * @param  int    $max_age Minimum file age in seconds (0 removes all matching files)
	 */
	private function clean_temporary_files($match, $max_age) {
		if (!is_dir($this->royalbr_dir)) return;

		$d = dir($this->royalbr_dir);
		$time_now = time();

		while (false !== ($e = $d->read())) {
			if ('.' == $e || '..' == $e || !is_file($this->royalbr_dir . '/' . $e)) continue;

			// Match temp files while protecting completed .zip archives
			$is_temp_file = preg_match("/$match\.(tmp|table|txt\.gz)(\.gz)?$/i", $e);
			$is_ziparchive_temp = preg_match("/$match([0-9]+)?\.zip\.tmp\.(?:[A-Za-z0-9]+)$/i", $e);

			if (!$is_temp_file && !$is_ziparchive_temp) continue;

			$mtime = filemtime($this->royalbr_dir . '/' . $e);
			if ($max_age > 0 && ($time_now - $mtime) < $max_age) continue;

			// Safe to remove
			$this->log("Removing old temporary file: $e");
			@wp_delete_file($this->royalbr_dir . '/' . $e);
		}

		@$d->close();
	}

	/**
	 * Verify sufficient free memory is available for an operation.
	 *
	 * Checks both current and allocated memory
	 * to ensure operation can complete without hitting memory limit.
	 *
	 * @since  1.0.0
	 * @param  int $bytes_needed Bytes required for the operation.
	 * @return bool True if sufficient memory available, false otherwise.
	 */
	private function verify_free_memory( $bytes_needed ) {
		$memory_limit = $this->memory_check_current();

		// If memory_limit is -1 (unlimited), always return true.
		if ( -1 === $memory_limit ) {
			return true;
		}

		$memory_usage     = memory_get_usage( false ); // Current usage.
		$memory_usage_real = memory_get_usage( true );  // Allocated (peak).

		// Check both current and allocated memory have enough headroom.
		if ( ( $memory_limit - $memory_usage ) > $bytes_needed &&
			 ( $memory_limit - $memory_usage_real ) > $bytes_needed ) {
			return true;
		}

		return false;
	}

	/**
	 * Get current PHP memory limit in bytes.
	 *
	 * Parses PHP's memory_limit setting, handling K, M, G suffixes.
	 *
	 * @since  1.0.0
	 * @return int Memory limit in bytes, or -1 if unlimited.
	 */
	private function memory_check_current() {
		$memory_limit = ini_get( 'memory_limit' );

		// Handle -1 (unlimited).
		if ( '-1' === $memory_limit || -1 === $memory_limit ) {
			return -1;
		}

		// Parse size with suffix.
		$memory_limit = trim( $memory_limit );
		$last         = strtolower( $memory_limit[ strlen( $memory_limit ) - 1 ] );
		$memory_limit = (int) $memory_limit;

		switch ( $last ) {
			case 'g':
				$memory_limit *= 1024;
				// Fall through.
			case 'm':
				$memory_limit *= 1024;
				// Fall through.
			case 'k':
				$memory_limit *= 1024;
		}

		return $memory_limit;
	}

	/**
	 * Retrieve default exclusion patterns tailored to specific entity types.
	 *
	 * Provides sensible defaults to avoid backing up temporary and redundant data
	 * commonly found in WordPress installations.
	 *
	 * @since  1.0.0
	 * @param  string $whichone Entity type (uploads, others, plugins, themes)
	 * @return array Directory name exclusions appropriate for entity type
	 */
	private function get_exclude($whichone) {
		$exclude = array();

		switch ($whichone) {
			case 'uploads':
				// Skip nested backup directories created by other plugins
				$exclude = explode(',', 'backup,backups,backwpup,wp-clone,snapshots,updraft,wp-staging');
				break;
			case 'others':
				// Exclude WordPress core update cache and plugin backup storage
				$exclude = explode(',', 'upgrade,cache,backup,backups,aiowps_backups,wp-clone,updraft,wp-staging,debug.log');
				break;
			case 'plugins':
			case 'themes':
				// Plugins and themes backed up completely by default
				break;
		}

		return $exclude;
	}

	// ========================================================================
	// FILE BACKUP ORCHESTRATION - ENTITY PROCESSING WITH RESUMPTION
	// ========================================================================

	/**
	 * Initiate file backup process for all configured entities.
	 *
	 * Sets up entity task tracking and delegates to directory scanning engine.
	 * Supports resumption via task_file_entities tracking completed entities.
	 *
	 * @since  1.0.0
	 * @return array Associative array of backup filenames with size metadata
	 */
	public function process_file_backup() {
		global $royalbr_instance;

		// Check if task_file_entities already exists (resumption).
		$existing_entities = $royalbr_instance->retrieve_task_data( 'task_file_entities' );

		if ( empty( $existing_entities ) || ! is_array( $existing_entities ) ) {
			$task_file_entities = array();

			// Add standard file entities only if files backup is enabled.
			$include_files = $royalbr_instance->retrieve_task_data( 'task_backup_files' );
			if ( $include_files ) {
				$task_file_entities['plugins'] = array( 'index' => 0 );
				$task_file_entities['themes']  = array( 'index' => 0 );
				$task_file_entities['uploads'] = array( 'index' => 0 );
				$task_file_entities['others']  = array( 'index' => 0 );
			}

			// Add wpcore entity if enabled in task data.
			$include_wpcore = $royalbr_instance->retrieve_task_data( 'task_backup_wpcore' );
			if ( $include_wpcore ) {
				$task_file_entities['wpcore'] = array( 'index' => 0 );
			}

			$royalbr_instance->save_task_data( 'task_file_entities', $task_file_entities );
		}

		// Execute archive creation for all configured entities.
		$this->backup_files_array = $this->scan_backup_directories( 'begun' );

		return $this->backup_files_array;
	}

	/**
	 * Coordinate multi-entity backup by iterating entities and creating split archives.
	 *
	 * Handles resume logic, progress tracking, and final manifest generation for
	 * each WordPress entity type (plugins, themes, uploads, others).
	 *
	 * @since  1.0.0
	 * @param  string $task_status Execution phase ('begun' creates archives, 'finished' returns manifest)
	 * @return array Complete backup manifest with filenames indexed by entity and size metadata
	 */
	private function scan_backup_directories($task_status) {
		global $royalbr_instance;

		if (!$royalbr_instance->backup_time) {
			$royalbr_instance->backup_time_nonce();
		}

		$use_time = $royalbr_instance->backup_time;
		$backup_file_basename = $this->generate_backup_filename($use_time);

		$backup_array = array();

		// Check if backup directory is writable
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Required to check backup directory permissions
		if ('finished' != $task_status && !is_writable($this->royalbr_dir)) {
			$this->log("Backup directory (" . $this->royalbr_dir . ") is not writable, or does not exist");
			return array();
		}

		$this->task_file_entities = $royalbr_instance->retrieve_task_data('task_file_entities');

		if ( 'finished' !== $task_status && $royalbr_instance->current_resumption >= 2 ) {
			if ( $royalbr_instance->no_checkin_last_time ) {
				if ( $royalbr_instance->current_resumption - $royalbr_instance->last_successful_resumption > 2 ) {
					$this->try_split = true;
				}
			}
		}

		// Visual feedback counter
		$which_entity = 0;

		// Returns an array (keyed off the entity) of ($timestamp, $filename) arrays
		$existing_zips = $this->locate_existing_archives($this->royalbr_dir, $royalbr_instance->file_nonce);

		$possible_backups = $royalbr_instance->get_backupable_file_entities(true);

		foreach ($possible_backups as $youwhat => $whichdir) {

			if ($this->check_abort_requested()) {
				$this->log("Backup aborted by user during $youwhat entity");
				return array(); // Return empty array on abort
			}

			if (!isset($this->task_file_entities[$youwhat])) {
				$this->log("No backup of $youwhat: excluded by settings");
				continue;
			}

			$index = (int) $this->task_file_entities[$youwhat]['index'];
			if (empty($index)) $index = 0;
			if ( $index > 0 ) {
				$this->log( "Continuing $youwhat backup from file $index" );
			}
			$indextext = (0 == $index) ? '' : (1 + $index);

			$zip_file = $this->royalbr_dir . '/' . $backup_file_basename . '-' . $youwhat . $indextext . '.zip';

			$split_every = max((int) $royalbr_instance->retrieve_task_data('split_every', 250), 250);

			if (false != ($existing_file = $this->check_archive_exists($existing_zips, $youwhat, $index)) && filesize($this->royalbr_dir . '/' . $existing_file) > $split_every * 1048576) {
				$index++;
				$this->task_file_entities[$youwhat]['index'] = $index;
				$royalbr_instance->save_task_data('task_file_entities', $this->task_file_entities);
			}

			// Populate prior parts of $backup_array, if we're on a subsequent zip file
			if ($index > 0) {
				for ($i = 0; $i < $index; $i++) {
					$itext = (0 == $i) ? '' : ($i + 1);
					// Get the previously-stored filename if possible
					$zip_file = (isset($this->backup_files_array[$youwhat]) && isset($this->backup_files_array[$youwhat][$i])) ? $this->backup_files_array[$youwhat][$i] : $backup_file_basename . '-' . $youwhat . $itext . '.zip';

					$backup_array[$youwhat][$i] = $zip_file;
					$z = $this->royalbr_dir . '/' . $zip_file;
					$itext = (0 == $i) ? '' : $i;

					$fs_key = $youwhat . $itext . '-size';
					if (file_exists($z)) {
						$backup_array[$fs_key] = filesize($z);
					} elseif (isset($this->backup_files_array[$fs_key])) {
						$backup_array[$fs_key] = $this->backup_files_array[$fs_key];
					}
				}
			}

			if ('finished' == $task_status) {
				// Add the final part of the array
				if ($index > 0) {
					$zip_file = (isset($this->backup_files_array[$youwhat]) && isset($this->backup_files_array[$youwhat][$index])) ? $this->backup_files_array[$youwhat][$index] : $backup_file_basename . '-' . $youwhat . ($index + 1) . '.zip';
					$z = $this->royalbr_dir . '/' . $zip_file;
					$fs_key = $youwhat . $index . '-size';
					$backup_array[$youwhat][$index] = $zip_file;
					if (file_exists($z)) {
						$backup_array[$fs_key] = filesize($z);
					} elseif (isset($this->backup_files_array[$fs_key])) {
						$backup_array[$fs_key] = $this->backup_files_array[$fs_key];
					}
				} else {
					$zip_file = (isset($this->backup_files_array[$youwhat]) && isset($this->backup_files_array[$youwhat][0])) ? $this->backup_files_array[$youwhat][0] : $backup_file_basename . '-' . $youwhat . '.zip';

					$backup_array[$youwhat] = $zip_file;
					$fs_key = $youwhat . '-size';

					if (file_exists($zip_file)) {
						$backup_array[$fs_key] = filesize($zip_file);
					} elseif (isset($this->backup_files_array[$fs_key])) {
						$backup_array[$fs_key] = $this->backup_files_array[$fs_key];
					}
				}
			} else {
				// In progress - create the zip
				$which_entity++;
				$royalbr_instance->save_task_data('filecreating_substatus', array('e' => $youwhat, 'i' => $which_entity, 't' => count($this->task_file_entities)));

				if ('others' == $youwhat) {
					$this->log("Starting backup of additional content directories (index: $index)");
				}

				$created = apply_filters('royalbr_backup_makezip_' . $youwhat, $whichdir, $backup_file_basename, $index);

				// Fallback to default implementation if filter didn't handle creation
				if ($created === $whichdir) {

					if ('others' == $youwhat) {
						$dirlist = $royalbr_instance->backup_others_dirlist(true);
					} elseif ('uploads' == $youwhat) {
						// Uploads requires array of year subdirectories for proper structure preservation
						$dirlist = $royalbr_instance->backup_uploads_dirlist(true);
					} elseif ('wpcore' == $youwhat) {
						// WordPress core files (wp-admin, wp-includes, root files) excluding wp-content
						$dirlist = $royalbr_instance->backup_wpcore_dirlist(true);
					} else {
						// Plugins/themes use simple directory path
						$dirlist = $whichdir;
						if (is_array($dirlist)) $dirlist = array_shift($dirlist);
					}

					if (!empty($dirlist)) {
						$created = $this->build_archive($dirlist, $youwhat, $backup_file_basename, $index);
						// Now, store the results
						if (!is_string($created) && !is_array($created)) {
							$this->log("$youwhat: zip creation failed");
							// Check if a fatal error was set - if so, abort immediately.
							$error = $this->get_backup_error();
							if ( ! empty( $error ) ) {
								$this->log( 'Aborting backup due to error: ' . $error );
								return new WP_Error( 'backup_failed', $error );
							}
						}
					} else {
						$this->log("No backup of $youwhat: nothing to backup");
					}
				}

				if ($created != $whichdir && (is_string($created) || is_array($created))) {
					if (is_string($created)) $created = array($created);
					foreach ($created as $fname) {
						if (isset($backup_array[$youwhat]) && in_array($fname, $backup_array[$youwhat])) continue;
						$backup_array[$youwhat][$index] = $fname;
						$itext = (0 == $index) ? '' : $index;
						// File may have already been uploaded and removed so get the size from taskdata
						if (file_exists($this->royalbr_dir . '/' . $fname)) {
							$backup_array[$youwhat . $itext . '-size'] = filesize($this->royalbr_dir . '/' . $fname);
						} else {
							$backup_array[$youwhat . $itext . '-size'] = $royalbr_instance->retrieve_task_data('filesize-' . $youwhat . $index);
						}
						$index++;
					}
				}

				$this->task_file_entities[$youwhat]['index'] = $this->index;
				$royalbr_instance->save_task_data('task_file_entities', $this->task_file_entities);

				// Signal progress after each entity to allow scheduling decisions.
				ROYALBR_Task_Scheduler::something_useful_happened();
			}
		}

		return $backup_array;
	}

	/**
	 * Verify if specific archive part exists in resume manifest.
	 *
	 * @since  1.0.0
	 * @param  array  $existing_zips Resume manifest indexed by entity and split number
	 * @param  string $entity        Entity type to check
	 * @param  int    $index         Split part number
	 * @return string|false Archive filename if found, false if missing
	 */
	private function check_archive_exists($existing_zips, $entity, $index) {
		if (!isset($existing_zips[$entity])) return false;
		if (!isset($existing_zips[$entity][$index])) return false;
		return $existing_zips[$entity][$index];
	}

	/**
	 * Scan backup directory for archives matching backup nonce to support resumption.
	 *
	 * @since  1.0.0
	 * @param  string $dir   Backup storage directory path
	 * @param  string $nonce Unique backup session identifier
	 * @return array Nested array of found archives organized by entity and split index
	 */
	private function locate_existing_archives($dir, $nonce) {
		$existing = array();
		if (!is_dir($dir)) return $existing;

		$handle = opendir($dir);
		if (!$handle) return $existing;

		while (false !== ($entry = readdir($handle))) {
			if ('.' == $entry || '..' == $entry) continue;

			// Parse backup filename format: backup_YYYY-MM-DD-HHMM_sitename_nonce-entity.zip
			if (preg_match('/^backup_\d{4}-\d{2}-\d{2}-\d{4}_.*_' . preg_quote($nonce, '/') . '-([a-z]+)(\d*)\.zip$/i', $entry, $matches)) {
				$entity = $matches[1];
				$index = empty($matches[2]) ? 0 : ((int) $matches[2] - 1);
				$existing[$entity][$index] = $entry;
			}
		}
		closedir($handle);

		return $existing;
	}

	// ========================================================================
	// FILENAME GENERATION AND LOG FILE MANAGEMENT
	// ========================================================================

	/**
	 * Construct standardized backup filename from timestamp and site configuration.
	 *
	 * Format: backup_YYYY-MM-DD-HHMM_sitename_nonce
	 *
	 * @since  1.0.0
	 * @param  int $use_time Unix timestamp for filename date component
	 * @return string Complete basename without file extension or entity suffix
	 */
	public function generate_backup_filename($use_time) {
		global $royalbr_instance;
		// UTC timestamp formatting for cross-timezone consistency
		$date_string = gmdate('Y-m-d-Hi', $use_time);
		return 'backup_' . $date_string . '_' . $this->site_name . '_' . $royalbr_instance->file_nonce;
	}

	/**
	 * Generate log file path for specific backup session.
	 *
	 * Uses same naming pattern as backup files to enable automatic deletion
	 * when backup set is removed. Format: backup_{timestamp}_{sitename}_{nonce}-log.txt
	 *
	 * @since  1.0.0
	 * @param  string $nonce Backup session identifier
	 * @return string Absolute path to log file
	 */
	public function get_logfile_name($nonce) {
		global $royalbr_instance;

		// If backup_time is set (during backup creation), generate filename normally
		if (!empty($royalbr_instance->backup_time)) {
			$backup_basename = $this->generate_backup_filename( $royalbr_instance->backup_time );
			return $this->royalbr_dir . '/' . $backup_basename . '-log.txt';
		}

		// Otherwise (when viewing logs later), search for the log file by nonce pattern
		// Pattern: backup_{timestamp}_{sitename}_{nonce}-log.txt
		// We search for: *_{nonce}-log.txt
		$pattern = $this->royalbr_dir . '/*_' . $nonce . '-log.txt';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Needed to find log file by pattern
		$matches = glob($pattern);

		if (!empty($matches) && is_array($matches)) {
			// Return the first match (there should only be one)
			return $matches[0];
		}

		// Fallback: construct best-guess filename (will likely fail but provides clear error message)
		$date_string = gmdate('Y-m-d-Hi', time());
		return $this->royalbr_dir . '/backup_' . $date_string . '_' . $this->site_name . '_' . $nonce . '-log.txt';
	}

	/**
	 * Initialize log file with backup session metadata header.
	 *
	 * @since  1.0.0
	 * @param  string $nonce Backup session identifier
	 * @return void
	 */
	public function logfile_open($nonce) {
		$this->logfile_name = $this->get_logfile_name($nonce);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Required for streaming large database files
		$this->logfile_handle = fopen($this->logfile_name, 'a');

		// Initialize timing for log entries
		$this->opened_log_time = microtime(true);
		$this->task_time_ms = microtime(true);

		// Record session start information
		if ($this->logfile_handle) {
			$this->log('Royal Backup & Reset: Backup started');
			$this->log('WordPress version: ' . get_bloginfo('version'));
			$this->log('Site: ' . get_bloginfo('name') . ' (' . home_url() . ')');
			$this->log('Backup directory: ' . $this->royalbr_dir);
		}
	}

	/**
	 * Write line to backup log file
	 *
	 * Timestamp (resumption) [level] message
	 * Writes directly to the logfile handle for immediate persistence.
	 *
	 * @since  1.0.0
	 * @param  string $line  The log line to write
	 * @param  string $level The log level (notice, warning, error)
	 * @return void
	 */
	public function write_to_log( $line, $level = 'notice' ) {
		if ( empty( $this->logfile_handle ) ) {
			return;
		}

		// Calculate relative time since backup started
		$rtime = ! empty( $this->task_time_ms ) ? microtime( true ) - $this->task_time_ms : microtime( true ) - $this->opened_log_time;

		// Current resumption number (defaults to 0 if not set)
		$current_resumption = isset( $this->current_resumption ) ? $this->current_resumption : 0;

		// Format: "00000.123 (0) [level] message\n" - only add level prefix for non-notice
		$level_prefix = ( 'notice' !== $level ) ? '[' . ucfirst( $level ) . '] ' : '';
		$formatted_line = sprintf( '%08.03f', round( $rtime, 3 ) ) . ' (' . $current_resumption . ') ' . $level_prefix . $line . "\n";

		// Write to file immediately
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file operation needed for logging
		fwrite( $this->logfile_handle, $formatted_line );
		fflush( $this->logfile_handle );
	}

	/**
	 * Flush and close active log file handle.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function logfile_close() {
		if ($this->logfile_handle) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing database file handle
			fclose($this->logfile_handle);
			$this->logfile_handle = false;
		}
	}

	/**
	 * PHP error handler for backup process.
	 *
	 * Captures PHP errors during backup operations and stores
	 * the message for inclusion in error responses to the UI.
	 *
	 * @since  1.0.0
	 * @param int    $errno   Error number.
	 * @param string $errstr  Error message.
	 * @param string $errfile File where error occurred.
	 * @param int    $errline Line number where error occurred.
	 * @return bool False to continue normal error handling.
	 */
	public function php_error( $errno, $errstr, $errfile, $errline ) {
		// Log the error
		$this->log( "PHP Error ($errno): $errstr in $errfile on line $errline" );

		// Store the error message for inclusion in error responses
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
	 * @since  1.0.0
	 * @return string Error detail suffix (e.g., " (No space left on device)") or empty string.
	 */
	private function get_php_error_detail() {
		if ( empty( $this->last_php_error ) ) {
			return '';
		}
		$error_detail         = ' (' . $this->last_php_error . ')';
		$this->last_php_error = '';
		return $error_detail;
	}

	/**
	 * Set backup error message for display to user.
	 *
	 * Stores the error message and saves it to taskdata for progress polling
	 * to retrieve and display in the UI.
	 *
	 * @since  1.0.0
	 * @param string $error_message Error message to display.
	 * @return void
	 */
	public function set_backup_error( $error_message ) {
		global $royalbr_instance;
		$this->backup_error = $error_message;
		// Store in taskdata for progress polling to retrieve.
		if ( $royalbr_instance ) {
			$royalbr_instance->save_task_data( 'backup_error', $error_message );
		}
	}

	/**
	 * Get the current backup error message.
	 *
	 * Checks class property first, then falls back to taskdata for errors
	 * that were set in previous WP-Cron resumptions.
	 *
	 * @since  1.0.0
	 * @return string Current backup error message or empty string.
	 */
	public function get_backup_error() {
		// Return class property if set.
		if ( ! empty( $this->backup_error ) ) {
			return $this->backup_error;
		}

		// Fallback: Check taskdata for error (persists across resumptions).
		global $royalbr_instance;
		if ( $royalbr_instance ) {
			$taskdata_error = $royalbr_instance->retrieve_task_data( 'backup_error' );
			if ( ! empty( $taskdata_error ) ) {
				return $taskdata_error;
			}
		}

		return '';
	}

	/**
	 * Check if error message indicates disk space or write failure.
	 *
	 * Used to detect ZipArchive write failures that return true but emit PHP warnings.
	 *
	 * @since  1.0.0
	 * @param string $error_msg Error message to check.
	 * @return bool True if disk/write-related error.
	 */
	private function is_disk_write_error( $error_msg ) {
		$error_patterns = array(
			'No space left on device',
			'Disk quota exceeded',
			'disk full',
			'Write error',
			'failed to write',
		);
		foreach ( $error_patterns as $pattern ) {
			if ( stripos( $error_msg, $pattern ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record zip error with disk space diagnostics.
	 *
	 * Checks disk space when a zip error occurs and sets appropriate error message.
	 *
	 * @since  1.0.0
	 * @param array  $files_zipadded_since_open Files that were being added when error occurred.
	 * @param string $error_msg                 The PHP error message if any.
	 * @return void
	 */
	private function record_zip_error( $files_zipadded_since_open, $error_msg = '' ) {
		// Check disk space when zip error occurs.
		$backup_dir = defined( 'ROYALBR_BACKUP_DIR' ) ? ROYALBR_BACKUP_DIR : ( WP_CONTENT_DIR . '/royal-backup-reset/' );
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
		$disk_free = function_exists( 'disk_free_space' ) ? @disk_free_space( $backup_dir ) : false;

		$quota_low = false;
		if ( false !== $disk_free ) {
			$this->log( 'Free disk space: ' . size_format( $disk_free ) );
			if ( $disk_free < 52428800 ) { // 50MB threshold.
				$quota_low = true;
				$this->log(
					sprintf(
						/* translators: %s: remaining disk space */
						esc_html__( 'Your free disk space is very low - only %s remain', 'royal-backup-reset' ),
						size_format( $disk_free )
					),
					'warning'
				);
			}
		}

		// Set appropriate error message based on disk space status.
		if ( $quota_low ) {
			$error_message = esc_html__( 'Backup failed - insufficient disk space', 'royal-backup-reset' ) .
							' (' . size_format( $disk_free ) . ' ' . esc_html__( 'remaining', 'royal-backup-reset' ) . ')';
		} else {
			$error_message = esc_html__( 'Failed to finalize backup archive', 'royal-backup-reset' );
			if ( ! empty( $error_msg ) ) {
				$error_message .= ' (' . $error_msg . ')';
			}
		}

		$this->set_backup_error( $error_message );

		// Direct error for frontend - picked up immediately by progress polling.
		update_option( 'royalbr_backup_error', $error_message, false );

		// Log files that were being added.
		$this->log( 'Zip close failed. Files being added:' );
		foreach ( $files_zipadded_since_open as $ffile ) {
			$exists = file_exists( $ffile['file'] ) ? 'yes' : 'no';
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Silenced to suppress errors that may arise because of the function.
			$size = @filesize( $ffile['file'] );
			$this->log( "  - {$ffile['addas']} (exists: $exists, size: $size)" );
		}
	}
}

