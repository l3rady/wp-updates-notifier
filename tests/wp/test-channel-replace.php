<?php
/**
 * Replacing a channel type (#445).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channel_Form;
use WPUN\Admin\Channels_Tab;
use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Dispatcher;
use WPUN\Notification;
use WPUN\Notification_Item;

/**
 * A channel that takes over a type.
 */
class Test_Replacement_Channel extends Test_Channel {

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Replacement';
	}
}

/**
 * A channel that needs an API version this plugin does not have.
 */
class Test_Unmet_Channel extends Test_Channel {

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
 * Registry rules, delivery, the Channels tab, saved settings and Site Health.
 */
class Test_Channel_Replace extends WPUN_Test_Case {

	/**
	 * Tests the plugin as one site's, so on a multisite it is not network-activated.
	 *
	 * @var bool
	 */
	protected $network_activated = false;

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Create the user.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * An administrator, the default settings, and no module hooks.
	 */
	public function set_up() {
		parent::set_up();

		remove_all_actions( 'sc_wpun_register_channels' );
		sc_wpun()->config()->flush();
		$this->save_settings();
		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Remove the types the tests used from the shared registry; the built-in channels are never replaced here.
	 */
	public function tear_down() {
		foreach ( array( 'swap', 'swap_gated' ) as $type ) {
			sc_wpun()->channels()->unregister( $type );
		}
		sc_wpun()->config()->flush();
		$_POST = array();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'], $_GET['type'] );
		parent::tear_down();
	}

	/**
	 * A notification.
	 *
	 * @return Notification
	 */
	private function notification() {
		$item = new Notification_Item(
			array(
				'type'            => 'core',
				'key'             => 'core',
				'name'            => 'WordPress',
				'current_version' => '6.0',
				'new_version'     => '6.1',
			)
		);

		return new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $item ) );
	}

	/**
	 * The Channels tab, as HTML.
	 *
	 * @return string
	 */
	private function tab(): string {
		$_GET['tab'] = 'channels';
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();

		return (string) ob_get_clean();
	}

	// The registry.

	/**
	 * The replacement is active whether it runs before or after the original, and the original is kept.
	 */
	public function test_replace_is_independent_of_order() {
		$original    = new Test_Channel( 'swap' );
		$replacement = new Test_Replacement_Channel( 'swap' );

		$first = new Channel_Registry();
		$this->assertTrue( $first->register( $original ) );
		$this->assertTrue( $first->replace( $replacement ) );
		$this->assertSame( $replacement, $first->get( 'swap' ) );
		$this->assertSame( $original, $first->get_replaced( 'swap' ) );

		$second = new Channel_Registry();
		$this->assertTrue( $second->replace( $replacement ) );
		$this->assertNull( $second->get_replaced( 'swap' ), 'The original has not registered yet.' );
		$this->assertFalse( $second->register( $original ), 'Quietly, without a warning.' );
		$this->assertSame( $replacement, $second->get( 'swap' ) );
		$this->assertSame( $original, $second->get_replaced( 'swap' ) );
		$this->assertSame( array( 'swap' => $replacement ), $second->all() );
	}

	/**
	 * Through the hook, the order of the two add-ons does not matter.
	 */
	public function test_replace_through_the_hook_in_either_order() {
		$original    = new Test_Channel( 'swap' );
		$replacement = new Test_Replacement_Channel( 'swap' );

		foreach ( array( 5, 15 ) as $replace_priority ) {
			remove_all_actions( 'sc_wpun_register_channels' );
			add_action(
				'sc_wpun_register_channels',
				static function ( Channel_Registry $registry ) use ( $original ) {
					$registry->register( $original );
				},
				10
			);
			add_action(
				'sc_wpun_register_channels',
				static function ( Channel_Registry $registry ) use ( $replacement ) {
					$registry->replace( $replacement );
				},
				$replace_priority
			);

			$registry = new Channel_Registry();
			$this->assertSame( $replacement, $registry->get( 'swap' ) );
			$this->assertSame( $original, $registry->get_replaced( 'swap' ) );
		}
	}

	/**
	 * The first replacement wins; a second warns and is refused.
	 */
	public function test_a_second_replace_is_refused() {
		$this->setExpectedIncorrectUsage( 'WPUN\Channel_Registry::replace' );

		$registry = new Channel_Registry();
		$registry->register( new Test_Channel( 'swap' ) );
		$first = new Test_Replacement_Channel( 'swap' );

		$this->assertTrue( $registry->replace( $first ) );
		$this->assertFalse( $registry->replace( new Test_Replacement_Channel( 'swap' ) ) );
		$this->assertSame( $first, $registry->get( 'swap' ) );
	}

	/**
	 * An invalid type warns.
	 */
	public function test_replace_with_an_invalid_type_warns() {
		$this->setExpectedIncorrectUsage( 'WPUN\Channel_Registry::replace' );

		$registry = new Channel_Registry();

		$this->assertFalse( $registry->replace( new Test_Replacement_Channel( 'Not Valid' ) ) );
		$this->assertFalse( $registry->has( 'Not Valid' ) );
	}

