<?php
/**
 * Task Scheduler for Royal Backup Reset
 *
 * Handles scheduling-related code for backup resumptions.
 *
 * @package Royal_Backup_Reset
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ROYALBR_Task_Scheduler
 *
 * Manages WP-Cron based backup resumptions for handling large backups
 * that would otherwise timeout.
 */
class ROYALBR_Task_Scheduler {

	/**
	 * Record that PHP is still running.
	 *
	 * Updates the record of maximum detected runtime on each run.
	 * Also checks if the resumption interval is being approached.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function record_still_alive() {
		global $royalbr_instance;

		if ( empty( $royalbr_instance ) || empty( $royalbr_instance->opened_log_time ) ) {
			return;
		}

		// Update the record of maximum detected runtime on each run.
		$time_passed = $royalbr_instance->retrieve_task_data( 'run_times' );
		if ( ! is_array( $time_passed ) ) {
			$time_passed = array();
		}

		$time_this_run = microtime( true ) - $royalbr_instance->opened_log_time;
		$time_passed[ $royalbr_instance->current_resumption ] = $time_this_run;
		$royalbr_instance->save_task_data( 'run_times', $time_passed );

		$resume_interval = $royalbr_instance->retrieve_task_data( 'resume_interval' );
		if ( $time_this_run + 30 > $resume_interval ) {
			$new_interval = ceil( $time_this_run + 30 );
			set_site_transient( 'royalbr_initial_resume_interval', (int) $new_interval, 8 * 86400 );
			$royalbr_instance->write_to_log( "The time we have been running (" . round( $time_this_run, 1 ) . ") is approaching the resumption interval ($resume_interval) - increasing resumption interval to $new_interval" );
			$royalbr_instance->save_task_data( 'resume_interval', $new_interval );
		}
	}

	/**
	 * Check if rescheduling is needed based on time to next resumption.
	 *
	 * If the scheduled resumption is within 45 seconds, reschedule to avoid overlap.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function reschedule_if_needed() {
		global $royalbr_instance;

		// If nothing is scheduled, then no re-scheduling is needed.
		if ( empty( $royalbr_instance->newresumption_scheduled ) ) {
			return;
		}

		$time_away = $royalbr_instance->newresumption_scheduled - time();

		// 45 is chosen because it is 15 seconds more than what is used to detect recent activity.
		if ( $time_away > 1 && $time_away <= 45 ) {
			$royalbr_instance->write_to_log( 'The scheduled resumption is within 45 seconds - will reschedule' );
			// Increase interval generally by 45 seconds.
			self::increase_resume_and_reschedule( 45 );
		}
	}

	/**
	 * Indicate that something useful happened.
	 *
	 * Calling this at appropriate times is important for scheduling decisions.
	 * It records the progress and schedules next resumption if needed.
	 * Also refreshes the semaphore lock to prevent timeout during long operations.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function something_useful_happened() {
		global $royalbr_instance;

		if ( empty( $royalbr_instance ) ) {
			return;
		}

		self::record_still_alive();

		// Refresh the semaphore lock to prevent timeout during long operations.
		if ( ! empty( $royalbr_instance->semaphore ) && is_object( $royalbr_instance->semaphore ) && method_exists( $royalbr_instance->semaphore, 'refresh_lock' ) ) {
			$royalbr_instance->semaphore->refresh_lock();
		}

		if ( ! $royalbr_instance->something_useful_happened ) {
			// Update the record of when something useful happened.
			$useful_checkins = $royalbr_instance->retrieve_task_data( 'useful_checkins' );
			if ( ! is_array( $useful_checkins ) ) {
				$useful_checkins = array();
			}
			if ( ! in_array( $royalbr_instance->current_resumption, $useful_checkins, true ) ) {
				$useful_checkins[] = $royalbr_instance->current_resumption;
				$royalbr_instance->save_task_data( 'useful_checkins', $useful_checkins );
			}
		}

		$royalbr_instance->something_useful_happened = true;

		// Check for abort request.
		$backup_dir = defined( 'ROYALBR_BACKUP_DIR' ) ? ROYALBR_BACKUP_DIR : '';
		if ( ! empty( $backup_dir ) && file_exists( $backup_dir . 'deleteflag-' . $royalbr_instance->file_nonce . '.txt' ) ) {
			$royalbr_instance->write_to_log( 'User request for abort: backup task will be immediately halted' );
			@unlink( $backup_dir . 'deleteflag-' . $royalbr_instance->file_nonce . '.txt' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$royalbr_instance->backup_finish( true, true );
			die;
		}

		// Schedule next resumption if we're past resumption 9 and no new resumption is scheduled.
		if ( $royalbr_instance->current_resumption >= 9 && false === $royalbr_instance->newresumption_scheduled ) {
			$royalbr_instance->write_to_log( 'This is resumption ' . $royalbr_instance->current_resumption . ', but meaningful activity is still taking place; so a new one will be scheduled' );
			// Use max to ensure we get a number.
			$resume_interval = max( $royalbr_instance->retrieve_task_data( 'resume_interval' ), 75 );
			$schedule_for    = time() + $resume_interval;
			$royalbr_instance->newresumption_scheduled = $schedule_for;
			wp_schedule_single_event( $schedule_for, 'royalbr_backup_resume', array( $royalbr_instance->current_resumption + 1, $royalbr_instance->file_nonce ) );
		} else {
			self::reschedule_if_needed();
		}
	}

	/**
	 * Reschedule the next resumption.
	 *
	 * @since 1.0.0
	 * @param int $how_far_ahead Number of seconds until next resumption (minimum 60).
	 * @return void
	 */
	public static function reschedule( $how_far_ahead ) {
		global $royalbr_instance;

		if ( empty( $royalbr_instance ) ) {
			return;
		}

		// Reschedule - remove presently scheduled event.
		$next_resumption = $royalbr_instance->current_resumption + 1;
		wp_clear_scheduled_hook( 'royalbr_backup_resume', array( $next_resumption, $royalbr_instance->file_nonce ) );

		// Add new event with minimum 60 seconds.
		if ( $how_far_ahead < 60 ) {
			$how_far_ahead = 60;
		}

		$schedule_for = time() + $how_far_ahead;
		$royalbr_instance->write_to_log( "Rescheduling resumption $next_resumption: moving to $how_far_ahead seconds from now ($schedule_for)" );
		wp_schedule_single_event( $schedule_for, 'royalbr_backup_resume', array( $next_resumption, $royalbr_instance->file_nonce ) );
		$royalbr_instance->newresumption_scheduled = $schedule_for;
	}

