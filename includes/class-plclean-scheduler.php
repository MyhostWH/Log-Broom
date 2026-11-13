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
	 * Replace the recurring event according to the schedule setting.
	 *
	 * @param string $schedule off|daily|weekly.
	 * @return void
	 */
	public static function sync( $schedule ) {
		wp_clear_scheduled_hook( self::HOOK );
		if ( in_array( $schedule, array( 'daily', 'weekly' ), true ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::HOOK );
		} else {
			// Turning automatic cleaning off also cancels a pending continuation and forgets its partial run.
			wp_clear_scheduled_hook( self::CONTINUE_HOOK );
			delete_option( PLCLEAN_Cleaner::PENDING_OPTIMIZE_OPTION );
		}
	}

	/**
	 * Cron callback: clean the enabled rules.
	 *
	 * When the time budget runs out, a single follow-up event continues the work. The last-run record is
	 * written when a run completes and holds the rows deleted by that final run only (earlier continuation
	 * runs are not accumulated).
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

		if ( $result['done'] ) {
			PLCLEAN_Settings::set_last_run( time(), $result['deleted'] );
		} elseif ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::CONTINUE_HOOK );
		}
	}
}
