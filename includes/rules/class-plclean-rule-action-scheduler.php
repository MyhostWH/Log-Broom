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
		return __( 'Deletes completed and canceled scheduled actions, failed ones older than 7 days, and their log entries.', 'log-broom' );
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
	 * What the plugin cleans by itself (shown in Details).
	 *
	 * @return string
	 */
	public function builtin_note() {
		return __( 'WooCommerce removes finished tasks itself, but slowly (a few at a time). Log Broom removes them all now.', 'log-broom' );
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'pending and running tasks; failed tasks from the last 7 days', 'log-broom' );
	}

	/**
	 * Finished actions that may be deleted: completed and canceled ones, and failed ones older than 7 days.
	 *
	 * Recent failed actions are kept so Action Scheduler can still stop frequently recurring tasks that keep failing
	 * (it looks at the last 5 runs of the same hook, so the 7-day window covers tasks that run about daily or more
	 * often), and admins can diagnose recent failures. last_attempt_gmt is set when an action fails; a failed action
	 * of unknown age is kept: one without a last attempt (NULL) or with Action Scheduler's default zero date
	 * ('0000-00-00 00:00:00', which sorts below every real date, so "1000-01-01 or later" leaves it out).
	 */
	const DELETABLE = "(`status` IN ('complete','canceled') OR (`status` = 'failed' AND `last_attempt_gmt` >= '1000-01-01 00:00:00' AND `last_attempt_gmt` < UTC_TIMESTAMP() - INTERVAL 7 DAY))";

	/**
	 * The exact complement of DELETABLE for the actions table aliased `a` (NULL-safe: a failed action without a
	 * last attempt, or with the zero date, is kept, so it is here). Written as ranges so the status index finds the
	 * few kept actions.
	 */
	const KEPT_A = "(a.`status` NOT IN ('complete','canceled') AND (a.`status` <> 'failed' OR a.`last_attempt_gmt` IS NULL OR a.`last_attempt_gmt` < '1000-01-01 00:00:00' OR a.`last_attempt_gmt` >= UTC_TIMESTAMP() - INTERVAL 7 DAY))";

	/**
	 * Finished actions selected and deleted together (one SELECT, then one pair of DELETEs). Selecting chunk by
	 * chunk keeps a request's memory flat whatever the batch size.
	 */
	const ACTION_CHUNK = 5000;

	/** Ids per DELETE … IN (…) statement, so the range optimizer of every server keeps using the index. */
	const IN_CHUNK = 1000;

	/** Transient that holds where the scans are, so the next request (AJAX or cron) carries on from there. */
	const SCAN_STATE = 'plclean_as_scan';

	/**
	 * Real names of the tables the last clean_batch() call deleted rows from.
	 *
	 * @var string[]
	 */
	private $as_touched = array();

	/**
	 * Rows the last clean_batch() call deleted.
	 *
	 * @var int
	 */
	private $last_deleted = 0;

	/**
	 * Whether the last clean_batch() call moved a scan forward (more of the tables are still to be read).
	 *
	 * @var bool
	 */
	private $progressed = false;

	/**
	 * Whether the last clean_batch() call finished a pass over both tables.
	 *
	 * @var bool
	 */
	private $pass_end = false;

	/**
	 * Parts of the last count: array( 'tasks' => int, 'logs' => int ), or null before any count.
	 *
	 * @var array|null
	 */
	private $last_parts = null;

	/**
	 * Table specs. They name the tables (availability, size, Details, optimizing); count() and clean_batch() are
	 * this rule's own.
	 *
	 * @return array[]
	 */
	protected function specs() {
		return array(
			array(
				'table'     => 'actionscheduler_actions',
				'where'     => self::DELETABLE,
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

	/**
	 * Batches count actions: the progress line says "tasks".
	 *
	 * @return string
	 */
	public function batch_unit() {
		return 'tasks';
	}

	/**
	 * Action ids read per request in the actions phase (an id window, so a request's cost never depends on how many
	 * kept actions lie ahead). Filterable for tests and unusual servers.
	 *
	 * @return int
	 */
	protected function action_span() {
		/**
		 * Filters how many action ids one Action Scheduler batch reads at most.
		 *
		 * @param int $span Ids, default 250000.
		 */
		return max( 1, (int) apply_filters( 'plclean_as_action_span', 250000 ) );
	}

	/**
	 * Log ids read per request in the orphan phase.
	 *
	 * @return int
	 */
	protected function log_span() {
		/**
		 * Filters how many log ids one Action Scheduler orphan batch reads at most.
		 *
		 * @param int $span Ids, default 250000.
		 */
		return max( 1, (int) apply_filters( 'plclean_as_log_span', 250000 ) );
	}

	/**
	 * Real names of the actions and logs tables (null when missing).
	 *
	 * @return array array( actions|null, logs|null ).
	 */
	private function as_tables() {
		return array( PLCLEAN_DB::find_table( 'actionscheduler_actions' ), PLCLEAN_DB::find_table( 'actionscheduler_logs' ) );
	}

	/**
	 * Where the scans are. Only a position hint: every DELETE checks its own conditions, so a stale or shared
	 * state can at worst make a pass start at the wrong place, never delete a kept row.
	 *
	 * @return array
	 */
	private function scan_state() {
		$state    = get_transient( self::SCAN_STATE );
		$defaults = array(
			'phase'        => 'actions',
			'action'       => 0,
			'log'          => 0,
			'pass_deleted' => 0,
			'empty_passes' => 0,
			'from_top'     => true,
			'remaining'    => null,
			'parts'        => null,
		);
		if ( ! is_array( $state ) ) {
			return $defaults;
		}
		// A saved pass of unknown origin may have started part-way (an earlier, interrupted run).
		$from_top              = ! empty( $state['from_top'] );
		$state                 = array_merge( $defaults, array_intersect_key( $state, $defaults ) );
		$state['from_top']     = $from_top;
		$state['phase']        = 'orphans' === $state['phase'] ? 'orphans' : 'actions';
		$state['action']       = absint( $state['action'] );
		$state['log']          = absint( $state['log'] );
		$state['pass_deleted'] = absint( $state['pass_deleted'] );
		$state['empty_passes'] = absint( $state['empty_passes'] );
		$state['remaining']    = null === $state['remaining'] ? null : absint( $state['remaining'] );
		$state['parts']        = is_array( $state['parts'] ) && isset( $state['parts']['tasks'], $state['parts']['logs'] ) ? array(
			'tasks' => absint( $state['parts']['tasks'] ),
			'logs'  => absint( $state['parts']['logs'] ),
		) : null;
		return $state;
	}

	/**
	 * Save where the scans are (for an hour; after that a new pass starts at the top).
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function save_scan_state( array $state ) {
		set_transient( self::SCAN_STATE, $state, HOUR_IN_SECONDS );
	}

	/**
	 * Entries to delete, in two parts: finished actions ('tasks') and log entries ('logs': those of finished actions
	 * and those whose action is already gone).
	 *
	 * Counted without the anti-join (which reads every log entry and every action): every log entry belongs to a
	 * finished action, to a kept action, or to no action, so all log entries - log entries of kept actions is
	 * exact. The counts are read in one statement, so they see the same moment.
	 *
	 * @return array array( 'tasks' => int, 'logs' => int ).
	 */
	public function count_parts() {
		global $wpdb;
		list( $actions, $logs ) = $this->as_tables();
		if ( null === $actions ) {
			// Without the actions table every log entry is orphaned.
			$parts = array(
				'tasks' => 0,
				'logs'  => null === $logs ? 0 : PLCLEAN_DB::count( $logs ),
			);
		} elseif ( null === $logs ) {
			$parts = array(
				'tasks' => PLCLEAN_DB::count( $actions, self::DELETABLE ),
				'logs'  => 0,
			);
		} else {
			$deletable = self::DELETABLE;
			$kept      = self::KEPT_A;
			// Tables are resolved by find_table(); the conditions are static fragments from this class; live count must not be cached.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$row   = $wpdb->get_row( "SELECT (SELECT COUNT(*) FROM `{$actions}` WHERE {$deletable}) AS tasks, (SELECT COUNT(*) FROM `{$logs}`) - (SELECT COUNT(*) FROM `{$actions}` a JOIN `{$logs}` l ON l.`action_id` = a.`action_id` WHERE {$kept}) AS logs", ARRAY_A );
			$parts = array(
				'tasks' => max( 0, (int) ( $row['tasks'] ?? 0 ) ),
				'logs'  => max( 0, (int) ( $row['logs'] ?? 0 ) ),
			);
		}
		$this->last_parts = $parts;
		return $parts;
	}

	/**
	 * Entries to delete: finished actions, their log entries, and log entries whose action is already gone.
	 *
	 * @return int
	 */
	public function count() {
		$parts = $this->count_parts();
		return $parts['tasks'] + $parts['logs'];
	}

	/**
	 * Parts of the count behind the last remaining_after_batch() or count() (null before either).
	 *
	 * @return array|null
	 */
	public function last_count_parts() {
		return $this->last_parts;
	}

	/**
	 * Remaining entries after a batch. A batch that only moved a scan forward (it deleted nothing) reuses the last
	 * count when it was above 0, instead of counting again; any other batch counts. So "nothing left" (which ends a
	 * run) always comes from a real count.
	 *
	 * @return int
	 */
	public function remaining_after_batch() {
		$state = $this->scan_state();
		// Only a positive count is reused: a saved 0 may be from an earlier run, and must never end this one.
		if ( 0 === $this->last_deleted && ! $this->pass_end && $state['remaining'] > 0 && null !== $state['parts'] ) {
			$this->last_parts = $state['parts'];
			return $state['remaining'];
		}
		$parts              = $this->count_parts();
		$state['remaining'] = $parts['tasks'] + $parts['logs'];
		$state['parts']     = $parts;
		if ( 0 === $state['remaining'] ) {
			// Nothing is left: the next run starts a new pass at the top.
			$state['phase']        = 'actions';
			$state['action']       = 0;
			$state['log']          = 0;
			$state['pass_deleted'] = 0;
			$state['empty_passes'] = 0;
			$state['from_top']     = true;
		}
		$this->save_scan_state( $state );
		return $state['remaining'];
	}

	/**
	 * Whether the last batch moved a scan forward without deleting anything, so a batch that deleted nothing while
	 * entries remain is progress, not a stall.
	 *
	 * @return bool
	 */
	public function last_batch_progressed() {
		return $this->progressed;
	}

	/**
	 * Delete up to $limit finished actions together with their log entries. Once a pass over the actions table is
	 * done, delete up to $limit log entries whose action is gone (orphans). Both phases read one bounded id window
	 * per request, from where the previous request stopped (see scan_state()).
	 *
	 * A log entry is only ever deleted when its action no longer exists: the actions are deleted first (the
	 * condition is checked again in the DELETE), then the log entries of those of them that are gone. If the request
	 * stops in between, the entries left behind are orphans and are deleted by the orphan phase.
	 *
	 * @param int $limit Maximum actions (actions phase) or orphaned log entries (orphan phase) to delete.
	 * @return int|false Rows deleted (actions plus log entries), or false on a database error.
	 */
	public function clean_batch( $limit ) {
		$limit                  = max( 1, (int) $limit );
		$this->as_touched       = array();
		$this->last_deleted     = 0;
		$this->progressed       = false;
		$this->pass_end         = false;
		list( $actions, $logs ) = $this->as_tables();

		if ( null === $actions || null === $logs ) {
			$table = null === $actions ? $logs : $actions;
			if ( null === $table ) {
				$this->pass_end = true;
				return 0;
			}
			$n = PLCLEAN_DB::delete_batch( $table, null === $actions ? '' : self::DELETABLE, $limit );
			if ( $n > 0 ) {
				$this->as_touched[] = $table;
				$this->last_deleted = (int) $n;
			}
			return $n;
		}

		$state = $this->scan_state();
		if ( 'actions' === $state['phase'] && 0 === $state['action'] ) {
			// A pass starts at the top: only such whole passes count as empty passes.
			if ( ! $state['from_top'] ) {
				$state['empty_passes'] = 0;
			}
			$state['from_top']     = true;
			$state['pass_deleted'] = 0;
		}
		if ( 'actions' === $state['phase'] ) {
			$deleted = $this->actions_phase( $actions, $logs, $limit, $state );
		} else {
			$deleted = $this->orphan_phase( $actions, $logs, $limit, $state );
		}
		if ( false === $deleted ) {
			// The rows deleted before the error stay deleted; the scan position is kept for the retry.
			return false;
		}
		$this->last_deleted     = $deleted;
		$state['pass_deleted'] += $deleted;

		if ( $this->pass_end ) {
			// A whole pass over both tables is done. Another pass starts at the top: it finds rows that became
			// deletable behind the scans. Only a whole pass (started at the top) that deleted nothing counts as empty;
			// a partial one left by an earlier, interrupted run does not.
			$empty                 = $state['from_top'] && 0 === $state['pass_deleted'];
			$state['empty_passes'] = $empty ? $state['empty_passes'] + 1 : 0;
			$this->progressed      = $state['empty_passes'] < 2 && 0 === $deleted;
			if ( ! $this->progressed ) {
				// The stall is reported now; the next run starts counting afresh.
				$state['empty_passes'] = 0;
			}
			$state['phase']        = 'actions';
			$state['action']       = 0;
			$state['log']          = 0;
			$state['pass_deleted'] = 0;
			$state['from_top']     = true;
		}
		$this->save_scan_state( $state );
		return $deleted;
	}

	/**
	 * Actions phase: read the next window of action ids and delete the finished actions in it (and their logs),
	 * up to $limit actions. At the end of the table, switch to the orphan phase.
	 *
	 * @param string $actions Actions table.
	 * @param string $logs    Logs table.
	 * @param int    $limit   Maximum actions.
	 * @param array  $state   Scan state (updated).
	 * @return int|false Rows deleted, or false on a database error.
	 */
	private function actions_phase( $actions, $logs, $limit, array &$state ) {
		global $wpdb;
		// Tables are resolved by find_table().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$max       = (int) $wpdb->get_var( "SELECT MAX(`action_id`) FROM `{$actions}`" );
		$end       = min( $max, $state['action'] + $this->action_span() );
		$deletable = self::DELETABLE;
		$deleted   = 0;
		$left      = $limit;
		while ( $left > 0 && $state['action'] < $end ) {
			$want = min( self::ACTION_CHUNK, $left );
			// Table is resolved by find_table(); the condition is a static fragment from this class; the window and limit are prepared.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT `action_id` FROM `{$actions}` WHERE `action_id` > %d AND `action_id` <= %d AND {$deletable} ORDER BY `action_id` LIMIT %d", $state['action'], $end, $want ) );
			if ( '' !== $wpdb->last_error ) {
				return false;
			}
			$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
			if ( array() === $ids ) {
				$state['action'] = $end;
				break;
			}
			$n = $this->delete_actions_with_logs( $actions, $logs, $ids );
			if ( false === $n ) {
				return false;
			}
			$deleted        += $n;
			$left           -= count( $ids );
			$state['action'] = count( $ids ) < $want ? $end : (int) end( $ids );
		}
		$this->progressed = true;
		if ( $state['action'] >= $max ) {
			$state['phase'] = 'orphans';
			$state['log']   = 0;
		}
		return $deleted;
	}

	/**
	 * Orphan phase: read the next window of log ids and delete up to $limit entries whose action is gone. At the end
	 * of the table the pass ends.
	 *
	 * @param string $actions Actions table.
	 * @param string $logs    Logs table.
	 * @param int    $limit   Maximum entries.
	 * @param array  $state   Scan state (updated).
	 * @return int|false Rows deleted, or false on a database error.
	 */
	private function orphan_phase( $actions, $logs, $limit, array &$state ) {
		global $wpdb;
		// Tables are resolved by find_table().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$max = (int) $wpdb->get_var( "SELECT MAX(`log_id`) FROM `{$logs}`" );
		$end = min( $max, $state['log'] + $this->log_span() );
		$ids = array();
		if ( $state['log'] < $end ) {
			// Tables are resolved by find_table(); the window and the limit are prepared. The scan reads the primary key
			// from the cursor to the end of the window, looking each action up by its primary key.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT l.`log_id` FROM `{$logs}` l LEFT JOIN `{$actions}` a ON a.`action_id` = l.`action_id` WHERE a.`action_id` IS NULL AND l.`log_id` > %d AND l.`log_id` <= %d ORDER BY l.`log_id` LIMIT %d", $state['log'], $end, $limit ) );
			if ( '' !== $wpdb->last_error ) {
				return false;
			}
		}
		$ids     = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		$deleted = 0;
		foreach ( array_chunk( $ids, self::IN_CHUNK ) as $chunk ) {
			$n = $this->delete_logs_in( $actions, $logs, '`log_id`', $chunk );
			if ( false === $n ) {
				return false;
			}
			$deleted += $n;
		}
		$state['log']     = count( $ids ) < $limit ? $end : (int) end( $ids );
		$this->progressed = true;
		if ( $state['log'] >= $max ) {
			$this->pass_end = true;
		}
		return $deleted;
	}

	/**
	 * Real names of the tables the last clean_batch() call deleted rows from (empty before the first call).
	 *
	 * @return string[]
	 */
	public function last_tables_touched() {
		return array_values( array_unique( $this->as_touched ) );
	}

	/**
	 * Delete the given finished actions (if still finished), then the log entries of the ones that are gone.
	 *
	 * When the ids are close together (the usual case: finished actions are most rows), both DELETEs work on the
	 * id range from the first to the last id, so each reads one index range: the primary key of the actions table
	 * and the action_id index of the logs table. The ranges need no LIMIT: they are bounded by the chunk's ids (at
	 * most ACTION_CHUNK finished actions and the rows between them). When the ids are spread out (kept actions in
	 * between, more than 4 ids of span per finished action), the chunk is deleted by id lists instead, so the
	 * DELETE neither reads nor locks the kept rows between them. Rows in a range that are not finished actions, or
	 * log entries of actions that still exist, are left alone.
	 *
	 * @param string $actions Actions table.
	 * @param string $logs    Logs table.
	 * @param int[]  $ids     Action ids, ascending.
	 * @return int|false Rows deleted, or false on a database error.
	 */
	private function delete_actions_with_logs( $actions, $logs, array $ids ) {
		global $wpdb;
		$deletable = self::DELETABLE;
		$first     = (int) reset( $ids );
		$last      = (int) end( $ids );
		if ( $last - $first + 1 > 4 * count( $ids ) ) {
			$deleted = 0;
			foreach ( array_chunk( $ids, self::IN_CHUNK ) as $chunk ) {
				$in = implode( ',', array_map( 'absint', $chunk ) );
				// Table is resolved by find_table(); the condition is a static fragment from this class; $in is a list of absint() ids.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$n = $wpdb->query( "DELETE FROM `{$actions}` WHERE `action_id` IN ({$in}) AND {$deletable}" );
				if ( false === $n ) {
					return false;
				}
				if ( $n > 0 ) {
					$this->as_touched[] = $actions;
				}
				$deleted += (int) $n;
				$n        = $this->delete_logs_in( $actions, $logs, '`action_id`', $chunk );
				if ( false === $n ) {
					return false;
				}
				$deleted += $n;
			}
			return $deleted;
		}

		// Table is resolved by find_table(); the condition is a static fragment from this class; the range is prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$n = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$actions}` WHERE `action_id` BETWEEN %d AND %d AND {$deletable}", $first, $last ) );
		if ( false === $n ) {
			return false;
		}
		if ( $n > 0 ) {
			$this->as_touched[] = $actions;
		}
		$deleted = (int) $n;

		// Only entries whose action no longer exists: an action that is not finished any more keeps its entries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$n = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$logs}` WHERE `action_id` BETWEEN %d AND %d AND NOT EXISTS (SELECT 1 FROM `{$actions}` p WHERE p.`action_id` = `{$logs}`.`action_id`)", $first, $last ) );
		if ( false === $n ) {
			return false;
		}
		if ( $n > 0 ) {
			$this->as_touched[] = $logs;
		}
		return $deleted + (int) $n;
	}

	/**
	 * Delete the log entries whose $column is in $ids and whose action no longer exists.
	 *
	 * @param string $actions Actions table.
	 * @param string $logs    Logs table.
	 * @param string $column  '`log_id`' or '`action_id`' (fixed by this class).
	 * @param int[]  $ids     Ids (at most IN_CHUNK).
	 * @return int|false Rows deleted, or false on a database error.
	 */
	private function delete_logs_in( $actions, $logs, $column, array $ids ) {
		global $wpdb;
		if ( array() === $ids ) {
			return 0;
		}
		$column = '`log_id`' === $column ? '`log_id`' : '`action_id`';
		$in     = implode( ',', array_map( 'absint', $ids ) );
		// Table is resolved by find_table(); the column is one of two fixed names; $in is a list of absint() ids; the action is checked again.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$n = $wpdb->query( "DELETE FROM `{$logs}` WHERE {$column} IN ({$in}) AND NOT EXISTS (SELECT 1 FROM `{$actions}` p WHERE p.`action_id` = `{$logs}`.`action_id`)" );
		if ( false === $n ) {
			return false;
		}
		if ( $n > 0 ) {
			$this->as_touched[] = $logs;
		}
		return (int) $n;
	}
}
