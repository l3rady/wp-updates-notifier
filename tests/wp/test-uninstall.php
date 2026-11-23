<?php
/**
 * Uninstalling removes everything the plugin stored (#78).
 *
 * @package wp-updates-notifier
 */

/**
 * The uninstall routine (uninstall.php).
 */
class Test_Uninstall extends WPUN_Test_Case {
	/**
	 * Option name constants that uninstall.php leaves out on purpose, with the reason.
	 *
	 * @var array<string, string>
	 */
	const KEPT_ON_PURPOSE = array(
		'WPUN\\Settings::OPT_VERSION'       => 'A version number, not an option name.',
		'WPUN\\Settings_Groups::OPT_PREFIX' => 'The options of settings groups are kept on uninstall (decision 0031).',
	);

	/**
	 * A user who dismissed the notice that 2.x showed about Slack.
	 *
	 * @var int
	 */
	private static $user_id;

	/**
	 * Create the user.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$user_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Constants that name an option or a user meta key, found by scanning the PHP files of the plugin and its modules.
	 *
	 * Found by name (`META_KEY` and `OPT_*`), so a constant added later is picked up without editing this test.
	 *
	 * @return array<string, mixed> The value of each, by `Class::CONSTANT`.
	 */
	private static function storage_constants() {
		$root      = dirname( __DIR__, 2 );
		$constants = array();
		foreach ( array( 'includes', 'modules' ) as $dir ) {
			if ( ! is_dir( "$root/$dir" ) ) {
				continue;
			}
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$dir", FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
				if ( 1 !== preg_match( '/^namespace\s+([\w\\\\]+);/m', $code, $namespace ) || 1 !== preg_match( '/^(?:final\s+|abstract\s+)*class\s+(\w+)/m', $code, $class ) ) {
					continue;
				}
				preg_match_all( '/^\s*const\s+(META_KEY|OPT_\w+)\s*=/m', $code, $found );
				foreach ( $found[1] as $name ) {
					$full                              = $namespace[1] . '\\' . $class[1];
					$constants[ $full . '::' . $name ] = ( new ReflectionClassConstant( $full, $name ) )->getValue();
				}
			}
		}

