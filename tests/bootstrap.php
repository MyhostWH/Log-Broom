<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite (wp-phpunit) with Log Broom active.
 *
 * @package Log_Broom
 */

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$plclean_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require_once $plclean_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/log-broom.php';
	}
);

require $plclean_tests_dir . '/includes/bootstrap.php';
