<?php
/**
 * ROYALBR Database Schema Inspector
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database Schema Feature Inspector.
 *
 * Detects database server capabilities including generated columns,
 * stored routines, and retrieves schema information. Uses singleton
 * pattern with caching for performance.
 *
 * @since 1.0.0
 */
class ROYALBR_DB_Schema_Inspector {

	use ROYALBR_SQL_Helpers;

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var   ROYALBR_DB_Schema_Inspector
	 */
	private static $singleton_instance = null;

	/**
	 * Cached feature detection results.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	private $capability_cache = array();

	/**
	 * Private constructor for singleton.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Singleton pattern - prevent direct instantiation.
	}

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 * @return ROYALBR_DB_Schema_Inspector Inspector instance.
	 */
	public static function get_instance() {
		if ( is_null( self::$singleton_instance ) ) {
			self::$singleton_instance = new self();
		}
		return self::$singleton_instance;
	}

	/**
	 * Detect generated column support for storage engine.
	 *
	 * @since 1.0.0
	 * @param string $storage_engine Storage engine name or empty for default.
	 * @return array|false Capability details or false if unsupported.
	 */
	public function detect_generated_column_support( $storage_engine = '' ) {
		global $table_prefix, $wpdb;

		$cache_key = 'generated_column_' . $storage_engine;
		if ( isset( $this->capability_cache[ $cache_key ] ) ) {
			return $this->capability_cache[ $cache_key ];
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$temp_table_name = $table_prefix . 'royalbr_tmp_' . wp_rand( 0, 9999999 ) . md5( microtime( true ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with sanitized temporary table name for capability testing.
		$cleanup_sql = "DROP TABLE IF EXISTS `$temp_table_name`;";

		$test_queries = array(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with sanitized temporary table name for capability testing.
			"CREATE TABLE `$temp_table_name` (`virtual_column` varchar(17) GENERATED ALWAYS AS ('virtual_column') VIRTUAL COMMENT 'virtual_column')" . ( ! empty( $storage_engine ) ? " ENGINE=$storage_engine" : '' ) . ';',
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with sanitized temporary table name for capability testing.
			"ALTER TABLE `$temp_table_name` ADD `persistent_column` VARCHAR(17) AS ('persistent_column') PERSISTENT COMMENT 'generated_column';",
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with sanitized temporary table name for capability testing.
			"ALTER TABLE `$temp_table_name` ADD `virtual_column_not_null` VARCHAR(17) AS ('virtual_column_not_null') VIRTUAL NOT NULL COMMENT 'virtual_column_not_null';",
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with sanitized temporary table name for capability testing.
			"INSERT IGNORE INTO `$temp_table_name` (`virtual_column`) VALUES('virtual_column');",
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with sanitized temporary table name for capability testing.
			"CREATE INDEX `idx_wp_royalbr_generated_column_test_generated_column` ON `$temp_table_name` (virtual_column) COMMENT 'virtual_column' ALGORITHM DEFAULT LOCK DEFAULT;",
		);

		$previous_error_suppression = $wpdb->suppress_errors();
		$wpdb->query( $cleanup_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$base_support               = $wpdb->query( $test_queries[0] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $base_support ) {
			$base_support = array(
				'is_persistent_supported'               => $wpdb->query( $test_queries[1] ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'is_not_null_supported'                 => $wpdb->query( $test_queries[2] ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'can_insert_ignore_to_generated_column' => (bool) $wpdb->query( $test_queries[3] ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'is_virtual_index_supported'            => $wpdb->query( $test_queries[4] ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		} else {
			$base_support = false;
		}

		$wpdb->query( $cleanup_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $previous_error_suppression );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$this->capability_cache[ $cache_key ] = $base_support;
		return $base_support;
	}

	/**
	 * Detect stored routine (function/procedure) support.
	 *
	 * @since 1.0.0
	 * @return array|WP_Error Capability details or error.
	 */
	public function detect_stored_routine_support() {
		global $wpdb;

		if ( isset( $this->capability_cache['stored_routine'] ) ) {
			return $this->capability_cache['stored_routine'];
		}

		$test_function_name = 'royalbr_test_stored_routine';
		$test_queries       = array(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with hardcoded function name for capability testing.
			'DROP_FUNCTION'                 => 'DROP FUNCTION IF EXISTS ' . $test_function_name,
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with hardcoded function name for capability testing.
			'CREATE_FUNCTION'               => "CREATE FUNCTION $test_function_name() RETURNS tinyint(1) DETERMINISTIC READS SQL DATA RETURN true",
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with hardcoded function name for capability testing.
			'CREATE_REPLACE_FUNCTION'       => "CREATE OR REPLACE FUNCTION $test_function_name() RETURNS tinyint(1) DETERMINISTIC READS SQL DATA RETURN true",
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with hardcoded function name for capability testing.
			'CREATE_FUNCTION_IF_NOT_EXISTS' => "CREATE FUNCTION IF NOT EXISTS $test_function_name() RETURNS tinyint(1) DETERMINISTIC READS SQL DATA RETURN true",
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL statement with hardcoded function name for capability testing.
			'CREATE_REPLACE_AGGREGATE'      => "CREATE OR REPLACE AGGREGATE FUNCTION $test_function_name() RETURNS tinyint(1) DETERMINISTIC READS SQL DATA BEGIN RETURN true; FETCH GROUP NEXT ROW; END;",
		);

		$previous_suppression = $wpdb->suppress_errors();
		$wpdb->query( $test_queries['DROP_FUNCTION'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$support_detected     = $wpdb->query( $test_queries['CREATE_FUNCTION'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $support_detected ) {
			$binary_logging_status = 1 === $wpdb->get_var( 'SELECT @@GLOBAL.log_bin' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( false === $binary_logging_status ) {
				$binary_logging_status = $wpdb->get_results( "SHOW GLOBAL VARIABLES LIKE 'log_bin'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

			if ( is_array( $binary_logging_status ) && isset( $binary_logging_status[0]['Value'] ) && '' !== $binary_logging_status[0]['Value'] ) {
				$binary_logging_status = $binary_logging_status[0]['Value'];
			}

			if ( is_string( $binary_logging_status ) ) {
				$upper_value = strtoupper( $binary_logging_status );
				if ( 'ON' === $upper_value || '1' === $binary_logging_status ) {
					$binary_logging_status = true;
				} elseif ( 'OFF' === $upper_value || '0' === $binary_logging_status ) {
					$binary_logging_status = false;
				}
			}

			$support_detected = array(
				'is_create_or_replace_supported'     => $wpdb->query( $test_queries['CREATE_REPLACE_FUNCTION'] ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'is_if_not_exists_function_supported' => $wpdb->query( $test_queries['CREATE_FUNCTION_IF_NOT_EXISTS'] ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'is_aggregate_function_supported'    => $wpdb->query( $test_queries['CREATE_REPLACE_AGGREGATE'] ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'is_binary_logging_enabled'          => $binary_logging_status,
				'is_function_creators_trusted'       => 1 === $wpdb->get_var( 'SELECT @@GLOBAL.log_bin_trust_function_creators' ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			);
			$wpdb->query( $test_queries['DROP_FUNCTION'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			/* translators: 1: Last database error, 2: SQL create function statement. */
			$support_detected = new WP_Error( 'routine_creation_error', sprintf( esc_html__( 'An error occurred while attempting to check the support of stored routines creation (%1$s %2$s)', 'royal-backup-reset' ), esc_html( $wpdb->last_error . ' -' ), esc_html( $test_queries['CREATE_FUNCTION'] ) ) );
		}

		$wpdb->suppress_errors( $previous_suppression );

		$this->capability_cache['stored_routine'] = $support_detected;
		return $support_detected;
	}

	/**
	 * Retrieve all stored routines from database.
	 *
	 * @since 1.0.0
	 * @return array|WP_Error Routine data or error.
	 */
	public function fetch_stored_routines() {
		global $wpdb;

		$previous_suppression = $wpdb->suppress_errors();
		try {
			/* translators: 1: Last database error, 2: Additional error details. */
			$error_template  = __( 'An error occurred while attempting to retrieve routine status (%1$s %2$s)', 'royal-backup-reset' );
			$function_list   = $wpdb->get_results( $wpdb->prepare( 'SHOW FUNCTION STATUS WHERE DB = %s', DB_NAME ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! empty( $wpdb->last_error ) ) {
				throw new Exception( sprintf( $error_template, $wpdb->last_error . ' -', $wpdb->last_query ), 0 );
			}
			$procedure_list  = $wpdb->get_results( $wpdb->prepare( 'SHOW PROCEDURE STATUS WHERE DB = %s', DB_NAME ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! empty( $wpdb->last_error ) ) {
				throw new Exception( sprintf( $error_template, $wpdb->last_error . ' -', $wpdb->last_query ), 0 );
			}
			$all_routines = array_merge( (array) $function_list, (array) $procedure_list );

			foreach ( (array) $all_routines as $index => $routine_info ) {
				if ( empty( $routine_info['Name'] ) || empty( $routine_info['Type'] ) ) {
					continue;
				}

				$routine_identifier      = $routine_info['Name'];
				$escaped_routine_name    = self::backquote( str_replace( '`', '``', $routine_identifier ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$routine_definition      = $wpdb->get_results( $wpdb->prepare( 'SHOW CREATE %1$s %2$s', $routine_info['Type'], $escaped_routine_name ), ARRAY_A );
				if ( ! empty( $wpdb->last_error ) ) {
					/* translators: 1: Last database error, 2: Last executed SQL query. */
					throw new Exception( sprintf( __( 'An error occurred while attempting to retrieve the routine SQL/DDL statement (%1$s %2$s)', 'royal-backup-reset' ), $wpdb->last_error . ' -', $wpdb->last_query ), 1 );
				}
				$all_routines[ $index ] = array_merge( $all_routines[ $index ], $routine_definition ? $routine_definition[0] : array() );
			}
		} catch ( Exception $exception ) {
			$all_routines = new WP_Error( 1 === $exception->getCode() ? 'routine_sql_error' : 'routine_status_error', $exception->getMessage() );
		}

		$wpdb->suppress_errors( $previous_suppression );

		return $all_routines;
	}

	/**
	 * Check if table has composite primary key.
	 *
	 * @since 1.0.0
	 * @param string    $table_name Table to check.
	 * @param wpdb|null $wpdb_obj   Optional wpdb instance.
	 * @return bool True if composite key exists.
	 */
	public function has_composite_primary_key( $table_name, $wpdb_obj = null ) {
		$wpdb = ( null === $wpdb_obj ) ? $GLOBALS['wpdb'] : $wpdb_obj;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_schema = $wpdb->get_results( 'DESCRIBE ' . self::backquote( $table_name ) );
		if ( ! $table_schema ) {
			return false;
		}

		$primary_key_count = 0;

		foreach ( $table_schema as $column_info ) {
			if ( isset( $column_info->Key ) && 'PRI' === $column_info->Key ) {
				$primary_key_count++;
				if ( $primary_key_count > 1 ) {
					return true;
				}
			}
		}

		return false;
	}
}
