<?php
/**
 * One bad module or routing callback does not stop the update check (HN8 of the 2.0.0 security audit).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;

/**
 * The real check, with an instance whose channel throws while its fields are read, and one whose routing callback throws.
 */
class Test_Check_Isolation extends WPUN_Test_Case {

	/**
	 * Notices raised while the check ran.
	 *
	 * @var string[]
	 */
	private $notices = array();

	/**
	 * A channel type whose schema throws, and the instances.
	 */
	public function set_up() {
		parent::set_up();

		$this->save_settings( array( 'notify_to' => 'ok@example.org' ) );
		$this->set_updates( '99.0.0' );
	}

	/**
	 * Drop the stand-in channel.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( 'boom' );
		parent::tear_down();
	}

	/**
	 * Run the check, capturing the notices WP_DEBUG makes the plugin raise.
	 *
	 * It stays local, with its own name: it runs the base `run_update_check()` inside capture(), which collects
	 * notices (the base `render()` collects output).
	 */
	private function run_check_capturing_notices() {
		$this->capture( fn() => $this->run_update_check() );
	}

	/**
	 * Run a callable, capturing the notices WP_DEBUG makes the plugin raise.
	 *
	 * @param callable $run What to run.
	 * @return mixed What it returned.
	 */
	private function capture( callable $run ) {
		$this->notices = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing the notices the plugin raises under WP_DEBUG.
			function ( $number, $message ) {
				$this->notices[] = $message;
				return true;
			},
			E_USER_NOTICE
		);
		try {
			return $run();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Addresses mail went to in the last run.
	 *
	 * @return string[]
	 */
	private function recipients() {
		$out = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $i => $mail ) {
			$out[] = tests_retrieve_phpmailer_instance()->get_recipient( 'to', $i )->address;
		}
		sort( $out );

		return $out;
	}

	/**
	 * A module whose fields cannot be read neither stops the check nor the store, and nothing goes through it:
	 * its supplied values can't be laid over the stored ones.
	 */
	public function test_a_module_whose_fields_throw_does_not_stop_the_check() {
		$channel = new Test_Throwing_Fields_Channel( 'boom' );
		sc_wpun()->channels()->register( $channel );
		sc_wpun_channels()->save( new Channel_Instance( 'broken', 'boom', 'Broken', true, array( 'behaviour' => 'succeed' ) ) );
		sc_wpun_channels()->save( new Channel_Instance( 'good', 'email', 'Good', true, array( 'to' => 'good@example.org' ) ) );
		$channel->explode = true;

		$all = $this->capture( static fn() => sc_wpun_channels()->all() );
		$this->assertArrayHasKey( 'broken', $all, 'The store survives the throwing schema.' );
		$this->assertCount( 1, $this->notices, 'Reported once.' );
		$this->assertStringContainsString( 'RuntimeException', $this->notices[0] );

		$this->run_check_capturing_notices();

		$this->assertContains( 'good@example.org', $this->recipients() );
		$results = sc_wpun()->update_checker()->last_results();
		$this->assertTrue( $results['good']->is_success() );
		$this->assertSame( 'exception', $results['broken']->get_code(), 'Not sent with the stored values.' );
		$this->assertSame( array( 'exception' => RuntimeException::class ), $results['broken']->get_context() );
		$this->assertStringNotContainsString( 'secret-xyz', implode( ' ', $this->notices ), 'The message is not logged.' );
	}

