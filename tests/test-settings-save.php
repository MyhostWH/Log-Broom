<?php
/**
 * Saving automatic cleaning settings from the admin page.
 *
 * @package Log_Broom
 */

class Test_PLCLEAN_Settings_Save extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();
		( new PLCLEAN_Admin() )->register();
		$this->_setRole( 'administrator' );
		PLCLEAN_Registry::reset();
		PLCLEAN_DB::flush_cache();
	}

	/**
	 * Post a settings save and return the saved settings.
	 *
	 * @param array $fields POST fields.
	 * @return array
	 */
	private function save( array $fields ) {
		$_POST = array_merge( array( 'nonce' => wp_create_nonce( PLCLEAN_Admin::NONCE ) ), $fields );
		$this->_last_response = '';
		try {
			$this->_handleAjax( 'plclean_save_settings' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e ); // wp_send_json_success() ends the request.
		}
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'], $this->_last_response );
		return PLCLEAN_Settings::get();
	}

	public function test_enabled_source_that_is_not_on_the_page_is_kept() {
		// No WooCommerce log table exists, so the page shows no switch for it.
		$this->assertFalse( PLCLEAN_Registry::get( 'woocommerce_log' )->is_available() );
		update_option(
			PLCLEAN_Settings::OPTION,
			array(
				'enabled_rules' => array( 'woocommerce_log', 'revisions' ),
				'schedule'      => 'daily',
			)
		);

		$saved = $this->save(
			array(
				'schedule'      => 'daily',
				'backup_ack'    => '1',
				'enabled_rules' => array( 'revisions', 'expired_transients' ),
			)
		);

		$this->assertEqualSets( array( 'woocommerce_log', 'revisions', 'expired_transients' ), $saved['enabled_rules'] );
	}

	public function test_unticked_source_on_the_page_is_removed() {
		update_option(
			PLCLEAN_Settings::OPTION,
			array(
				'enabled_rules' => array( 'revisions', 'expired_transients' ),
				'schedule'      => 'daily',
			)
		);

		$saved = $this->save(
			array(
				'schedule'      => 'daily',
				'backup_ack'    => '1',
				'enabled_rules' => array( 'revisions' ),
			)
		);

		$this->assertSame( array( 'revisions' ), $saved['enabled_rules'] );
	}

	/**
	 * Create a real (not temporary) Wordfence table so SHOW TABLES finds it, run the callback, drop the table.
	 *
	 * @param callable $callback Test body.
	 */
	private function with_wordfence_table( callable $callback ) {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$table = $wpdb->base_prefix . 'wfhits';
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` ( id INT PRIMARY KEY )" ); // phpcs:ignore WordPress.DB
		try {
			$callback();
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * A site administrator on multisite keeps the network-wide rules a network administrator chose, unless the
	 * save changes the schedule (documented in PLCLEAN_Admin::allowed_enabled_rules()).
	 *
	 * @group multisite
	 */
	public function test_network_wide_rules_follow_the_schedule_change_rule() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->with_wordfence_table(
			function () {
				$this->assertTrue( PLCLEAN_Admin::is_network_locked( PLCLEAN_Registry::get( 'wordfence' ) ) );
				$stored = array(
					'enabled_rules' => array( 'wordfence', 'revisions' ),
					'schedule'      => 'daily',
				);

				update_option( PLCLEAN_Settings::OPTION, $stored );
				$same = $this->save(
					array(
						'schedule'      => 'daily',
						'backup_ack'    => '1',
						'enabled_rules' => array( 'revisions' ),
					)
				);
				$this->assertEqualSets( array( 'wordfence', 'revisions' ), $same['enabled_rules'] );

				update_option( PLCLEAN_Settings::OPTION, $stored );
				$changed = $this->save(
					array(
						'schedule'      => 'weekly',
						'backup_ack'    => '1',
						'enabled_rules' => array( 'revisions', 'wordfence' ),
					)
				);
				$this->assertSame( array( 'revisions' ), $changed['enabled_rules'] );
			}
		);
	}
}
