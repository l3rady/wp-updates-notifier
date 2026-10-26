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
	 */
	private function run_check() {
		reset_phpmailer_instance();
		$this->capture( static fn() => sc_wpun()->update_checker()->do_update_check() );
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

		$this->run_check();

		$this->assertContains( 'good@example.org', $this->recipients() );
		$results = sc_wpun()->update_checker()->last_results();
		$this->assertTrue( $results['good']->is_success() );
		$this->assertSame( 'exception', $results['broken']->get_code(), 'Not sent with the stored values.' );
		$this->assertSame( array( 'exception' => RuntimeException::class ), $results['broken']->get_context() );
		$this->assertStringNotContainsString( 'secret-xyz', implode( ' ', $this->notices ), 'The message is not logged.' );
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

		$this->run_check();

		$results = sc_wpun()->update_checker()->last_results();
		$this->assertSame( 'exception', $results['bad']->get_code() );
		$this->assertSame( array( 'exception' => LogicException::class ), $results['bad']->get_context() );
		$this->assertStringNotContainsString( 'secret-xyz', $results['bad']->get_message() . implode( ' ', $this->notices ) );
		$this->assertTrue( $results['good']->is_success() );
		$this->assertSame( array( 'good@example.org', 'ok@example.org' ), $this->recipients() );
	}
}
