<?php
/**
 * Plugin Name:       Log Broom
 * Description:       Safely clean up log tables and clutter that plugins leave behind in your WordPress database.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            MyHost
 * Author URI:        https://myhost.co.il/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       log-broom
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

define( 'PLCLEAN_VERSION', '1.0.0' );
define( 'PLCLEAN_FILE', __FILE__ );
define( 'PLCLEAN_DIR', plugin_dir_path( __FILE__ ) );

require_once PLCLEAN_DIR . 'includes/class-plclean-db.php';
require_once PLCLEAN_DIR . 'includes/rules/abstract-class-plclean-rule.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-table.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-woocommerce-log.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-redirection.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-rank-math-404.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-wp-mail-smtp.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-wp-activity-log.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-wordfence.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-action-scheduler.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-solid-security.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-revisions.php';
require_once PLCLEAN_DIR . 'includes/rules/class-plclean-rule-transients.php';
require_once PLCLEAN_DIR . 'includes/class-plclean-registry.php';
require_once PLCLEAN_DIR . 'includes/class-plclean-settings.php';
require_once PLCLEAN_DIR . 'includes/class-plclean-cleaner.php';
require_once PLCLEAN_DIR . 'includes/class-plclean-scheduler.php';
require_once PLCLEAN_DIR . 'includes/admin/class-plclean-admin.php';
require_once PLCLEAN_DIR . 'includes/class-plclean-plugin.php';

register_activation_hook( PLCLEAN_FILE, array( 'PLCLEAN_Plugin', 'activate' ) );
register_deactivation_hook( PLCLEAN_FILE, array( 'PLCLEAN_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( PLCLEAN_Plugin::instance(), 'boot' ) );
