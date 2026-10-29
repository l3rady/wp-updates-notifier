<?php
/**
 * The admin classes load only in admin requests.
 *
 * @package wp-updates-notifier
 */

/**
 * Each test runs in its own process: a class that has been loaded cannot be unloaded.
 */
class Test_Admin_Loading extends WPUN_Test_Case {

	/**
	 * A front-end request never loads the admin classes, and keeps the hooks that are not admin only.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_front_end_does_not_load_the_admin_classes() {
		$this->assertFalse( is_admin() );
		$this->assertFalse( class_exists( 'WPUN\Admin\Settings_Page', false ) );
		$this->assertFalse( class_exists( 'WPUN\Admin\Update_Nag', false ) );
		$this->assertFalse( class_exists( 'WPUN\Admin\Plugin_Requirements', false ) );
		$this->assertFalse( class_exists( 'WPUN\Admin\Check_Now', false ) );
		$this->assertFalse( class_exists( 'WPUN\Admin\Plugins_Screen_Mutes', false ) );
		$this->assertFalse( class_exists( 'WPUN\Admin\Muted_Tab', false ) );
		$this->assertNotFalse( has_filter( 'extra_plugin_headers' ) );
		$this->assertFalse( has_action( 'after_plugin_row' ) );
		$this->assertFalse( has_filter( 'auto_core_update_email' ) );
		$this->assertNotFalse( has_action( 'sc_wpun_update_check' ) );
		$this->assertNotFalse( has_action( 'admin_post_sc_wpun_dismiss_slack_notice' ) );
		$this->assertFalse( has_filter( 'plugin_action_links_' . plugin_basename( SC_WPUN_FILE ) ) );
		$this->assertFalse( has_filter( 'plugin_row_meta' ) );
	}

	/**
	 * An admin request loads them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_loads_the_admin_classes() {
		set_current_screen( 'dashboard' );
		( new \WPUN\Plugin() )->boot();

		$this->assertTrue( class_exists( 'WPUN\Admin\Settings_Page', false ) );
		$this->assertTrue( class_exists( 'WPUN\Admin\Update_Nag', false ) );
		$this->assertTrue( class_exists( 'WPUN\Admin\Plugin_Requirements', false ) );
		$this->assertTrue( class_exists( 'WPUN\Admin\Check_Now', false ) );
		$this->assertTrue( class_exists( 'WPUN\Admin\Plugins_Screen_Mutes', false ) );
		$this->assertTrue( class_exists( 'WPUN\Admin\Muted_Tab', false ) );
		$this->assertNotFalse( has_action( 'admin_post_sc_wpun_mute' ) );
		$this->assertNotFalse( has_action( 'admin_post_sc_wpun_muted_add' ) );
		$this->assertFalse( has_filter( 'plugin_action_links' ), 'The mute links are added when the Plugins screen loads.' );
		$this->assertNotFalse( has_action( 'admin_post_sc_wpun_check_now' ) );
		$this->assertNotFalse( has_action( 'after_plugin_row' ) );
		$this->assertFalse( has_filter( 'auto_core_update_email' ) );
		$this->assertNotFalse( has_action( 'sc_wpun_update_check' ) );
	}
}
