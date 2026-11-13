<?php
/**
 * Batched cleaner.
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs rules in batches, for manual (AJAX) and scheduled (cron) cleaning.
 */
class PLCLEAN_Cleaner {

	/**
	 * Option holding the names of the tables entries were deleted from during a scheduled run that is still in
	 * progress (spread over continuation events). Cleared when the run completes.
	 */
	const PENDING_OPTIMIZE_OPTION = 'plclean_pending_optimize';

	/**
	 * Site option (network-wide on multisite) holding when each table was last optimized by hand:
	 * array( table name => Unix time ). Entries older than OPTIMIZE_INTERVAL are dropped when it is saved.
	 */
	const OPTIMIZED_OPTION = 'plclean_optimized';

	/** A table can be optimized by hand at most once in this many seconds. */
	const OPTIMIZE_INTERVAL = 600;

	/**
	 * Tables larger than this (data plus indexes, bytes) are optimized by hand only after an extra confirmation
	 * (`large_ok`), and never from cron unless the `plclean_cron_optimize_max_bytes` filter raises the limit.
	 */
	const OPTIMIZE_LARGE_BYTES = 1073741824;

	/** Smallest requested batch size for adjustable rules. */
	const MIN_BATCH = 1000;

	/** Largest automatic batch size for plugin log tables. */
	const MAX_AUTO_BATCH = 50000;

	/** Largest fixed batch size (Advanced settings) for plugin log tables. */
	const MAX_FIXED_BATCH = 100000;

	/** A full batch faster than this (seconds) doubles the next one. */
	const FAST_BATCH = 1.0;

	/** A batch slower than this (seconds) halves the next one. */
	const SLOW_BATCH = 3.0;

	/**
	 * Option counting, per rule, the batches in a row that deleted nothing but reported progress (a scan moving
	 * forward). Kept in an option, not in memory or a transient, so it counts across AJAX requests and
	 * continuation events even when an object cache drops transients.
	 */
	const IDLE_OPTION = 'plclean_idle_batches';

	/**
	 * Batches in a row that delete nothing before a run is stopped as stalled, even if they report progress.
	 * Each idle batch scans one 250,000-id window, so 400 covers a full pass over about 100 million ids
	 * (actions plus logs) while still ending a run whose scan position is lost on every request.
	 */
	const MAX_IDLE_BATCHES = 400;

	/**
	 * Current time in seconds (filterable so tests can control it).
	 *
	 * @return float
	 */
	private function now() {
		/**
		 * Filters the time source used for the run time budget.
		 *
		 * @param float $now Seconds, default microtime( true ).
		 */
		return (float) apply_filters( 'plclean_now', microtime( true ) );
	}

