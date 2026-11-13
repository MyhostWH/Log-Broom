<?php
/**
 * WP-Cron scheduler.
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and runs automatic cleaning.
 */
class PLCLEAN_Scheduler {

	const HOOK          = 'plclean_scheduled_clean';
	const CONTINUE_HOOK = 'plclean_scheduled_clean_continue';

	/**
	 * Option holding the rows deleted so far by the scheduled run in progress (across continuation parts).
	 */
	const RUN_DELETED_OPTION = 'plclean_run_deleted';

	/**
	 * Option holding the last automatic run's database error, array( 'time' => int, 'message' => string ).
	 * Not autoloaded; removed by the next completed run.
	 */
	const LAST_ERROR_OPTION = 'plclean_last_error';

	/** Seconds before an automatic run that hit a database error tries again. */
	const RETRY_DELAY = 300;

	/**
	 * Replace the recurring event according to the schedule setting. An event that already has the wanted
	 * recurrence is left alone, so saving other settings does not move the next run.
	 *
	 * @param string $schedule off|daily|weekly.
	 * @return void
	 */
	public static function sync( $schedule ) {
		if ( in_array( $schedule, array( 'daily', 'weekly' ), true ) ) {
			$event = wp_get_scheduled_event( self::HOOK );
			if ( $event && $schedule === $event->schedule ) {
				return;
			}
			wp_clear_scheduled_hook( self::HOOK );
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::HOOK );
		} else {
			wp_clear_scheduled_hook( self::HOOK );
			// Turning automatic cleaning off also cancels a pending continuation and forgets its partial run.
			wp_clear_scheduled_hook( self::CONTINUE_HOOK );
			delete_option( PLCLEAN_Cleaner::PENDING_OPTIMIZE_OPTION );
			delete_option( self::RUN_DELETED_OPTION );
			delete_option( self::LAST_ERROR_OPTION );
		}
	}

	/**
	 * The last automatic run's database error, if it has not completed since.
	 *
	 * @return array|null array( 'time' => int, 'message' => string ) or null. The message is plain text; escape it.
	 */
	public static function get_last_error() {
		$value = get_option( self::LAST_ERROR_OPTION, null );
		if ( ! is_array( $value ) || ! isset( $value['time'], $value['message'] ) || ! is_scalar( $value['message'] ) || '' === (string) $value['message'] ) {
			return null;
		}
		return array(
			'time'    => (int) $value['time'],
			'message' => (string) $value['message'],
		);
	}

	/**
	 * Cron callback: clean the enabled rules.
	 *
	 * When the time budget runs out, a single follow-up event continues the work. Each part adds its deletes to
	 * a running total (RUN_DELETED_OPTION, not autoloaded). When the run completes, the last-run record gets the
	 * total of all its parts and the running total is removed.
	 *
	 * A database error is not a completed run: the error is stored (LAST_ERROR_OPTION) for the admin page, no
	 * last run is recorded, and the run continues in RETRY_DELAY seconds unless a continuation is already
	 * scheduled. The next completed run clears the error.
	 *
	 * @return void
	 */
	public static function handle() {
		$settings = PLCLEAN_Settings::get();
		// A leftover event must never clean while automatic cleaning is off.
		if ( 'off' === $settings['schedule'] ) {
			return;
		}
		$result = ( new PLCLEAN_Cleaner() )->run_all( $settings['enabled_rules'] );
		$total  = absint( get_option( self::RUN_DELETED_OPTION, 0 ) ) + (int) $result['deleted'];

		if ( $result['done'] ) {
			PLCLEAN_Settings::set_last_run( time(), $total );
			delete_option( self::RUN_DELETED_OPTION );
			delete_option( self::LAST_ERROR_OPTION );
			return;
		}
		update_option( self::RUN_DELETED_OPTION, $total, false );

		$delay = 60;
		if ( isset( $result['error'] ) ) {
			$delay = self::RETRY_DELAY;
			update_option(
				self::LAST_ERROR_OPTION,
				array(
					'time'    => time(),
					'message' => PLCLEAN_Cleaner::error_text( $result['error'] ),
				),
				false
			);
		}
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CONTINUE_HOOK );
		}
	}
}
