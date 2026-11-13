<?php
/**
 * Rule: Rank Math: 404 monitor log
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans Rank Math: 404 monitor log data.
 */
class PLCLEAN_Rule_Rank_Math_404 extends PLCLEAN_Rule_Table {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'rank_math_404';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Rank Math: 404 monitor log', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes the 404 errors recorded by the Rank Math 404 monitor.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'your redirections and SEO settings', 'log-broom' );
	}

	/**
	 * Table specs.
	 *
	 * @return array[]
	 */
	protected function specs() {
		return array(
			array(
				'table'     => 'rank_math_404_logs',
				'where'     => '',
				'orphan_of' => null,
				'global'    => false,
			),
		);
	}
}
