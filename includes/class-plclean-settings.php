<?php
/**
 * Plugin settings storage.
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitizes and saves settings and the last-run record.
 */
class PLCLEAN_Settings {

	const OPTION          = 'plclean_settings';
	const LAST_RUN_OPTION = 'plclean_last_run';

	/** Fixed batch size: default, smallest and largest. */
	const BATCH_DEFAULT = 5000;
	const BATCH_MIN     = 1000;
	const BATCH_MAX     = 100000;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled_rules' => array(),
			'schedule'      => 'off',
			'optimize'      => false,
			'batch_mode'    => 'auto',
			'batch_size'    => self::BATCH_DEFAULT,
		);
	}

	/**
	 * Whitelist the batch mode.
	 *
	 * @param mixed $mode Raw value.
	 * @return string auto|fixed.
	 */
	public static function sanitize_batch_mode( $mode ) {
		return is_string( $mode ) && 'fixed' === $mode ? 'fixed' : 'auto';
	}

	/**
	 * Clamp the fixed batch size to [1000, 100000].
	 *
	 * @param mixed $size Raw value.
	 * @return int
	 */
	public static function sanitize_batch_size( $size ) {
		$size = is_scalar( $size ) ? absint( $size ) : 0;
		return max( self::BATCH_MIN, min( self::BATCH_MAX, $size ) );
	}

	/**
	 * Current settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );

		$out['enabled_rules'] = is_array( $out['enabled_rules'] ) ? array_values( $out['enabled_rules'] ) : array();
		$out['optimize']      = (bool) $out['optimize'];
		$out['batch_mode']    = self::sanitize_batch_mode( $out['batch_mode'] );
		$out['batch_size']    = self::sanitize_batch_size( $out['batch_size'] );
		return $out;
	}

	/**
	 * Sanitize and save settings.
	 *
	 * The batch settings (batch_mode, batch_size) keep their stored values when the input leaves them out.
	 *
	 * @param array $input Raw input.
	 * @return array The saved settings.
	 */
	public static function save( array $input ) {
		$rules = isset( $input['enabled_rules'] ) ? (array) $input['enabled_rules'] : array();
		$rules = array_map( 'strval', array_filter( $rules, 'is_scalar' ) );
		$rules = array_values( array_intersect( array_unique( $rules ), array_keys( PLCLEAN_Registry::all() ) ) );

		$schedule = isset( $input['schedule'] ) && is_string( $input['schedule'] ) ? $input['schedule'] : 'off';
		if ( ! in_array( $schedule, array( 'off', 'daily', 'weekly' ), true ) ) {
			$schedule = 'off';
		}

		$stored = self::get();

		$settings = array(
			'enabled_rules' => $rules,
			'schedule'      => $schedule,
			'optimize'      => ! empty( $input['optimize'] ),
			'batch_mode'    => array_key_exists( 'batch_mode', $input ) ? self::sanitize_batch_mode( $input['batch_mode'] ) : $stored['batch_mode'],
			'batch_size'    => array_key_exists( 'batch_size', $input ) ? self::sanitize_batch_size( $input['batch_size'] ) : $stored['batch_size'],
		);

		// Not autoloaded: only needed on our own page and in cron.
		update_option( self::OPTION, $settings, false );
		return $settings;
	}

	/**
	 * Save only the batch settings, keeping everything else as stored. They change how fast entries are
	 * deleted, never which entries, so they need no backup acknowledgement.
	 *
	 * @param mixed $mode Batch mode.
	 * @param mixed $size Fixed batch size.
	 * @return array The saved settings.
	 */
	public static function save_batch( $mode, $size ) {
		$settings               = self::get();
		$settings['batch_mode'] = self::sanitize_batch_mode( $mode );
		$settings['batch_size'] = self::sanitize_batch_size( $size );
		update_option( self::OPTION, $settings, false );
		return $settings;
	}

	/**
	 * Whether the manual (fixed) batch size is chosen.
	 *
	 * @return bool
	 */
	public static function is_fixed_batch() {
		return 'fixed' === self::get()['batch_mode'];
	}

	/**
	 * Last completed run.
	 *
	 * @return array|null array( 'time' => int, 'deleted' => int ) or null.
	 */
	public static function get_last_run() {
		$value = get_option( self::LAST_RUN_OPTION, null );
		if ( ! is_array( $value ) || ! isset( $value['time'], $value['deleted'] ) ) {
			return null;
		}
		return array(
			'time'    => (int) $value['time'],
			'deleted' => (int) $value['deleted'],
		);
	}

	/**
	 * Record a completed run.
	 *
	 * @param int $time    Unix time.
	 * @param int $deleted Rows deleted.
	 * @return void
	 */
	public static function set_last_run( $time, $deleted ) {
		update_option(
			self::LAST_RUN_OPTION,
			array(
				'time'    => (int) $time,
				'deleted' => (int) $deleted,
			),
			false
		);
	}
}
