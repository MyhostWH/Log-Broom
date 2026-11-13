<?php
/**
 * Base class for cleaning rules.
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * A cleaning rule: knows what it deletes, how much there is, and how to delete a batch.
 */
abstract class PLCLEAN_Rule {

	/**
	 * Unique rule id.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Translated label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Translated description of what is deleted.
	 *
	 * @return string
	 */
	abstract public function description();

	/**
	 * Whether the rule applies on this site.
	 *
	 * @return bool
	 */
	abstract public function is_available();

	/**
	 * Number of rows that would be deleted.
	 *
	 * @return int
	 */
	abstract public function count();

	/**
	 * Delete up to $limit rows.
	 *
	 * @param int $limit Maximum rows to delete in this call.
	 * @return int Rows deleted.
	 */
	abstract public function clean_batch( $limit );

	/**
	 * Whether the rule cleans WordPress core data (not a plugin's tables).
	 *
	 * @return bool
	 */
	public function is_core() {
		return false;
	}

	/**
	 * Whether the rule cleans network-wide tables (shared by every site of a multisite network).
	 *
	 * On multisite, only a network administrator may clean or schedule such rules.
	 *
	 * @return bool
	 */
	public function is_network_wide() {
		return false;
	}

	/**
	 * Short translated noun phrase of what is kept (empty when nothing notable).
	 *
	 * @return string
	 */
	public function kept() {
		return '';
	}

	/**
	 * Bytes used by the tables this rule cleans (0 when unknown).
	 *
	 * @return int
	 */
	public function size() {
		return 0;
	}

	/**
	 * Real names of tables this rule touches.
	 *
	 * @return string[]
	 */
	public function tables() {
		return array();
	}

	/**
	 * Tables this rule touches, for the source card's "Details" panel.
	 *
	 * Rules that clean only some rows of shared WordPress tables report no per-table size (null).
	 *
	 * @return array[] List of array( 'name' => string, 'size' => int|null ).
	 */
	public function table_details() {
		$out = array();
		foreach ( $this->tables() as $table ) {
			$out[] = array(
				'name' => (string) $table,
				'size' => null,
			);
		}
		return $out;
	}

	/**
	 * Translated note shown under the table list (empty when none).
	 *
	 * @return string
	 */
	public function tables_note() {
		return '';
	}

	/**
	 * Translated note shown in Details once the source has nothing left to clean.
	 *
	 * @return string
	 */
	public function cleaned_note() {
		return __( 'Cleaned. Space on disk is given back when tables are optimized.', 'log-broom' );
	}

	/**
	 * Rows per delete batch.
	 *
	 * @return int
	 */
	public function batch_size() {
		return 5000;
	}

	/**
	 * Largest batch the rule accepts. Rules that never change their batch size (post revisions, the single-pass
	 * transients) return batch_size(), so a requested limit never exceeds their own size.
	 *
	 * @return int
	 */
	public function max_batch_size() {
		return $this->batch_size();
	}
}
