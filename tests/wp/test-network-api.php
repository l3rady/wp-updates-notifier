<?php
/**
 * The public functions for networks: sc_wpun_is_network() and sc_wpun_settings_url() (#85).
 *
 * @package wp-updates-notifier
 */

/**
 * Both functions answer for the mode the plugin runs in.
 */
class Test_Network_Api extends WPUN_Test_Case {

	/**
	 * A single site, or a multisite where the suite network-activates the plugin.
	 */
	public function test_is_network_follows_network_activation() {
		$this->assertSame( is_multisite(), sc_wpun_is_network() );

		if ( is_multisite() ) {
			$this->set_network_activated( false );
			$this->assertFalse( sc_wpun_is_network() );
		}
	}

	/**
	 * The settings screen and its tabs, in the admin the plugin's screen is in.
	 */
	public function test_settings_url_points_to_the_screen_in_either_mode() {
		if ( is_multisite() ) {
			$this->assertSame( network_admin_url( 'settings.php?page=wp-updates-notifier' ), sc_wpun_settings_url() );
			$this->assertSame( network_admin_url( 'settings.php?page=wp-updates-notifier&tab=channels' ), sc_wpun_settings_url( 'channels' ) );
			$this->set_network_activated( false );
		}

		$this->assertSame( admin_url( 'options-general.php?page=wp-updates-notifier' ), sc_wpun_settings_url() );
		$this->assertSame( admin_url( 'options-general.php?page=wp-updates-notifier&tab=muted' ), sc_wpun_settings_url( 'muted' ) );
	}

	/**
	 * A tab name is encoded, not trusted.
	 */
	public function test_settings_url_encodes_the_tab() {
		$this->assertStringEndsWith( '&tab=a%20b%26c', sc_wpun_settings_url( 'a b&c' ) );
	}
}
