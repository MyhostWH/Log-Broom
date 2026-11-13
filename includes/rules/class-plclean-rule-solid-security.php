<?php
/**
 * Rule: Solid Security: logs and expired lockouts
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans Solid Security: logs and expired lockouts data.
 */
class PLCLEAN_Rule_Solid_Security extends PLCLEAN_Rule_Table {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'solid_security';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Solid Security: logs and expired lockouts', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes the Solid Security event log, temporary attempt records and lockouts that have already expired.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'active lockouts', 'log-broom' );
	}

	/**
	 * Table specs.
	 *
	 * @return array[]
	 */
	protected function specs() {
		return array(
			array(
				'table'     => 'itsec_logs',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'itsec_temp',
				'where'     => '',
				'orphan_of' => null,
				'global'    => true,
			),
			array(
				'table'     => 'itsec_lockouts',
				'where'     => '`lockout_expire_gmt` < UTC_TIMESTAMP()',
				'orphan_of' => null,
				'global'    => true,
			),
		);
	}
}
