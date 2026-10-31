<?php
/**
 * Where the plugin keeps its options, on a single site and network-wide (#85).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Channel_Store;
use WPUN\Mute_Store;
use WPUN\Network_Context;
use WPUN\Plugin;
use WPUN\Settings;
use WPUN\State;

/**
 * Network_Context and the stores that use it.
 */
class Test_Network_Context extends WPUN_Test_Case {

	/**
	 * The plugin's key in the network's active plugins.
	 *
	 * @return string
	 */
	private function basename() {
		return plugin_basename( SC_WPUN_FILE );
	}

	/**
	 * Network-activate the plugin, as far as the plugin can tell.
	 */
	private function network_activate() {
		$active                      = (array) get_site_option( 'active_sitewide_plugins', array() );
		$active[ $this->basename() ] = time();
		update_site_option( 'active_sitewide_plugins', $active );
	}

	/**
	 * Undo network_activate().
	 */
	private function network_deactivate() {
		$active = (array) get_site_option( 'active_sitewide_plugins', array() );
		unset( $active[ $this->basename() ] );
		update_site_option( 'active_sitewide_plugins', $active );
	}

	/**
	 * Skip on a single site.
	 */
	private function require_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite.' );
		}
	}

	/**
	 * Start without the plugin's options.
	 */
	public function set_up() {
		parent::set_up();

		foreach ( array( 'sc_wpun_test', 'sc_wpun_settings', 'sc_wpun_state', 'sc_wpun_channels', 'sc_wpun_muted' ) as $name ) {
			delete_option( $name );
			if ( is_multisite() ) {
				delete_site_option( $name );
			}
		}
	}

	/**
	 * Leave the network as found.
	 */
	public function tear_down() {
		if ( is_multisite() ) {
			$this->network_deactivate();
		}
		parent::tear_down();
	}

	/**
	 * Not network-activated, the options are ordinary ones.
	 */
	public function test_it_is_not_a_network_unless_network_activated() {
		$context = new Network_Context();

		$this->assertFalse( $context->is_network() );

		$context->update_option( 'sc_wpun_test', 'a' );
		$this->assertSame( 'a', get_option( 'sc_wpun_test' ) );
	}

	/**
	 * Single-site storage maps onto the option functions, with the autoload flag honoured.
	 */
	public function test_site_storage_uses_the_option_functions() {
		$context = new Network_Context();

		$this->assertSame( 'fallback', $context->get_option( 'sc_wpun_test', 'fallback' ) );
		$this->assertFalse( $context->get_option( 'sc_wpun_test' ) );

		$this->assertTrue( $context->add_option( 'sc_wpun_test', 'a' ) );
		$this->assertSame( 'a', get_option( 'sc_wpun_test' ) );
		$this->assertSame( 'off', $this->autoload( 'sc_wpun_test' ) );

		$this->assertTrue( $context->update_option( 'sc_wpun_test', 'b' ) );
		$this->assertSame( 'b', $context->get_option( 'sc_wpun_test' ) );

		$this->assertTrue( $context->delete_option( 'sc_wpun_test' ) );
		$this->assertFalse( get_option( 'sc_wpun_test' ) );
	}

	/**
	 * The autoload setting of an option.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	private function autoload( $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reads the autoload flag, which no API exposes.
		return $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * The callback gets the old and the new value when an option changes.
	 */
	public function test_the_update_callback_receives_old_and_new_values_on_a_site() {
		$calls   = array();
		$context = new Network_Context();
		$context->on_option_updated(
			'sc_wpun_test',
			static function () use ( &$calls ) {
				$calls[] = func_get_args();
			}
		);

		update_option( 'sc_wpun_test', 'a' );
		update_option( 'sc_wpun_test', 'b' );

		$this->assertSame( array( array( 'a', 'b' ) ), array_slice( $calls, -1 ) );
	}

	/**
	 * The main site of a single site is the current one.
	 */
	public function test_the_main_site_id() {
		$context = new Network_Context();

		$this->assertSame( is_multisite() ? get_main_site_id() : get_current_blog_id(), $context->main_site_id() );
	}

	/**
	 * Network-activating flips the answer, and deactivating flips it back.
	 */
	public function test_network_activation_makes_it_a_network() {
		$this->require_multisite();
		$context = new Network_Context();

		$this->network_activate();
		$this->assertTrue( $context->is_network() );

		$this->network_deactivate();
		$this->assertFalse( $context->is_network() );
	}

	/**
	 * Network storage uses site options and leaves the site's own options alone.
	 */
	public function test_network_storage_uses_site_options() {
		$this->require_multisite();
		$context = new Network_Context();
		$this->network_activate();

		$this->assertSame( 'fallback', $context->get_option( 'sc_wpun_test', 'fallback' ) );
		$this->assertTrue( $context->add_option( 'sc_wpun_test', 'a', true ) );
		$this->assertSame( 'a', get_site_option( 'sc_wpun_test' ) );
		$this->assertFalse( get_option( 'sc_wpun_test' ) );

		$this->assertTrue( $context->update_option( 'sc_wpun_test', 'b' ) );
		$this->assertSame( 'b', get_site_option( 'sc_wpun_test' ) );

		// A site option of the same name is not what the network reads.
		update_option( 'sc_wpun_test', 'site' );
		$this->assertSame( 'b', $context->get_option( 'sc_wpun_test' ) );

		$this->assertTrue( $context->delete_option( 'sc_wpun_test' ) );
		$this->assertFalse( get_site_option( 'sc_wpun_test' ) );
		$this->assertSame( 'site', get_option( 'sc_wpun_test' ) );
	}

	/**
	 * Network-wide the callback still gets ( old, new ), not WordPress's four arguments.
	 */
	public function test_the_update_callback_receives_old_and_new_values_on_the_network() {
		$this->require_multisite();
		$this->network_activate();

		$calls   = array();
		$context = new Network_Context();
		$context->on_option_updated(
			'sc_wpun_test',
			static function () use ( &$calls ) {
				$calls[] = func_get_args();
			}
		);

		update_option( 'sc_wpun_test', 'site' ); // Not the network's option.
		update_site_option( 'sc_wpun_test', 'a' );
		update_site_option( 'sc_wpun_test', 'b' );

		$this->assertSame( array( array( 'a', 'b' ) ), array_slice( $calls, -1 ) );
		$this->assertCount( 1, $calls );
	}

	/**
	 * Every store saves to and reads from the network's options when network-activated.
	 */
	public function test_the_stores_round_trip_through_site_options_on_the_network() {
		$this->require_multisite();
		$this->network_activate();

		$seen_get = array();
		$seen_put = array();
		add_filter(
			'sc_wpun_get_options_filter',
			static function ( $value, $field ) use ( &$seen_get ) {
				$seen_get[] = $field;
				return $value;
			},
			10,
			2
		);
		add_filter(
			'sc_wpun_put_options_filter',
			static function ( $value, $field ) use ( &$seen_put ) {
				$seen_put[] = $field;
				return $value;
			},
			10,
			2
		);

		$plugin = sc_wpun();

		$plugin->settings()->write_option( Settings::OPT_FIELD, array( 'frequency' => 'weekly' ) );
		$this->assertSame( 'weekly', get_site_option( 'sc_wpun_settings' )['frequency'] );
		$this->assertSame( 'weekly', $plugin->settings()->read_option( Settings::OPT_FIELD )['frequency'] );

		$state                      = $plugin->state()->get();
		$state['slack_notice_sent'] = true;
		$plugin->state()->save( $state );
		$this->assertTrue( get_site_option( 'sc_wpun_state' )['slack_notice_sent'] );
		$this->assertTrue( $plugin->state()->get()['slack_notice_sent'] );

		$plugin->channel_store()->save( new Channel_Instance( 'one', 'test', 'Label', true, array( 'key' => 'value' ), array( 'types' => array( 'core' ) ) ) );
		$this->assertArrayHasKey( 'one', get_site_option( 'sc_wpun_channels' ) );
		$this->assertArrayHasKey( 'one', $plugin->channel_store()->all() );

		$this->set_updates(
			null,
			array(
				'noisy/noisy.php' => array(
					'new_version' => '2.0',
					'slug'        => 'noisy',
					'url'         => '',
				),
			)
		);
		$this->assertNotWPError( $plugin->mute_store()->mute( 'plugin', 'noisy/noisy.php' ) );
		$this->assertArrayHasKey( 'plugin', get_site_option( 'sc_wpun_muted' ) );
		$this->assertNotEmpty( $plugin->mute_store()->all() );

		foreach ( array( 'sc_wpun_settings', 'sc_wpun_state', 'sc_wpun_channels', 'sc_wpun_muted' ) as $name ) {
			$this->assertFalse( get_option( $name ), $name . ' must not be a site option.' );
			$this->assertContains( $name, $seen_get, $name . ' goes through the get filter.' );
			$this->assertContains( $name, $seen_put, $name . ' goes through the put filter.' );
		}
	}

	/**
	 * A store built without a context follows the site's setup, so add-ons constructing one keep working.
	 */
	public function test_stores_default_to_the_current_setup() {
		$store = new Channel_Store( sc_wpun()->config(), sc_wpun()->channels() );
		$store->save( new Channel_Instance( 'one', 'test', 'Label', true, array( 'key' => 'value' ), array( 'types' => array( 'core' ) ) ) );

		$this->assertArrayHasKey( 'one', get_option( 'sc_wpun_channels' ) );

		( new State() )->save( array() );
		$this->assertIsArray( get_option( 'sc_wpun_state' ) );

		$this->assertInstanceOf( Mute_Store::class, new Mute_Store() );
	}

	/**
	 * Changing the frequency network-wide reschedules the check.
	 */
	public function test_a_network_frequency_change_reschedules_the_check() {
		$this->require_multisite();
		$this->network_activate();
		$this->save_network_settings( 'daily' );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );

		$plugin = new Plugin();
		$plugin->boot();
		$this->assertNotFalse( has_action( 'update_site_option_sc_wpun_settings' ) );

		$settings              = get_site_option( 'sc_wpun_settings' );
		$settings['frequency'] = 'weekly';
		update_site_option( 'sc_wpun_settings', $settings );

		$this->assertSame( 'weekly', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * Store settings as a network option.
	 *
	 * @param string $frequency Frequency.
	 */
	private function save_network_settings( $frequency ) {
		$settings              = sc_wpun()->settings()->default_settings();
		$settings['frequency'] = $frequency;
		update_site_option( 'sc_wpun_settings', $settings );
		update_site_option( 'sc_wpun_settings_ver', self::SETTINGS_VERSION );
	}

	/**
	 * On a single site the settings change reschedules through the ordinary option hook.
	 */
	public function test_a_site_frequency_change_reschedules_the_check() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single site only.' );
		}
		$this->save_settings( array( 'frequency' => 'daily' ) );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );

		$plugin = new Plugin();
		$plugin->boot();
		$this->assertNotFalse( has_action( 'update_option_sc_wpun_settings' ) );

		update_option( 'sc_wpun_settings', array_merge( get_option( 'sc_wpun_settings' ), array( 'frequency' => 'weekly' ) ) );

		$this->assertSame( 'weekly', wp_get_schedule( 'sc_wpun_update_check' ) );
	}
}
