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
	 * Option holding the ids of rules that deleted entries during a scheduled run that is still in progress
	 * (spread over continuation events). Cleared when the run completes.
	 */
	const PENDING_OPTIMIZE_OPTION = 'plclean_pending_optimize';

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
	 * callers stop instead of looping forever. When the database refused the delete, 'error' holds its message
	 * (plain text, markup stripped, never empty) and both 'done' and 'stalled' are false: the batch can be retried. 'elapsed' is the time clean_batch() took, in seconds, measured
	 * with the plclean_now clock (microtime( true ) unless filtered); 'limit' is the limit used.
	 *
	 * @param PLCLEAN_Rule $rule  Rule.
	 * @param int|null     $limit Rows to delete at most; null uses the rule's batch_size(). Callers clamp it
	 *                            with clamp_limit() first.
	 * @return array array( 'deleted' => int, 'remaining' => int, 'done' => bool, 'stalled' => bool,
	 *               'elapsed' => float, 'limit' => int ), plus 'error' => string on a database error.
	 */
	public function run_batch( PLCLEAN_Rule $rule, $limit = null ) {
		$limit = null === $limit ? (int) $rule->batch_size() : max( 1, (int) $limit );

		if ( ! $rule->is_available() ) {
			return array(
				'deleted'   => 0,
				'remaining' => 0,
				'done'      => true,
				'stalled'   => false,
				'elapsed'   => 0.0,
				'limit'     => $limit,
			);
		}

		$started = $this->now();
		$deleted = $rule->clean_batch( $limit );
		$elapsed = max( 0.0, $this->now() - $started );

		if ( false === $deleted ) {
			// Read the reason before count() runs another query and clears it.
			$error = self::error_text( $GLOBALS['wpdb']->last_error );
			return array(
				'deleted'   => 0,
				'remaining' => (int) $rule->count(),
				'done'      => false,
				'stalled'   => false,
				'elapsed'   => round( $elapsed, 4 ),
				'limit'     => $limit,
				'error'     => $error,
			);
		}

		$deleted   = (int) $deleted;
		$remaining = (int) $rule->count();
		$stalled   = 0 === $deleted && $remaining > 0;

		return array(
			'deleted'   => $deleted,
			'remaining' => $remaining,
			'done'      => 0 === $remaining || $stalled,
			'stalled'   => $stalled,
			'elapsed'   => round( $elapsed, 4 ),
			'limit'     => $limit,
		);
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
	 * batch that took over three seconds (down to 1000), otherwise keep the size.
	 *
	 * @param int   $limit   Limit used for the last batch.
	 * @param int   $deleted Rows the last batch deleted.
	 * @param float $elapsed Seconds the last batch took.
	 * @param int   $max     Largest allowed limit.
	 * @return int Next limit.
	 */
	public static function next_limit( $limit, $deleted, $elapsed, $max ) {
		$limit = (int) $limit;
		if ( $elapsed < self::FAST_BATCH && (int) $deleted === $limit ) {
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
	 * When the whole run is done and optimizing is switched on, only the tables of rules that deleted entries
	 * during the run (including earlier continuation runs) are optimized, and never the shared WordPress core
	 * tables (rules whose is_core() is true).
	 *
	 * @param string[] $rule_ids    Rule ids; unknown or unavailable rules are skipped.
	 * A database error stops the run with done = false and 'error' set; the tables with deletes so far stay
	 * pending for the run's next part.
	 *
	 * @param int      $time_budget Seconds before stopping with done = false.
	 * @return array array( 'deleted' => int, 'done' => bool ), plus 'error' => string on a database error.
	 */
	public function run_all( array $rule_ids, $time_budget = 20 ) {
		$start        = $this->now();
		$deleted      = 0;
		$with_deletes = $this->pending_optimize();
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
					return array(
						'deleted' => $deleted,
						'done'    => false,
					);
				}
				$result   = $this->run_batch( $rule, $limit );
				$deleted += $result['deleted'];
				if ( isset( $result['error'] ) ) {
					update_option( self::PENDING_OPTIMIZE_OPTION, array_values( array_unique( $with_deletes ) ), false );
					return array(
						'deleted' => $deleted,
						'done'    => false,
						'error'   => $result['error'],
					);
				}
				if ( $adapt ) {
					$limit = self::next_limit( $result['limit'], $result['deleted'], $result['elapsed'], $rule->max_batch_size() );
				}
				if ( $result['deleted'] > 0 ) {
					$with_deletes[] = $rule->id();
				}
			} while ( ! $result['done'] );
		}

		delete_option( self::PENDING_OPTIMIZE_OPTION );

		if ( $settings['optimize'] ) {
			foreach ( array_unique( $with_deletes ) as $id ) {
				$rule = PLCLEAN_Registry::get( $id );
				if ( null === $rule || $rule->is_core() || ! $rule->is_available() ) {
					continue;
				}
				$this->optimize( $rule );
			}
		}

		return array(
			'deleted' => $deleted,
			'done'    => true,
		);
	}

	/**
	 * Rule ids with deletes from earlier parts of the scheduled run in progress.
	 *
	 * @return string[]
	 */
	private function pending_optimize() {
		$stored = get_option( self::PENDING_OPTIMIZE_OPTION, array() );
		return is_array( $stored ) ? array_values( array_map( 'strval', array_filter( $stored, 'is_scalar' ) ) ) : array();
	}

	/**
	 * Optimize the tables a rule touches.
	 *
	 * @param PLCLEAN_Rule $rule Rule.
	 * @return void
	 */
	public function optimize( PLCLEAN_Rule $rule ) {
		foreach ( $rule->tables() as $table ) {
			PLCLEAN_DB::optimize( $table );
		}
	}
}