	/**
	 * Delete one batch for a rule.
	 *
	 * 'stalled' is true when a batch deleted nothing although rows remain; 'done' is then also true so
	 * callers stop instead of looping forever. 'tables_touched' lists the tables the batch deleted rows from.
	 * When the database refused the delete, 'error' holds its message
	 * (plain text, markup stripped, never empty) and both 'done' and 'stalled' are false: the batch can be retried. 'elapsed' is the time clean_batch() took, in seconds, measured
	 * with the plclean_now clock (microtime( true ) unless filtered); 'limit' is the limit used.
	 *
	 * @param PLCLEAN_Rule $rule  Rule.
	 * @param int|null     $limit Rows to delete at most; null uses the rule's batch_size(). Callers clamp it
	 *                            with clamp_limit() first.
	 * @return array array( 'deleted' => int, 'remaining' => int, 'done' => bool, 'stalled' => bool,
	 *               'elapsed' => float, 'limit' => int, 'tables_touched' => string[] ), plus
	 *               'error' => string on a database error, 'parts' => int[] for rules that count in parts, and
	 *               'stall_reason' => string (plain text) when the run was stopped after too many batches in a
	 *               row that deleted nothing.
	 */
	public function run_batch( PLCLEAN_Rule $rule, $limit = null ) {
		$limit = null === $limit ? (int) $rule->batch_size() : max( 1, (int) $limit );

		if ( ! $rule->is_available() ) {
			return array(
				'deleted'        => 0,
				'remaining'      => 0,
				'done'           => true,
				'stalled'        => false,
				'elapsed'        => 0.0,
				'limit'          => $limit,
				'tables_touched' => array(),
			);
		}

		$started = $this->now();
		$deleted = $rule->clean_batch( $limit );
		$elapsed = max( 0.0, $this->now() - $started );

		if ( false === $deleted ) {
			// Read the reason before count() runs another query and clears it.
			$error = self::error_text( $GLOBALS['wpdb']->last_error );
			return array(
				'deleted'        => 0,
				'remaining'      => (int) $rule->count(),
				'done'           => false,
				'stalled'        => false,
				'elapsed'        => round( $elapsed, 4 ),
				'limit'          => $limit,
				'tables_touched' => self::tables_touched( $rule, 0 ),
				'error'          => $error,
			);
		}

		$deleted   = (int) $deleted;
		$remaining = (int) $rule->remaining_after_batch();
		// A batch that deleted nothing while rows remain is a stall, unless it moved a scan forward.
		$stalled = 0 === $deleted && $remaining > 0 && ! $rule->last_batch_progressed();
		$reason  = '';
		if ( self::idle_limit_reached( $rule, 0 === $deleted && $remaining > 0 && ! $stalled ) ) {
			// Progress that never deletes anything (for example a scan position the object cache keeps losing) must
			// still end.
			$stalled = true;
			$reason  = sprintf(
				/* translators: %s: number of batches. */
				__( 'Stopped after %s batches in a row that removed nothing. Try again later.', 'log-broom' ),
				number_format_i18n( self::max_idle_batches() )
			);
		}

		$result = array(
			'deleted'        => $deleted,
			'remaining'      => $remaining,
			'done'           => 0 === $remaining || $stalled,
			'stalled'        => $stalled,
			'elapsed'        => round( $elapsed, 4 ),
			'limit'          => $limit,
			'tables_touched' => self::tables_touched( $rule, $deleted ),
		);
		$parts  = $rule->last_count_parts();
		if ( is_array( $parts ) ) {
			$result['parts'] = array_map( 'intval', $parts );
		}
		if ( '' !== $reason ) {
			$result['stall_reason'] = $reason;
		}
		return $result;
	}

	/**
	 * Largest number of batches in a row that may delete nothing (filterable, at least 1).
	 *
	 * @return int
	 */
	private static function max_idle_batches() {
		/**
		 * Filters how many batches in a row may delete nothing before a run is stopped as stalled.
		 *
		 * @param int $max Batches, default 50.
		 */
		return max( 1, (int) apply_filters( 'plclean_max_idle_batches', self::MAX_IDLE_BATCHES ) );
	}

	/**
	 * Count a batch that deleted nothing but reported progress; any other batch resets the count.
	 *
	 * @param PLCLEAN_Rule $rule Rule.
	 * @param bool         $idle Whether the batch was idle (deleted nothing, rows remain, not stalled).
	 * @return bool True when the limit is reached (the count is then reset).
	 */
	private static function idle_limit_reached( PLCLEAN_Rule $rule, $idle ) {
		$counts = get_option( self::IDLE_OPTION, array() );
		$counts = is_array( $counts ) ? $counts : array();
		$id     = $rule->id();
		if ( ! $idle ) {
			if ( isset( $counts[ $id ] ) ) {
				unset( $counts[ $id ] );
				self::save_idle_counts( $counts );
			}
			return false;
		}
		$counts[ $id ] = ( isset( $counts[ $id ] ) ? absint( $counts[ $id ] ) : 0 ) + 1;
		$reached       = $counts[ $id ] >= self::max_idle_batches();
		if ( $reached ) {
			unset( $counts[ $id ] );
		}
		self::save_idle_counts( $counts );
		return $reached;
	}

