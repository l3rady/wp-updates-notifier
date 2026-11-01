<?php
/**
 * Mutes in the update check (#82).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;

/**
 * Muted items are left out of messages but still count as waiting.
 */
class Test_Mutes_Check extends WPUN_Test_Case {

	/**
	 * Fresh settings, no mutes, no ended-mute listeners.
	 */
	public function set_up() {
		parent::set_up();

		$this->save_settings();
		sc_wpun()->network_context()->delete_option( 'sc_wpun_muted' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
	}

	/**
	 * Run a check with the plugin (and optionally a theme) waiting.
	 *
	 * @param string      $plugin_version Plugin version waiting, or empty for none.
	 * @param string|null $theme          Theme stylesheet waiting, or null.
	 * @param string      $theme_version  Theme version.
	 */
	private function check( $plugin_version = '2.0', $theme = null, $theme_version = '5.0' ) {
		$plugins = '' === $plugin_version ? array() : array( 'hello.php' => array( 'new_version' => $plugin_version ) );
		$themes  = null === $theme ? array() : array( $theme => array( 'new_version' => $theme_version ) );
		$this->set_updates( null, $plugins, $themes );
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * Count calls of an action.
	 *
	 * @param string $hook Action.
	 * @return array Reference to the calls.
	 */
	private function &record( $hook ) {
		$calls = array();
		add_action(
			$hook,
			function () use ( &$calls ) {
				$calls[] = func_get_args();
			},
			10,
			2
		);

		return $calls;
	}

	/**
	 * A plugin and a theme muted always go to nobody and are not recorded.
	 */
	public function test_always_muted_plugin_and_theme_are_not_sent() {
		$theme = array_keys( wp_get_themes() )[0];
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		sc_wpun_mutes()->mute( 'theme', $theme );

		$this->check( '2.0', $theme );

		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( '', $this->notified_version( 'plugin', 'hello.php' ) );
		$this->assertSame( '', $this->notified_version( 'theme', $theme ) );
		$this->assertArrayNotHasKey( 'ended_mutes', $this->stored_state() );
	}

	/**
	 * What was notified before the mute survives it, so unmuting does not send it again.
	 */
	public function test_unmuting_does_not_resend_what_was_notified() {
		$this->check( '2.0' );
		$this->assertSame( 1, $this->sent_mail_count() );

		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$this->check( '2.0' );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', 'hello.php' ), 'History kept while muted.' );

		sc_wpun_mutes()->unmute( 'plugin', 'hello.php' );
		$this->check( '2.0' );
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * An item never notified is sent on the next check after unmuting.
	 */
	public function test_unmuting_sends_what_was_never_notified() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$this->check( '2.0' );
		$this->assertSame( 0, $this->sent_mail_count() );

		sc_wpun_mutes()->unmute( 'plugin', 'hello.php' );
		$this->check( '2.0' );

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', 'hello.php' ) );
	}

	/**
	 * A skip mute holds for equal and older offered versions and ends for a newer one, which is sent.
	 */
	public function test_skip_this_version() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0' );

		$this->check( '2.0' );
		$this->check( '1.9' );
		$this->assertSame( 0, $this->sent_mail_count() );

		$this->check( '2.1' );
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( '2.1', $this->notified_version( 'plugin', 'hello.php' ) );
	}

	/**
	 * Version comparison edge cases through the check.
	 */
	public function test_skip_version_edge_cases() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '1.9' );
		$this->check( '1.10' );
		$this->assertSame( 1, $this->sent_mail_count(), '1.10 is newer than 1.9.' );

		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0' );
		$this->check( '2.0.0' );
		$this->assertSame( 2, $this->sent_mail_count(), '2.0.0 is newer than 2.0, so the mute ends.' );
	}

	/**
	 * The ended action fires once, after the state is saved, and not again.
	 */
	public function test_mute_ended_fires_once() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0' );
		$ended = &$this->record( 'sc_wpun_mute_ended' );

		$this->check( '2.0' );
		$this->assertCount( 0, $ended );

		$this->check( '2.1' );
		$this->check( '2.1' );
		$this->check( '2.2' );

		$this->assertCount( 1, $ended );
		$this->assertSame( '2.0', $ended[0][0]->get_until() );
		$this->assertSame( 'hello.php', $ended[0][1]->get_key() );
		$this->assertSame( '2.1', $ended[0][1]->get_new_version() );
		$this->assertSame( array( 'plugin' => array( 'hello.php' => '2.0' ) ), $this->stored_state()['ended_mutes'] );
	}

	/**
	 * A dry run never fires the ended action nor records it.
	 */
	public function test_dry_run_does_not_fire_mute_ended() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0' );
		$ended = &$this->record( 'sc_wpun_mute_ended' );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.1' ) ) );

		sc_wpun()->update_checker()->run_check( 'cli', true );
		$this->assertCount( 0, $ended );
		$this->assertArrayNotHasKey( 'ended_mutes', $this->stored_state() );

		sc_wpun()->update_checker()->run_check( 'cli' );
		$this->assertCount( 1, $ended );
	}

	/**
	 * Recorded ended mutes are dropped once the mute is gone or changed.
	 */
	public function test_ended_records_are_pruned() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0' );
		$this->check( '2.1' );
		$this->assertArrayHasKey( 'ended_mutes', $this->stored_state() );

		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.1' );
		$this->check( '2.1' );
		$this->assertArrayNotHasKey( 'ended_mutes', $this->stored_state(), 'Changed mute: forgotten.' );
	}

	/**
	 * Reminders leave muted items out.
	 *
	 * @dataProvider reminder_settings
	 *
	 * @param string $remind The `remind` routing value.
	 */
	public function test_reminders_leave_muted_items_out( $remind ) {
		sc_wpun_channels()->save( new Channel_Instance( 'email', 'email', 'Email', true, array(), array( 'remind' => $remind ) ) );
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$this->check( '2.0' );
		$state                                       = $this->stored_state();
		$state['channels']['email']['last_reminder'] = time() - 3 * DAY_IN_SECONDS;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );

		$this->check( '2.0' );

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * The reminder settings that repeat items.
	 *
	 * @return array<string, array<string>>
	 */
	public static function reminder_settings() {
		return array(
			'daily'       => array( 'daily' ),
			'every check' => array( 'every_check' ),
		);
	}

	/**
	 * An item that a filter added is muted too.
	 */
	public function test_an_item_added_by_a_filter_is_muted() {
		add_filter(
			'sc_wpun_update_items',
			function ( $items ) {
				$items[] = new WPUN\Notification_Item(
					array(
						'type'        => 'plugin',
						'key'         => 'extra/extra.php',
						'name'        => 'Extra',
						'new_version' => '3.0',
					)
				);
				return $items;
			}
		);
		sc_wpun_mutes()->mute( 'plugin', 'extra/extra.php' );

		$this->check( '' );

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * Dry runs and forced runs respect mutes.
	 */
	public function test_dry_run_and_force_respect_mutes() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0' ) ) );

		$dry   = sc_wpun()->update_checker()->run_check( 'cli', true );
		$force = sc_wpun()->update_checker()->run_check( 'cli', false, true );

		$this->assertSame( array(), $dry['email']['items'] );
		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( array(), $force['email']['items'] );
	}

	/**
	 * The veto receives false for a muted item and can override it.
	 */
	public function test_the_veto_sees_the_mute_and_can_override_it() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$seen = array();
		add_filter(
			'sc_wpun_should_notify_item',
			function ( $notify, $item ) use ( &$seen ) {
				$seen[ $item->get_key() ] = $notify;
				return $notify;
			},
			10,
			2
		);
		$this->check( '2.0' );
		$this->assertFalse( $seen['hello.php'] );
		$this->assertSame( 0, $this->sent_mail_count() );

		add_filter( 'sc_wpun_should_notify_item', '__return_true', 20 );
		$this->check( '2.0' );
		$this->assertSame( 1, $this->sent_mail_count() );
	}
}
