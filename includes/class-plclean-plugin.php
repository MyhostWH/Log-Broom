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
	 * Deactivation callback. Deactivated network-wide, it clears every site of the network, as uninstall.php does.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( ! is_multisite() || ! $network_wide ) {
			self::deactivate_site();
			return;
		}
		// Cron events and run state are stored per site.
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			self::deactivate_site();
			restore_current_blog();
		}
	}

	/**
	 * Clear the current site's events and run state.
	 *
	 * @return void
	 */
	private static function deactivate_site() {
		wp_clear_scheduled_hook( PLCLEAN_Scheduler::HOOK );
		wp_clear_scheduled_hook( PLCLEAN_Scheduler::CONTINUE_HOOK );
		// The interrupted run is abandoned: forget its tables waiting to be optimized, its running total, its errors
		// and the retry count.
		PLCLEAN_Scheduler::forget_state();
	}
}
