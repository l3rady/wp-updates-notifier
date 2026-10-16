<?php
/**
 * The email methods of the old public class keep working, deprecated (#97).
 *
 * @package wp-updates-notifier
 */

/**
 * SC_WP_Updates_Notifier::send_email_message() and the From filter callbacks.
 */
class Test_Email_Legacy extends WPUN_Test_Case {

	/**
	 * The deprecated send still sends to every enabled email instance, through the old filters.
	 */
	public function test_send_email_message_still_sends() {
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::send_email_message' );
		$this->save_settings();
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'client', 'email', 'Client', true, array( 'to' => 'client@example.org' ) ) );
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'off', 'email', 'Off', false, array( 'to' => 'off@example.org' ) ) );
		add_filter(
			'sc_wpun_email_content',
			static function ( $content ) {
				return $content . ' + filtered';
			}
		);

		$this->assertTrue( $this->plugin()->send_email_message( 'Hello' ) );

		$this->assertSame( 2, $this->sent_mail_count() );
		$this->assertStringStartsWith( 'WP Updates Notifier: updates available for', $this->sent_mail()->subject );
		$this->assertStringContainsString( 'Hello + filtered', $this->sent_mail()->body );
	}

	/**
	 * It reports false when there was nobody to send to.
	 */
	public function test_send_email_message_returns_false_when_nothing_was_sent() {
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::send_email_message' );
		$this->save_settings( array( 'email_notifications' => 0 ) );

		$this->assertFalse( $this->plugin()->send_email_message( 'Hello' ) );
	}

	/**
	 * The three mail filter callbacks are deprecated and answer as before.
	 */
	public function test_the_mail_filter_callbacks_are_deprecated() {
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_wp_mail_from' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_wp_mail_from_name' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_wp_mail_content_type' );
		$this->save_settings( array( 'notify_from' => 'ops@example.org' ) );
		$plugin = $this->plugin();

		$this->assertSame( 'ops@example.org', $plugin->sc_wpun_wp_mail_from() );
		$this->assertSame( 'WP Updates Notifier', $plugin->sc_wpun_wp_mail_from_name() );
		$this->assertSame( 'text/plain', $plugin->sc_wpun_wp_mail_content_type() );
	}
}
