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
	 * An instance without a usable recipient is skipped, and the ones after it still get the message.
	 */
	public function test_send_email_message_skips_a_blank_recipient_and_goes_on() {
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::send_email_message' );
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$attempts = array();
		add_filter(
			'wp_mail',
			static function ( $args ) use ( &$attempts ) {
				$attempts[] = $args['to'];
				return $args;
			}
		);
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'blank', 'email', 'Blank', true, array( 'to' => '   ' ) ) );
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'client', 'email', 'Client', true, array( 'to' => 'client@example.org' ) ) );

		$this->assertTrue( $this->plugin()->send_email_message( 'Hello' ) );

		$this->assertSame( array( 'client@example.org' ), $attempts, 'No mail is attempted for a blank recipient.' );
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( 'client@example.org', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
	}

	/**
	 * The mail filter callbacks are deprecated. The two for the From address do nothing now.
	 */
	public function test_the_mail_filter_callbacks_are_deprecated() {
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_wp_mail_from' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_wp_mail_from_name' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_wp_mail_content_type' );
		$this->save_settings( array( 'notify_from' => 'ops@example.org' ) );
		$plugin = $this->plugin();

		$this->assertSame( '', $plugin->sc_wpun_wp_mail_from() );
		$this->assertSame( '', $plugin->sc_wpun_wp_mail_from_name() );
		$this->assertSame( 'text/plain', $plugin->sc_wpun_wp_mail_content_type() );
	}

	/**
	 * WordPress's automatic update emails are left alone: nothing is hooked and the old callback returns the email as it got it.
	 */
	public function test_the_auto_update_email_callback_is_deprecated_and_changes_nothing() {
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::filter_auto_core_update_email' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_settings_main_field_notify_automatic' );
		$this->save_settings( array( 'notify_to' => 'ops@example.org' ) );
		$email = array(
			'to'      => 'admin@example.org',
			'subject' => 'Subject',
			'body'    => 'Body',
			'headers' => '',
		);

		$this->assertFalse( has_filter( 'auto_core_update_email' ) );
		$this->assertSame( $email, apply_filters( 'auto_core_update_email', $email, 'success', new stdClass(), null ) );
		$this->assertSame( $email, $this->plugin()->filter_auto_core_update_email( $email ) );

		ob_start();
		$this->plugin()->sc_wpun_settings_main_field_notify_automatic();
		$this->assertSame( '', ob_get_clean() );
	}
}
