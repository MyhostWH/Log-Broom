<?php
/**
 * Rule: Wordfence: traffic, login and scan logs
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans Wordfence: traffic, login and scan logs data.
 */
class PLCLEAN_Rule_Wordfence extends PLCLEAN_Rule_Table {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wordfence';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Wordfence: traffic, login and scan logs', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes the Wordfence live traffic, login attempt, scan activity and scan scratch data tables.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'Wordfence settings, blocked IPs and 2FA', 'log-broom' );
	}

	/**
	 * Table specs.
	 *
	 * @return array[]
	 */
	protected function specs() {
		return array(
			array(
				'table'     => 'wfhits',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'wfstatus',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'wflogins',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'wfhoover',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'wfleechers',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'wfnet404s',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'wfvulnscanners',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
		);
	}
}
