<?php
/**
 * Rule: Post revisions
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deletes post revisions, keeping autosaves, the posts themselves and the Site Editor's design history.
 */
class PLCLEAN_Rule_Revisions extends PLCLEAN_Rule {

	/**
	 * Rule id.
	 *
	 * @return string
	 */
	public function id() {
		return 'revisions';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Post revisions', 'log-broom' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Deletes old saved versions (revisions) of posts, pages, products and other content.', 'log-broom' );
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
		return __( 'autosaves; Site Editor history (styles, templates, navigation, patterns, custom CSS)', 'log-broom' );
	}

	/**
	 * Extra note for the "What's deleted" part of Details.
	 *
	 * @return string
	 */
	public function details_note() {
		return __( 'Includes revisions from page builders such as Elementor.', 'log-broom' );
	}

	/**
	 * Post types whose revisions are always kept: the Site Editor's design history (Styles > Revisions,
	 * template, navigation and pattern history, Additional CSS).
	 *
	 * Filterable through `plclean_revisions_excluded_types`, which can only ADD types: the defaults are always
	 * merged back in, and a filter result that is not an array is ignored. Every entry is passed through
	 * sanitize_key().
	 *
	 * @return string[]
	 */
	public function excluded_types() {
		$defaults = array( 'wp_global_styles', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block', 'custom_css' );
		/**
		 * Filters the post types whose revisions Log Broom never deletes. Types can only be added: the Site
		 * Editor types are always kept.
		 *
		 * @param string[] $types Post type names.
		 */
		$filtered = apply_filters( 'plclean_revisions_excluded_types', $defaults );
		$types    = is_array( $filtered ) ? array_merge( $defaults, $filtered ) : $defaults;
		$out      = array();
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}
			$type = sanitize_key( $type );
			if ( '' !== $type ) {
				$out[] = $type;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * FROM/WHERE part shared by count() and clean_batch(), prepared: non-autosave revisions whose parent is
	 * not of an excluded type. Revisions whose parent is missing (orphans) match.
	 *
	 * @return string|null Null when the posts table name can't be quoted (see PLCLEAN_DB::quote_identifier()).
	 */
	private function revisions_sql() {
		global $wpdb;
		$posts = PLCLEAN_DB::quote_identifier( $wpdb->posts );
		if ( null === $posts ) {
			return null;
		}
		$types = $this->excluded_types();
		// The posts table is quoted by quote_identifier(); the pattern is prepared.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"FROM {$posts} r LEFT JOIN {$posts} p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND r.post_name NOT LIKE %s",
			$this->autosave_pattern()
		);
		if ( array() !== $types ) {
			$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			// One %s placeholder per type; the list is built from count( $types ).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$sql .= $wpdb->prepare( " AND ( p.ID IS NULL OR p.post_type NOT IN ({$placeholders}) )", $types );
		}
		return $sql;
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
	 * Batch size.
	 *
	 * @return int
	 */
	public function batch_size() {
		return 100;
	}

	/**
	 * Note for the "Details" panel: these are shared core tables, so no per-table size is shown.
	 *
	 * @return string
	 */
	public function tables_note() {
		return __( 'Shared WordPress tables — only revision rows are removed.', 'log-broom' );
	}

	/**
	 * Tables touched.
	 *
	 * @return string[]
	 */
	public function tables() {
		global $wpdb;
		return array( $wpdb->posts, $wpdb->postmeta );
	}

	/**
	 * Autosave name pattern (leading wildcard included).
	 *
	 * @return string
	 */
	private function autosave_pattern() {
		global $wpdb;
		return '%' . $wpdb->esc_like( '-autosave-v1' );
	}

	/**
	 * Number of non-autosave revisions outside the Site Editor history.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		$from = $this->revisions_sql();
		if ( null === $from ) {
			return 0;
		}
		// The fragment is prepared in revisions_sql().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $from );
	}

	/**
	 * Delete up to $limit revisions.
	 *
	 * Revisions that cannot be deleted (e.g. another plugin blocks it) are stepped over, so they never keep the
	 * newer ones from being cleaned. A batch deletes nothing only when every remaining revision is blocked.
	 *
	 * @param int $limit Maximum revisions to delete.
	 * @return int Number deleted.
	 */
	public function clean_batch( $limit ) {
		global $wpdb;
		$limit   = (int) $limit;
		$deleted = 0;
		// shortcut: each batch re-tries blocked revisions from the start; store a cursor if sites block thousands.
		$after = 0;
		$from  = $this->revisions_sql();
		while ( null !== $from && $deleted < $limit ) {
			// $from is prepared in revisions_sql(); the cursor and limit are prepared here.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ids = $wpdb->get_col( 'SELECT r.ID ' . $from . $wpdb->prepare( ' AND r.ID > %d ORDER BY r.ID ASC LIMIT %d', $after, $limit - $deleted ) );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				$after  = (int) $id;
				$result = wp_delete_post_revision( $after );
				if ( $result && ! is_wp_error( $result ) ) {
					++$deleted;
				}
			}
		}
		return $deleted;
	}
}
