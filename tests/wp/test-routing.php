<?php
/**
 * Which items each channel instance receives (#95).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Dispatcher;
use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Routing;

/**
 * Routing rules, the add-on filter and their effect on the update check and the dispatcher.
 */
class Test_Routing extends WPUN_Test_Case {

	const OWN_PLUGIN = 'wp-updates-notifier/wp-updates-notifier.php';

	/**
	 * Fake what core asks api.wordpress.org about plugins.
	 */
	public function set_up() {
		parent::set_up();

		$this->fake_http(
			'api.wordpress.org/plugins/info',
			$this->fake_response(
				200,
				wp_json_encode(
					array(
						'name'    => 'X',
						'slug'    => 'x',
						'version' => '9',
						'tested'  => '99',
					)
				)
			)
		);
		update_option( 'active_plugins', array( 'hello.php' ) );
	}

	/**
	 * Save an email instance with routing.
	 *
	 * @param string $id      Id.
	 * @param array  $routing Routing.
	 */
	private function add_routed( $id, array $routing ) {
		sc_wpun_channels()->save( new Channel_Instance( $id, 'email', ucfirst( $id ), true, array( 'to' => $id . '@example.org' ), $routing ) );
	}

	/**
	 * Run the check; returns the mail bodies by recipient.
	 *
	 * @return string[]
	 */
	private function run_check() {
		reset_phpmailer_instance();
		sc_wpun()->update_checker()->do_update_check();

		$out = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $i => $mail ) {
			$out[ tests_retrieve_phpmailer_instance()->get_recipient( 'to', $i )->address ] = $mail['body'];
		}

