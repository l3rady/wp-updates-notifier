<?php
/**
 * The plugin is extensible from outside (#105): the test add-on in tests/fixtures/plugins/wpun-test-addon uses only
 * the public API (bin/check-public-api.php enforces that), and these tests prove what it does works.
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channel_Form;
use WPUN\CLI\Channels_Command;
use WPUN\CLI\Updates_Notifier_Command;
use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Delivery_Result;
use WPUN\Notification;
use WPUN_Test_Addon\Addon;
use WPUN_Test_Addon\Addon_Channel;

require_once __DIR__ . '/fixtures/wp-cli.php';

/**
 * Registering a module, its form, routing, delivery and results, configuration from outside, and the hook reference.
 */
class Test_Extension_Api extends WPUN_Test_Case {

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
	 * "Activate" the add-on: wire its hooks, and register its module in the registry that is already loaded.
	 *
	 * The hooks are removed again by WordPress after the test; the registry lives on, so tear_down() unregisters.
	 */
	public function set_up() {
		parent::set_up();

		WPUN_Test_Addon\boot();
		$this->assertNotFalse( has_filter( 'sc_wpun_pre_send', array( Addon::class, 'pre_send' ) ), 'The add-on is wired.' );
		Addon::reset();
		// The first read of the registry fires the registration action, which the hooks above now answer too.
		if ( ! sc_wpun()->channels()->has( Addon_Channel::TYPE ) ) {
			Addon::register_channels( sc_wpun()->channels() );
		}

		sc_wpun()->config()->flush();
		// The default email instance is off, so only the add-on's instances receive anything.
		$this->save_settings( array( 'email_notifications' => 0 ) );
		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Put the registry back as the rest of the suite expects it.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( Addon_Channel::TYPE );
		if ( ! sc_wpun()->channels()->has( 'email' ) ) {
			sc_wpun()->channels()->register( new WPUN\Modules\Email\Email_Channel() );
		}
		Addon::reset();
		sc_wpun()->config()->flush();
		$_POST = array();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'], $_GET['type'] );
		parent::tear_down();
	}

	/**
	 * Add an instance of the add-on's module through the settings screen handler.
	 *
	 * @param string $label    Label.
	 * @param array  $settings Settings to replace.
	 * @param string $url      Set to the address the handler returned.
	 * @return string The id of the new instance, or an empty string if none was added.
	 */
	private function add_instance( $label, array $settings = array(), &$url = '' ) {
		$before = array_keys( sc_wpun_channels()->all() );
		$_POST  = array(
			'action'   => Channel_Form::SAVE_ACTION,
			'mode'     => 'new',
			'type'     => Addon_Channel::TYPE,
			'label'    => $label,
			'enabled'  => '1',
			'routing'  => array(
				'scope' => 'all',
				'core'  => 'all',
				'types' => array( '' ),
			),
			'settings' => array_merge(
				array(
					'code'     => 'ABC-123',
					'name'     => 'Name',
					'receives' => 'all',
					'note'     => '',
				),
				$settings
			),
			'_wpnonce' => wp_create_nonce( Channel_Form::SAVE_ACTION . '_new_' . Addon_Channel::TYPE ),
		);

		$url = (string) sc_wpun()->channel_form()->process_save();
		$new = array_values( array_diff( array_keys( sc_wpun_channels()->all() ), $before ) );

		return $new[0] ?? '';
	}

