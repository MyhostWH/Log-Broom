<?php
/**
 * Rule that cleans rows from one or more tables described by specs.
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Table rule. Subclasses implement specs().
 *
 * Each spec: array( 'table' => suffix, 'where' => string, 'orphan_of' => array( parent_suffix, child_col, parent_col )|null, 'global' => bool ).
 */
abstract class PLCLEAN_Rule_Table extends PLCLEAN_Rule {

	/**
	 * Real names of the tables the last clean_batch() call deleted rows from.
	 *
	 * @var string[]
	 */
	private $touched = array();

	/**
	 * Table specs, processed in order.
	 *
	 * @return array[]
	 */
	abstract protected function specs();

	/**
	 * Resolve specs to existing tables with their WHERE clause.
	 *
	 * @return array[] List of array( 'table' => real name, 'where' => string ).
	 */
	private function resolved() {
		$out = array();
		foreach ( $this->specs() as $spec ) {
			$global = ! empty( $spec['global'] );
			$table  = PLCLEAN_DB::find_table( $spec['table'], $global );
			if ( null === $table ) {
				continue;
			}
			$where = isset( $spec['where'] ) ? (string) $spec['where'] : '';
			if ( ! empty( $spec['orphan_of'] ) ) {
				list( $parent_suffix, $child_col, $parent_col ) = $spec['orphan_of'];
				$parent = PLCLEAN_DB::find_table( $parent_suffix, $global );
				if ( null === $parent ) {
					$where = '';
				} else {
					// Identifiers come from rule code, never user input.
					$where = "NOT EXISTS (SELECT 1 FROM `{$parent}` p WHERE p.`{$parent_col}` = `{$table}`.`{$child_col}`)";
				}
			}
			$out[] = array(
				'table' => $table,
				'where' => $where,
			);
		}
		return $out;
	}

	/**
	 * Whether any spec is network-wide.
	 *
	 * @return bool
	 */
	private function has_global_spec() {
		foreach ( $this->specs() as $spec ) {
			if ( ! empty( $spec['global'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Network-wide when any spec is global.
	 *
	 * @return bool
	 */
	public function is_network_wide() {
		return $this->has_global_spec();
	}

	/**
	 * Available when any spec table exists. Network-wide tables (base prefix) are shared by every site of every
	 * network, so on multisite they are only available on the main site of the main network.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( $this->has_global_spec() && is_multisite() && ( ! is_main_site() || ! is_main_network() ) ) {
			return false;
		}
		return array() !== $this->resolved();
	}

	/**
	 * Count matching rows.
	 *
	 * @return int
	 */
	public function count() {
		$total = 0;
		foreach ( $this->resolved() as $item ) {
			$total += PLCLEAN_DB::count( $item['table'], $item['where'] );
		}
		return $total;
	}

	/**
	 * Delete up to $limit rows in total across specs, in order.
	 *
	 * Stops at the first database error and returns false; rows already deleted from earlier tables in this
	 * call stay deleted.
	 *
	 * @param int $limit Maximum rows to delete.
	 * @return int|false Rows deleted, or false on a database error.
	 */
	public function clean_batch( $limit ) {
		$remaining     = (int) $limit;
		$deleted       = 0;
		$this->touched = array();
		foreach ( $this->resolved() as $item ) {
			if ( $remaining <= 0 ) {
				break;
			}
			$n = PLCLEAN_DB::delete_batch( $item['table'], $item['where'], $remaining );
			if ( false === $n ) {
				return false;
			}
			if ( $n > 0 ) {
				$this->touched[] = $item['table'];
			}
			$deleted   += $n;
			$remaining -= $n;
		}
		return $deleted;
	}

	/**
	 * Real names of the tables the last clean_batch() call deleted rows from (empty before the first call).
	 *
	 * @return string[]
	 */
	public function last_tables_touched() {
		return array_values( array_unique( $this->touched ) );
	}

	/**
	 * Total size of existing tables.
	 *
	 * @return int
	 */
	public function size() {
		$total = 0;
		foreach ( $this->tables() as $table ) {
			$total += PLCLEAN_DB::table_size( $table );
		}
		return $total;
	}

	/**
	 * Real names of existing spec tables.
	 *
	 * @return string[]
	 */
	public function tables() {
		return array_values( array_unique( wp_list_pluck( $this->resolved(), 'table' ) ) );
	}

	/**
	 * Largest batch for plugin log tables: automatic batches grow on fast servers up to 50000; a fixed size
	 * chosen under Advanced settings may go up to 100000.
	 *
	 * @return int
	 */
	public function max_batch_size() {
		return PLCLEAN_Settings::is_fixed_batch() ? PLCLEAN_Cleaner::MAX_FIXED_BATCH : PLCLEAN_Cleaner::MAX_AUTO_BATCH;
	}

	/**
	 * Existing spec tables with their exact names (as the database reports them) and sizes in bytes.
	 *
	 * @return array[] List of array( 'name' => string, 'size' => int ).
	 */
	public function table_details() {
		$out = array();
		foreach ( $this->tables() as $table ) {
			$out[] = array(
				'name' => $table,
				'size' => PLCLEAN_DB::table_size( $table ),
			);
		}
		return $out;
	}
}
