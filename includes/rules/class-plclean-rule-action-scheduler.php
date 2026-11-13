<?php
/**
 * Rule: Action Scheduler: finished actions and logs
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans Action Scheduler: finished actions and logs data.
 */
class PLCLEAN_Rule_Action_Scheduler extends PLCLEAN_Rule_Table {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'action_scheduler';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Action Scheduler: finished actions and logs', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes completed, canceled and failed scheduled actions and their log entries.', 'log-broom' );
	}

	/**
	 * Note shown in Details once nothing is left to clean.
	 *
	 * @return string
	 */
	public function cleaned_note() {
		return __( 'Cleaned. Space on disk is given back when tables are optimized. The size includes pending tasks, which are kept.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'pending tasks', 'log-broom' );
	}

	/**
	 * Table specs.
	 *
	 * @return array[]
	 */
	protected function specs() {
		return array(
			array(
				'table'     => 'actionscheduler_actions',
				'where'     => "`status` IN ('complete','canceled','failed')",
				'orphan_of' => null,
				'global'    => false,
			),
			array(
				'table'     => 'actionscheduler_logs',
				'where'     => '',
				'orphan_of' => array( 'actionscheduler_actions', 'action_id', 'action_id' ),
				'global'    => false,
			),
		);
	}
}
