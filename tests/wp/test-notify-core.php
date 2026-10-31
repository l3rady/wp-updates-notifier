<?php
/**
 * The global setting that decides whether WordPress updates are collected (#260).
 *
 * @package wp-updates-notifier
 */

/**
 * `notify_core`: none, all or major, applied before any channel routing.
 */
class Test_Notify_Core extends WPUN_Test_Case {

	/**
	 * A release of the installed major.minor: not a major release.
	 *
	 * @return string
	 */
	private function minor_release() {
		return WPUN\Routing::major_minor( wp_get_wp_version() ) . '.99';
	}

	/**
	 * A release of another major.minor.
	 *
	 * @return string
	 */
	private function major_release() {
		return '99.1';
	}

	/**
	 * Run a check and return the core items found.
	 *
	 * @return WPUN\Notification_Item[]
	 */
	private function core_items() {
		reset_phpmailer_instance();
		sc_wpun()->update_checker()->do_update_check();
		$notification = sc_wpun()->update_checker()->last_notification();

		return null === $notification ? array() : $notification->get_items_of_type( 'core' );
	}

	/**
	 * Save the settings with a core value and offer a core update.
	 *
	 * @param string $setting notify_core.
	 * @param string $offer   Version on offer.
	 */
	private function offer( $setting, $offer ) {
		$this->save_settings( array( 'notify_core' => $setting ) );
		$this->set_updates( $offer );
	}

	/**
	 * A new install and an upgraded site report core updates as before.
	 */
	public function test_the_default_is_all() {
		$this->assertSame( 'all', sc_wpun()->settings()->default_settings()['notify_core'] );

		sc_wpun()->network_context()->delete_option( 'sc_wpun_settings' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_settings_ver' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_channels' );
		sc_wpun()->settings()->maybe_upgrade();
		$this->assertSame( 'all', $this->stored_settings()['notify_core'] );
	}

	/**
	 * An upgrade from the previous settings version adds the key without touching the others.
	 */
	public function test_the_upgrade_adds_the_setting() {
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', array_diff_key( $this->global_settings( array( 'notify_plugins' => 'active' ) ), array( 'notify_core' => 1 ) ) );
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings_ver', '11.0' );

		sc_wpun()->settings()->maybe_upgrade();

		$stored = $this->stored_settings();
		$this->assertSame( 'all', $stored['notify_core'] );
		$this->assertSame( 'active', $stored['notify_plugins'] );
		$this->assertSame( '13.0', sc_wpun()->network_context()->get_option( 'sc_wpun_settings_ver' ) );
	}

	/**
	 * The default can be filtered like the others; a key it does not accept falls back.
	 */
	public function test_the_default_can_be_filtered() {
		add_filter(
			'sc_wpun_default_settings',
			static function ( $defaults ) {
				$defaults['notify_core'] = 'major';
				return $defaults;
			}
		);
		$this->assertSame( 'major', sc_wpun()->settings()->default_settings()['notify_core'] );

		remove_all_filters( 'sc_wpun_default_settings' );
		add_filter(
			'sc_wpun_default_settings',
			static function ( $defaults ) {
				$defaults['notify_core'] = 'active';
				return $defaults;
			}
		);
		$this->assertSame( 'all', sc_wpun()->settings()->default_settings()['notify_core'] );
		remove_all_filters( 'sc_wpun_default_settings' );
	}

	/**
	 * Each valid value is kept; anything else (a number, an unknown word, a plugin key) falls back to all.
	 */
	public function test_valid_values_are_kept_and_invalid_ones_fall_back() {
		foreach ( array( 'none', 'all', 'major' ) as $value ) {
			sc_wpun()->network_context()->update_option( 'sc_wpun_settings', array_merge( $this->global_settings(), array( 'notify_core' => $value ) ) );
			$this->assertSame( $value, sc_wpun()->settings()->get()['notify_core'], $value );
		}

		foreach ( array( 'active', 'bogus', 1, 2, '' ) as $bad ) {
			sc_wpun()->network_context()->update_option( 'sc_wpun_settings', array_merge( $this->global_settings(), array( 'notify_core' => $bad ) ) );
			$this->assertSame( 'all', sc_wpun()->settings()->get()['notify_core'], var_export( $bad, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}

		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', array_diff_key( $this->global_settings(), array( 'notify_core' => 1 ) ) );
		$this->assertSame( 'all', sc_wpun()->settings()->get()['notify_core'], 'Missing.' );
	}

	/**
	 * Core is not collected at all with none, and what was notified about it is kept.
	 */
	public function test_none_collects_no_core_item() {
		$this->offer( 'none', $this->major_release() );
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '98.0' ) ) );

