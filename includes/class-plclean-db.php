<?php
/**
 * Database helper for locating and trimming other plugins' log tables.
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static DB helpers. All `$where` arguments must be static SQL fragments from rule code, never user input.
 */
class PLCLEAN_DB {

	/**
	 * Per-request cache of resolved table names.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Whether this request already asked MySQL 8 for current table statistics.
	 *
	 * @var bool
	 */
	private static $fresh_stats = false;

	/**
	 * Characters a table name may have. Anything else (a backtick, a space, a dot, a hyphen…) is refused, so a
	 * table name can never change the SQL it is put into. `D` stops `$` from also matching before a final newline.
	 */
	const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_$]+$/D';

	/**
	 * A table name ready for SQL: wrapped in backticks, or null when the name does not match IDENTIFIER_PATTERN.
	 * Callers skip the table on null.
	 *
	 * @param mixed $name Table name.
	 * @return string|null
	 */
	public static function quote_identifier( $name ) {
		if ( ! is_string( $name ) || ! preg_match( self::IDENTIFIER_PATTERN, $name ) ) {
			return null;
		}
		return '`' . $name . '`';
	}

	/**
	 * Find the real name of a table, matching case-insensitively. A table whose name could not be quoted (see
	 * quote_identifier()) is never returned, so its rule skips it.
	 *
	 * The prefix part of the LIKE pattern is case-sensitive on servers with case-sensitive table names, and the
	 * suffix is compared case-insensitively. If two tables share the prefix and differ only in the case of the
	 * suffix (for example a legacy wp_wfHits next to wp_wfhits), the first one SHOW TABLES lists wins. This is
	 * intentional: both belong to the same plugin, and a table under another install's differently-cased prefix
	 * can never match.
	 *
	 * @param string $suffix Table name without the prefix.
	 * @param bool   $global Use the network base prefix instead of the site prefix.
	 * @return string|null Real table name or null when missing.
	 */
	public static function find_table( $suffix, $global = false ) {// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.globalFound -- Name is part of the specified interface.
		global $wpdb;

		$prefix = $global ? $wpdb->base_prefix : $wpdb->prefix;
		$key    = $prefix . '|' . $suffix;
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		$wanted = strtolower( $prefix . $suffix );
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$found  = null;

		// Table discovery is a one-off, per-request-cached schema query; values are prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		foreach ( (array) $tables as $table ) {
			if ( strtolower( $table ) === $wanted && null !== self::quote_identifier( $table ) ) {
				$found = $table;
				break;
			}
		}

		self::$cache[ $key ] = $found;
		return $found;
	}

	/**
	 * Clear the per-request caches (table names, and whether fresh table statistics were asked for).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache       = array();
		self::$fresh_stats = false;
	}

	/**
	 * Whether a database server version string is MySQL 8.0 or later (not MariaDB, which also reports a version
	 * such as "5.5.5-10.11.14-MariaDB").
	 *
	 * @param string $server_info Server version string, e.g. from $wpdb->db_server_info().
	 * @return bool
	 */
	public static function is_mysql8( $server_info ) {
		$info = (string) $server_info;
		if ( false !== stripos( $info, 'mariadb' ) || ! preg_match( '/^(\d+\.\d+)/', $info, $m ) ) {
			return false;
		}
		return version_compare( $m[1], '8.0', '>=' );
	}

	/**
	 * On MySQL 8, make information_schema sizes current for this session (they are cached for 24 hours by
	 * default, so a size read after a clean or an optimize would be stale). Once per request; errors are ignored.
	 *
	 * @return void
	 */
	private static function fresh_stats() {
		global $wpdb;
		if ( self::$fresh_stats ) {
			return;
		}
		self::$fresh_stats = true;
		if ( ! self::is_mysql8( $wpdb->db_server_info() ) ) {
			return;
		}
		$suppress = $wpdb->suppress_errors( true );
		// A fixed session setting, no values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET SESSION information_schema_stats_expiry = 0' );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Table size in bytes (data plus indexes).
	 *
	 * @param string $table Real table name from find_table().
	 * @return int
	 */
	public static function table_size( $table ) {
		global $wpdb;

		self::fresh_stats();

		// Reading live table statistics; caching would make the size stale.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT data_length + index_length FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			)
		);
		return (int) $size;
	}

	/**
	 * Count rows, optionally filtered.
	 *
	 * @param string $table Real table name from find_table().
	 * @param string $where Static SQL condition from rule code (no WHERE keyword), or empty.
	 * @return int
	 */
	public static function count( $table, $where = '' ) {
		global $wpdb;

		$quoted = self::quote_identifier( $table );
		if ( null === $quoted ) {
			return 0;
		}
		$sql = "SELECT COUNT(*) FROM {$quoted}";
		if ( '' !== $where ) {
			$sql .= " WHERE {$where}";
		}
		// Table is resolved by find_table() and quoted by quote_identifier(); $where is a static fragment from rule code; live count must not be cached.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Delete up to $limit rows. Empty $where deletes all rows.
	 *
	 * @param string $table Real table name from find_table().
	 * @param string $where Static SQL condition from rule code (no WHERE keyword), or empty.
	 * @param int    $limit Maximum rows to delete.
	 * @return int|false Rows deleted (0 for a name quote_identifier() refuses), or false when the database refused the DELETE (lock wait timeout,
	 *                   deadlock, read-only server…); $wpdb->last_error then holds the reason.
	 */
	public static function delete_batch( $table, $where, $limit ) {
		global $wpdb;

		$quoted = self::quote_identifier( $table );
		if ( null === $quoted ) {
			return 0;
		}
		$limit = max( 1, (int) $limit );
		$sql   = "DELETE FROM {$quoted}";
		if ( '' !== $where ) {
			$sql .= " WHERE {$where}";
		}
		$sql .= " LIMIT {$limit}";
		// Table is resolved by find_table() and quoted by quote_identifier(); $where is a static fragment from rule code; $limit is an int.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$deleted = $wpdb->query( $sql );
		return false === $deleted ? false : (int) $deleted;
	}

	/**
	 * Refresh a table's statistics (ANALYZE TABLE), so the sizes read afterwards are current. Cheap: InnoDB samples
	 * a few index pages instead of reading the table.
	 *
	 * @param string $table Real table name from find_table().
	 * @return void
	 */
	public static function analyze( $table ) {
		global $wpdb;

		$quoted = self::quote_identifier( $table );
		if ( null === $quoted ) {
			return;
		}
		// Table is resolved by find_table() and quoted by quote_identifier().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "ANALYZE TABLE {$quoted}" );
	}

	/**
	 * Optimize a table to reclaim space.
	 *
	 * @param string $table Real table name from find_table().
	 * @return void
	 */
	public static function optimize( $table ) {
		global $wpdb;

		$quoted = self::quote_identifier( $table );
		if ( null === $quoted ) {
			return;
		}
		// Table is resolved by find_table() and quoted by quote_identifier().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "OPTIMIZE TABLE {$quoted}" );
	}
}
