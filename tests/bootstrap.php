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
		require WP_PLUGIN_DIR . '/wp-updates-notifier/wp-updates-notifier.php';

		// On a multisite the plugin only runs network-activated; the tests that need another state change it themselves.
		if ( is_multisite() ) {
			$active                                    = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active[ plugin_basename( SC_WPUN_FILE ) ] = time();
			update_site_option( 'active_sitewide_plugins', $active );
		}
	}
);

require $wpun_tests_dir . '/includes/bootstrap.php';

// Core's test bootstrap sets WP_MEMORY_LIMIT and WP_MAX_MEMORY_LIMIT to -1, so WordPress lifts PHP's memory limit
// while it loads. Put a limit back: a runaway test then stops with a fatal error instead of using all the memory
// the machine has. wp_raise_memory_limit() can still lift it (it uses WP_MAX_MEMORY_LIMIT, with no filter), so
// WPUN_Test_Case puts it back before each test, and the test container's memory cap catches the rest.
define( 'WPUN_TESTS_MEMORY_LIMIT', '1G' );
ini_set( 'memory_limit', WPUN_TESTS_MEMORY_LIMIT ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- wp_raise_memory_limit() only raises it.

// The channel registry as the plugin's own boot leaves it: WPUN_Test_Case puts it back before each test.
$GLOBALS['wpun_channels_snapshot'] = sc_wpun()->channels()->snapshot();

// The traits the base test case uses.
require_once __DIR__ . '/wp/traits/trait-wpun-builders.php';
require_once __DIR__ . '/wp/traits/trait-wpun-markup.php';
require_once __DIR__ . '/wp/traits/trait-wpun-environment.php';

// Needs WP_UnitTestCase, so it loads after core's bootstrap.
require_once __DIR__ . '/wp/class-wpun-test-case.php';
require_once __DIR__ . '/wp/class-wpun-slack-test-case.php';
require_once __DIR__ . '/wp/fixtures/class-test-channel.php';
require_once __DIR__ . '/wp/fixtures/class-test-config-source.php';
require_once __DIR__ . '/wp/fixtures/class-test-fields-channel.php';
require_once __DIR__ . '/wp/fixtures/class-test-throwing-fields-channel.php';
require_once __DIR__ . '/wp/fixtures/class-test-webhook-fixed-channel.php';
require_once __DIR__ . '/fixtures/plugins/wpun-test-addon/class-test-form-channel.php';
// The test add-on's definitions only: nothing is wired until a test calls WPUN_Test_Addon\boot().
require_once __DIR__ . '/fixtures/plugins/wpun-test-addon/wpun-test-addon.php';
