<?php
/**
 * ROYALBR Database Utility Class
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load required dependencies.
require_once __DIR__ . '/trait-royalbr-sql-helpers.php';
require_once __DIR__ . '/class-royalbr-db-sorting-engine.php';
require_once __DIR__ . '/class-royalbr-sql-mode-manager.php';
require_once __DIR__ . '/class-royalbr-db-schema-inspector.php';
require_once __DIR__ . '/class-royalbr-generated-column-parser.php';

/**
 * Database Utility Facade.
 *
 * Provides unified interface to database operations by delegating to
 * specialized classes. Maintains backward compatibility with all existing
 * static method calls while organizing functionality into focused components.
 *
 * @since 1.0.0
 */
class ROYALBR_Database_Utility {

	use ROYALBR_SQL_Helpers;

	/**
	 * Database type identifier.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private static $db_type_id;

	/**
	 * Raw table prefix string.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private static $prefix_raw;

	/**
	 * Database connection handle.
	 *
	 * @since 1.0.0
	 * @var   wpdb
	 */
	private static $db_handle;

	/**
	 * Cached table status information.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	private static $table_status = array();

	/**
	 * Sorting engine instance.
	 *
	 * @since 1.0.0
	 * @var   ROYALBR_DB_Sorting_Engine
	 */
	private static $sorting_engine;

	/**
	 * SQL mode manager instance.
	 *
	 * @since 1.0.0
	 * @var   ROYALBR_SQL_Mode_Manager
	 */
	private static $mode_manager;

	/**
	 * Schema inspector instance.
	 *
	 * @since 1.0.0
	 * @var   ROYALBR_DB_Schema_Inspector
	 */
	private static $schema_inspector;

	/**
	 * Column parser instance.
	 *
	 * @since 1.0.0
	 * @var   ROYALBR_Generated_Column_Parser
	 */
	private static $column_parser;

	/**
	 * Initialize database utility configuration.
	 *
	 * @since 1.0.0
	 * @param string $db_type   Database type identifier.
	 * @param string $prefix    Table prefix.
	 * @param wpdb   $db_handle Database connection handle.
	 */
	public static function init( $db_type, $prefix, $db_handle ) {
		self::$db_type_id = $db_type;
		self::$prefix_raw = $prefix;
		self::$db_handle  = $db_handle;

		// Initialize specialized components lazily.
		self::$sorting_engine  = null;
		self::$mode_manager    = null;
		self::$schema_inspector = null;
		self::$column_parser   = null;
	}

	/**
	 * Get or create sorting engine instance.
	 *
	 * @since 1.0.0
	 * @return ROYALBR_DB_Sorting_Engine Sorting engine.
	 */
	private static function get_sorting_engine() {
		if ( is_null( self::$sorting_engine ) ) {
			self::$sorting_engine = new ROYALBR_DB_Sorting_Engine(
				self::$db_type_id,
				self::$prefix_raw,
				self::$db_handle
			);
		}
		return self::$sorting_engine;
	}

	/**
	 * Get or create SQL mode manager instance.
	 *
	 * @since 1.0.0
	 * @return ROYALBR_SQL_Mode_Manager Mode manager.
	 */
	private static function get_mode_manager() {
		if ( is_null( self::$mode_manager ) ) {
			self::$mode_manager = new ROYALBR_SQL_Mode_Manager();
		}
		return self::$mode_manager;
	}

	/**
	 * Get schema inspector singleton.
	 *
	 * @since 1.0.0
	 * @return ROYALBR_DB_Schema_Inspector Inspector instance.
	 */
	private static function get_schema_inspector() {
		if ( is_null( self::$schema_inspector ) ) {
			self::$schema_inspector = ROYALBR_DB_Schema_Inspector::get_instance();
		}
		return self::$schema_inspector;
	}

	/**
	 * Get or create column parser instance.
	 *
	 * @since 1.0.0
	 * @return ROYALBR_Generated_Column_Parser Parser instance.
	 */
	private static function get_column_parser() {
		if ( is_null( self::$column_parser ) ) {
			self::$column_parser = new ROYALBR_Generated_Column_Parser();
		}
		return self::$column_parser;
	}

	/**
	 * Compare two tables for backup ordering.
	 *
	 * Delegates to sorting engine to ensure proper table dependencies
	 * are respected during backup operations.
	 *
	 * @since 1.0.0
	 * @param array $first_table  First table with 'name' and 'type'.
	 * @param array $second_table Second table with 'name' and 'type'.
	 * @return int Comparison result.
	 */
	public static function sort_tables_for_backup( $first_table, $second_table ) {
		return self::get_sorting_engine()->compare_tables( $first_table, $second_table );
	}

