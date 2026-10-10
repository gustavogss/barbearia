<?php
/**
 * ROYALBR Database Sorting Engine
 *
 * @package RoyalBackupReset
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database Table Sorting Engine.
 *
 * Handles intelligent table ordering for backup operations to ensure
 * dependencies are respected during restore (e.g., views after tables,
 * WordPress core tables in proper order).
 *
 * @since 1.0.0
 */
class ROYALBR_DB_Sorting_Engine {

	use ROYALBR_SQL_Helpers;

	/**
	 * Database type identifier.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private $database_type;

	/**
	 * Raw table prefix for identification.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private $prefix_raw;

	/**
	 * Database connection handle.
	 *
	 * @since 1.0.0
	 * @var   wpdb
	 */
	private $db_connection;

	/**
	 * Priority table names in backup order.
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	private $priority_tables = array();

	/**
	 * Core table names (without prefix).
	 *
	 * @since 1.0.0
	 * @var   array
	 */
	private $core_table_names = array();

	/**
	 * Constructor - Initialize sorting engine.
	 *
	 * @since 1.0.0
	 * @param string $db_type      Database type identifier.
	 * @param string $prefix       Table prefix.
	 * @param wpdb   $db_handle    Database connection.
	 */
	public function __construct( $db_type, $prefix, $db_handle ) {
		$this->database_type = $db_type;
		$this->prefix_raw    = $prefix;
		$this->db_connection = $db_handle;

		$this->initialize_priority_order();
		$this->initialize_core_tables();
	}

	/**
	 * Set up priority table ordering.
	 *
	 * @since 1.0.0
	 */
	private function initialize_priority_order() {
		$prefix = $this->prefix_raw;

		$this->priority_tables = array(
			$prefix . 'options',
			$prefix . 'site',
			$prefix . 'blogs',
			$prefix . 'users',
			$prefix . 'usermeta',
		);
	}

	/**
	 * Initialize core WordPress table list.
	 *
	 * @since 1.0.0
	 */
	private function initialize_core_tables() {
		try {
			$this->core_table_names = array_merge(
				$this->db_connection->tables,
				$this->db_connection->global_tables,
				$this->db_connection->ms_global_tables
			);
		} catch ( Exception $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'ROYALBR DB Sorting Engine: ' . $exception->getMessage() );
			}
		}

		if ( empty( $this->core_table_names ) ) {
			$this->core_table_names = array(
				'terms',
				'term_taxonomy',
				'termmeta',
				'term_relationships',
				'commentmeta',
				'comments',
				'links',
				'postmeta',
				'posts',
				'site',
				'sitemeta',
				'blogs',
				'blogversions',
				'blogmeta',
			);
		}
	}

	/**
	 * Compare two tables for backup ordering.
	 *
	 * @since 1.0.0
	 * @param array $first_table  First table data with 'name' and 'type'.
	 * @param array $second_table Second table data with 'name' and 'type'.
	 * @return int Comparison result (-1, 0, 1).
	 */
	public function compare_tables( $first_table, $second_table ) {
		$first_name       = $first_table['name'];
		$first_table_type = $first_table['type'];
		$second_name      = $second_table['name'];
		$second_type      = $second_table['type'];

		// Views must follow tables due to dependencies.
		if ( 'VIEW' === $first_table_type && 'VIEW' !== $second_type ) {
			return 1;
		}
		if ( 'VIEW' === $second_type && 'VIEW' !== $first_table_type ) {
			return -1;
		}

		// Non-WordPress databases use simple alphabetical ordering.
		if ( 'wp' !== $this->database_type ) {
			return strcmp( $first_name, $second_name );
		}

		if ( $first_name === $second_name ) {
			return 0;
		}

		// Check priority table ordering.
		$first_priority  = $this->get_table_priority( $first_name );
		$second_priority = $this->get_table_priority( $second_name );

		if ( false !== $first_priority || false !== $second_priority ) {
			if ( false === $second_priority ) {
				return -1;
			}
			if ( false === $first_priority ) {
				return 1;
			}
			return $first_priority - $second_priority;
		}

		// Empty prefix fallback to alphabetical.
		if ( empty( $this->prefix_raw ) ) {
			return strcmp( $first_name, $second_name );
		}

		// Separate core tables from custom tables.
		$first_stripped  = self::replace_first_occurrence( $this->prefix_raw, '', $first_name );
		$second_stripped = self::replace_first_occurrence( $this->prefix_raw, '', $second_name );
		$first_is_core   = in_array( $first_stripped, $this->core_table_names, true );
		$second_is_core  = in_array( $second_stripped, $this->core_table_names, true );

		if ( $first_is_core && ! $second_is_core ) {
			return -1;
		}
		if ( ! $first_is_core && $second_is_core ) {
			return 1;
		}

		return strcmp( $first_name, $second_name );
	}

	/**
	 * Get priority order for a table (lower = earlier).
	 *
	 * @since 1.0.0
	 * @param string $table_name Table name to check.
	 * @return int|false Priority index or false if not priority.
	 */
	private function get_table_priority( $table_name ) {
		$position = array_search( $table_name, $this->priority_tables, true );
		return false !== $position ? $position : false;
	}
}
