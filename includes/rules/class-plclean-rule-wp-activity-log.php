<?php
/**
 * Rule: WP Activity Log: event log
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans WP Activity Log: event log data.
 */
class PLCLEAN_Rule_WP_Activity_Log extends PLCLEAN_Rule_Table {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wp_activity_log';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'WP Activity Log: event log', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes the recorded events and their details from the WP Activity Log tables.', 'log-broom' );
	}

	/**
	 * What the plugin cleans by itself (shown in Details).
	 *
	 * @return string
	 */
	public function builtin_note() {
		return __( 'WP Activity Log can delete old events itself (its retention setting). Log Broom removes them regardless of that setting.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'activity log settings', 'log-broom' );
	}

	/**
	 * Table specs.
	 *
	 * @return array[]
	 */
	protected function specs() {
		return array(
			array(
				'table'     => 'wsal_occurrences',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'wsal_metadata',
				'where'     => '',
				'orphan_of' => array( 'wsal_occurrences', 'occurrence_id', 'id' ),
				'global'    => true,
			),
		);
	}
}
