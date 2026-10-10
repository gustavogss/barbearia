<?php
/**
 * Semaphore Lock Management for Royal Backup Reset
 *
 * Prevents concurrent backup operations using database-based locks.
 *
 * @package Royal_Backup_Reset
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ROYALBR_Semaphore
 *
 * Database-based semaphore lock to prevent concurrent backup runs.
 */
class ROYALBR_Semaphore {

	/**
	 * Whether the lock was broken due to being stuck.
	 *
	 * @var bool
	 */
	protected $lock_broke = false;

	/**
	 * Name of this lock.
	 *
	 * @var string
	 */
	public $lock_name = 'lock';

	/**
	 * Lock timeout in seconds.
	 *
	 * Extended timeout for large backup operations
	 * to prevent premature lock release during long zip close operations.
	 * Default 600s (10 minutes) for general operations.
	 *
	 * @var int
	 */
	public $lock_timeout = 600;

	/**
	 * Factory method to create a new semaphore instance.
	 *
	 * @since 1.0.0
	 * @param int $timeout Optional lock timeout in seconds (default 180).
	 * @return ROYALBR_Semaphore
	 */
	public static function factory( $timeout = 180 ) {
		$instance = new self();
		$instance->lock_timeout = $timeout;
		return $instance;
	}

	/**
	 * Attempts to acquire the lock.
	 *
	 * Uses atomic database UPDATE to rename option from unlocked to locked.
	 *
	 * @since 1.0.0
	 * @return bool True if lock acquired, false otherwise.
	 */
	public function lock() {
		global $wpdb, $royalbr_instance;

		// Attempt to set the lock via atomic UPDATE.
		$affected = $wpdb->query(
			"UPDATE $wpdb->options
			SET option_name = 'royalbr_locked_" . esc_sql( $this->lock_name ) . "'
			WHERE option_name = 'royalbr_unlocked_" . esc_sql( $this->lock_name ) . "'"
		);

		if ( '0' == $affected && ! $this->stuck_check() ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			if ( ! empty( $royalbr_instance ) ) {
				$royalbr_instance->write_to_log( 'Semaphore lock (' . $this->lock_name . ', ' . $wpdb->options . ') failed (line ' . __LINE__ . ')' );
			}
			return false;
		}

		// Check to see if all processes are complete.
		$affected = $wpdb->query(
			"UPDATE $wpdb->options
			SET option_value = CAST(option_value AS UNSIGNED) + 1
			WHERE option_name = 'royalbr_semaphore_" . esc_sql( $this->lock_name ) . "'
			AND option_value = '0'"
		);

		if ( '1' != $affected ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			if ( ! $this->stuck_check() ) {
				if ( ! empty( $royalbr_instance ) ) {
					$royalbr_instance->write_to_log( 'Semaphore lock (' . $this->lock_name . ', ' . $wpdb->options . ') failed (line ' . __LINE__ . ')' );
				}
				return false;
			}

			// Reset the semaphore to 1.
			$wpdb->query(
				"UPDATE $wpdb->options
				SET option_value = '1'
				WHERE option_name = 'royalbr_semaphore_" . esc_sql( $this->lock_name ) . "'"
			);

			if ( ! empty( $royalbr_instance ) ) {
				$royalbr_instance->write_to_log( 'Semaphore (' . $this->lock_name . ', ' . $wpdb->options . ') reset to 1' );
			}
		}

		// Set the lock time.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $wpdb->options
				SET option_value = %s
				WHERE option_name = 'royalbr_last_lock_time_" . esc_sql( $this->lock_name ) . "'",
				current_time( 'mysql', 1 )
			)
		);

		if ( ! empty( $royalbr_instance ) ) {
			$royalbr_instance->write_to_log( 'Set semaphore last lock (' . $this->lock_name . ') time to ' . current_time( 'mysql', 1 ) );
			$royalbr_instance->write_to_log( 'Semaphore lock (' . $this->lock_name . ') complete' );
		}

		return true;
	}

	/**
	 * Ensure semaphore options exist in database.
	 *
	 * @since 1.0.0
	 * @param string $semaphore Name of the semaphore.
	 * @return void
	 */
	public static function ensure_semaphore_exists( $semaphore ) {
		global $wpdb, $royalbr_instance;

		// Check if semaphore options exist.
		$results = $wpdb->get_results(
			"SELECT option_id
			FROM $wpdb->options
			WHERE option_name IN (
				'royalbr_locked_" . esc_sql( $semaphore ) . "',
				'royalbr_unlocked_" . esc_sql( $semaphore ) . "',
				'royalbr_last_lock_time_" . esc_sql( $semaphore ) . "',
				'royalbr_semaphore_" . esc_sql( $semaphore ) . "'
			)"
		);

		if ( ! is_array( $results ) || count( $results ) < 3 ) {
			if ( is_array( $results ) && count( $results ) > 0 ) {
				if ( ! empty( $royalbr_instance ) ) {
					$royalbr_instance->write_to_log( 'Semaphore (' . $semaphore . ', ' . $wpdb->options . ') in an impossible/broken state - fixing (' . count( $results ) . ')' );
				}
			} else {
				if ( ! empty( $royalbr_instance ) ) {
					$royalbr_instance->write_to_log( 'Semaphore (' . $semaphore . ', ' . $wpdb->options . ') being initialised' );
				}
			}

			// Delete existing and recreate.
			$wpdb->query(
				"DELETE FROM $wpdb->options
				WHERE option_name IN (
					'royalbr_locked_" . esc_sql( $semaphore ) . "',
					'royalbr_unlocked_" . esc_sql( $semaphore ) . "',
					'royalbr_last_lock_time_" . esc_sql( $semaphore ) . "',
					'royalbr_semaphore_" . esc_sql( $semaphore ) . "'
				)"
			);

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $wpdb->options (option_name, option_value, autoload)
					VALUES
					('royalbr_unlocked_" . esc_sql( $semaphore ) . "', '1', 'no'),
					('royalbr_last_lock_time_" . esc_sql( $semaphore ) . "', %s, 'no'),
					('royalbr_semaphore_" . esc_sql( $semaphore ) . "', '0', 'no')",
					current_time( 'mysql', 1 )
				)
			);
		}
	}

	/**
	 * Increment the semaphore counter.
	 *
	 * @since 1.0.0
	 * @param array $filters Optional filters for increment.
	 * @return ROYALBR_Semaphore
	 */
	public function increment( array $filters = array() ) {
		global $wpdb, $royalbr_instance;

		if ( count( $filters ) ) {
			// Loop through filters and increment.
			foreach ( $filters as $priority ) {
				for ( $i = 0, $j = count( $priority ); $i < $j; ++$i ) {
					$this->increment();
				}
			}
		} else {
			$wpdb->query(
				"UPDATE $wpdb->options
				SET option_value = CAST(option_value AS UNSIGNED) + 1
				WHERE option_name = 'royalbr_semaphore_" . esc_sql( $this->lock_name ) . "'"
			);
			if ( ! empty( $royalbr_instance ) ) {
				$royalbr_instance->write_to_log( 'Incremented the semaphore (' . $this->lock_name . ') by 1' );
			}
		}

		return $this;
	}

	/**
	 * Decrement the semaphore counter.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function decrement() {
		global $wpdb, $royalbr_instance;

		$wpdb->query(
			"UPDATE $wpdb->options
			SET option_value = CAST(option_value AS UNSIGNED) - 1
			WHERE option_name = 'royalbr_semaphore_" . esc_sql( $this->lock_name ) . "'
			AND CAST(option_value AS UNSIGNED) > 0"
		);

		if ( ! empty( $royalbr_instance ) ) {
			$royalbr_instance->write_to_log( 'Decremented the semaphore (' . $this->lock_name . ') by 1' );
		}
	}

	/**
	 * Unlock the semaphore.
	 *
	 * @since 1.0.0
	 * @return bool True if unlocked successfully, false otherwise.
	 */
	public function unlock() {
		global $wpdb, $royalbr_instance;

		// Decrement for the master process.
		$this->decrement();

		$result = $wpdb->query(
			"UPDATE $wpdb->options
			SET option_name = 'royalbr_unlocked_" . esc_sql( $this->lock_name ) . "'
			WHERE option_name = 'royalbr_locked_" . esc_sql( $this->lock_name ) . "'"
		);

		if ( '1' == $result ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			if ( ! empty( $royalbr_instance ) ) {
				$royalbr_instance->write_to_log( 'Semaphore (' . $this->lock_name . ') unlocked' );
			}
			return true;
		}

		if ( ! empty( $royalbr_instance ) ) {
			$royalbr_instance->write_to_log( 'Semaphore (' . $this->lock_name . ', ' . $wpdb->options . ') still locked (' . $result . ')' );
		}
		return false;
	}

	/**
	 * Check if lock is stuck and break it if necessary.
	 *
	 * A lock is considered stuck if it's older than 3 minutes.
	 *
	 * @since 1.0.0
	 * @return bool True if lock was broken or already broken, false otherwise.
	 */
	private function stuck_check() {
		global $wpdb, $royalbr_instance;

		// Check if we already broke the lock.
		if ( $this->lock_broke ) {
			return true;
		}

		$current_time = current_time( 'mysql', 1 );
		// Use instance timeout, fallback to constant, then default 180s.
		$lock_wait = $this->lock_timeout;
		if ( defined( 'ROYALBR_SEMAPHORE_LOCK_WAIT' ) ) {
			$lock_wait = ROYALBR_SEMAPHORE_LOCK_WAIT;
		}
		$timeout_before = gmdate( 'Y-m-d H:i:s', time() - $lock_wait );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $wpdb->options
				SET option_value = %s
				WHERE option_name = 'royalbr_last_lock_time_" . esc_sql( $this->lock_name ) . "'
				AND option_value <= %s",
				$current_time,
				$timeout_before
			)
		);

		if ( '1' == $affected ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			if ( ! empty( $royalbr_instance ) ) {
				$royalbr_instance->write_to_log( 'Semaphore (' . $this->lock_name . ', ' . $wpdb->options . ') was stuck, set lock time to ' . $current_time );
			}
			$this->lock_broke = true;
			return true;
		}

		// Check if lock is greater than 24 hours.
		$last_lock_time = strtotime( get_option( 'royalbr_last_lock_time_' . $this->lock_name, $current_time ) );
		$next_day       = strtotime( $current_time . ' +1 day' );
		if ( $last_lock_time > $next_day ) {
			$this->lock_broke = true;
			return true;
		}

		return false;
	}

	/**
	 * Remove the lock from database.
	 *
	 * @since 1.0.0
	 * @return bool True if lock was removed, false otherwise.
	 */
	public function delete_lock() {
		global $wpdb;

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				$this->lock_name
			)
		);
	}

	/**
	 * Refresh the lock time to prevent timeout during long operations.
	 *
	 * Call this method periodically during long-running operations
	 * (like ZipArchive::close()) to prevent the semaphore from being
	 * considered stuck and broken by another process.
	 *
	 * @since 1.0.0
	 * @return bool True if lock was refreshed, false otherwise.
	 */
	public function refresh_lock() {
		global $wpdb, $royalbr_instance;

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s",
				current_time( 'mysql', 1 ),
				'royalbr_last_lock_time_' . esc_sql( $this->lock_name )
			)
		);

		if ( $affected && ! empty( $royalbr_instance ) ) {
			$royalbr_instance->write_to_log( 'Refreshed semaphore lock time (' . $this->lock_name . ') to ' . current_time( 'mysql', 1 ) );
		}

		return (bool) $affected;
	}
}
