<?php
/**
 * The channel registry.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Registry;

/**
 * Registering channels.
 */
class Test_Channel_Registry extends WPUN_Test_Case {

	/**
	 * Start without the built-in modules, so the tests see only the channels they register.
	 */
	public function set_up() {
		parent::set_up();

		remove_all_actions( 'sc_wpun_register_channels' );
	}

	/**
	 * The email module registers itself through the hook, on a fresh registry.
	 */
	public function test_the_email_module_is_registered_through_the_hook() {
		require WP_PLUGIN_DIR . '/wp-updates-notifier/modules/email/email.php';

		$registry = new Channel_Registry();

		$this->assertTrue( $registry->has( 'email' ) );
		$this->assertInstanceOf( WPUN\Modules\Email\Email_Channel::class, $registry->get( 'email' ) );
	}

	/**
	 * Registered channels can be read back.
	 */
	public function test_register_get_has_all_and_unregister() {
		$registry = new Channel_Registry();
		$one      = new Test_Channel( 'one' );
		$two      = new Test_Channel( 'two' );

		$this->assertFalse( $registry->has( 'one' ) );
		$this->assertNull( $registry->get( 'one' ) );
		$this->assertTrue( $registry->register( $one ) );
		$this->assertTrue( $registry->register( $two ) );

		$this->assertTrue( $registry->has( 'one' ) );
		$this->assertSame( $one, $registry->get( 'one' ) );
		$this->assertSame(
			array(
				'one' => $one,
				'two' => $two,
			),
			$registry->all()
		);

		$this->assertTrue( $registry->unregister( 'one' ) );
		$this->assertFalse( $registry->unregister( 'one' ) );
		$this->assertFalse( $registry->has( 'one' ) );
		$this->assertSame( array( 'two' => $two ), $registry->all() );
	}

	/**
	 * A channel registered through the hook can be unregistered before anything has read the registry.
	 */
	public function test_unregister_before_the_first_read_removes_a_hook_registered_channel() {
		$registry = new Channel_Registry();
		add_action(
			'sc_wpun_register_channels',
			static function ( $registry ) {
				$registry->register( new Test_Channel( 'built_in' ) );
			}
		);

		$this->assertTrue( $registry->unregister( 'built_in' ) );
		$this->assertFalse( $registry->has( 'built_in' ) );
	}

	/**
	 * A type can only be registered once.
	 */
	public function test_duplicate_type_is_rejected() {
		$this->setExpectedIncorrectUsage( 'WPUN\\Channel_Registry::register' );

		$registry = new Channel_Registry();
		$first    = new Test_Channel( 'one' );

		$this->assertTrue( $registry->register( $first ) );
		$this->assertFalse( $registry->register( new Test_Channel( 'one' ) ) );
		$this->assertSame( $first, $registry->get( 'one' ) );
	}

	/**
	 * Types are lower case letters, digits and underscores.
	 *
	 * @dataProvider invalid_types
	 *
	 * @param string $type Type.
	 */
	public function test_invalid_type_is_rejected( $type ) {
		$this->setExpectedIncorrectUsage( 'WPUN\\Channel_Registry::register' );

		$registry = new Channel_Registry();

		$this->assertFalse( $registry->register( new Test_Channel( $type ) ) );
		$this->assertSame( array(), $registry->all() );
	}

	/**
	 * Types that are not valid.
	 *
	 * @return array
	 */
	public function invalid_types() {
		return array(
			'empty'       => array( '' ),
			'upper case'  => array( 'Slack' ),
			'dash'        => array( 'my-channel' ),
			'with space'  => array( 'my channel' ),
			'with a dot'  => array( 'my.channel' ),
			'with slash'  => array( 'my/channel' ),
			'with a line' => array( "slack\n" ),
		);
	}

	/**
	 * Add-ons register through the action, which fires once and only when the channels are first read.
	 */
	public function test_registration_action_fires_once_on_first_read() {
		$registry = new Channel_Registry();
		$calls    = 0;
		$channel  = new Test_Channel( 'from_hook' );

		add_action(
			'sc_wpun_register_channels',
			static function ( $received ) use ( &$calls, $channel ) {
				++$calls;
				$received->register( $channel );
			}
		);

		$registry->register( new Test_Channel( 'early' ) );
		$this->assertSame( 0, $calls, 'Registering does not fire the action.' );

		$this->assertTrue( $registry->has( 'from_hook' ) );
		$registry->get( 'early' );
		$registry->all();
		$this->assertSame( 1, $calls );
		$this->assertSame( array( 'early', 'from_hook' ), array_keys( $registry->all() ) );
	}

	/**
	 * The action passes the registry that fired it.
	 */
	public function test_registration_action_receives_the_registry() {
		$registry = new Channel_Registry();
		$received = null;

		add_action(
			'sc_wpun_register_channels',
			static function ( $arg ) use ( &$received ) {
				$received = $arg;
			}
		);
		$registry->all();

		$this->assertSame( $registry, $received );
	}

	/**
	 * The helper registers on the plugin's registry.
	 */
	public function test_helper_registers_on_the_plugin_registry() {
		$channel = new Test_Channel( 'via_helper' );

		$this->assertTrue( sc_wpun_register_channel( $channel ) );
		$this->assertSame( $channel, sc_wpun()->channels()->get( 'via_helper' ) );

		sc_wpun()->channels()->unregister( 'via_helper' );
	}
}
