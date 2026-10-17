<?php
/**
 * "Only active plugins / themes" (#71).
 *
 * @package wp-updates-notifier
 */

/**
 * What counts as active.
 */
class Test_Active_Only extends WPUN_Test_Case {

	/**
	 * Fake the plugin information request core makes (until #70 removes it).
	 */
	public function set_up() {
		parent::set_up();

		$this->fake_http(
			'api.wordpress.org/plugins/info',
			$this->fake_response(
				200,
				wp_json_encode(
					array(
						'name'    => 'Hello Dolly',
						'slug'    => 'hello-dolly',
						'version' => '9.9.9',
						'tested'  => '99.0',
					)
				)
			)
		);
	}

	/**
	 * Two installed themes, standing in for a parent and a child.
	 *
	 * @return array
	 */
	private function parent_and_child() {
		$slugs = array_keys( wp_get_themes() );
		update_option( 'template', $slugs[0] );
		update_option( 'stylesheet', $slugs[1] );

		return array( $slugs[0], $slugs[1] );
	}

	/**
	 * The active child theme is notified about.
	 */
	public function test_the_active_child_theme_is_notified_about() {
		list( , $child ) = $this->parent_and_child();
		$this->save_settings(
			array(
				'notify_themes'  => 'active',
				'notify_plugins' => 'none',
			)
		);
		$this->set_updates( null, array(), array( $child => array( 'new_version' => '99.3' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * Its parent theme is still notified about.
	 */
	public function test_the_parent_of_the_active_child_theme_is_notified_about() {
		list( $parent ) = $this->parent_and_child();
		$this->save_settings(
			array(
				'notify_themes'  => 'active',
				'notify_plugins' => 'none',
			)
		);
		$this->set_updates( null, array(), array( $parent => array( 'new_version' => '99.3' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * Other installed themes are not.
	 */
	public function test_other_themes_are_not_notified_about() {
		$slugs = array_keys( wp_get_themes() );
		$this->assertGreaterThanOrEqual( 3, count( $slugs ), 'The checkout should ship at least three themes.' );
		update_option( 'template', $slugs[0] );
		update_option( 'stylesheet', $slugs[1] );
		$this->save_settings(
			array(
				'notify_themes'  => 'active',
				'notify_plugins' => 'none',
			)
		);
		$this->set_updates( null, array(), array( $slugs[2] => array( 'new_version' => '99.3' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * A network-activated plugin counts as active on multisite.
	 */
	public function test_a_network_activated_plugin_counts_as_active() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}

		update_option( 'active_plugins', array() );
		update_site_option( 'active_sitewide_plugins', array( 'hello.php' => time() ) );
		$this->save_settings( array( 'notify_plugins' => 'active' ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * A plugin that is neither site- nor network-active is skipped.
	 */
	public function test_an_inactive_plugin_is_skipped() {
		update_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			update_site_option( 'active_sitewide_plugins', array() );
		}
		$this->save_settings( array( 'notify_plugins' => 'active' ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}
}
