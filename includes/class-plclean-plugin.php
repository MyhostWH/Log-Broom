<?php
/**
 * Main plugin class.
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap and singleton.
 */
class PLCLEAN_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var PLCLEAN_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return PLCLEAN_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. Translations load automatically for WordPress.org plugins.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( PLCLEAN_Scheduler::HOOK, array( 'PLCLEAN_Scheduler', 'handle' ) );
		add_action( PLCLEAN_Scheduler::CONTINUE_HOOK, array( 'PLCLEAN_Scheduler', 'handle_continue' ) );

		if ( is_admin() ) {
			( new PLCLEAN_Admin() )->register();
		}
	}

	/**
	 * Activation callback: restore the automatic cleaning event that deactivation removed.
	 *
	 * The backup acknowledgement was given when the schedule was turned on, so a stored daily or weekly
	 * schedule is scheduled again; 'off' schedules nothing.
	 *
	 * @return void
	 */
	public static function activate() {
		$settings = PLCLEAN_Settings::get();
		PLCLEAN_Scheduler::sync( $settings['schedule'] );
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( PLCLEAN_Scheduler::HOOK );
		wp_clear_scheduled_hook( PLCLEAN_Scheduler::CONTINUE_HOOK );
		// The interrupted run is abandoned: forget its tables waiting to be optimized, its running total, its errors
		// and the retry count.
		PLCLEAN_Scheduler::forget_state();
	}
}