	/**
	 * Store the idle batch counts (the option is removed when empty).
	 *
	 * @param array $counts Counts by rule id.
	 * @return void
	 */
	private static function save_idle_counts( array $counts ) {
		if ( array() === $counts ) {
			delete_option( self::IDLE_OPTION );
		} else {
			update_option( self::IDLE_OPTION, $counts, false );
		}
	}

	/**
	 * Tables the rule's last clean_batch() deleted rows from: exactly those for table rules, and all of the
	 * rule's tables for the others once they deleted anything.
	 *
	 * @param PLCLEAN_Rule $rule    Rule.
	 * @param int          $deleted Rows the batch deleted.
	 * @return string[]
	 */
	private static function tables_touched( PLCLEAN_Rule $rule, $deleted ) {
		if ( $rule instanceof PLCLEAN_Rule_Table ) {
			// A failed batch can still have deleted from an earlier table.
			return $rule->last_tables_touched();
		}
		return $deleted > 0 ? array_values( array_map( 'strval', $rule->tables() ) ) : array();
	}

	/**
	 * A database error message as plain text: markup and control characters removed, never empty. It is
	 * untrusted text and is still escaped wherever it is shown.
	 *
	 * @param mixed $message Raw message, e.g. $wpdb->last_error.
	 * @return string
	 */
	public static function error_text( $message ) {
		$text = is_scalar( $message ) ? trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', wp_strip_all_tags( (string) $message ) ) ) : '';
		return '' !== $text ? $text : __( 'Unknown database error.', 'log-broom' );
	}

	/**
	 * Whether a rule's batch size can be changed (plugin log tables). Post revisions and the single-pass
	 * transients always use their own size.
	 *
	 * @param PLCLEAN_Rule $rule Rule.
	 * @return bool
	 */
	public static function is_adjustable( PLCLEAN_Rule $rule ) {
		return (int) $rule->max_batch_size() > (int) $rule->batch_size();
	}

	/**
	 * Clamp a requested batch size to what the rule accepts: [1000, max_batch_size()] for adjustable rules,
	 * and always the rule's own batch_size() for the others.
	 *
	 * @param PLCLEAN_Rule $rule  Rule.
	 * @param int          $limit Requested limit.
	 * @return int
	 */
	public static function clamp_limit( PLCLEAN_Rule $rule, $limit ) {
		if ( ! self::is_adjustable( $rule ) ) {
			return (int) $rule->batch_size();
		}
		$max = (int) $rule->max_batch_size();
		return max( min( self::MIN_BATCH, $max ), min( $max, (int) $limit ) );
	}

	/**
	 * Automatic batch sizing: double after a full batch that took under a second (up to $max), halve after a
	 * batch that took over three seconds (down to 1000), otherwise keep the size. A batch is full when it deleted
	 * at least $limit rows: Action Scheduler batches count actions and also delete their log entries.
	 *
	 * @param int   $limit   Limit used for the last batch.
	 * @param int   $deleted Rows the last batch deleted.
	 * @param float $elapsed Seconds the last batch took.
	 * @param int   $max     Largest allowed limit.
	 * @return int Next limit.
	 */
	public static function next_limit( $limit, $deleted, $elapsed, $max ) {
		$limit = (int) $limit;
		if ( $elapsed < self::FAST_BATCH && (int) $deleted >= $limit ) {
			return min( (int) $max, $limit * 2 );
		}
		if ( $elapsed > self::SLOW_BATCH ) {
			return max( min( self::MIN_BATCH, $limit ), intdiv( $limit, 2 ) );
		}
		return $limit;
	}

