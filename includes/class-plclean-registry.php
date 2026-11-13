<?php
/**
 * Registry of cleaning rules.
 *
 * @package Log_Broom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and caches the list of rules.
 */
class PLCLEAN_Registry {

	/**
	 * Core rule class names. Later tasks append here.
	 *
	 * @var string[]
	 */
	private static $core = array(
		'PLCLEAN_Rule_WooCommerce_Log',
		'PLCLEAN_Rule_Redirection',
		'PLCLEAN_Rule_Rank_Math_404',
		'PLCLEAN_Rule_WP_Mail_SMTP',
		'PLCLEAN_Rule_WP_Activity_Log',
		'PLCLEAN_Rule_Wordfence',
		'PLCLEAN_Rule_Action_Scheduler',
		'PLCLEAN_Rule_Solid_Security',
		'PLCLEAN_Rule_Revisions',
		'PLCLEAN_Rule_Transients',
	);

	/**
	 * Cached rules keyed by id.
	 *
	 * @var PLCLEAN_Rule[]|null
	 */
	private static $rules = null;

	/**
	 * All rules keyed by id().
	 *
	 * @return PLCLEAN_Rule[]
	 */
	public static function all() {
		if ( null !== self::$rules ) {
			return self::$rules;
		}

		$list = array();
		foreach ( self::$core as $class ) {
			$list[] = new $class();
		}

		/**
		 * Filters the list of cleaning rules.
		 *
		 * @param PLCLEAN_Rule[] $list Rule objects.
		 */
		$filtered = apply_filters( 'plclean_rules', $list );
		if ( ! is_array( $filtered ) ) {
			$filtered = $list;
		}

		$rules = array();
		foreach ( $filtered as $rule ) {
			if ( ! $rule instanceof PLCLEAN_Rule ) {
				continue;
			}
			$id = $rule->id();
			if ( isset( $rules[ $id ] ) ) {
				continue;
			}
			$rules[ $id ] = $rule;
		}

		self::$rules = $rules;
		return $rules;
	}

	/**
	 * Get a rule by id.
	 *
	 * @param string $id Rule id.
	 * @return PLCLEAN_Rule|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Clear the cache (for tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$rules = null;
	}
}
