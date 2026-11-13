<?php
/**
 * Removes the plugin's options and scheduled events when it is deleted from the Plugins screen.
 *
 * Runs without the plugin loaded, so the names are literals (kept in sync with PLCLEAN_Settings,
 * PLCLEAN_Cleaner, PLCLEAN_Scheduler and PLCLEAN_Rule_Action_Scheduler). Cleaned log data is never restored and no other data is touched.
 *
 * @package Log_Broom
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove everything the plugin stored for the current site.
 */
function plclean_uninstall_site() {
	delete_option( 'plclean_settings' );
	delete_option( 'plclean_last_run' );
	delete_option( 'plclean_pending_optimize' );
	delete_option( 'plclean_run_deleted' );
	delete_option( 'plclean_last_error' );
	delete_option( 'plclean_run_errors' );
	delete_option( 'plclean_run_scope' );
	delete_option( 'plclean_idle_batches' );
	delete_transient( 'plclean_as_scan' );
	wp_clear_scheduled_hook( 'plclean_scheduled_clean' );
	wp_clear_scheduled_hook( 'plclean_scheduled_clean_continue' );
}

if ( is_multisite() ) {
	// Options and cron events are stored per site.
	$plclean_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $plclean_site_ids as $plclean_site_id ) {
		switch_to_blog( $plclean_site_id );
		plclean_uninstall_site();
		restore_current_blog();
	}
} else {
	plclean_uninstall_site();
}