		return $out;
	}

	/**
	 * An item.
	 *
	 * @param string $type    Type.
	 * @param array  $flags   Flags.
	 * @param string $current Installed version.
	 * @param string $available Available version.
	 * @return Notification_Item
	 */
	private function item( $type, array $flags = array(), $current = '1.0', $available = '2.0' ) {
		return new Notification_Item(
			array(
				'type'            => $type,
				'key'             => $type . '-key',
				'name'            => 'Name',
				'current_version' => $current,
				'new_version'     => $available,
				'flags'           => $flags,
			)
		);
	}

	/**
	 * Known keys get defaults, bad values fall back, extra keys are kept.
	 */
	public function test_normalise() {
		$this->assertSame(
			array(
				'types'  => array(),
				'scope'  => 'all',
				'core'   => 'all',
				'remind' => 'never',
			),
			Routing::normalise( array() )
		);
		$this->assertSame(
			array(
				'types'  => array( 'core', 'plugin' ),
				'scope'  => 'all',
				'core'   => 'all',
				'addon'  => array( 'x' => 1 ),
				'remind' => 'never',
			),
			Routing::normalise(
				array(
					'types' => array( 'core', 5, '', 'core', 'plugin' ),
					'scope' => 'bogus',
					'core'  => array(),
					'addon' => array( 'x' => 1 ),
				)
			)
		);
		$this->assertSame( 'active', Routing::normalise( array( 'scope' => 'active' ) )['scope'] );
		$this->assertSame( 'major', Routing::normalise( array( 'core' => 'major' ) )['core'] );
		$this->assertSame( array(), Routing::normalise( array( 'types' => 'core' ) )['types'] );
	}

	/**
	 * Major.minor comparison.
	 *
	 * @dataProvider core_versions
	 *
	 * @param string $current Installed.
	 * @param string $available Available.
	 * @param bool   $major   Expected.
	 */
	public function test_is_major_core_update( $current, $available, $major ) {
		$this->assertSame( $major, Routing::is_major_core_update( $current, $available ) );
	}

	/**
	 * Versions.
	 *
	 * @return array
	 */
	public function core_versions() {
		return array(
			'minor release'      => array( '6.7', '6.7.1', false ),
			'patch to patch'     => array( '6.7.1', '6.7.2', false ),
			'next major.minor'   => array( '6.8.3', '6.9', true ),
			'new major'          => array( '6.9', '7.0', true ),
			'release candidate'  => array( '6.8', '6.9-RC1', true ),
			'rc becomes release' => array( '6.9-RC1', '6.9', false ),
		);
	}

	/**
	 * Two instances with different types each get their items only, record only those, and nothing is resent.
	 */
	public function test_instances_get_only_their_types() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->add_routed( 'core', array( 'types' => array( 'core' ) ) );
		$this->add_routed( 'rest', array( 'types' => array( 'plugin', 'theme' ) ) );
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ), array( get_stylesheet() => array( 'new_version' => '99.3' ) ) );

		$bodies = $this->run_check();

		$this->assertSame( array( 'core@example.org', 'rest@example.org' ), array_keys( $bodies ) );
		$this->assertStringContainsString( '99.0.0', $bodies['core@example.org'] );
		$this->assertStringNotContainsString( 'Hello Dolly', $bodies['core@example.org'] );
		$this->assertStringContainsString( 'Hello Dolly', $bodies['rest@example.org'] );
		$this->assertStringContainsString( '99.3', $bodies['rest@example.org'] );
		$this->assertStringNotContainsString( '99.0.0', $bodies['rest@example.org'] );
		$this->assertSame( array( 'core' ), array_keys( $this->channel_notified( 'core' ) ) );
		$this->assertSame( array( 'plugin', 'theme' ), array_keys( $this->channel_notified( 'rest' ) ) );

		$this->assertSame( array(), $this->run_check() );
	}

	/**
	 * Scope "active" keeps inactive plugins from the instance; the "all" instance gets both.
	 */
	public function test_active_scope_for_plugins() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->add_routed( 'everything', array() );
		$this->add_routed( 'active', array( 'scope' => 'active' ) );
		$this->set_updates(
			null,
			array(
				'hello.php'      => array( 'new_version' => '2.0.0' ),
				self::OWN_PLUGIN => array( 'new_version' => '4.5.6' ),
			)
		);

		$bodies = $this->run_check();

		$this->assertStringContainsString( 'WP Updates Notifier from version ', $bodies['everything@example.org'] );
		$this->assertStringContainsString( 'Hello Dolly', $bodies['everything@example.org'] );
		$this->assertStringContainsString( 'Hello Dolly', $bodies['active@example.org'] );
		$this->assertStringNotContainsString( 'WP Updates Notifier from version ', $bodies['active@example.org'] );
	}

	/**
	 * A network-activated plugin is active on multisite.
	 */
	public function test_network_activated_plugins_are_active() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->add_routed( 'active', array( 'scope' => 'active' ) );
		update_site_option( 'active_sitewide_plugins', array( self::OWN_PLUGIN => time() ) );
		$this->set_updates( null, array( self::OWN_PLUGIN => array( 'new_version' => '4.5.6' ) ) );

		$bodies = $this->run_check();

		$this->assertStringContainsString( 'WP Updates Notifier from version ', $bodies['active@example.org'] );
	}

	/**
	 * A child theme and its parent are active; other themes are not.
	 */
	public function test_active_scope_for_themes() {
		$slugs = array_keys( wp_get_themes() );
		update_option( 'template', $slugs[0] );
		update_option( 'stylesheet', $slugs[1] );
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->add_routed( 'active', array( 'scope' => 'active' ) );
		$this->set_updates(
			null,
			array(),
			array(
				$slugs[0] => array( 'new_version' => '90.1' ),
				$slugs[1] => array( 'new_version' => '91.1' ),
				$slugs[2] => array( 'new_version' => '92.1' ),
			)
		);

		$bodies = $this->run_check();

		$this->assertStringContainsString( '90.1', $bodies['active@example.org'] );
		$this->assertStringContainsString( '91.1', $bodies['active@example.org'] );
		$this->assertStringNotContainsString( '92.1', $bodies['active@example.org'] );
	}

	/**
	 * Core "major" skips a minor release for that instance only; a major release reaches both.
	 */
	public function test_core_major_only() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->add_routed( 'everything', array() );
		$this->add_routed( 'major', array( 'core' => 'major' ) );
		$minor = Routing::major_minor( wp_get_wp_version() ) . '.99';
		$this->set_updates( $minor );

		$bodies = $this->run_check();
		$this->assertSame( array( 'everything@example.org' ), array_keys( $bodies ) );

		$this->set_updates( '99.0.0' );
		$bodies = $this->run_check();
		$this->assertSame( array( 'everything@example.org', 'major@example.org' ), array_keys( $bodies ), 'The new release is a different version for the first instance and a major one for the second.' );
	}

	/**
	 * A major core release reaches both instances.
	 */
	public function test_a_major_release_reaches_both() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->add_routed( 'everything', array() );
		$this->add_routed( 'major', array( 'core' => 'major' ) );
		$this->set_updates( '99.0.0' );

		$this->assertSame( array( 'everything@example.org', 'major@example.org' ), array_keys( $this->run_check() ) );
	}

	/**
	 * The filter gets the three arguments, can veto for one instance, and its return is cast to bool.
	 */
	public function test_the_filter() {
		$seen = array();
		add_filter(
			'sc_wpun_channel_should_receive_item',
			static function ( $receive, $item, $instance ) use ( &$seen ) {
				$seen[] = array( $receive, $item->get_type(), $instance->get_id() );
				return 'vetoed' === $instance->get_id() ? 0 : 'yes';
			},
			10,
			3
		);
		$core = $this->item( 'core' );

		$this->assertFalse( Routing::should_receive( $core, new Channel_Instance( 'vetoed', 'email', 'V', true ) ) );
		$this->assertTrue( Routing::should_receive( $core, new Channel_Instance( 'other', 'email', 'O', true ) ) );
		$this->assertSame( array( array( true, 'core', 'vetoed' ), array( true, 'core', 'other' ) ), $seen );
	}

	/**
	 * Add-on item types pass when no types are listed, and are blocked when the list does not have them.
	 */
	public function test_add_on_item_types() {
		$addon = $this->item( 'addon' );

		$this->assertTrue( Routing::should_receive( $addon, new Channel_Instance( 'a', 'email', 'A', true ) ) );
		$this->assertFalse( Routing::should_receive( $addon, new Channel_Instance( 'a', 'email', 'A', true, array(), array( 'types' => array( 'core' ) ) ) ) );
		$this->assertTrue( Routing::should_receive( $addon, new Channel_Instance( 'a', 'email', 'A', true, array(), array( 'types' => array( 'addon' ) ) ) ) );
	}

	/**
	 * The dispatcher routes, skips an instance left with nothing, and never skips a test notification.
	 */
	public function test_the_dispatcher_routes() {
		$registry   = new Channel_Registry();
		$channel    = new Test_Channel( 'test' );
		$dispatcher = new Dispatcher( $registry );
		$registry->register( $channel );
		$instances = array(
			new Channel_Instance( 'cores', 'test', 'C', true, array(), array( 'types' => array( 'core' ) ) ),
			new Channel_Instance( 'plugins', 'test', 'P', true, array(), array( 'types' => array( 'plugin' ) ) ),
		);
		$mixed     = new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $this->item( 'core' ) ) );

		$results = $dispatcher->dispatch( $mixed, $instances );

		$this->assertSame( array( 'cores' ), array_keys( $results ) );
		$this->assertSame( array( 'cores' ), array_keys( $channel->received ) );
		$this->assertCount( 1, $channel->received['cores'][0]->get_items() );

		$channel->received = array();
		$test              = new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array(), array( 'is_test' => true ) );
		$this->assertSame( array( 'cores', 'plugins' ), array_keys( $dispatcher->dispatch( $test, $instances ) ) );

		$channel->received = array();
		$dispatcher->send_one( $mixed, $instances[1] );
		$this->assertCount( 1, $channel->received['plugins'][0]->get_items(), 'send_one does not route.' );
	}

	/**
	 * Widening the routing later sends the pending items of the newly allowed type, since nothing was recorded for them.
	 */
	public function test_widening_routing_sends_what_was_held_back() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->add_routed( 'one', array( 'types' => array( 'core' ) ) );
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		$this->run_check();
		$this->assertSame( array( 'core' ), array_keys( $this->channel_notified( 'one' ) ) );

		$this->add_routed( 'one', array() );
		$bodies = $this->run_check();

		$this->assertStringContainsString( 'Hello Dolly', $bodies['one@example.org'] );
		$this->assertStringNotContainsString( '99.0.0', $bodies['one@example.org'] );
	}

	/**
	 * Skipping pending records only the routed items.
	 */
	public function test_skip_pending_records_only_routed_items() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->add_routed( 'one', array( 'types' => array( 'core' ) ) );
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		sc_wpun()->state()->skip_pending( 'one' );

		$this->assertSame( array(), $this->run_check() );
		$this->assertSame( array( 'core' => array( 'core' => '99.0.0' ) ), $this->channel_notified( 'one' ) );
	}

	/**
	 * An instance routed nothing does not stop the ones after it.
	 */
	public function test_an_instance_routed_nothing_does_not_stop_the_next() {
		$registry   = new Channel_Registry();
		$channel    = new Test_Channel( 'test' );
		$dispatcher = new Dispatcher( $registry );
		$registry->register( $channel );
		$instances = array(
			new Channel_Instance( 'plugins', 'test', 'P', true, array(), array( 'types' => array( 'plugin' ) ) ),
			new Channel_Instance( 'cores', 'test', 'C', true, array(), array( 'types' => array( 'core' ) ) ),
		);
		$mixed     = new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $this->item( 'core' ) ) );

		$results = $dispatcher->dispatch( $mixed, $instances );

		$this->assertSame( array( 'cores' ), array_keys( $results ) );
	}

	/**
	 * An instance receives the items routed to it as a list, and a test notification keeps all of its items.
	 */
	public function test_routed_items_are_a_list_and_a_test_notification_is_not_narrowed() {
		$registry   = new Channel_Registry();
		$channel    = new Test_Channel( 'test' );
		$dispatcher = new Dispatcher( $registry );
		$registry->register( $channel );
		$cores = new Channel_Instance( 'cores', 'test', 'C', true, array(), array( 'types' => array( 'core' ) ) );
		$items = array( $this->item( 'plugin' ), $this->item( 'core' ) );

		$dispatcher->dispatch( new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', $items ), array( $cores ) );
		$this->assertSame( array( 0 ), array_keys( $channel->received['cores'][0]->get_items() ) );

		$channel->received = array();
		$dispatcher->dispatch( new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', $items, array( 'is_test' => true ) ), array( $cores ) );
		$this->assertCount( 2, $channel->received['cores'][0]->get_items() );
	}

	/**
	 * Only a channel that returns the wrong type is reported as such; another exception that mentions it is an exception.
	 */
	public function test_an_exception_that_mentions_the_result_type_is_still_an_exception() {
		$registry   = new Channel_Registry();
		$dispatcher = new Dispatcher( $registry );
		$registry->register(
			new class() extends Test_Channel {
				/**
				 * Throw an exception whose message looks like the type error.
				 *
				 * @param Notification     $notification Notification.
				 * @param Channel_Instance $instance     Instance.
				 * @return \WPUN\Delivery_Result
				 * @throws RuntimeException For the instance a.
				 */
				public function send( Notification $notification, Channel_Instance $instance ): \WPUN\Delivery_Result {
					if ( 'a' === $instance->get_id() ) {
						throw new RuntimeException( 'Return value of Delivery_Result' );
					}

					return \WPUN\Delivery_Result::success();
				}
			}
		);
		$notification = new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $this->item( 'core' ) ) );

		$results = @$dispatcher->dispatch( $notification, array( new Channel_Instance( 'a', 'test', 'A', true ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- WP_DEBUG reports the exception as a notice.

		$this->assertSame( 'exception', $results['a']->get_code() );
	}

	/**
	 * Dumping an instance lists all of its parts.
	 */
	public function test_instance_debug_info_lists_every_part() {
		$instance = new Channel_Instance( 'ops', 'email', 'Ops', true, array( 'to' => 'secret@example.org' ), array( 'scope' => 'active' ) );

		$this->assertSame(
			array(
				'id'       => 'ops',
				'type'     => 'email',
				'label'    => 'Ops',
				'enabled'  => true,
				'routing'  => $instance->get_routing(),
				'settings' => array( 'to' => '[redacted]' ),
			),
			$instance->__debugInfo()
		);
	}

	/**
	 * A channel type is refused when anything else follows it, a line break included.
	 */
	public function test_a_channel_type_with_a_line_break_is_refused() {
		$this->expectException( InvalidArgumentException::class );

		new Channel_Instance( 'ops', "email\n", 'Ops', true );
	}

	/**
	 * With "only active", an item that does not say whether it is active is not sent.
	 */
	public function test_an_item_without_an_active_flag_is_not_active() {
		$instance = new Channel_Instance( 'a', 'email', 'A', true, array(), array( 'scope' => 'active' ) );

		$this->assertFalse( Routing::should_receive( $this->item( 'plugin' ), $instance ) );
		$this->assertFalse( Routing::should_receive( $this->item( 'theme', array( 'active' => 'yes' ) ), $instance ) );
		$this->assertTrue( Routing::should_receive( $this->item( 'plugin', array( 'active' => true ) ), $instance ) );
	}

	/**
	 * An instance id is refused when anything else follows it, a line break included.
	 */
	public function test_an_instance_id_with_a_line_break_is_refused() {
		$this->expectException( InvalidArgumentException::class );

		new Channel_Instance( "ops\n", 'email', 'Ops', true );
	}

	/**
	 * The add-on filter may answer with anything: it counts as a yes or a no.
	 */
	public function test_the_filter_answer_counts_as_yes_or_no() {
		$instance = new Channel_Instance( 'a', 'email', 'A', true );
		add_filter(
			'sc_wpun_channel_should_receive_item',
			static function () {
				return array( 'maybe' );
			}
		);

		$this->assertTrue( Routing::should_receive( $this->item( 'core' ), $instance ) );
	}
}
