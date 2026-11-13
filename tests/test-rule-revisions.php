<?php
/**
 * Post revisions rule.
 *
 * @package Log_Broom
 */

class Test_PLCLEAN_Rule_Revisions extends WP_UnitTestCase {

	/**
	 * Revision ids that another plugin refuses to delete.
	 *
	 * @var int[]
	 */
	private $blocked = array();

	public function set_up() {
		parent::set_up();
		add_filter( 'pre_delete_post', array( $this, 'block_delete' ), 10, 2 );
	}

	public function block_delete( $check, $post ) {
		return in_array( (int) $post->ID, $this->blocked, true ) ? false : $check;
	}

	/**
	 * Create revisions of one post, oldest first.
	 *
	 * @param int $n Number of revisions.
	 * @return int[] Revision ids.
	 */
	private function make_revisions( $n ) {
		$parent = self::factory()->post->create();
		$ids    = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'   => 'revision',
					'post_status' => 'inherit',
					'post_parent' => $parent,
					'post_name'   => $parent . '-revision-v1',
				)
			);
		}
		return $ids;
	}

	public function test_undeletable_revisions_do_not_block_newer_ones() {
		$ids           = $this->make_revisions( 3 );
		$this->blocked = array( $ids[0], $ids[1] );
		$rule          = new PLCLEAN_Rule_Revisions();

		$result = ( new PLCLEAN_Cleaner() )->run_batch( $rule, 2 );

		$this->assertSame( 1, $result['deleted'] );
		$this->assertFalse( $result['stalled'] );
		$this->assertNull( get_post( $ids[2] ) );
	}

	public function test_stalls_when_only_undeletable_revisions_remain() {
		$ids           = $this->make_revisions( 2 );
		$this->blocked = $ids;

		$result = ( new PLCLEAN_Cleaner() )->run_batch( new PLCLEAN_Rule_Revisions(), 2 );

		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( 2, $result['remaining'] );
		$this->assertTrue( $result['stalled'] );
	}

	public function test_batch_deletes_at_most_the_limit() {
		$ids = $this->make_revisions( 3 );

		$result = ( new PLCLEAN_Cleaner() )->run_batch( new PLCLEAN_Rule_Revisions(), 2 );

		$this->assertSame( 2, $result['deleted'] );
		$this->assertNotNull( get_post( $ids[2] ) );
	}
}