	/**
	 * Run several rules within a time budget (scheduled cleaning).
	 *
	 * Plugin log tables use automatic batch sizing (see next_limit()): each rule starts at its default size,
	 * doubles while full batches take under a second, stops at its maximum, and halves when a batch takes
	 * over three seconds. When a fixed batch size is chosen (Advanced settings), it is used for every batch.
	 *
	 * When the whole run is done and optimizing is switched on, only the tables entries were deleted from during
	 * the run (including earlier continuation runs) are optimized. Only tables of available rules that are not
	 * is_core() qualify, so the shared WordPress core tables are never optimized from cron. Tables over 1 GB are
	 * left alone too, unless the `plclean_cron_optimize_max_bytes` filter raises the limit.
	 *
	 * A rule that hits a database error is set aside (its error is returned in 'errors') and the run carries on with
	 * the next rule, so one failing source never blocks the others. The run is done when every rule is done or
	 * failed.
	 *
	 * @param string[] $rule_ids    Rule ids; unknown or unavailable rules are skipped.
	 * @param int      $time_budget Seconds before stopping with done = false.
	 * @return array array( 'deleted' => int, 'done' => bool ), plus 'errors' => array( rule id => message ) when
	 *               rules hit a database error (plain-text messages, see error_text()).
	 */
	public function run_all( array $rule_ids, $time_budget = 20 ) {
		$start        = $this->now();
		$deleted      = 0;
		$with_deletes = $this->pending_optimize();
		$errors       = array();
		$settings     = PLCLEAN_Settings::get();
		$fixed        = 'fixed' === $settings['batch_mode'];

		foreach ( $rule_ids as $id ) {
			$rule = PLCLEAN_Registry::get( $id );
			if ( null === $rule || ! $rule->is_available() ) {
				continue;
			}

			$adjustable = self::is_adjustable( $rule );
			$adapt      = $adjustable && ! $fixed;
			$limit      = $adjustable && $fixed ? self::clamp_limit( $rule, $settings['batch_size'] ) : (int) $rule->batch_size();

			do {
				if ( $this->now() - $start >= $time_budget ) {
					update_option( self::PENDING_OPTIMIZE_OPTION, array_values( array_unique( $with_deletes ) ), false );
					return self::run_result( $deleted, false, $errors );
				}
				$result   = $this->run_batch( $rule, $limit );
				$deleted += $result['deleted'];
				if ( ! $rule->is_core() ) {
					$with_deletes = array_merge( $with_deletes, $result['tables_touched'] );
				}
				if ( isset( $result['error'] ) ) {
					// Set this rule aside and carry on with the next one.
					$errors[ $rule->id() ] = $result['error'];
					break;
				}
				if ( $adapt ) {
					$limit = self::next_limit( $result['limit'], $result['deleted'], $result['elapsed'], $rule->max_batch_size() );
				}
			} while ( ! $result['done'] );
		}

		delete_option( self::PENDING_OPTIMIZE_OPTION );

		if ( $settings['optimize'] ) {
			/**
			 * Filters the largest table (data plus indexes, bytes) that automatic cleaning optimizes. Rebuilding a
			 * bigger table can take minutes and slow the site, so it is skipped.
			 *
			 * @param int $bytes Default 1 GB.
			 */
			$max    = (int) apply_filters( 'plclean_cron_optimize_max_bytes', self::OPTIMIZE_LARGE_BYTES );
			$wanted = array_unique( $with_deletes );
			foreach ( PLCLEAN_Registry::all() as $rule ) {
				if ( $rule->is_core() || ! $rule->is_available() ) {
					continue;
				}
				$wanted = array_diff( $wanted, $this->optimize_tables( $rule, $wanted, $max ) );
			}
		}

		return self::run_result( $deleted, true, $errors );
	}

	/**
	 * What run_all() returns.
	 *
	 * @param int   $deleted Rows deleted.
	 * @param bool  $done    Whether every rule is done or failed.
	 * @param array $errors  Rule id => database error message.
	 * @return array
	 */
	private static function run_result( $deleted, $done, array $errors ) {
		$result = array(
			'deleted' => $deleted,
			'done'    => $done,
		);
		if ( array() !== $errors ) {
			$result['errors'] = $errors;
		}
		return $result;
	}