	/**
	 * A replacement that needs a newer API is refused and recorded, and the original stays, in either order.
	 */
	public function test_a_refused_replace_keeps_the_original() {
		$calls = array();
		add_action(
			'sc_wpun_channel_rejected',
			static function ( $type ) use ( &$calls ) {
				$calls[] = $type;
			}
		);

		$original = new Test_Channel( 'swap' );
		$gated    = new Test_Unmet_Channel( 'swap' );

		$before = new Channel_Registry();
		$this->assertFalse( $before->replace( $gated ) );
		$this->assertTrue( $before->register( $original ) );
		$this->assertSame( $original, $before->get( 'swap' ) );
		$this->assertNull( $before->get_replaced( 'swap' ) );
		$this->assertSame( array( 'swap' ), array_keys( $before->get_rejected() ), 'Still reported after the original registered.' );

		$after = new Channel_Registry();
		$this->assertTrue( $after->register( $original ) );
		$this->assertFalse( $after->replace( $gated ) );
		$this->assertSame( $original, $after->get( 'swap' ) );
		$this->assertNull( $after->get_replaced( 'swap' ) );
		$this->assertSame( array( 'swap' ), array_keys( $after->get_rejected() ) );
		$this->assertSame( array( 'swap', 'swap' ), $calls );
	}

	/**
	 * A refused replacement does not claim the type: a later valid one succeeds and clears the rejection.
	 */
	public function test_a_valid_replace_after_a_refused_one_clears_the_rejection() {
		$original    = new Test_Channel( 'swap' );
		$replacement = new Test_Replacement_Channel( 'swap' );

		$registry = new Channel_Registry();
		$registry->register( $original );
		$this->assertFalse( $registry->replace( new Test_Unmet_Channel( 'swap' ) ) );
		$this->assertTrue( $registry->replace( $replacement ) );

		$this->assertSame( array(), $registry->get_rejected() );
		$this->assertSame( $replacement, $registry->get( 'swap' ) );
		$this->assertSame( $original, $registry->get_replaced( 'swap' ) );
	}

	/**
	 * An original that needs a newer API is dropped quietly when the type is replaced.
	 */
	public function test_an_original_that_cannot_run_is_not_recorded() {
		$replacement = new Test_Replacement_Channel( 'swap' );

		$registry = new Channel_Registry();
		$this->assertTrue( $registry->replace( $replacement ) );
		$this->assertFalse( $registry->register( new Test_Unmet_Channel( 'swap' ) ) );

		$this->assertSame( $replacement, $registry->get( 'swap' ) );
		$this->assertNull( $registry->get_replaced( 'swap' ) );
		$this->assertSame( array(), $registry->get_rejected() );
	}

	/**
	 * Replacing a type nothing registers works like register().
	 */
	public function test_replacing_an_unregistered_type_works_like_register() {
		$replacement = new Test_Replacement_Channel( 'swap' );

		$registry = new Channel_Registry();
		$this->assertTrue( $registry->replace( $replacement ) );

		$this->assertTrue( $registry->has( 'swap' ) );
		$this->assertSame( $replacement, $registry->get( 'swap' ) );
		$this->assertNull( $registry->get_replaced( 'swap' ) );
	}

	/**
	 * Unregistering removes the replacement and the original, and the type can be registered normally again.
	 */
	public function test_unregister_removes_the_replacement_and_the_original() {
		$registry = new Channel_Registry();
		$registry->register( new Test_Channel( 'swap' ) );
		$registry->replace( new Test_Replacement_Channel( 'swap' ) );

		$this->assertTrue( $registry->unregister( 'swap' ) );
		$this->assertFalse( $registry->has( 'swap' ) );
		$this->assertNull( $registry->get_replaced( 'swap' ) );

		$again = new Test_Channel( 'swap' );
		$this->assertTrue( $registry->register( $again ) );
		$this->assertSame( $again, $registry->get( 'swap' ) );
		$this->assertNull( $registry->get_replaced( 'swap' ) );
	}

	/**
	 * A type that is not replaced has no original to give.
	 */
	public function test_get_replaced_is_null_for_other_types() {
		$registry = new Channel_Registry();
		$registry->register( new Test_Channel( 'swap' ) );

		$this->assertNull( $registry->get_replaced( 'swap' ) );
		$this->assertNull( $registry->get_replaced( 'unknown' ) );
	}

	// Delivery and the Channels tab.

