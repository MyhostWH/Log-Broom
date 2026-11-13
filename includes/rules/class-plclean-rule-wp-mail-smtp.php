<?php
/**
 * Rule: WP Mail SMTP: debug events
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans WP Mail SMTP: debug events data.
 */
class PLCLEAN_Rule_WP_Mail_SMTP extends PLCLEAN_Rule_Table {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wp_mail_smtp';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'WP Mail SMTP: debug events', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes the debug events recorded by WP Mail SMTP.', 'log-broom' );
	}

	/**
	 * What the plugin cleans by itself (shown in Details).
	 *
	 * @return string
	 */
	public function builtin_note() {
		return __( 'Cleaning also resets WP Mail SMTP\'s recent email-error count on its dashboard.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'mailer settings and queued emails', 'log-broom' );
	}

	/**
	 * Table specs.
	 *
	 * @return array[]
	 */
	protected function specs() {
		return array(
			array(
				'table'     => 'wpmailsmtp_debug_events',
				'where'     => '',
				'orphan_of' => null,
				'global'    => false,
			),
		);
	}
}