	/**
	 * Table names with deletes from earlier parts of the scheduled run in progress. Entries that are not a
	 * table of an available, non-core rule (for example rule ids stored by 1.0.0) are ignored when optimizing.
	 *
	 * @return string[]
	 */
	private function pending_optimize() {
		$stored = get_option( self::PENDING_OPTIMIZE_OPTION, array() );
		return is_array( $stored ) ? array_values( array_map( 'strval', array_filter( $stored, 'is_scalar' ) ) ) : array();
	}

	/**
	 * The names in a list that are exactly one of the rule's own tables(), each once, in the rule's order. The list
	 * may come from untrusted input.
	 *
	 * @param PLCLEAN_Rule $rule   Rule.
	 * @param array        $tables Wanted table names.
	 * @return string[]
	 */
	public static function own_tables( PLCLEAN_Rule $rule, array $tables ) {
		$wanted = array();
		foreach ( $tables as $table ) {
			if ( is_string( $table ) ) {
				$wanted[] = $table;
			}
		}
		$out = array();
		foreach ( $rule->tables() as $table ) {
			if ( in_array( (string) $table, $wanted, true ) && ! in_array( (string) $table, $out, true ) ) {
				$out[] = (string) $table;
			}
		}
		return $out;
	}

	/**
	 * Largest table (data plus indexes, in bytes) that analyze_tables() refreshes.
	 *
	 * InnoDB's own ANALYZE only samples a few index pages (milliseconds even on a 1.4 GB table). But MariaDB with
	 * use_stat_tables=PREFERABLY (or COMPLEMENTARY) also collects engine-independent statistics, which reads the
	 * whole table, and MySQL before 8.0.24 flushes the table after ANALYZE, which can stall other queries waiting on
	 * it. Bigger tables are therefore left alone; their sizes are then the server's own estimate.
	 */
	const ANALYZE_MAX_BYTES = 1073741824;

	/**
	 * Refresh the statistics of some of a rule's tables (ANALYZE TABLE), once each, so the sizes read afterwards are
	 * current. Only names that are exactly one of the rule's own tables() are analyzed. Core rules are skipped (their
	 * shared WordPress tables show no size), and so are tables over the size limit (see ANALYZE_MAX_BYTES).
	 *
	 * @param PLCLEAN_Rule $rule   Rule.
	 * @param array        $tables Wanted table names (the tables a clean deleted from).
	 * @return string[] The tables analyzed.
	 */
	public function analyze_tables( PLCLEAN_Rule $rule, array $tables ) {
		if ( $rule->is_core() ) {
			return array();
		}
		/**
		 * Filters the largest table size (data plus indexes, bytes) that is analyzed after a manual clean.
		 *
		 * @param int $bytes Default 1 GB.
		 */
		$max  = (int) apply_filters( 'plclean_analyze_max_bytes', self::ANALYZE_MAX_BYTES );
		$done = array();
		foreach ( self::own_tables( $rule, $tables ) as $table ) {
			if ( PLCLEAN_DB::table_size( $table ) > $max ) {
				continue;
			}
			PLCLEAN_DB::analyze( $table );
			$done[] = $table;
		}
		return $done;
	}

	/**
	 * Optimize some of a rule's tables. Only names that are exactly one of the rule's own tables() are
	 * optimized, so the list may come from untrusted input.
	 *
	 * @param PLCLEAN_Rule $rule      Rule.
	 * @param string[]     $tables    Wanted table names.
	 * @param int|null     $max_bytes Skip tables larger than this (data plus indexes, bytes); null for no limit.
	 * @return string[] The tables optimized.
	 */
	public function optimize_tables( PLCLEAN_Rule $rule, array $tables, $max_bytes = null ) {
		$done = array();
		foreach ( self::own_tables( $rule, $tables ) as $table ) {
			if ( null === PLCLEAN_DB::quote_identifier( $table ) ) {
				continue;
			}
			if ( null !== $max_bytes && PLCLEAN_DB::table_size( $table ) > (int) $max_bytes ) {
				continue;
			}
			PLCLEAN_DB::optimize( $table );
			$done[] = $table;
		}
		return $done;
	}