	/**
	 * An instance is delivered through the replacement; with the replacement gone it goes through the original
	 * and is listed on the Channels tab again.
	 */
	public function test_instances_follow_the_active_channel() {
		$original    = new Test_Channel( 'swap' );
		$replacement = new Test_Replacement_Channel( 'swap' );
		$instance    = new Channel_Instance( 'swap_1', 'swap', 'Swap one', true, array() );

		$with = new Channel_Registry();
		$with->register( $original );
		$with->replace( $replacement );
		$result = ( new Dispatcher( $with ) )->send_one( $this->notification(), $instance );

		$this->assertTrue( $result->is_success() );
		$this->assertCount( 1, $replacement->received['swap_1'] );
		$this->assertSame( array(), $original->received );

		$without = new Channel_Registry();
		$without->register( $original );
		$result = ( new Dispatcher( $without ) )->send_one( $this->notification(), $instance );

		$this->assertTrue( $result->is_success() );
		$this->assertCount( 1, $original->received['swap_1'] );
		$this->assertCount( 1, $replacement->received['swap_1'] );

		sc_wpun_channels()->save( $instance );
		sc_wpun()->channels()->register( $original );
		$this->assertStringContainsString( 'Swap one', $this->tab() );
	}

	/**
	 * A refused replacement over a working original does not make its instances unavailable.
	 */
	public function test_a_refused_replacement_does_not_list_working_instances_as_unavailable() {
		sc_wpun_channels()->save( new Channel_Instance( 'swap_1', 'swap', 'Swap one', true, array() ) );
		sc_wpun()->channels()->register( new Test_Channel( 'swap' ) );
		sc_wpun()->channels()->replace( new Test_Unmet_Channel( 'swap' ) );
		$this->assertArrayHasKey( 'swap', sc_wpun()->channels()->get_rejected(), 'The refusal is still reported.' );

		$html = $this->tab();

		$this->assertStringNotContainsString( 'sc-wpun-unavailable-channels', $html );
		$this->assertStringContainsString( 'Swap one', $html );
	}

	// Settings.

	/**
	 * Store an instance with a key that the active channel does not declare.
	 */
	private function store_with_extra_key() {
		sc_wpun()->channels()->register( new Test_Channel( 'swap' ) );
		sc_wpun()->channels()->replace( new Test_Replacement_Channel( 'swap' ) );
		sc_wpun_channels()->save(
			new Channel_Instance(
				'swap_1',
				'swap',
				'Swap one',
				true,
				array(
					'behaviour' => 'succeed',
					'extra_key' => 'kept',
				)
			)
		);
	}

	/**
	 * Saving the form keeps the keys the active channel does not declare.
	 */
	public function test_saving_the_form_keeps_unknown_setting_keys() {
		$this->store_with_extra_key();
		$_POST = array(
			'action'   => Channel_Form::SAVE_ACTION,
			'mode'     => 'edit',
			'id'       => 'swap_1',
			'label'    => 'Swap renamed',
			'enabled'  => '1',
			'routing'  => array(
				'scope' => 'all',
				'core'  => 'all',
				'types' => array( '' ),
			),
			'settings' => array(
				'behaviour'   => 'fail',
				'retry_after' => '',
			),
			'_wpnonce' => wp_create_nonce( Channel_Form::SAVE_ACTION . '_edit_swap_1' ),
		);

		sc_wpun()->channel_form()->process_save();

		$saved = sc_wpun_channels()->stored_all()['swap_1'];
		$this->assertSame( 'Swap renamed', $saved->get_label() );
		$this->assertSame( 'fail', $saved->get_setting( 'behaviour' ) );
		$this->assertSame( 'kept', $saved->get_setting( 'extra_key' ) );
	}

	/**
	 * Duplicating keeps the keys the active channel does not declare.
	 */
	public function test_duplicating_keeps_unknown_setting_keys() {
		$this->store_with_extra_key();
		$_POST['id']       = 'swap_1';
		$_POST['_wpnonce'] = wp_create_nonce( Channels_Tab::DUPLICATE_ACTION . '_swap_1' );

		$this->assertTrue( sc_wpun()->channels_tab()->process_duplicate() );

		$copy = sc_wpun_channels()->stored_all()['swap_1_copy'];
		$this->assertSame( 'kept', $copy->get_setting( 'extra_key' ) );
	}

	// Site Health.

	/**
	 * The debug information names the replacement for an instance of a replaced type, and shows a dash otherwise.
	 */
	public function test_site_health_shows_the_replacement() {
		$this->store_with_extra_key();
		sc_wpun_channels()->save( new Channel_Instance( 'swap_plain', 'swap_gated', 'Plain', true, array() ) );
		sc_wpun()->channels()->register( new Test_Channel( 'swap_gated' ) );

		$fields = apply_filters( 'debug_information', array() )[ WPUN\Site_Health::SECTION ]['fields'];

		$this->assertStringContainsString( 'Replaced by: Test_Replacement_Channel;', $fields['channel_swap_1']['value'] );
		$this->assertStringContainsString( 'replaced by: Test_Replacement_Channel;', $fields['channel_swap_1']['debug'] );
		$this->assertStringContainsString( 'Replaced by: -;', $fields['channel_swap_plain']['value'] );
		$this->assertStringContainsString( 'replaced by: -;', $fields['channel_swap_plain']['debug'] );
	}
}
