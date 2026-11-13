<?php
/**
 * Rule: Redirection: 404 and redirect logs
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans Redirection: 404 and redirect logs data.
 */
class PLCLEAN_Rule_Redirection extends PLCLEAN_Rule_Table {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'redirection';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Redirection: 404 and redirect logs', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes the redirect hit log and the 404 error log of the Redirection plugin.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'your redirects', 'log-broom' );
	}

	/**
	 * Table specs.
	 *
	 * @return array[]
	 */
	protected function specs() {
		return array(
			array(
				'table'     => 'redirection_logs',
				'where'     => '',
				'orphan_of' => null,
				'global'    => false,
			),
			array(
				'table'     => 'redirection_404',
				'where'     => '',
				'orphan_of' => null,
				'global'    => false,
			),
		);
	}
}