	/**
	 * Terminate a backup run because other activity was detected.
	 *
	 * @since 1.0.0
	 * @param string $file                Indicates the file whose modification indicates activity.
	 * @param int    $time_now            Epoch time when detection occurred.
	 * @param int    $time_mod            Epoch time when file was modified.
	 * @param bool   $increase_resumption Whether to increase the resumption interval.
	 * @return void
	 */
	public static function terminate_due_to_activity( $file, $time_now, $time_mod, $increase_resumption = true ) {
		global $royalbr_instance;

		if ( empty( $royalbr_instance ) ) {
			return;
		}

		// Check-in to avoid 'no check in last time!' detectors firing.
		self::record_still_alive();

		// Log the termination.
		$file_size = file_exists( $file ) ? round( filesize( $file ) / 1024, 1 ) . 'KB' : 'n/a';
		$royalbr_instance->write_to_log( 'Terminate: ' . basename( $file ) . " exists with activity within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=" . ( floor( $time_now - $time_mod ) ) . ", size=$file_size). This likely means that another Royal Backup run is at work; so we will exit." );

		$increase_by = $increase_resumption ? 120 : 0;
		self::increase_resume_and_reschedule( $increase_by, true );

		// Die unless there is a deliberate over-ride.
		if ( ! defined( 'ROYALBR_ALLOW_RECENT_ACTIVITY' ) || ! ROYALBR_ALLOW_RECENT_ACTIVITY ) {
			die;
		}
	}

	/**
	 * Increase the resumption interval and reschedule the next resumption.
	 *
	 * @since 1.0.0
	 * @param int  $howmuch       How much to add to existing resumption interval.
	 * @param bool $due_to_overlap Whether the increase is due to overlap detection.
	 * @return void
	 */
	private static function increase_resume_and_reschedule( $howmuch = 120, $due_to_overlap = false ) {
		global $royalbr_instance;

		if ( empty( $royalbr_instance ) ) {
			return;
		}

		$resume_interval = max( (int) $royalbr_instance->retrieve_task_data( 'resume_interval' ), ( 0 === $howmuch ) ? 120 : 300 );

		if ( empty( $royalbr_instance->newresumption_scheduled ) && $due_to_overlap ) {
			$royalbr_instance->write_to_log( 'A new resumption will be scheduled to prevent the task ending' );
		}

		$new_resume = $resume_interval + $howmuch;

		// Check if we already know the new value will be insufficient.
		if ( $royalbr_instance->opened_log_time > 100 && microtime( true ) - $royalbr_instance->opened_log_time > $new_resume ) {
			$new_resume = ceil( microtime( true ) - $royalbr_instance->opened_log_time ) + 45;
			$howmuch    = $new_resume - $resume_interval;
		}

		// Limit how far ahead we schedule in case of overlap.
		$how_far_ahead = $due_to_overlap ? min( $new_resume, 900 ) : $new_resume;

		// For early resumptions with very long intervals, try sooner.
		if ( $royalbr_instance->current_resumption <= 1 && $new_resume > 720 ) {
			$how_far_ahead = 600;
		}

		if ( ! empty( $royalbr_instance->newresumption_scheduled ) || $due_to_overlap ) {
			self::reschedule( $how_far_ahead );
		}

		$royalbr_instance->save_task_data( 'resume_interval', $new_resume );
		$royalbr_instance->write_to_log( "To decrease the likelihood of overlaps, increasing resumption interval to: $resume_interval + $howmuch = $new_resume" );
	}
}
