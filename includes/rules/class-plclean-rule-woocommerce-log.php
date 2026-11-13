<?php
/**
 * Rule: WooCommerce: database log
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans WooCommerce: database log data.
 */
class PLCLEAN_Rule_WooCommerce_Log extends PLCLEAN_Rule_Table {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'woocommerce_log';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'WooCommerce: database log', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes the log entries WooCommerce stores in its database table. Only used when WooCommerce log storage is set to Database.', 'log-broom' );
	}

	/**
	 * Note under the table list: WooCommerce logs are usually files, which this plugin does not touch.
	 *
	 * @return string
	 */
	public function tables_note() {
		return __( 'WooCommerce stores logs as files by default (wp-content/uploads/wc-logs). Log Broom cleans the database only. Manage log files in WooCommerce → Status → Logs.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'orders, products and settings', 'log-broom' );
	}

	/**
	 * Table specs.
	 *
	 * @return array[]
	 */
	protected function specs() {
		return array(
			array(
				'table'     => 'woocommerce_log',
				'where'     => '',
				'orphan_of' => null,
				'global'    => false,
			),
		);
	}
}
