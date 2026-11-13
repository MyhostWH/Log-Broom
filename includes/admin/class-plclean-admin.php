<?php
/**
 * Admin controller: menu, page data and AJAX handlers.
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tools > Log Broom screen.
 */
class PLCLEAN_Admin {

	const SLUG  = 'log-broom';
	const NONCE = 'plclean_admin';
	const CAP   = 'manage_options';

	/**
	 * Hook suffix of our page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * View data computed once per request.
	 *
	 * @var array|null
	 */
	private $view_data = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_plclean_stats', array( $this, 'ajax_stats' ) );
		add_action( 'wp_ajax_plclean_clean', array( $this, 'ajax_clean' ) );
		add_action( 'wp_ajax_plclean_optimize', array( $this, 'ajax_optimize' ) );
		add_action( 'wp_ajax_plclean_save_settings', array( $this, 'ajax_save_settings' ) );
	}

	/**
	 * Add Tools > Log Broom.
	 *
	 * @return void
	 */
	public function add_menu() {
		$suffix = add_management_page(
			__( 'Log Broom', 'log-broom' ),
			__( 'Log Broom', 'log-broom' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render_page' )
		);

		$this->hook_suffix = is_string( $suffix ) ? $suffix : '';
		if ( '' !== $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'maybe_restore_schedule' ) );
		}
	}

	/**
	 * Self-heal on page load: if automatic cleaning is on but its event is missing, schedule it again.
	 *
	 * @return void
	 */
	public function maybe_restore_schedule() {
		$settings = PLCLEAN_Settings::get();
		if ( 'off' !== $settings['schedule'] && ! wp_next_scheduled( PLCLEAN_Scheduler::HOOK ) ) {
			PLCLEAN_Scheduler::sync( $settings['schedule'] );
		}
	}

	/**
	 * Whether the current user may not clean or schedule this rule because it is network-wide.
	 *
	 * On multisite, network-wide tables (for example security and audit logs) are shared by every site,
	 * so only a network administrator may clean them.
	 *
	 * @param PLCLEAN_Rule $rule Rule.
	 * @return bool
	 */
	public static function is_network_locked( PLCLEAN_Rule $rule ) {
		return is_multisite() && $rule->is_network_wide() && ! current_user_can( 'manage_network_options' );
	}

	/**
	 * Why a network-wide rule is locked.
	 *
	 * @return string
	 */
	public static function network_locked_message() {
		return __( 'Only a network administrator can clean network-wide logs.', 'log-broom' );
	}

	/**
	 * Load assets on our page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'plclean-admin', plugins_url( 'assets/css/admin.css', PLCLEAN_FILE ), array( 'dashicons' ), PLCLEAN_VERSION );
		// Keeps the page usable without JS: script-only controls stay hidden until admin.js removes `no-js`, and
		// their read-only replacements are shown only until then.
		wp_add_inline_style( 'plclean-admin', '.plclean-app.no-js .plclean-js-only{display:none}.plclean-app:not(.no-js) .plclean-no-js-only{display:none}' );

		wp_enqueue_script( 'plclean-admin', plugins_url( 'assets/js/admin.js', PLCLEAN_FILE ), array( 'jquery', 'wp-i18n' ), PLCLEAN_VERSION, true );
		wp_localize_script( 'plclean-admin', 'plcleanAdmin', $this->get_script_data() );
		wp_set_script_translations( 'plclean-admin', 'log-broom' );
	}

	/**
	 * Data for the script: the global `plcleanAdmin`.
	 *
	 * @return array
	 */
	public function get_script_data() {
		$data     = $this->get_view_data();
		$last_run = null;
		if ( $data['last_run'] ) {
			$last_run = array(
				'date'    => wp_date( get_option( 'date_format' ), $data['last_run']['time'] ),
				'deleted' => $data['last_run']['deleted'],
			);
		}
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'sources' => $data['sources'],
			'lastRun' => $last_run,
			'batch'   => array(
				'mode' => $data['settings']['batch_mode'],
				'size' => $data['settings']['batch_size'],
			),
		);
	}

	/**
	 * Forget the memoized view data (for tests, or after data changed within a request).
	 *
	 * @return void
	 */
	public function reset_view_data() {
		$this->view_data = null;
	}

	/**
	 * Bar width (0-100) for a source: its entries to clean relative to the largest source. 0 when nothing is left.
	 * Mirrored by barPercent() in admin.js.
	 *
	 * @param int $count     Entries in the source.
	 * @param int $max_count Entries in the largest available source.
	 * @return int
	 */
	public static function bar_percent( $count, $max_count ) {
		if ( $count <= 0 || $max_count <= 0 ) {
			return 0;
		}
		return (int) round( 100 * $count / $max_count );
	}

	/**
	 * Data shared by the view and the script. Computed once per request.
	 *
	 * Only cheap facts are read here (labels, availability, table names, settings), so the page opens at once on
	 * any site. Counts and sizes are unknown (null) until the script asks for them, one source at a time
	 * (plclean_stats, see stats()).
	 *
	 * Network-wide rules a non-network administrator may not clean are reported as unavailable with
	 * 'network_only' set.
	 *
	 * @return array
	 */
	public function get_view_data() {
		if ( null !== $this->view_data ) {
			return $this->view_data;
		}

		$sources = array();
		foreach ( PLCLEAN_Registry::all() as $rule ) {
			$available    = (bool) $rule->is_available();
			$network_only = $available && self::is_network_locked( $rule );
			$available    = $available && ! $network_only;
			$tables       = array();
			if ( $available ) {
				foreach ( $rule->tables() as $table ) {
					$tables[] = array(
						'name'       => (string) $table,
						'size'       => null,
						'size_human' => '',
					);
				}
			}

			$sources[] = array(
				'id'             => $rule->id(),
				'label'          => $rule->label(),
				'description'    => $rule->description(),
				'details_note'   => $rule->details_note(),
				'builtin_note'   => $rule->builtin_note(),
				'kept'           => $rule->kept(),
				'core'           => $rule->is_core(),
				'count'          => null,
				'size'           => null,
				'size_human'     => '',
				'available'      => $available,
				'network_only'   => $network_only,
				'tables'         => $tables,
				'adaptive'       => PLCLEAN_Cleaner::is_adjustable( $rule ),
				'batch_unit'     => 'tasks' === $rule->batch_unit() ? 'tasks' : 'entries',
				// Single-pass rules (expired transients) have no batch size worth showing: 0.
				'batch_size'     => $rule->batch_size() < PHP_INT_MAX ? (int) $rule->batch_size() : 0,
				// Automatic sizing grows up to this; a fixed size may be larger (the server clamps it).
				'max_batch_size' => $rule->max_batch_size() < PHP_INT_MAX ? (int) min( $rule->max_batch_size(), PLCLEAN_Cleaner::MAX_AUTO_BATCH ) : 0,
				'tables_note'    => $available ? $rule->tables_note() : '',
				'cleaned_note'   => $available ? $rule->cleaned_note() : '',
			);
		}

		$this->view_data = array(
			'sources'    => $sources,
			'settings'   => PLCLEAN_Settings::get(),
			'last_run'   => PLCLEAN_Settings::get_last_run(),
			'last_error' => PLCLEAN_Scheduler::get_last_error(),
		);
		return $this->view_data;
	}

	/**
	 * Count and size of one source: what plclean_stats returns.
	 *
	 * @param PLCLEAN_Rule $rule Rule.
	 * @return array array( 'count' => int, 'size' => int, 'size_human' => string, 'tables' => array[] ), where each
	 *               table is array( 'name' => string, 'size' => int|null, 'size_human' => string ); plus
	 *               'parts' => int[] (e.g. array( 'tasks' => n, 'logs' => n )) for rules that count in parts.
	 */
	public static function stats( PLCLEAN_Rule $rule ) {
		$parts  = $rule->count_parts();
		$count  = is_array( $parts ) ? (int) array_sum( $parts ) : (int) $rule->count();
		$size   = (int) $rule->size();
		$tables = array();
		foreach ( $rule->table_details() as $table ) {
			$table_size = null === $table['size'] ? null : (int) $table['size'];
			$tables[]   = array(
				'name'       => (string) $table['name'],
				'size'       => $table_size,
				'size_human' => null !== $table_size && $table_size > 0 ? size_format( $table_size ) : '',
			);
		}
		$out = array(
			'count'      => $count,
			'size'       => $size,
			'size_human' => $size > 0 ? size_format( $size ) : '',
			'tables'     => $tables,
		);
		if ( is_array( $parts ) ) {
			$out['parts'] = array_map( 'intval', $parts );
		}
		return $out;
	}

	/**
	 * The line about the last automatic run's database errors (plain text; escape it).
	 *
	 * With failed sources: "Last automatic run: <date>, removed N entries. <Source>: database error: <message>.
	 * It will retry at <time>." An error stored by 1.0.1 (no sources) keeps its old line.
	 *
	 * @param array      $error    From PLCLEAN_Scheduler::get_last_error().
	 * @param array|null $last_run From PLCLEAN_Settings::get_last_run().
	 * @return string
	 */
	public static function last_error_line( array $error, $last_run ) {
		if ( array() === $error['rules'] ) {
			/* translators: %s: error message from the database. */
			return sprintf( __( 'Last automatic run failed: %s. It will retry automatically.', 'log-broom' ), rtrim( $error['message'], '. ' ) );
		}
		$parts = array();
		if ( $last_run ) {
			$parts[] = sprintf(
				/* translators: 1: date, 2: number of entries removed. */
				_n( 'Last automatic run: %1$s, removed %2$s entry.', 'Last automatic run: %1$s, removed %2$s entries.', $last_run['deleted'], 'log-broom' ),
				wp_date( get_option( 'date_format' ), $last_run['time'] ),
				number_format_i18n( $last_run['deleted'] )
			);
		}
		foreach ( $error['rules'] as $id => $message ) {
			$rule    = PLCLEAN_Registry::get( $id );
			$parts[] = sprintf(
				/* translators: 1: source name, 2: error message from the database. */
				__( '%1$s: database error: %2$s.', 'log-broom' ),
				null === $rule ? $id : $rule->label(),
				rtrim( $message, '. ' )
			);
		}
		$retry = wp_next_scheduled( PLCLEAN_Scheduler::CONTINUE_HOOK );
		if ( false === $retry ) {
			$parts[] = __( 'It will retry automatically.', 'log-broom' );
		} else {
			/* translators: %s: date and time of the next try. */
			$parts[] = sprintf( __( 'It will retry at %s.', 'log-broom' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $retry ) );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'log-broom' ), 403 );
		}
		$data = $this->get_view_data();
		require PLCLEAN_DIR . 'includes/admin/views/page.php';
	}

	/**
	 * Common guards: nonce, capability. Dies on failure.
	 *
	 * @return void
	 */
	private function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'log-broom' ) ), 403 );
		}
	}

	/**
	 * Resolve the posted rule, or send a 400 error (unknown or unavailable) or a 403 error (network-wide, not a network administrator).
	 *
	 * @return PLCLEAN_Rule
	 */
	private function posted_rule() {
		// Nonce verified in guard().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id   = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
		$rule = PLCLEAN_Registry::get( $id );
		if ( null === $rule ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unknown source.', 'log-broom' ),
					'code'    => 'unknown',
				),
				400
			);
		}
		if ( ! $rule->is_available() ) {
			wp_send_json_error(
				array(
					'message' => __( 'This source was not found on this site.', 'log-broom' ),
					'code'    => 'unavailable',
				),
				400
			);
		}
		if ( self::is_network_locked( $rule ) ) {
			wp_send_json_error(
				array(
					'message' => self::network_locked_message(),
					'code'    => 'network_only',
				),
				403
			);
		}
		return $rule;
	}

	/**
	 * AJAX: count and size of one source (the page asks for each source after it opens).
	 *
	 * @return void
	 */
	public function ajax_stats() {
		$this->guard();
		$rule = $this->posted_rule();
		wp_send_json_success( self::stats( $rule ) );
	}

	/**
	 * AJAX: delete one batch for a rule. Requires backup confirmation.
	 *
	 * @return void
	 */
	public function ajax_clean() {
		$this->guard();
		$rule = $this->posted_rule();

		// Nonce verified in guard().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$confirmed = isset( $_POST['backup_confirmed'] ) ? sanitize_key( wp_unslash( $_POST['backup_confirmed'] ) ) : '';
		if ( '1' !== $confirmed ) {
			wp_send_json_error( array( 'message' => __( 'Please confirm you have a database backup before cleaning.', 'log-broom' ) ), 400 );
		}

		// Optional batch size from the script (automatic sizing or the fixed setting), clamped to what the rule accepts.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in guard().
		$limit = isset( $_POST['limit'] ) ? PLCLEAN_Cleaner::clamp_limit( $rule, absint( wp_unslash( $_POST['limit'] ) ) ) : null;

		$result = ( new PLCLEAN_Cleaner() )->run_batch( $rule, $limit );
		if ( isset( $result['error'] ) ) {
			// The message is plain text (see PLCLEAN_Cleaner::error_text()); the script shows it as text.
			wp_send_json_error(
				array(
					/* translators: %s: error message from the database. */
					'message' => sprintf( __( 'The database refused to delete entries: %s', 'log-broom' ), $result['error'] ),
				),
				500
			);
		}
		$result['size'] = (int) $rule->size();
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: optimize the posted tables of a rule (the tables its batches deleted from, as reported in
	 * `tables_touched`). Only names that are exactly one of the rule's own tables() are optimized, so the
	 * request can never name another table.
	 *
	 * @return void
	 */
	public function ajax_optimize() {
		$this->guard();
		$rule = $this->posted_rule();

		$tables = array();
		// Nonce verified in guard(); each name is only compared with the rule's own table names.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['tables'] ) && is_array( $_POST['tables'] ) ) {
			foreach ( wp_unslash( $_POST['tables'] ) as $table ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
				if ( is_string( $table ) ) {
					$tables[] = sanitize_text_field( $table );
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		( new PLCLEAN_Cleaner() )->optimize_tables( $rule, $tables );
		wp_send_json_success( array( 'size' => (int) $rule->size() ) );
	}

	/**
	 * AJAX: save automatic cleaning settings. Turning a schedule on requires a backup acknowledgement.
	 *
	 * A request with batch_mode and without schedule saves only the batch settings (Advanced): they change how
	 * fast entries are deleted, never which ones, so they need no backup acknowledgement and leave everything
	 * else as stored.
	 *
	 * @return void
	 */
	public function ajax_save_settings() {
		$this->guard();

		// Nonce verified in guard(); values are whitelisted and clamped in PLCLEAN_Settings::save_batch().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['batch_mode'] ) && ! isset( $_POST['schedule'] ) ) {
			$mode = sanitize_key( wp_unslash( $_POST['batch_mode'] ) );
			$size = isset( $_POST['batch_size'] ) ? absint( wp_unslash( $_POST['batch_size'] ) ) : PLCLEAN_Settings::get()['batch_size'];
			wp_send_json_success( PLCLEAN_Settings::save_batch( $mode, $size ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Nonce verified in guard(); values are sanitized below and again in PLCLEAN_Settings::save().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$rules = array();
		if ( isset( $_POST['enabled_rules'] ) && is_array( $_POST['enabled_rules'] ) ) {
			$rules = array_map( 'sanitize_key', array_filter( (array) wp_unslash( $_POST['enabled_rules'] ), 'is_scalar' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_key via array_map.
		}
		$schedule = isset( $_POST['schedule'] ) ? sanitize_key( wp_unslash( $_POST['schedule'] ) ) : 'off';
		$optimize = isset( $_POST['optimize'] ) && '1' === sanitize_key( wp_unslash( $_POST['optimize'] ) );
		$ack      = isset( $_POST['backup_ack'] ) ? sanitize_key( wp_unslash( $_POST['backup_ack'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'off' !== $schedule && '1' !== $ack ) {
			wp_send_json_error( array( 'message' => __( 'Please confirm you have regular automatic backups before turning on automatic cleaning.', 'log-broom' ) ), 400 );
		}

		// A schedule change of any kind, or turning optimize on, re-arms or intensifies automatic cleaning.
		$stored    = PLCLEAN_Settings::get();
		$intensify = $schedule !== $stored['schedule'] || ( $optimize && ! $stored['optimize'] );

		$saved = PLCLEAN_Settings::save(
			array(
				'enabled_rules' => $this->allowed_enabled_rules( $rules, ! $intensify ),
				'schedule'      => $schedule,
				'optimize'      => $optimize,
			)
		);
		PLCLEAN_Scheduler::sync( $saved['schedule'] );
		wp_send_json_success( $saved );
	}

	/**
	 * Enabled rules as the current user may save them.
	 *
	 * Network-wide rules are only changed by a network administrator. For anyone else (on multisite), posted
	 * network-wide rules are ignored. The stored network-wide rules (chosen by a network administrator) are kept
	 * as they are only while the save neither changes the schedule nor turns optimize on: a site administrator
	 * must not re-arm or intensify automatic cleaning of network-wide logs, so such a save drops them and a
	 * network administrator has to include them again.
	 *
	 * Stored rules that are not available right now (their plugin is inactive or its table is missing) have no
	 * switch on the page, so they cannot be posted; they are kept so they clean again when the plugin returns.
	 *
	 * @param string[] $posted      Posted rule ids.
	 * @param bool     $keep_locked Keep the stored network-wide rules the current user may not change.
	 * @return string[]
	 */
	private function allowed_enabled_rules( array $posted, $keep_locked ) {
		$out = array();
		foreach ( $posted as $id ) {
			$rule = PLCLEAN_Registry::get( $id );
			if ( null !== $rule && ! self::is_network_locked( $rule ) ) {
				$out[] = $id;
			}
		}
		foreach ( PLCLEAN_Settings::get()['enabled_rules'] as $id ) {
			$rule = PLCLEAN_Registry::get( $id );
			if ( null === $rule ) {
				continue;
			}
			$keep = self::is_network_locked( $rule ) ? $keep_locked : ! $rule->is_available();
			if ( $keep ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
