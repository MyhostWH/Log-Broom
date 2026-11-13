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
		return __( 'Deletes Wordfence live traffic older than 24 hours, failed login attempts and the scan activity log. Scan results and scan working data are never touched.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'Wordfence settings, blocked IPs, 2FA, successful logins, the last 24 hours of traffic and all scan results and scan working data', 'log-broom' );
	}

	/**
	 * Table specs.
	 *
	 * Not listed, on purpose:
	 * - wfHoover: scan working data. A running scan collects URLs there across requests and checks them at the end;
	 *   clearing it mid-scan would make the scan skip them silently. Wordfence empties it itself.
	 * - wfLeechers, wfNet404s, wfVulnScanners: deprecated, dropped by Wordfence 7.3.1 and later.
	 *
	 * @return array[]
	 */
	protected function specs() {
		return array(
			array(
				'table'     => 'wfhits',
				// ctime is unix seconds (a double). The last 24 hours stay: the firewall sends recent attack data
				// from this table to Wordfence later.
				'where'     => '`ctime` < UNIX_TIMESTAMP() - 86400',
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
				// Successful logins mark the IPs Wordfence trusts for users whose password is in a breach list.
				'where'     => "`action` NOT IN ('loginOK','loginPasskeyOK')",
				'orphan_of' => null,
				'global'    => true,
			),
		);
	}
}