	/**
	 * The Channels tab for a request, parsed.
	 *
	 * @param array $get Query arguments.
	 * @return DOMXPath
	 */
	private function tab( array $get = array() ) {
		$_GET['tab'] = 'channels';
		unset( $_GET['action'], $_GET['id'], $_GET['type'] );
		foreach ( $get as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		ob_start();
		sc_wpun()->channels_tab()->render();
		$html = (string) ob_get_clean();

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return new DOMXPath( $doc );
	}

	/**
	 * Run an update check with the given updates pending.
	 *
	 * @param string|null $core    Core version offered.
	 * @param array       $plugins Plugin updates.
	 */
	private function run_check( $core = null, array $plugins = array() ) {
		$this->set_updates( $core, $plugins );
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * The stored last result of an instance.
	 *
	 * @param string $id Instance id.
	 * @return array|null
	 */
	private function last_result( $id ) {
		return $this->stored_state()['channels'][ $id ]['last_result'] ?? null;
	}

	/**
	 * Report a note as an item of the add-on's own type on every update check.
	 */
	private function report_a_note() {
		update_option(
			Addon::OPTION,
			array(
				'items' => array(
					array(
						'key'     => 'note-1',
						'name'    => 'A note',
						'current' => '1',
						'new'     => '2',
					),
				),
			)
		);
	}

	// The module and its form.

	/**
	 * The module registers itself through the action, on a registry that has not been read yet.
	 */
	public function test_the_module_registers_through_the_action() {
		$registry = new Channel_Registry();

		$this->assertTrue( $registry->has( Addon_Channel::TYPE ) );
		$this->assertInstanceOf( Addon_Channel::class, $registry->get( Addon_Channel::TYPE ) );
		$this->assertTrue( $registry->has( 'email' ), 'Next to the built-in module.' );
	}

	/**
	 * Without the add-on's hooks nothing of it is there: it only does what its hooks do.
	 */
	public function test_nothing_happens_without_the_hooks() {
		remove_all_actions( 'sc_wpun_register_channels' );
		remove_all_filters( 'sc_wpun_item_types' );
		remove_all_filters( 'sc_wpun_update_items' );
		$this->report_a_note();

		$this->assertFalse( ( new Channel_Registry() )->has( Addon_Channel::TYPE ) );
		$this->assertArrayNotHasKey( Addon::ITEM_TYPE, WPUN\Item_Types::all() );
		$this->run_check();
		$this->assertSame( array(), sc_wpun()->update_checker()->last_notification()->get_items() );
	}

	/**
	 * It adds nothing of its own to an update check until its data says so.
	 */
	public function test_it_adds_no_items_by_default() {
		$this->add_instance( 'First' );

		$this->run_check( '99.0.0' );

		$this->assertSame( array( 'core' ), array_map( static fn( $item ) => $item->get_type(), sc_wpun()->update_checker()->last_notification()->get_items() ) );
	}

	/**
	 * The module can be added twice, and both instances are stored with the settings of the form.
	 */
	public function test_the_module_is_added_twice_through_the_form() {
		$first  = $this->add_instance( 'First one', array( 'receives' => 'plugin' ) );
		$second = $this->add_instance( 'Second one', array( 'name' => 'Other', 'code' => 'XYZ-999' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertNotSame( '', $first );
		$this->assertNotSame( '', $second );
		$this->assertNotSame( $first, $second );
		$this->assertSame( Addon_Channel::TYPE, sc_wpun_channels()->get( $first )->get_type() );
		$this->assertSame( 'plugin', $this->instance_setting( $first, 'receives' ) );
		$this->assertSame( 'XYZ-999', $this->instance_setting( $second, 'code' ) );
		$this->assertSame( 'Other', $this->instance_setting( $second, 'name' ) );
	}

	/**
	 * The add-on's field type validates: a bad code is refused and nothing is stored.
	 */
	public function test_the_custom_field_type_validates() {
		$url = '';
		$id  = $this->add_instance( 'Bad code', array( 'code' => 'not a code' ), $url );

		$this->assertSame( '', $id );
		$this->assertStringContainsString( 'action=new', $url, 'Back to the form.' );
		$this->assertSame( array( 'email' ), array_keys( sc_wpun_channels()->all() ) );
		$this->assertInstanceOf( WP_Error::class, WPUN\Field_Types::get( 'test_addon_code' )['sanitize']( 'nope', array() ) );
		$this->assertSame( 'QRS-123', WPUN\Field_Types::get( 'test_addon_code' )['sanitize']( 'QRS-123', array() ) );
	}

	/**
	 * The field type draws its own control.
	 */
	public function test_the_custom_field_type_draws_its_control() {
		$markup = WPUN\Field_Types::get( 'test_addon_code' )['render']( array(), 'settings[code]', 'code_id', 'ABC-123', false, '', 'Bad' );

		$this->assertStringContainsString( 'class="test-addon-code"', $markup );
		$this->assertStringContainsString( 'name="settings[code]"', $markup );
		$this->assertStringContainsString( 'aria-invalid="true"', $markup );
	}

	/**
	 * The form uses the module's own render_settings() for the settings.
	 */
	public function test_the_form_uses_the_custom_render() {
		$id = $this->add_instance( 'Drawn', array( 'name' => 'Shown name' ) );

		foreach ( array(
			array( 'new', array( 'type' => Addon_Channel::TYPE ) ),
			array( 'edit', array( 'id' => $id ) ),
		) as $case ) {
			$xpath = $this->tab( array( 'action' => $case[0] ) + $case[1] );

			$this->assertSame( 1, $xpath->query( '//form//div[@class="test-addon-settings"]' )->length, $case[0] );
			$this->assertSame( 0, $xpath->query( '//input[@class="test-addon-code"]' )->length, 'The generic controls are replaced.' );
			$this->assertGreaterThan( 0, $xpath->query( '//input[@name="enabled"]' )->length, 'The core form stays around the settings.' );
		}
		$this->assertSame( 'Shown name', $xpath->query( '//div[@class="test-addon-settings"]/input[@name="settings[name]"]' )->item( 0 )->getAttribute( 'value' ) );
	}

	// Delivery.

	/**
	 * Each instance receives only the items routed to it: by its own setting, and by the routing of the form.
	 */
	public function test_each_instance_receives_only_the_items_routed_to_it() {
		$this->report_a_note();
		$plugins  = $this->add_instance( 'Plugins only', array( 'receives' => 'plugin' ) );
		$notes    = $this->add_instance( 'Notes only', array( 'receives' => Addon::ITEM_TYPE ) );
		$everyone = $this->add_instance( 'Everything' );

		$this->run_check( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->assertSame( array( array( 'plugin:hello.php' ) ), Addon::$received[ $plugins ] );
		$this->assertSame( array( array( 'test_addon_note:note-1' ) ), Addon::$received[ $notes ] );
		$this->assertCount( 1, Addon::$received[ $everyone ] );
		$this->assertEqualsCanonicalizing( array( 'core:core', 'plugin:hello.php', 'test_addon_note:note-1' ), Addon::$received[ $everyone ][0] );
		$this->assertSame( 0, $this->sent_mail_count(), 'Email is off.' );

		$this->assertSame( array( 'test_addon_note' ), array_keys( array_intersect_key( WPUN\Item_Types::all(), array( 'test_addon_note' => 1 ) ) ), 'The item type is registered.' );

		// Delivered once: the next run has nothing new.
		$this->run_check( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		$this->assertCount( 1, Addon::$received[ $everyone ] );

		// The routing of the form applies as well: an instance set to core only gets none of the add-on's items.
		$core = $this->add_instance( 'Core by routing', array(), $url );
		sc_wpun_channels()->save( sc_wpun_channels()->get( $core )->with_routing( array( 'types' => array( 'core' ) ) ) );
		$this->run_check( '99.1.0', array( 'hello.php' => array( 'new_version' => '2.1.0' ) ) );
		$this->assertSame( array( array( 'core:core' ) ), Addon::$received[ $core ] );
	}

	/**
	 * The add-on's listeners record what its instances were told, for success and for failure.
	 */
	public function test_sent_and_failed_events_reach_the_add_on() {
		$good = $this->add_instance( 'Good' );
		$bad  = $this->add_instance( 'Bad', array( 'note' => 'fail' ) );

		$this->run_check( '99.0.0' );

		$this->assertEqualsCanonicalizing(
			array(
				array( 'sent', $good, 'success' ),
				array( 'failed', $bad, 'addon_refused' ),
			),
			Addon::$events
		);
		$this->assertNotFalse( has_action( 'sc_wpun_notification_sent' ) );
		$this->assertNotFalse( has_action( 'sc_wpun_notification_failed' ) );
		$this->assertSame( 'addon_refused', sc_wpun()->update_checker()->last_results()[ $bad ]->get_code() );
		$this->assertSame( '', $this->notified_version( 'core', 'core', $bad ), 'A failure is retried.' );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', $good ) );
	}

	/**
	 * Results show in the stored state and in the Channels list.
	 */
	public function test_results_show_in_the_channel_list() {
		$good = $this->add_instance( 'Good one' );
		$bad  = $this->add_instance( 'Bad one', array( 'note' => 'fail' ) );

		$this->assertNull( $this->last_result( $good ) );
		$this->run_check( '99.0.0' );

		$last = $this->last_result( $good );
		$this->assertTrue( $last['success'] );
		$this->assertSame( 'Received 1 items.', $last['message'] );
		$last = $this->last_result( $bad );
		$this->assertFalse( $last['success'] );
		$this->assertSame( 'The add-on refused.', $last['message'] );

		$xpath = $this->tab();
		$this->assertStringContainsString( 'Test add-on', $xpath->query( '//tr[@data-id="' . $good . '"]/td[@class="sc-wpun-channel-module"]' )->item( 0 )->textContent );
		$this->assertStringStartsWith( 'Sent ', $xpath->query( '//tr[@data-id="' . $good . '"]/td[@class="sc-wpun-channel-last"]' )->item( 0 )->textContent );
		$this->assertStringContainsString( 'Failed', $xpath->query( '//tr[@data-id="' . $bad . '"]/td[@class="sc-wpun-channel-last"]' )->item( 0 )->textContent );
		$this->assertStringContainsString( 'The add-on refused.', $xpath->query( '//tr[@data-id="' . $bad . '"]/td[@class="sc-wpun-channel-last"]' )->item( 0 )->textContent );
	}

	// Deactivating the add-on.

	/**
	 * Without the module its instances stay stored, leave the list and are reported unavailable; unregistering sticks.
	 */
	public function test_deactivating_the_add_on() {
		$one = $this->add_instance( 'One' );
		$two = $this->add_instance( 'Two' );
		$this->assertSame( 2, $this->tab()->query( '//tr[@data-id="' . $one . '" or @data-id="' . $two . '"]' )->length );

		$this->assertTrue( sc_wpun()->channels()->unregister( Addon_Channel::TYPE ) );

		$this->assertFalse( sc_wpun()->channels()->has( Addon_Channel::TYPE ), 'It is not registered again by the next read.' );
		$this->assertArrayNotHasKey( Addon_Channel::TYPE, sc_wpun()->channels()->all() );
		$this->assertNotNull( sc_wpun_channels()->get( $one ), 'Still stored.' );
		$this->assertNotNull( sc_wpun_channels()->get( $two ) );
		$this->assertSame( 0, $this->tab()->query( '//tr[@data-id="' . $one . '" or @data-id="' . $two . '"]' )->length, 'Absent from the list.' );
		$this->assertFalse( sc_wpun()->dispatcher()->is_available( sc_wpun_channels()->get( $one ) ) );

		$this->run_check( '99.0.0' );
		$results = sc_wpun()->update_checker()->last_results();
		$this->assertSame( 'unavailable', $results[ $one ]->get_code() );
		$this->assertSame( 'unavailable', $results[ $two ]->get_code() );
		$this->assertSame( array(), Addon::$received, 'The add-on is never asked.' );

		$notification = new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array() );
		$this->assertSame( 'unavailable', sc_wpun()->dispatcher()->send_one( $notification, sc_wpun_channels()->get( $one ) )->get_code() );

		// Activating it again brings them back.
		Addon::register_channels( sc_wpun()->channels() );
		$this->assertSame( 2, $this->tab()->query( '//tr[@data-id="' . $one . '" or @data-id="' . $two . '"]' )->length );
	}

	/**
	 * With every built-in module gone the plugin still boots, checks and dispatches without errors.
	 */
	public function test_the_plugin_runs_without_any_built_in_module() {
		$this->save_settings();
		foreach ( array_keys( sc_wpun()->channels()->all() ) as $type ) {
			sc_wpun()->channels()->unregister( $type );
		}
		$this->assertSame( array(), sc_wpun()->channels()->all() );

		$plugin = new WPUN\Plugin();
		$plugin->boot();
		$this->run_check( '99.0.0' );

		$this->assertSame( 'unavailable', sc_wpun()->update_checker()->last_results()['email']->get_code() );
		$this->assertSame( 0, $this->sent_mail_count() );
		$results = sc_wpun()->dispatcher()->dispatch( sc_wpun()->update_checker()->last_notification(), sc_wpun_channels()->all() );
		$this->assertSame( 'unavailable', $results['email']->get_code() );

		$xpath = $this->tab();
		$this->assertSame( 0, $xpath->query( '//tr[@data-id]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[contains(@class,"sc-wpun-no-channels")]' )->length );
		$xpath = $this->tab( array( 'action' => 'new', 'type' => 'email' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 0, $xpath->query( '//form[@class="sc-wpun-channel-form"]' )->length );
	}

	// Configuration from outside the database.

	/**
	 * A channel instance the add-on defines in code is listed read-only and receives updates.
	 */
	public function test_the_add_on_defines_a_channel_in_code() {
		$this->assertNull( sc_wpun_channels()->get( Addon::CODE_ID ), 'Only when its data asks for it.' );

		update_option( Addon::OPTION, array( 'code_channel' => true ) );
		sc_wpun()->config()->flush();

		$instance = sc_wpun_channels()->get( Addon::CODE_ID );
		$this->assertNotNull( $instance );
		$this->assertTrue( sc_wpun_channels()->is_code_defined( Addon::CODE_ID ) );
		$this->assertSame( Addon_Channel::TYPE, $instance->get_type() );
		$this->assertSame( 'COD-001', $instance->get_setting( 'code' ) );

		$xpath = $this->tab();
		$this->assertSame( 1, $xpath->query( '//tr[@data-id="' . Addon::CODE_ID . '"]//*[contains(@class,"sc-wpun-code-defined")]' )->length );
		$this->assertSame( 0, $xpath->query( '//tr[@data-id="' . Addon::CODE_ID . '"]//a[@class="sc-wpun-edit"]' )->length, 'Read-only.' );

		$this->run_check( '99.0.0' );
		$this->assertSame( array( array( 'core:core' ) ), Addon::$received[ Addon::CODE_ID ] );
		$this->assertTrue( $this->last_result( Addon::CODE_ID )['success'] );
	}

	/**
	 * The add-on's configuration source supplies values for its own instances, and only those.
	 */
	public function test_the_add_on_supplies_configuration() {
		foreach ( array( 'addon_stored', 'other_stored' ) as $id ) {
			sc_wpun_channels()->save( new Channel_Instance( $id, Addon_Channel::TYPE, $id, true, array( 'code' => 'ABC-123', 'name' => 'Stored name' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		update_option(
			Addon::OPTION,
			array(
				'config' => array(
					'SC_WPUN_CHANNEL_ADDON_STORED__NAME' => 'From the add-on',
					'SC_WPUN_CHANNEL_OTHER_STORED__NAME' => 'Not for this one',
				),
			)
		);
		sc_wpun()->config()->flush();

		$this->assertSame( 'From the add-on', sc_wpun_channels()->get( 'addon_stored' )->get_setting( 'name' ) );
		$this->assertStringContainsString( 'the test add-on', (string) sc_wpun_channels()->external_source( 'addon_stored', 'name' ) );
		$this->assertSame( 'Stored name', sc_wpun_channels()->get( 'other_stored' )->get_setting( 'name' ), 'Not an id the add-on owns.' );
		$this->assertNull( sc_wpun_channels()->external_source( 'other_stored', 'name' ) );

		delete_option( Addon::OPTION );
		sc_wpun()->config()->flush();
		$this->assertSame( 'Stored name', sc_wpun_channels()->get( 'addon_stored' )->get_setting( 'name' ), 'Nothing supplied without its data.' );
	}

	// The hook reference (#96).

	/**
	 * The delivery hooks fire with the documented arguments, and a short-circuit failure and success are respected.
	 */
	public function test_the_delivery_hooks_get_the_documented_arguments() {
		$this->save_settings();
		$seen = array();
		add_filter(
			'sc_wpun_pre_send',
			static function ( $pre, $notification, $instance ) use ( &$seen ) {
				$seen['pre_send'] = array( $pre, $notification instanceof Notification, $instance instanceof Channel_Instance, $instance->get_id() );
				return 'email' === $instance->get_id() && isset( $seen['fail'] ) ? Delivery_Result::failure( 'No.', 'no' ) : Delivery_Result::success( 'Yes.' );
			},
			10,
			3
		);
		foreach ( array( 'sent', 'failed' ) as $kind ) {
			add_action(
				'sc_wpun_notification_' . $kind,
				static function ( $result, $notification, $instance ) use ( &$seen, $kind ) {
					$seen[ $kind ] = array( $result->get_message(), $notification instanceof Notification, $instance->get_id() );
				},
				10,
				3
			);
		}

		$this->run_check( '99.0.0' );
		$this->assertSame( array( null, true, true, 'email' ), $seen['pre_send'] );
		$this->assertSame( array( 'Yes.', true, 'email' ), $seen['sent'] );
		$this->assertArrayNotHasKey( 'failed', $seen );

		$seen['fail'] = true;
		$this->run_check( '99.1.0' );
		$this->assertSame( array( 'No.', true, 'email' ), $seen['failed'] );
		$this->assertSame( 'no', sc_wpun()->update_checker()->last_results()['email']->get_code() );
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * The payload filters get the payload, the notification and the instance, and what they return is sent.
	 */
	public function test_the_payload_filters_get_the_documented_arguments() {
		$this->save_settings();
		$seen = array();
		foreach ( array( 'sc_wpun_channel_payload', 'sc_wpun_channel_payload_email' ) as $hook ) {
			add_filter(
				$hook,
				static function ( $payload, $notification, $instance ) use ( &$seen, $hook ) {
					$seen[ $hook ]      = array( is_array( $payload ), $notification instanceof Notification, $instance instanceof Channel_Instance );
					$payload['subject'] = 'Payload ' . $hook;
					return $payload;
				},
				10,
				3
			);
		}

		$this->run_check( '99.0.0' );

		$this->assertSame( array( true, true, true ), $seen['sc_wpun_channel_payload'] );
		$this->assertSame( array( true, true, true ), $seen['sc_wpun_channel_payload_email'] );
		$this->assertSame( 'Payload sc_wpun_channel_payload_email', $this->sent_mail()->subject );
	}

	/**
	 * Every hook the plugin fires is named in at least one test.
	 */
	public function test_every_hook_is_referenced_by_a_test() {
		$root  = dirname( SC_WPUN_FILE );
		$tests = '';
		foreach ( (array) glob( __DIR__ . '/*.php' ) as $file ) {
			$tests .= (string) file_get_contents( $file ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local files.
		}

		$sources = array( $root . '/wp-updates-notifier.php' );
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$path = $file->getPathname();
			if ( 'php' === $file->getExtension() && ( 0 === strpos( $path, $root . '/includes/' ) || 0 === strpos( $path, $root . '/modules/' ) ) ) {
				$sources[] = $path;
			}
		}

		$hooks = array();
		foreach ( $sources as $source ) {
			preg_match_all( '/\b(?:apply_filters|do_action)(?:_deprecated)?\(\s*["\'](sc_wpun_[^"\']*)["\']/', (string) file_get_contents( $source ), $matches ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local files.
			$hooks = array_merge( $hooks, $matches[1] );
		}
		$hooks = array_unique( $hooks );

		$this->assertGreaterThan( 30, count( $hooks ), 'The scan found the hooks.' );
		foreach ( $hooks as $hook ) {
			$dynamic = strpos( $hook, '{' );
			if ( false === $dynamic ) {
				$this->assertMatchesRegularExpression( '/[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/', $tests, $hook . ' is not named by any test.' );
			} else {
				$this->assertMatchesRegularExpression( '/[\'"]' . preg_quote( substr( $hook, 0, $dynamic ), '/' ) . '/', $tests, $hook . ' is not named by any test.' );
			}
		}
	}

	// WP-CLI (#79).

	/**
	 * The add-on's instances are listed with their module and last result, and the status shows their fields.
	 */
	public function test_cli_lists_the_add_on_instances() {
		WP_CLI::reset();
		$good = $this->add_instance( 'Good' );
		$bad  = $this->add_instance( 'Bad', array( 'note' => 'fail' ) );
		$this->run_check( '99.0.0' );

		( new Channels_Command() )->list_( array(), array() );
		$out = WP_CLI::text();
		$this->assertStringContainsString( $good . "\tGood\t" . Addon_Channel::TYPE . "\tavailable\tyes\tno\tsuccess, ", $out );
		$this->assertStringContainsString( 'Received 1 items.', $out );
		$this->assertStringContainsString( $bad . "\tBad\t" . Addon_Channel::TYPE . "\tavailable\tyes\tno\tfailure, ", $out );
		$this->assertStringContainsString( 'The add-on refused.', $out );

		WP_CLI::reset();
		( new Updates_Notifier_Command() )->status( array(), array() );
		$this->assertStringContainsString( "name\tName\tdatabase", WP_CLI::text() );
		$this->assertStringContainsString( "note\tfail\tdatabase", WP_CLI::text() );
	}

	/**
	 * A test message goes through the add-on, with its filter and its events.
	 */
	public function test_cli_test_delivers_through_the_add_on() {
		WP_CLI::reset();
		$id        = $this->add_instance( 'Tested' );
		$pre_sends = 0;
		add_filter(
			'sc_wpun_pre_send',
			static function ( $pre, $notification ) use ( &$pre_sends ) {
				$pre_sends += $notification->is_test() ? 1 : 0;
				return $pre;
			},
			5,
			2
		);

		( new Updates_Notifier_Command() )->test( array(), array( 'channel' => $id ) );

		$this->assertStringContainsString( 'Success: Test message sent through Tested.', WP_CLI::text() );
		$this->assertSame( 1, $pre_sends );
		$this->assertSame( array( array( 'sent', $id, 'success' ) ), Addon::$events );
	}

	/**
	 * With the module unregistered its instances are unavailable and a test is refused.
	 */
	public function test_cli_reports_the_add_on_unavailable_when_deactivated() {
		WP_CLI::reset();
		$id = $this->add_instance( 'Gone' );
		sc_wpun()->channels()->unregister( Addon_Channel::TYPE );

		( new Channels_Command() )->list_( array(), array() );
		$this->assertStringContainsString( $id . "\tGone\t" . Addon_Channel::TYPE . "\tunknown module\tyes", WP_CLI::text() );

		try {
			( new Updates_Notifier_Command() )->test( array(), array( 'channel' => $id ) );
			$this->fail( 'The test must be refused.' );
		} catch ( Test_Cli_Error $e ) {
			$this->assertSame( 'Gone cannot be used: unknown module.', $e->getMessage() );
		}
		$this->assertSame( array(), Addon::$events );
		$this->assertSame( array(), Addon::$received );
	}
}