		return $constants;
	}

	/**
	 * The user meta keys the plugin writes.
	 *
	 * @return string[]
	 */
	private static function meta_keys() {
		$keys = array();
		foreach ( self::storage_constants() as $constant => $value ) {
			if ( 'META_KEY' === substr( $constant, -8 ) ) {
				$keys[] = $value;
			}
		}

		// Written by 2.x, no constant any more.
		$keys[] = 'sc_wpun_dismissed_slack_notice';

		return $keys;
	}

	/**
	 * Store everything the plugin can leave behind on the current site.
	 */
	private function leave_traces() {
		// The site's own options, as 2.x and a single site keep them, whatever mode the suite runs in.
		foreach ( WPUN\Settings::OPTIONS as $option ) {
			update_option( $option, array( 'trace' => true ), false );
		}
		update_option( WPUN\Instance_Data::PREFIX . 'email', array( 'cursor' ), false );
		update_option( WPUN\Instance_Data::PREFIX . 'email__cursor', 'abc', false );
		update_option( WPUN\Instance_Data::PREFIX . 'gone__cursor', 'abc', false ); // An instance removed from code.
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		wp_schedule_event( time(), 'daily', 'sc_wpun_update_check' );
		set_transient( 'sc_wpun_send_test_email', 1 );
		set_transient( 'sc_wpun_send_test_slack', 1 );
		foreach ( self::meta_keys() as $key ) {
			update_user_meta( self::$user_id, $key, array( 'trace' => true ) );
		}
	}

	/**
	 * Assert nothing of the plugin is left on the current site.
	 *
	 * @param string $where Label for failure messages.
	 */
	private function assert_no_traces( $where ) {
		$prefix  = WPUN\Instance_Data::PREFIX;
		$options = array_merge( WPUN\Settings::OPTIONS, array( $prefix . 'email', $prefix . 'email__cursor', $prefix . 'gone__cursor' ) );
		foreach ( $options as $option ) {
			$this->assertFalse( get_option( $option ), "$where: $option" );
			if ( is_multisite() ) {
				$this->assertFalse( get_site_option( $option ), "$where: network $option" );
			}
		}
		$this->assertFalse( wp_next_scheduled( 'sc_wpun_update_check' ), "$where: cron event" );
		$this->assertFalse( get_transient( 'sc_wpun_send_test_email' ), "$where: test email transient" );
		$this->assertFalse( get_transient( 'sc_wpun_send_test_slack' ), "$where: test Slack transient" );
		foreach ( self::meta_keys() as $key ) {
			$this->assertSame( '', get_user_meta( self::$user_id, $key, true ), "$where: user meta $key" );
		}
	}

	/**
	 * Run uninstall.php the way WordPress does.
	 */
	private function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-updates-notifier/wp-updates-notifier.php' );
		}

		include WP_PLUGIN_DIR . '/wp-updates-notifier/uninstall.php';
	}

	/**
	 * Single site: options, run state, transients and the cron event all go.
	 */
	public function test_uninstall_removes_everything() {
		$this->leave_traces();

		$this->uninstall();

		$this->assert_no_traces( 'site' );
	}

	/**
	 * Running it twice is harmless.
	 */
	public function test_uninstall_can_run_twice() {
		$this->leave_traces();

		$this->uninstall();
		$this->uninstall();

		$this->assert_no_traces( 'site' );
	}

	/**
	 * Multisite: every site of the network is cleaned, not just the current one.
	 */
	public function test_uninstall_cleans_every_site_of_a_network() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}

		$this->leave_traces();
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		$this->leave_traces();
		restore_current_blog();

		$this->uninstall();

		$this->assert_no_traces( 'main site' );
		switch_to_blog( $blog_id );
		$this->assert_no_traces( 'other site' );
		restore_current_blog();
	}

	/**
	 * Multisite: the network's own options go too, and the sites keep nothing.
	 */
	public function test_uninstall_removes_the_network_options() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}

		$this->leave_traces();
		foreach ( WPUN\Settings::OPTIONS as $option ) {
			update_site_option( $option, array( 'network' => true ) );
		}
		update_site_option( WPUN\Instance_Data::PREFIX . 'email', array( 'cursor' ) );
		update_site_option( WPUN\Instance_Data::PREFIX . 'email__cursor', 'abc' );
		update_site_option( WPUN\Instance_Data::PREFIX . 'gone__cursor', 'abc' );

		$this->uninstall();

		$this->assert_no_traces( 'network' );
	}

	/**
	 * Every option the plugin stores is named in uninstall.php, which runs without the plugin loaded and so keeps
	 * its own list. Adding an `OPT_*` constant without naming it there, or in Settings::OPTIONS, fails here.
	 */
	public function test_every_option_constant_is_in_the_options_list_and_in_uninstall() {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$constants = self::storage_constants();
		$this->assertArrayHasKey( 'WPUN\\Settings::OPT_FIELD', $constants, 'The scan finds the constants.' );

		foreach ( $constants as $constant => $value ) {
			if ( 0 !== strpos( substr( $constant, (int) strpos( $constant, '::' ) + 2 ), 'OPT_' ) || isset( self::KEPT_ON_PURPOSE[ $constant ] ) ) {
				continue;
			}
			$this->assertContains( $value, WPUN\Settings::OPTIONS, "$constant is not in Settings::OPTIONS." );
		}
		foreach ( self::KEPT_ON_PURPOSE as $constant => $reason ) {
			$this->assertArrayHasKey( $constant, $constants, "$constant is excluded ($reason) but no longer exists." );
		}
		foreach ( WPUN\Settings::OPTIONS as $option ) {
			$this->assertStringContainsString( "'$option'", $uninstall, "uninstall.php does not remove the option $option." );
		}
	}

	/**
	 * Every user meta key and the instance data prefix is named in uninstall.php.
	 */
	public function test_uninstall_names_every_user_meta_key_and_the_data_prefix() {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		$this->assertGreaterThanOrEqual( 4, count( self::meta_keys() ), 'The scan finds the keys.' );
		foreach ( self::meta_keys() as $key ) {
			$this->assertStringContainsString( "'$key'", $uninstall, "uninstall.php does not remove the user meta $key." );
		}
		$this->assertStringContainsString( "'" . WPUN\Instance_Data::PREFIX . "'", $uninstall );
	}
}
