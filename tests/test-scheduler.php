<?php
/**
 * Automatic cleaning schedule.
 *
 * @package Log_Broom
 */

class Test_PLCLEAN_Scheduler extends WP_UnitTestCase {

	public function tear_down() {
		wp_clear_scheduled_hook( PLCLEAN_Scheduler::HOOK );
		parent::tear_down();
	}

	public function test_saving_the_same_schedule_keeps_the_next_run() {
		PLCLEAN_Scheduler::sync( 'daily' );
		$first = wp_next_scheduled( PLCLEAN_Scheduler::HOOK );
		// Pretend the event is due sooner than a fresh one would be.
		$event = wp_get_scheduled_event( PLCLEAN_Scheduler::HOOK );
		wp_unschedule_event( $event->timestamp, PLCLEAN_Scheduler::HOOK );
		wp_schedule_event( $first - 1800, 'daily', PLCLEAN_Scheduler::HOOK );

		PLCLEAN_Scheduler::sync( 'daily' );

		$this->assertSame( $first - 1800, wp_next_scheduled( PLCLEAN_Scheduler::HOOK ) );
	}

	public function test_changing_the_schedule_replaces_the_event() {
		PLCLEAN_Scheduler::sync( 'daily' );
		PLCLEAN_Scheduler::sync( 'weekly' );

		$this->assertSame( 'weekly', wp_get_scheduled_event( PLCLEAN_Scheduler::HOOK )->schedule );
	}

	public function test_turning_off_removes_the_event() {
		PLCLEAN_Scheduler::sync( 'daily' );
		PLCLEAN_Scheduler::sync( 'off' );

		$this->assertFalse( wp_next_scheduled( PLCLEAN_Scheduler::HOOK ) );
	}
}
