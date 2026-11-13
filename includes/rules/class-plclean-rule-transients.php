<?php
/**
 * Rule: Expired transients
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deletes expired transients using WordPress' own routine.
 */
class PLCLEAN_Rule_Transients extends PLCLEAN_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'expired_transients';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Expired transients', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes temporary cached values (transients) that have already expired.', 'log-broom' );
	}

	/**
	 * Core data, not a plugin's tables.
	 *
	 * @return bool
	 */
	public function is_core() {
		return true;
	}

	/**
	 * What is kept.
	 *
	 * @return string
	 */
	public function kept() {
		return __( 'unexpired transients', 'log-broom' );
	}

	/**
	 * Always available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * Single pass.
	 *
	 * @return int
	 */
	public function batch_size() {
		return PHP_INT_MAX;
	}

	/**
	 * Note for the "Details" panel: these are shared core tables, so no per-table size is shown.
	 *
	 * @return string
	 */
	public function tables_note() {
		return __( 'Shared WordPress tables — only expired transients are removed.', 'log-broom' );
	}

	/**
	 * Tables touched, as shown in Details and allowed for "Optimize tables".
	 *
	 * The network table sitemeta is listed only for network administrators (on the main site of the main
	 * network), so a site administrator can never rebuild it. Expired site transients in sitemeta are still
	 * deleted by count()/clean_batch() through core's delete_expired_transients(), as core's own cron does.
	 *
	 * @return string[]
	 */
	public function tables() {
		global $wpdb;
		$tables = array( $wpdb->options );
		if ( is_multisite() && is_main_site() && is_main_network() && current_user_can( 'manage_network_options' ) ) {
			$tables[] = $wpdb->sitemeta;
		}
		return $tables;
	}

	/**
	 * Count value/timeout pairs exactly as core's delete_expired_transients() matches them.
	 *
	 * @param string $table  Table name ($wpdb->options or $wpdb->sitemeta).
	 * @param string $prefix Transient prefix, e.g. '_transient_'.
	 * @param bool   $sitemeta Whether $table is sitemeta (meta_key/meta_value columns).
	 * @return int
	 */
	private function count_pairs( $table, $prefix, $sitemeta ) {
		global $wpdb;
		$key     = $sitemeta ? 'meta_key' : 'option_name';
		$value   = $sitemeta ? 'meta_value' : 'option_value';
		$offset  = strlen( $prefix ) + 1;
		$timeout = $prefix . 'timeout_';
		$table   = PLCLEAN_DB::quote_identifier( $table );
		if ( null === $table ) {
			return 0;
		}

		// Table and column names are fixed internally (the table is quoted by quote_identifier()); values are prepared.
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} a, {$table} b
				WHERE a.{$key} LIKE %s
				AND a.{$key} NOT LIKE %s
				AND b.{$key} = CONCAT( %s, SUBSTRING( a.{$key}, {$offset} ) )
				AND b.{$value} < %d",
				$wpdb->esc_like( $prefix ) . '%',
				$wpdb->esc_like( $timeout ) . '%',
				$timeout,
				time()
			)
		);
		// phpcs:enable
	}

	/**
	 * Number of expired transients (value rows that have an expired timeout row), as core deletes them.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		$total = $this->count_pairs( $wpdb->options, '_transient_', false );

		if ( ! is_multisite() ) {
			$total += $this->count_pairs( $wpdb->options, '_site_transient_', false );
		} elseif ( is_main_site() && is_main_network() ) {
			$total += $this->count_pairs( $wpdb->sitemeta, '_site_transient_', true );
		}

		return $total;
	}

	/**
	 * Delete all expired transients in one pass ($limit is ignored).
	 *
	 * @param int $limit Unused.
	 * @return int Number of expired transients removed.
	 */
	public function clean_batch( $limit ) {
		unset( $limit );
		$before = $this->count();
		delete_expired_transients( true );
		return max( 0, $before - $this->count() );
	}
}