	/**
	 * Check if table has composite primary key.
	 *
	 * @since 1.0.0
	 * @param string    $table    Table name.
	 * @param wpdb|null $wpdb_obj Optional wpdb instance.
	 * @return bool True if composite key exists.
	 */
	public static function check_composite_key_exists( $table, $wpdb_obj = null ) {
		return self::get_schema_inspector()->has_composite_primary_key( $table, $wpdb_obj );
	}

	/**
	 * Set MySQL session variable value.
	 *
	 * @since 1.0.0
	 * @param string          $variable_name Variable to set.
	 * @param string          $value         Value to assign.
	 * @param resource|object $db_handle     Raw database connection.
	 * @return bool Success status.
	 */
	public static function write_db_session_var( $variable_name, $value, $db_handle ) {
		return self::get_mode_manager()->write_session_variable( $variable_name, $value, $db_handle );
	}

	/**
	 * Get MySQL session variable value.
	 *
	 * @since 1.0.0
	 * @param string          $variable_name Variable to read.
	 * @param resource|object $db_handle     Raw database connection.
	 * @return string|null|false Variable value or false on error.
	 */
	public static function read_db_session_var( $variable_name, $db_handle ) {
		return self::get_mode_manager()->read_session_variable( $variable_name, $db_handle );
	}

	/**
	 * Configure SQL mode settings.
	 *
	 * @since 1.0.0
	 * @param array       $modes_to_add    Modes to enable.
	 * @param array       $modes_to_remove Modes to disable.
	 * @param wpdb|object $db_handle       Database connection.
	 * @return bool|void Success status.
	 */
	public static function configure_db_sql_mode( $modes_to_add = array(), $modes_to_remove = array(), $db_handle = null ) {
		return self::get_mode_manager()->configure_sql_mode( $modes_to_add, $modes_to_remove, $db_handle );
	}

	/**
	 * Check if INSERT statement contains generated columns.
	 *
	 * @since 1.0.0
	 * @param string $insert_statement  INSERT query to analyze.
	 * @param array  $generated_columns Known generated column names.
	 * @return bool|null True if found, false if not, null on parse failure.
	 */
	public static function check_insert_has_generated_cols( $insert_statement, $generated_columns ) {
		return self::get_column_parser()->contains_generated_columns( $insert_statement, $generated_columns );
	}

	/**
	 * Extract generated column definition details.
	 *
	 * @since 1.0.0
	 * @param string $column_definition Column definition to parse.
	 * @param int    $starting_offset   Position in CREATE TABLE statement.
	 * @return array|false Parsed data or false if not generated.
	 */
	public static function parse_generated_col_definition( $column_definition, $starting_offset ) {
		return self::get_column_parser()->extract_column_definition( $column_definition, $starting_offset );
	}

	/**
	 * Detect generated column support for storage engine.
	 *
	 * @since 1.0.0
	 * @param string $engine Storage engine name or empty for default.
	 * @return array|false Capability details or false if unsupported.
	 */
	public static function detect_generated_col_support( $engine = '' ) {
		return self::get_schema_inspector()->detect_generated_column_support( $engine );
	}

	/**
	 * Detect stored routine support.
	 *
	 * @since 1.0.0
	 * @return array|WP_Error Capability details or error.
	 */
	public static function detect_routine_support() {
		return self::get_schema_inspector()->detect_stored_routine_support();
	}

	/**
	 * Retrieve all stored routines from database.
	 *
	 * @since 1.0.0
	 * @return array|WP_Error Routine data or error.
	 */
	public static function fetch_all_routines() {
		return self::get_schema_inspector()->fetch_stored_routines();
	}
}

/**
 * WPDB OtherDB Utility Class
 *
 * Extended wpdb class for handling external database connections.
 * Used for restoring to different databases, multisite operations, etc.
 *
 * @since 1.0.0
 */
class ROYALBR_WPDB_OtherDB_Utility extends wpdb {
	/**
	 * Custom bail handler that logs errors instead of dying.
	 *
	 * @since 1.0.0
	 * @param string $message    Error message.
	 * @param string $error_code Error code.
	 * @return bool Always returns false.
	 */
	public function bail( $message, $error_code = '500' ) {
		if ( 'db_connect_fail' === $error_code ) {
			$message = 'Connection failed: check your access details, that the database server is up, and that the network connection is not firewalled.';
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "ROYALBR WPDB_OtherDB error: $message ($error_code)" );
		}

		$this->error = class_exists( 'WP_Error' ) ? new WP_Error( $error_code, $message ) : $message;
		return false;
	}
}