	/**
	 * When each table was last optimized by hand, without entries older than OPTIMIZE_INTERVAL.
	 *
	 * @param int $now Current Unix time.
	 * @return int[] Table name => Unix time.
	 */
	private static function optimized_times( $now ) {
		$stored = get_site_option( self::OPTIMIZED_OPTION, array() );
		$times  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $table => $time ) {
			if ( is_string( $table ) && is_numeric( $time ) && $now - (int) $time < self::OPTIMIZE_INTERVAL ) {
				$times[ $table ] = (int) $time;
			}
		}
		return $times;
	}

	/**
	 * Optimize some of a rule's tables by hand (after a manual clean), with the limits a manual request needs:
	 *
	 * - only names that are exactly one of the rule's own tables() (the list may come from untrusted input);
	 * - each table at most once every OPTIMIZE_INTERVAL seconds (others are skipped as 'throttled');
	 * - a table larger than OPTIMIZE_LARGE_BYTES only when $large_ok (others are skipped as 'needs_confirmation').
	 *
	 * The space freed is measured: the tables are analyzed (see analyze_tables() for the ones that are not) and
	 * their size read before optimizing, and read again after. A table of a plugin rule that was too large to
	 * analyze has a stale size before optimizing, so it is left out of 'freed' and counted in 'unmeasured'.
	 *
	 * @param PLCLEAN_Rule $rule     Rule.
	 * @param array        $tables   Wanted table names.
	 * @param bool         $large_ok Whether the user confirmed optimizing tables over OPTIMIZE_LARGE_BYTES.
	 * @return array array( 'optimized' => string[], 'skipped' => array( table => 'throttled'|'needs_confirmation' ),
	 *               'freed' => int bytes, never negative, 'unmeasured' => int ).
	 */
	public function optimize_manual( PLCLEAN_Rule $rule, array $tables, $large_ok ) {
		$now     = time();
		$times   = self::optimized_times( $now );
		$run     = array();
		$skipped = array();
		foreach ( self::own_tables( $rule, $tables ) as $table ) {
			if ( isset( $times[ $table ] ) ) {
				$skipped[ $table ] = 'throttled';
			} elseif ( ! $large_ok && PLCLEAN_DB::table_size( $table ) > self::OPTIMIZE_LARGE_BYTES ) {
				$skipped[ $table ] = 'needs_confirmation';
			} else {
				$run[] = $table;
			}
		}

		$analyzed = $this->analyze_tables( $rule, $run );
		// Core rules are never analyzed (shared WordPress tables); they are measured as the server reports them.
		$measured = $rule->is_core() ? $run : array_values( array_intersect( $run, $analyzed ) );
		$before   = 0;
		foreach ( $measured as $table ) {
			$before += PLCLEAN_DB::table_size( $table );
		}

		if ( array() !== $run ) {
			// Recorded before optimizing, so a request sent while this one still runs is turned away.
			foreach ( $run as $table ) {
				$times[ $table ] = $now;
			}
			update_site_option( self::OPTIMIZED_OPTION, $times );
		}
		$optimized = $this->optimize_tables( $rule, $run );

		$after = 0;
		foreach ( $measured as $table ) {
			$after += PLCLEAN_DB::table_size( $table );
		}
		return array(
			'optimized'  => $optimized,
			'skipped'    => $skipped,
			'freed'      => max( 0, $before - $after ),
			'unmeasured' => count( $run ) - count( $measured ),
		);
	}
}
