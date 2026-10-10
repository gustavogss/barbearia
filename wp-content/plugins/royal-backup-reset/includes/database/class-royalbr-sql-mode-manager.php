<?php
/**
 * ROYALBR SQL Mode Manager
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SQL Mode Configuration Manager.
 *
 * Manages MySQL/MariaDB SQL modes for backup/restore operations,
 * ensuring compatibility across different server configurations and
 * removing problematic strict modes.
 *
 * @since 1.0.0
 */
class ROYALBR_SQL_Mode_Manager {

	/**
	 * Strict SQL modes that may cause issues.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	private $strict_mode_list = array(
		'STRICT_TRANS_TABLES',
		'STRICT_ALL_TABLES',
	);

	/**
	 * Incompatible SQL modes to remove.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	private $problematic_modes = array();

	/**
	 * Constructor - Initialize mode manager.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->problematic_modes = array_unique(
			array_merge(
				array(
					'NO_ZERO_DATE',
					'ONLY_FULL_GROUP_BY',
					'TRADITIONAL',
				),
				$this->strict_mode_list
			)
		);
	}

	/**
	 * Configure SQL mode with additions and removals.
	 *
	 * @since 1.0.0
	 * @param array       $modes_to_add    Modes to include.
	 * @param array       $modes_to_remove Additional modes to exclude.
	 * @param wpdb|object $connection      Database connection handle.
	 * @return bool|void True on success, void on invalid input.
	 */
	public function configure_sql_mode( $modes_to_add = array(), $modes_to_remove = array(), $connection = null ) {
		global $wpdb;

		$wpdb_instance = ( null !== $connection && is_a( $connection, 'WPDB' ) ) ? $connection : $wpdb;

		$current_modes = $this->fetch_current_modes( $connection, $wpdb_instance );

		if ( ! is_string( $current_modes ) || '' === $current_modes && false === $current_modes ) {
			return;
		}

		$mode_array = $this->parse_mode_string( $current_modes );
		$mode_array = array_unique( array_merge( $modes_to_add, $mode_array ) );
		$mode_array = array_change_key_case( $mode_array, CASE_UPPER );

		$exclusion_list = array_merge( $this->problematic_modes, $modes_to_remove );

		foreach ( $mode_array as $index => $mode_name ) {
			if ( in_array( $mode_name, $exclusion_list, true ) ) {
				unset( $mode_array[ $index ] );
			}
		}

		$final_mode_string = implode( ',', $mode_array );

		$this->apply_sql_mode( $final_mode_string, $connection, $wpdb_instance );
	}

	/**
	 * Retrieve current SQL mode setting.
	 *
	 * @since 1.0.0
	 * @param wpdb|object $connection      Raw connection or wpdb instance.
	 * @param wpdb        $wpdb_instance   WPDB instance.
	 * @return string|false Current mode string or false on failure.
	 */
	private function fetch_current_modes( $connection, $wpdb_instance ) {
		if ( is_null( $connection ) || is_a( $connection, 'WPDB' ) ) {
			return $wpdb_instance->get_var( 'SELECT @@SESSION.sql_mode' );
		} else {
			return $this->read_session_variable( 'sql_mode', $connection );
		}
	}

	/**
	 * Parse mode string into array.
	 *
	 * @since 1.0.0
	 * @param string $mode_string Comma-separated modes.
	 * @return array Mode names in uppercase.
	 */
	private function parse_mode_string( $mode_string ) {
		return array_change_key_case( explode( ',', $mode_string ), CASE_UPPER );
	}

	/**
	 * Apply SQL mode configuration.
	 *
	 * @since 1.0.0
	 * @param string      $mode_string   New mode configuration.
	 * @param wpdb|object $connection    Database connection.
	 * @param wpdb        $wpdb_instance WPDB instance.
	 * @return bool Success status.
	 */
	private function apply_sql_mode( $mode_string, $connection, $wpdb_instance ) {
		if ( is_null( $connection ) || is_a( $connection, 'WPDB' ) ) {
			return $wpdb_instance->query( $wpdb_instance->prepare( 'SET SESSION sql_mode = %s', $mode_string ) );
		} else {
			return $this->write_session_variable( 'sql_mode', $mode_string, $connection );
		}
	}

	/**
	 * Write value to MySQL session variable.
	 *
	 * @since 1.0.0
	 * @param string        $variable_name Variable to set.
	 * @param string        $value         Value to assign.
	 * @param resource|object $db_handle   Raw database connection.
	 * @return bool Success status.
	 */
	public function write_session_variable( $variable_name, $value, $db_handle ) {
		$uses_mysqli = is_a( $db_handle, 'mysqli' );
		if ( ! is_resource( $db_handle ) && ! $uses_mysqli ) {
			return false;
		}

		$query_template = "SET SESSION %s='%s'";
		if ( $uses_mysqli ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query, WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string
			$query_result = @mysqli_query( $db_handle, sprintf( $query_template, mysqli_real_escape_string( $db_handle, $variable_name ), mysqli_real_escape_string( $db_handle, $value ) ) );
		} else {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysql_query, WordPress.DB.RestrictedFunctions.mysql_mysql_real_escape_string
			$query_result = @mysql_query( sprintf( $query_template, mysql_real_escape_string( $variable_name, $db_handle ), mysql_real_escape_string( $value, $db_handle ) ), $db_handle );
		}

		return $query_result;
	}

	/**
	 * Read value from MySQL session variable.
	 *
	 * @since 1.0.0
	 * @param string        $variable_name Variable to read.
	 * @param resource|object $db_handle   Raw database connection.
	 * @return string|null|false Variable value or false on error.
	 */
	public function read_session_variable( $variable_name, $db_handle ) {
		$uses_mysqli = is_a( $db_handle, 'mysqli' );
		if ( ! is_resource( $db_handle ) && ! $uses_mysqli ) {
			return false;
		}

		$query_template = 'SELECT @@SESSION.%s';

		if ( $uses_mysqli ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query, WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string
			$query_result = @mysqli_query( $db_handle, sprintf( $query_template, mysqli_real_escape_string( $db_handle, $variable_name ) ) );
		} else {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysql_query, WordPress.DB.RestrictedFunctions.mysql_mysql_real_escape_string
			$query_result = @mysql_query( sprintf( $query_template, mysql_real_escape_string( $variable_name, $db_handle ) ), $db_handle );
		}

		if ( false === $query_result ) {
			return $query_result;
		}

		if ( $uses_mysqli ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_array
			$result_row = mysqli_fetch_array( $query_result );
			return isset( $result_row[0] ) ? $result_row[0] : null;
		} else {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysql_result
			$result_value = mysql_result( $query_result, 0 );
			return false === $result_value ? null : $result_value;
		}
	}
}