	/**
	 * An instance whose channel cannot list its fields can still be saved, and a value supplied for it from outside
	 * the database is still never written: `enabled` and the setting keep what was stored before.
	 */
	public function test_an_instance_whose_fields_throw_can_be_saved_without_writing_supplied_values() {
		$channel = new Test_Throwing_Fields_Channel( 'boom' );
		sc_wpun()->channels()->register( $channel );
		sc_wpun_channels()->save( new Channel_Instance( 'boomsave', 'boom', 'Broken', true, array( 'behaviour' => 'stored' ) ) );
		define( 'SC_WPUN_CHANNEL_BOOMSAVE__BEHAVIOUR', 'from-constant' );
		sc_wpun()->config()->flush();
		$channel->explode = true;

		$saved = $this->capture(
			static fn() => sc_wpun_channels()->save(
				new Channel_Instance(
					'boomsave',
					'boom',
					'Renamed',
					false,
					array(
						'behaviour'   => 'from-constant',
						'retry_after' => '5',
					)
				)
			)
		);

		$this->assertTrue( $saved );
		$stored = get_option( 'sc_wpun_channels' );
		if ( sc_wpun_is_network() ) {
			$stored = get_site_option( 'sc_wpun_channels' );
		}
		$this->assertSame( 'Renamed', $stored['boomsave']['label'] );
		$this->assertFalse( $stored['boomsave']['enabled'] );
		$this->assertSame( 'stored', $stored['boomsave']['settings']['behaviour'], 'The supplied value is not written.' );
		$this->assertSame( '5', $stored['boomsave']['settings']['retry_after'] );
		$this->assertStringNotContainsString( 'secret-xyz', implode( ' ', $this->notices ) );
	}

	/**
	 * A supplied `enabled` is not written either when the fields cannot be read.
	 */
	public function test_a_supplied_enabled_is_kept_when_the_fields_throw() {
		$channel = new Test_Throwing_Fields_Channel( 'boom' );
		sc_wpun()->channels()->register( $channel );
		sc_wpun_channels()->save( new Channel_Instance( 'boomon', 'boom', 'Broken', false, array() ) );
		define( 'SC_WPUN_CHANNEL_BOOMON__ENABLED', true );
		sc_wpun()->config()->flush();
		$channel->explode = true;

		$this->assertTrue( $this->capture( static fn() => sc_wpun_channels()->save( new Channel_Instance( 'boomon', 'boom', 'Broken', true, array() ) ) ) );
		$this->assertFalse( sc_wpun_channels()->stored_all()['boomon']->is_enabled() );
	}

	/**
	 * The owner of a broken add-on can switch its channel off from the Channels tab.
	 */
	public function test_an_instance_whose_fields_throw_can_be_switched_off() {
		$this->become_admin();
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		$channel = new Test_Throwing_Fields_Channel( 'boom' );
		sc_wpun()->channels()->register( $channel );
		sc_wpun_channels()->save( new Channel_Instance( 'boomoff', 'boom', 'Broken', true, array( 'behaviour' => 'succeed' ) ) );
		$channel->explode  = true;
		$_POST['id']       = 'boomoff';
		$_POST['_wpnonce'] = wp_create_nonce( WPUN\Admin\Channels_Tab::TOGGLE_ACTION . '_boomoff' );

		try {
			$this->assertTrue( $this->capture( static fn() => sc_wpun()->channels_tab()->process_toggle() ) );
		} finally {
			unset( $_POST['id'], $_POST['_wpnonce'] );
		}
		$this->assertFalse( sc_wpun_channels()->stored_all()['boomoff']->is_enabled() );
	}

	/**
	 * A routing callback that throws fails its own instance, and the others still go out.
	 */
	public function test_a_routing_callback_that_throws_does_not_stop_the_check() {
		sc_wpun_channels()->save( new Channel_Instance( 'bad', 'email', 'Bad', true, array( 'to' => 'bad@example.org' ) ) );
		sc_wpun_channels()->save( new Channel_Instance( 'good', 'email', 'Good', true, array( 'to' => 'good@example.org' ) ) );
		add_filter(
			'sc_wpun_channel_should_receive_item',
			static function ( $receive, $item, $instance ) {
				if ( 'bad' === $instance->get_id() ) {
					throw new LogicException( 'routing blew up with secret-xyz' );
				}
				return $receive;
			},
			10,
			3
		);

		$this->run_check_capturing_notices();

		$results = sc_wpun()->update_checker()->last_results();
		$this->assertSame( 'exception', $results['bad']->get_code() );
		$this->assertSame( array( 'exception' => LogicException::class ), $results['bad']->get_context() );
		$this->assertStringNotContainsString( 'secret-xyz', $results['bad']->get_message() . implode( ' ', $this->notices ) );
		$this->assertTrue( $results['good']->is_success() );
		$this->assertSame( array( 'good@example.org', 'ok@example.org' ), $this->recipients() );
	}
}
