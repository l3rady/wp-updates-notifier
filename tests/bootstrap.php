<?php
/**
 * PHPUnit bootstrap: WordPress's own test bootstrap with this plugin loaded.
 *
 * Run through `make test-wp` / `make test-wp-ms`; see docker/wp-tests.sh.
 *
 * @package wp-updates-notifier
 */

$wpun_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wpun_tests_dir ) {
	$wpun_tests_dir = '/opt/wordpress-develop/tests/phpunit';
}

if ( ! file_exists( $wpun_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found at {$wpun_tests_dir}. Run the tests with `make test-wp`.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}

require_once $wpun_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/wp-updates-notifier/class-sc-wp-updates-notifier.php';
	}
);

require $wpun_tests_dir . '/includes/bootstrap.php';

// Needs WP_UnitTestCase, so it loads after core's bootstrap.
require_once __DIR__ . '/wp/class-wpun-test-case.php';
