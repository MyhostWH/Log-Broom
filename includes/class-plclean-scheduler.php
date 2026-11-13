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
	 * Option holding the last automatic run's database errors: array( 'time' => int, 'message' => string,
	 * 'attempts' => int, 'rules' => array( rule id => message ) ). Not autoloaded; removed by the next run that
	 * completes without errors, by turning automatic cleaning off, on deactivation and on uninstall.
	 */
	const LAST_ERROR_OPTION = 'plclean_last_error';

	/**
	 * Option holding, for the run in progress (across continuation parts), the rules that hit a database error:
	 * array( rule id => message ). Those rules are not tried again before the run completes.
	 */
	const RUN_ERRORS_OPTION = 'plclean_run_errors';

	/**
	 * Option holding the rule ids a retry run in progress is limited to (the rules that failed). Absent for a full
	 * run.
	 */
	const RUN_SCOPE_OPTION = 'plclean_run_scope';

	/** Seconds before the first retry after a database error; each further retry waits three times longer. */
	const RETRY_DELAY = 300;

	/** Longest wait between retries, in seconds. */
	const MAX_RETRY_DELAY = 21600;

	/** Seconds before a run that ran out of time continues. */
	const CONTINUE_DELAY = 60;

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
			// Turning automatic cleaning off also cancels a pending continuation or retry and forgets its partial run.
			wp_clear_scheduled_hook( self::CONTINUE_HOOK );
			self::forget_state();
		}
	}

	/**
	 * Forget the run in progress and the last run's errors (with their retry count).
	 *
	 * @return void
	 */
	public static function forget_state() {
		delete_option( PLCLEAN_Cleaner::PENDING_OPTIMIZE_OPTION );
		delete_option( self::RUN_DELETED_OPTION );
		delete_option( self::RUN_ERRORS_OPTION );
		delete_option( self::RUN_SCOPE_OPTION );
		delete_option( self::LAST_ERROR_OPTION );
	}

	/**
	 * Seconds to wait before retry number $attempt (1 for the first): 5, 15, 45 and 135 minutes, then every 6 hours.
	 *
	 * @param int $attempt Failed runs in a row, at least 1.
	 * @return int
	 */
	public static function retry_delay( $attempt ) {
		$delay = self::RETRY_DELAY;
		for ( $i = 1; $i < (int) $attempt && $delay < self::MAX_RETRY_DELAY; $i++ ) {
			$delay *= 3;
		}
		return (int) min( $delay, self::MAX_RETRY_DELAY );
	}

	/**
	 * The last automatic run's database errors, if no run has completed without errors since.
	 *
	 * @return array|null array( 'time' => int, 'message' => string, 'attempts' => int, 'rules' => array( rule id =>
	 *                    message ) ) or null. 'rules' is empty for an error stored by 1.0.1. The messages are plain
	 *                    text; escape them.
	 */
	public static function get_last_error() {
		$value = get_option( self::LAST_ERROR_OPTION, null );
		if ( ! is_array( $value ) || ! isset( $value['time'], $value['message'] ) || ! is_scalar( $value['message'] ) || '' === (string) $value['message'] ) {
			return null;
		}
		return array(
			'time'     => (int) $value['time'],
			'message'  => (string) $value['message'],
			'attempts' => isset( $value['attempts'] ) && is_scalar( $value['attempts'] ) ? absint( $value['attempts'] ) : 0,
			'rules'    => self::rule_messages( isset( $value['rules'] ) ? $value['rules'] : array() ),
		);
	}

	/**
	 * A stored list of rule id => message, with anything else dropped.
	 *
	 * @param mixed $value Stored value.
	 * @return array
	 */
	private static function rule_messages( $value ) {
		$out = array();
		if ( ! is_array( $value ) ) {
			return $out;
		}
		foreach ( $value as $id => $message ) {
			if ( is_string( $id ) && sanitize_key( $id ) === $id && '' !== $id && is_scalar( $message ) ) {
				$out[ $id ] = PLCLEAN_Cleaner::error_text( $message );
			}
		}
		return $out;
	}

	/**
	 * Cron callback of the recurring event: clean every enabled rule (a full run).
	 *
	 * @return void
	 */
	public static function handle() {
		// A full run covers the rules a retry in progress was limited to.
		delete_option( self::RUN_SCOPE_OPTION );
		self::run();
	}

	/**
	 * Cron callback of the follow-up event: continue the run in progress, or retry the rules that failed.
	 *
	 * @return void
	 */
	public static function handle_continue() {
		if ( false === get_option( self::RUN_DELETED_OPTION, false ) ) {
			// No run in progress: this is a retry. Only the rules that failed are cleaned.
			$error = self::get_last_error();
			if ( $error && array() !== $error['rules'] ) {
				update_option( self::RUN_SCOPE_OPTION, array_keys( $error['rules'] ), false );
			} else {
				delete_option( self::RUN_SCOPE_OPTION );
			}
		}
		self::run();
	}

	/**
	 * Clean the enabled rules (or, for a retry, the enabled rules that failed).
	 *
	 * When the time budget runs out, a follow-up event continues the work in a minute. Each part adds its deletes to
	 * a running total (RUN_DELETED_OPTION, not autoloaded).
	 *
	 * A rule that hits a database error is set aside for the rest of the run (RUN_ERRORS_OPTION) and the other rules
	 * are still cleaned. The run completes when every rule is done or failed: the last-run record gets the total of
	 * all its parts (a retry adds its total to the run it retries). If rules failed, they are stored
	 * (LAST_ERROR_OPTION) with the number of failed runs in a row of the same sources, and only they are retried,
	 * after retry_delay(). A run that completes without errors clears them.
	 *
	 * @return void
	 */
	private static function run() {
		$settings = PLCLEAN_Settings::get();
		// A leftover event must never clean while automatic cleaning is off.
		if ( 'off' === $settings['schedule'] ) {
			return;
		}
		$rules = $settings['enabled_rules'];
		$scope = get_option( self::RUN_SCOPE_OPTION, null );
		if ( is_array( $scope ) ) {
			$rules = array_values( array_intersect( $rules, array_map( 'strval', array_filter( $scope, 'is_scalar' ) ) ) );
		}
		$errors = self::rule_messages( get_option( self::RUN_ERRORS_OPTION, array() ) );
		$rules  = array_values( array_diff( $rules, array_keys( $errors ) ) );

		$result = ( new PLCLEAN_Cleaner() )->run_all( $rules );
		$total  = absint( get_option( self::RUN_DELETED_OPTION, 0 ) ) + (int) $result['deleted'];
		if ( isset( $result['errors'] ) ) {
			$errors = array_merge( $errors, $result['errors'] );
		}

		if ( ! $result['done'] ) {
			update_option( self::RUN_DELETED_OPTION, $total, false );
			if ( array() !== $errors ) {
				update_option( self::RUN_ERRORS_OPTION, $errors, false );
			}
			$next = wp_next_scheduled( self::CONTINUE_HOOK );
			if ( false !== $next && $next > time() + self::CONTINUE_DELAY ) {
				// A retry waiting for later must not hold up this run.
				wp_clear_scheduled_hook( self::CONTINUE_HOOK );
				$next = false;
			}
			if ( false === $next ) {
				wp_schedule_single_event( time() + self::CONTINUE_DELAY, self::CONTINUE_HOOK );
			}
			return;
		}

		if ( is_array( $scope ) ) {
			// A retry is part of the same error episode: add to the run it retries, so a retry (failing or not) never
			// replaces that run's total with its own, smaller one.
			$previous = PLCLEAN_Settings::get_last_run();
			$total   += $previous ? (int) $previous['deleted'] : 0;
		}
		PLCLEAN_Settings::set_last_run( time(), $total );
		delete_option( self::RUN_DELETED_OPTION );
		delete_option( self::RUN_ERRORS_OPTION );
		delete_option( self::RUN_SCOPE_OPTION );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
		if ( array() === $errors ) {
			delete_option( self::LAST_ERROR_OPTION );
			return;
		}

		// The back-off continues only while a source that failed last time fails again; a source failing for the first
		// time starts at the shortest wait.
		$last     = self::get_last_error();
		$again    = $last && array() !== array_intersect_key( $errors, $last['rules'] );
		$attempts = $again ? $last['attempts'] + 1 : 1;
		update_option(
			self::LAST_ERROR_OPTION,
			array(
				'time'     => time(),
				'message'  => reset( $errors ),
				'attempts' => $attempts,
				'rules'    => $errors,
			),
			false
		);
		wp_schedule_single_event( time() + self::retry_delay( $attempts ), self::CONTINUE_HOOK );
	}
}
