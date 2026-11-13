<?php
/**
 * Rule: Post revisions
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deletes post revisions, keeping autosaves and the posts themselves.
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
		return __( 'Deletes old saved versions (revisions) of posts and pages.', 'log-broom' );
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
		return __( 'posts, pages and autosaves', 'log-broom' );
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
	 * Number of non-autosave revisions.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_name NOT LIKE %s",
				$this->autosave_pattern()
			)
		);
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
		while ( $deleted < $limit ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_name NOT LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
					$this->autosave_pattern(),
					$after,
					$limit - $deleted
				)
			);
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
