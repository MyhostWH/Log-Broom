<?php
/**
 * Rule: Solid Security: logs and old lockouts
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans the Solid Security event log, old attempt counters and old lockouts.
 */
class PLCLEAN_Rule_Solid_Security extends PLCLEAN_Rule_Table {

	/**
	 * Solid Security's default "Days to Remember Lockouts".
	 */
	const DEFAULT_BLACKLIST_PERIOD = 7;

	/**
	 * Upper bound for the expired-lockout retention, in days.
	 */
	const MAX_LOCKOUT_DAYS = 3650;

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'solid_security';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Solid Security: logs and old lockouts', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes the whole Solid Security event log, attempt counters older than one day, and lockouts that expired longer ago than the "Days to Remember Lockouts" period plus one day. Attempt counters and lockouts follow the same retention as Solid Security\'s own cleanup.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'active lockouts, recent lockouts used for repeat-offender bans, attempt counters from the last day, bans', 'log-broom' );
	}

	/**
	 * Days an expired lockout is kept: Solid Security's "Days to Remember Lockouts" (blacklist_period) plus one,
	 * as in its own purge_lockouts(). Repeat-offender bans count the expired lockouts in that window.
	 *
	 * Solid Security stores its settings in the `itsec-storage` network option (get_site_option(), which is the
	 * plain option on a single site). A missing or invalid value falls back to Solid Security's default of 7.
	 *
	 * @return int Between 2 and MAX_LOCKOUT_DAYS.
	 */
	public static function lockout_retention_days() {
		$period  = self::DEFAULT_BLACKLIST_PERIOD;
		$storage = get_site_option( 'itsec-storage' );
		if ( is_array( $storage ) && isset( $storage['global'] ) && is_array( $storage['global'] ) && isset( $storage['global']['blacklist_period'] ) ) {
			$value = $storage['global']['blacklist_period'];
			// Only a positive whole number counts (absint() alone would turn -5 into 5).
			if ( ( is_int( $value ) && $value > 0 ) || ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ) ) {
				$period = absint( $value );
			}
		}
		return min( self::MAX_LOCKOUT_DAYS, $period + 1 );
	}

	/**
	 * Table specs.
	 *
	 * @return array[]
	 */
	protected function specs() {
		$days = absint( self::lockout_retention_days() );
		return array(
			array(
				'table'     => 'itsec_logs',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'itsec_temp',
				// Attempt counters for lockout thresholds; Solid Security keeps one day of them.
				'where'     => '`temp_date_gmt` < UTC_TIMESTAMP() - INTERVAL 1 DAY',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'itsec_lockouts',
				// $days is an int from absint(), so the fragment stays static SQL.
				'where'     => '`lockout_expire_gmt` < UTC_TIMESTAMP() - INTERVAL ' . $days . ' DAY',
				'orphan_of' => null,
				'global'    => true,
			),
		);
	}
}