		$this->assertSame( array(), $this->core_items() );
		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( '98.0', $this->notified_version( 'core', 'core' ), 'History is kept.' );
	}

	/**
	 * With major, a major.minor change is reported and a minor release is not; all reports both.
	 */
	public function test_major_reports_major_releases_only() {
		$this->offer( 'major', $this->major_release() );
		$items = $this->core_items();
		$this->assertCount( 1, $items );
		$this->assertSame( $this->major_release(), $items[0]->get_new_version() );
		$this->assertSame( 1, $this->sent_mail_count() );

		$this->offer( 'major', $this->minor_release() );
		$this->assertSame( array(), $this->core_items() );
		$this->assertSame( 0, $this->sent_mail_count() );

		$this->offer( 'all', $this->minor_release() );
		$this->assertCount( 1, $this->core_items() );
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * A minor update skipped under major is kept waiting, so it is notified once the setting changes to all.
	 */
	public function test_a_skipped_minor_update_is_notified_when_the_setting_becomes_all() {
		$this->offer( 'major', $this->minor_release() );
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '98.0' ) ) );

		$this->assertSame( array(), $this->core_items() );
		$this->assertSame( '98.0', $this->notified_version( 'core', 'core' ), 'The waiting update keeps the history.' );

		$this->save_settings( array( 'notify_core' => 'major' ) );
		$this->save_channel_state( 'email', array() );
		$this->assertSame( array(), $this->core_items() );
		$this->assertSame( '', $this->notified_version( 'core', 'core' ), 'It was never recorded as notified.' );

		$this->save_settings( array( 'notify_core' => 'all' ) );
		$this->save_channel_state( 'email', array() );
		$this->assertCount( 1, $this->core_items() );
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( $this->minor_release(), $this->notified_version( 'core', 'core' ) );
	}

	/**
	 * Turning core off and on again does not resend what was notified before, but sends an update that was not.
	 */
	public function test_none_then_all_does_not_resend_a_notified_update() {
		$this->offer( 'all', $this->major_release() );
		$this->assertCount( 1, $this->core_items() );
		$this->assertSame( 1, $this->sent_mail_count() );

		$this->save_settings( array( 'notify_core' => 'none' ) );
		$this->assertSame( array(), $this->core_items() );
		$this->assertSame( $this->major_release(), $this->notified_version( 'core', 'core' ) );

		$this->save_settings( array( 'notify_core' => 'all' ) );
		$this->assertCount( 1, $this->core_items(), 'Still waiting.' );
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * An update that came out while core was off is sent once core is on again.
	 */
	public function test_none_then_all_sends_an_update_that_was_not_notified() {
		$this->offer( 'none', '99.2' );
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '99.1' ) ) );
		$this->assertSame( array(), $this->core_items() );

		$this->save_settings( array( 'notify_core' => 'all' ) );
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '99.1' ) ) );
		$this->assertCount( 1, $this->core_items() );
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( '99.2', $this->notified_version( 'core', 'core' ) );
	}

	/**
	 * The filter gets the setting's result as its default and can overrule it both ways.
	 */
	public function test_the_filter_can_overrule_the_default() {
		$defaults = array();
		add_filter(
			'sc_wpun_core_needs_update',
			static function ( $needs ) use ( &$defaults ) {
				$defaults[] = $needs;
				return $needs;
			}
		);
		$this->offer( 'major', $this->minor_release() );
		$this->core_items();
		$this->offer( 'major', $this->major_release() );
		$this->core_items();
		$this->offer( 'all', $this->minor_release() );
		$this->core_items();
		$this->assertSame( array( false, true, true ), $defaults );
		remove_all_filters( 'sc_wpun_core_needs_update' );

		add_filter( 'sc_wpun_core_needs_update', '__return_true' );
		$this->offer( 'major', $this->minor_release() );
		$this->assertCount( 1, $this->core_items(), 'The filter can report a minor release.' );
		remove_all_filters( 'sc_wpun_core_needs_update' );

		add_filter( 'sc_wpun_core_needs_update', '__return_false' );
		$this->offer( 'all', $this->major_release() );
		$this->assertSame( array(), $this->core_items(), 'The filter can drop a major release.' );
		remove_all_filters( 'sc_wpun_core_needs_update' );
	}
}
