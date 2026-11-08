<?php
/**
 * Looking up the registered channel of a type (#446).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Registry;
use WPUN\Modules\Email\Email_Channel;
use WPUN\Modules\Slack\Slack_Channel;
use WPUN\Modules\Webhook\Webhook_Channel;

/**
 * A channel that needs an API version this plugin does not have.
 */
class Test_Lookup_Unmet_Channel extends Test_Channel {

	/**
	 * What the channel needs.
	 *
	 * @return string
	 */
	public function get_api_constraint(): string {
		return '^99.0';
	}
}

/**
 * The channel of a type from sc_wpun_channel(), each test against a registry that has not been built yet.
 */
class Test_Channel_Lookup extends WPUN_Test_Case {

	/**
	 * The plugin's own registry, put back after each test.
	 *
	 * @var Channel_Registry
	 */
	private $shared;

	/**
	 * Give the plugin a fresh registry, so a test's rejections and types never reach the shared one.
	 */
	public function set_up() {
		parent::set_up();

		$this->shared = sc_wpun()->channels();
		$this->set_registry( new Channel_Registry() );
	}

	/**
	 * Put the plugin's registry back.
	 */
	public function tear_down() {
		$this->set_registry( $this->shared );
		parent::tear_down();
	}

	/**
	 * Swap the registry the plugin hands out.
	 *
	 * @param Channel_Registry $registry Registry.
	 */
	private function set_registry( Channel_Registry $registry ) {
		$property = new ReflectionProperty( sc_wpun(), 'channels' );
		$property->setAccessible( true );
		$property->setValue( sc_wpun(), $registry );
	}

	/**
	 * The built-in types give their channels, and an unknown type gives null.
	 */
	public function test_built_in_types_and_an_unknown_type() {
		$this->assertInstanceOf( Email_Channel::class, sc_wpun_channel( 'email' ) );
		$this->assertInstanceOf( Webhook_Channel::class, sc_wpun_channel( 'webhook' ) );
		$this->assertInstanceOf( Slack_Channel::class, sc_wpun_channel( 'slack' ) );
		$this->assertSame( sc_wpun()->channels()->get( 'email' ), sc_wpun_channel( 'email' ), 'The registry object itself, not a copy.' );
		$this->assertNull( sc_wpun_channel( 'nope' ) );
		$this->assertNull( sc_wpun_channel( '' ) );
	}

	/**
	 * The first call builds the registry: the registration action fires once.
	 */
	public function test_the_first_call_builds_the_registry() {
		$channel = new Test_Channel( 'lookup_built' );
		add_action(
			'sc_wpun_register_channels',
			static function ( Channel_Registry $registry ) use ( $channel ) {
				$registry->register( $channel );
			}
		);
		$fired = did_action( 'sc_wpun_register_channels' );

		$this->assertSame( $channel, sc_wpun_channel( 'lookup_built' ) );
		$this->assertSame( $fired + 1, did_action( 'sc_wpun_register_channels' ) );

		sc_wpun_channel( 'lookup_built' );
		$this->assertSame( $fired + 1, did_action( 'sc_wpun_register_channels' ), 'Built once.' );
	}

	/**
	 * A channel refused for its API constraint is not returned.
	 */
	public function test_a_refused_channel_gives_null() {
		add_action(
			'sc_wpun_register_channels',
			static function ( Channel_Registry $registry ) {
				$registry->register( new Test_Lookup_Unmet_Channel( 'lookup_gated' ) );
			}
		);

		$this->assertNull( sc_wpun_channel( 'lookup_gated' ) );
		$this->assertArrayHasKey( 'lookup_gated', sc_wpun()->channels()->get_rejected() );
	}

	/**
	 * A replaced type gives the replacement, whichever registers first, and a refused replacement gives the original.
	 */
	public function test_a_replaced_type_gives_the_replacement() {
		$original    = new Test_Channel( 'lookup_swap' );
		$replacement = new Test_Channel( 'lookup_swap' );
		$kept        = new Test_Channel( 'lookup_kept' );
		add_action(
			'sc_wpun_register_channels',
			static function ( Channel_Registry $registry ) use ( $original, $replacement, $kept ) {
				$registry->replace( $replacement );
				$registry->register( $original );
				$registry->register( $kept );
				$registry->replace( new Test_Lookup_Unmet_Channel( 'lookup_kept' ) );
			}
		);

		$this->assertSame( $replacement, sc_wpun_channel( 'lookup_swap' ) );
		$this->assertSame( $kept, sc_wpun_channel( 'lookup_kept' ) );
	}

	/**
	 * A replaced built-in type gives the replacement.
	 */
	public function test_a_replaced_built_in_type_gives_the_replacement() {
		$replacement = new Test_Channel( 'email' );
		add_action(
			'sc_wpun_register_channels',
			static function ( Channel_Registry $registry ) use ( $replacement ) {
				$registry->replace( $replacement );
			}
		);

		$this->assertSame( $replacement, sc_wpun_channel( 'email' ) );
		$this->assertInstanceOf( Email_Channel::class, sc_wpun()->channels()->get_replaced( 'email' ) );
	}
}
