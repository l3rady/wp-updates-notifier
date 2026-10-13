<?php
/**
 * Hiding WordPress's update notice (#77).
 *
 * @package wp-updates-notifier
 */

/**
 * The "hide the update notice" setting.
 */
class Test_Update_Nag extends WPUN_Test_Case {

	/**
	 * Register the notice as wp-admin does.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'admin_notices', 'update_nag', 3 );
		add_action( 'network_admin_notices', 'update_nag', 3 );
	}

	/**
	 * Log in a user with a role and optional extra capability.
	 *
	 * @param string      $role Role.
	 * @param string|null $cap  Extra capability.
	 */
	private function log_in( $role, $cap = null ) {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		if ( $cap ) {
			get_userdata( $user )->add_cap( $cap );
		}
		wp_set_current_user( $user );
	}

	/**
	 * Users who can update core keep the notice, even without other admin capabilities.
	 */
	public function test_users_who_can_update_core_keep_the_notice() {
		$this->save_settings( array( 'hide_updates' => 1 ) );
		$this->log_in( 'editor', 'update_core' );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() ); // On multisite only super admins can update core.
		}

		$this->plugin()->remove_update_nag_for_nonadmins();

		$this->assertSame( 3, has_action( 'admin_notices', 'update_nag' ) );
	}

	/**
	 * Users who cannot update core lose it, in the site and the network admin.
	 */
	public function test_users_who_cannot_update_core_lose_the_notice() {
		$this->save_settings( array( 'hide_updates' => 1 ) );
		$this->log_in( 'editor', 'update_plugins' );

		$this->plugin()->remove_update_nag_for_nonadmins();

		$this->assertFalse( has_action( 'admin_notices', 'update_nag' ) );
		$this->assertFalse( has_action( 'network_admin_notices', 'update_nag' ) );
	}

	/**
	 * Administrators keep it.
	 */
	public function test_administrators_keep_the_notice() {
		$this->save_settings( array( 'hide_updates' => 1 ) );
		$this->log_in( 'administrator' );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		$this->plugin()->remove_update_nag_for_nonadmins();

		$this->assertSame( 3, has_action( 'admin_notices', 'update_nag' ) );
	}

	/**
	 * With the setting off, nobody loses it.
	 */
	public function test_nothing_is_hidden_when_the_setting_is_off() {
		$this->save_settings( array( 'hide_updates' => 0 ) );
		$this->log_in( 'subscriber' );

		$this->plugin()->remove_update_nag_for_nonadmins();

		$this->assertSame( 3, has_action( 'admin_notices', 'update_nag' ) );
	}
}
