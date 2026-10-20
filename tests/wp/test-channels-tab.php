<?php
/**
 * The Channels tab: the list and the row actions (#165).
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties are camelCase.

use WPUN\Admin\Channels_Tab;
use WPUN\Channel_Instance;

/**
 * Rows, columns, unavailable and code-defined instances, and every action.
 */
class Test_Channels_Tab extends WPUN_Test_Case {

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * An editor.
	 *
	 * @var int
	 */
	private static $editor_id;

	/**
	 * Create the users.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * An administrator and the default email instance.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->config()->flush();
		$this->save_settings();
		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Remove what tests registered.
	 */
	public function tear_down() {
		foreach ( array( 'slack', 'test', 'test_secret' ) as $type ) {
			sc_wpun()->channels()->unregister( $type );
		}
		sc_wpun()->config()->flush();
		unset( $_POST['id'], $_POST['_wpnonce'], $_GET['tab'], $_GET['action'], $_GET['id'] );
		parent::tear_down();
	}

	/**
	 * Make the request of a row action form.
	 *
	 * @param string      $action   Action.
	 * @param string      $id       Instance id.
	 * @param string|null $nonce_id Instance the nonce is for; the same by default.
	 */
	private function post( $action, $id, $nonce_id = null ) {
		$_POST['id']       = $id;
		$_POST['_wpnonce'] = wp_create_nonce( $action . '_' . ( $nonce_id ?? $id ) );
	}

	/**
	 * The tab, parsed.
	 *
	 * @return DOMXPath
	 */
	private function tab(): DOMXPath {
		$_GET['tab'] = 'channels';
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$html = (string) ob_get_clean();

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return new DOMXPath( $doc );
	}

	/**
	 * The heading levels and texts of a page, in document order.
	 *
	 * @param DOMXPath $xpath Page.
	 * @return array<int, array{int, string}>
	 */
	private function headings( DOMXPath $xpath ): array {
		$found = array();
		foreach ( $xpath->query( '//h1 | //h2 | //h3 | //h4 | //h5 | //h6' ) as $node ) {
			$found[] = array( (int) substr( $node->nodeName, 1 ), trim( preg_replace( '/\s+/', ' ', $node->textContent ) ) );
		}

		return $found;
	}

	/**
	 * Assert the outline starts at h1 and never goes down more than one level at a time.
	 *
	 * @param DOMXPath $xpath Page.
	 */
	private function assert_no_skipped_level( DOMXPath $xpath ): void {
		$previous = 0;
		foreach ( $this->headings( $xpath ) as $heading ) {
			$this->assertLessThanOrEqual( $previous + 1, $heading[0], 'Heading "' . $heading[1] . '" skips a level.' );
			$previous = $heading[0];
		}
	}

	/**
	 * The text of a cell of a row.
	 *
	 * @param DOMXPath $xpath  Page.
	 * @param string   $id     Instance id.
	 * @param string   $column Cell class suffix.
	 * @return string
	 */
	private function cell( DOMXPath $xpath, $id, $column ) {
		$node = $xpath->query( '//tr[@data-id="' . $id . '"]/*[contains(@class,"sc-wpun-channel-' . $column . '")]' );

		return 1 === $node->length ? trim( preg_replace( '/\s+/', ' ', $node->item( 0 )->textContent ) ) : '';
	}

	/**
	 * Whether a row has a control.
	 *
	 * @param DOMXPath $xpath Page.
	 * @param string   $id    Instance id.
	 * @param string   $value Value of the action field, or `delete` for the delete link.
	 * @return bool
	 */
	private function has_action( DOMXPath $xpath, $id, $value ): bool {
		if ( 'delete' === $value ) {
			return $xpath->query( '//tr[@data-id="' . $id . '"]//a[contains(@class,"sc-wpun-delete")]' )->length > 0;
		}

		return $xpath->query( '//tr[@data-id="' . $id . '"]//form[input[@name="action"][@value="' . $value . '"]]' )->length > 0;
	}

	/**
	 * The notice left for the current user.
	 *
	 * @return array|null
	 */
	private function notice() {
		return sc_wpun()->notices()->take();
	}

	/**
	 * Store an instance of the 1.x Slack channel, whose module 2.0.0 does not ship.
	 */
	private function store_slack() {
		sc_wpun()->channels()->register( new Test_Channel( 'slack' ) );
		sc_wpun_channels()->save( new Channel_Instance( 'slack', 'slack', 'Slack', true, array( 'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX' ) ) );
		sc_wpun()->channels()->unregister( 'slack' );
	}

	/**
	 * Define an instance in code.
	 */
	private function define_in_code() {
		add_filter(
			'sc_wpun_channels',
			static function ( $channels ) {
				$channels[] = array(
					'id'       => 'ops_code',
					'type'     => 'email',
					'label'    => 'Ops',
					'enabled'  => true,
					'settings' => array( 'to' => 'code@example.org' ),
				);
				return $channels;
			}
		);
		sc_wpun()->config()->flush();
	}

	/**
	 * Everything the actions could change.
	 *
	 * @return array
	 */
	private function snapshot(): array {
		return array(
			get_option( 'sc_wpun_channels' ),
			get_option( 'sc_wpun_state' ),
			$this->sent_mail_count(),
		);
	}

	/**
	 * The process method of every action, by action name.
	 *
	 * @return array<string, string[]> Action => [ process method, handle method ].
	 */
	private function actions(): array {
		return array(
			Channels_Tab::TEST_ACTION      => array( 'process_test_send', 'handle_test_send' ),
			Channels_Tab::TOGGLE_ACTION    => array( 'process_toggle', 'handle_toggle' ),
			Channels_Tab::DUPLICATE_ACTION => array( 'process_duplicate', 'handle_duplicate' ),
			Channels_Tab::DELETE_ACTION    => array( 'process_delete', 'handle_delete' ),
		);
	}

	/**
	 * A row has the five columns and the row actions.
	 */
	public function test_the_list_has_a_row_with_the_columns() {
		$xpath = $this->tab();

		$this->assertSame( 1, $xpath->query( '//table[contains(@class,"sc-wpun-channels")]' )->length );
		$this->assertSame( array( 'Label', 'Module', 'Enabled', 'Routing', 'Last delivery' ), array_map( static fn( $th ) => trim( $th->textContent ), iterator_to_array( $xpath->query( '//thead//th' ) ) ) );
		$this->assertSame( 1, $xpath->query( '//tr[@data-id]' )->length );
		$this->assertStringStartsWith( 'Email', $this->cell( $xpath, 'email', 'label' ) );
		$this->assertSame( 'Email', $this->cell( $xpath, 'email', 'module' ) );
		$this->assertStringStartsWith( 'On', $this->cell( $xpath, 'email', 'enabled' ) );
		$this->assertSame( 'All updates', $this->cell( $xpath, 'email', 'routing' ) );
		$this->assertSame( 'Never', $this->cell( $xpath, 'email', 'last' ) );
		foreach ( array( Channels_Tab::TEST_ACTION, Channels_Tab::TOGGLE_ACTION, Channels_Tab::DUPLICATE_ACTION, 'delete' ) as $action ) {
			$this->assertTrue( $this->has_action( $xpath, 'email', $action ), $action );
		}
		$this->assertSame( 0, $xpath->query( '//*[contains(@class,"sc-wpun-code-defined")]' )->length );
	}

	/**
	 * Instances are listed in storage order, and a switched-off one says Off with an Enable button.
	 */
	public function test_several_rows_and_an_off_row() {
		sc_wpun_channels()->save( new Channel_Instance( 'second', 'email', 'Second <b>', false, array( 'to' => 'a@example.org' ) ) );
		$xpath = $this->tab();

		$ids = array();
		foreach ( $xpath->query( '//tr[@data-id]' ) as $row ) {
			$ids[] = $row->getAttribute( 'data-id' );
		}
		$this->assertSame( array( 'email', 'second' ), $ids );
		$this->assertStringStartsWith( 'Off', $this->cell( $xpath, 'second', 'enabled' ) );
		$this->assertStringContainsString( 'Enable', $this->cell( $xpath, 'second', 'label' ) );
		$this->assertStringContainsString( 'Disable', $this->cell( $xpath, 'email', 'label' ) );
		$this->assertSame( 'Off', $this->cell( $xpath, 'second', 'enabled' ), 'The column shows the state only.' );
		$this->assertStringStartsWith( 'Second <b>', $this->cell( $xpath, 'second', 'label' ), 'Escaped.' );
		$this->assertSame( 0, $xpath->query( '//b' )->length );
	}

	/**
	 * The routing summary is built from labels, never raw keys.
	 */
	public function test_the_routing_summary() {
		$this->assertSame( 'All updates', Channels_Tab::routing_summary( array() ) );
		$this->assertSame( 'All updates', Channels_Tab::routing_summary( array( 'core' => 'bogus' ) ) );
		$this->assertSame( 'WordPress updates: Only major releases', Channels_Tab::routing_summary( array( 'core' => 'major' ) ) );
		$this->assertSame( 'Plugins and themes: Only active ones', Channels_Tab::routing_summary( array( 'scope' => 'active' ) ) );
		$this->assertSame(
			'WordPress, Plugins · WordPress updates: Only major releases · Plugins and themes: Only active ones',
			Channels_Tab::routing_summary(
				array(
					'types' => array( 'core', 'plugin' ),
					'core'  => 'major',
					'scope' => 'active',
				)
			)
		);
		$this->assertSame(
			'Themes',
			Channels_Tab::routing_summary(
				array(
					'types' => array( 'theme' ),
					'core'  => 'major',
				)
			),
			'Core is not routed, so its rule is not mentioned.'
		);
		$this->assertSame( 'Plugins', Channels_Tab::routing_summary( array( 'types' => array( 'unknown_type', 'plugin' ) ) ) );
		$this->assertSame( 'No known item types', Channels_Tab::routing_summary( array( 'types' => array( 'unknown_type' ) ) ) );
	}

	/**
	 * The routing summary shows in the row.
	 */
	public function test_the_routing_is_shown_in_the_row() {
		sc_wpun_channels()->save( ( new Channel_Instance( 'email', 'email', 'Email', true, array( 'to' => 'a@example.org' ) ) )->with_routing( array( 'core' => 'major' ) ) );

		$this->assertSame( 'WordPress updates: Only major releases', $this->cell( $this->tab(), 'email', 'routing' ) );
	}

	/**
	 * The last delivery shows with its time, or the failure and why, or Never.
	 */
	public function test_the_last_delivery() {
		$time = (int) gmmktime( 10, 30, 0, 3, 4, 2026 );
		$this->save_channel_state( 'email', array() );
		sc_wpun_channels()->save( new Channel_Instance( 'bad', 'email', 'Bad', true, array( 'to' => 'a@example.org' ) ) );
		$state                                     = sc_wpun()->state()->get();
		$state['channels']['email']['last_result'] = array(
			'success' => true,
			'message' => '',
			'time'    => $time,
		);
		$state['channels']['bad']                  = array(
			'notified'     => array(),
			'skip_pending' => false,
			'last_result'  => array(
				'success' => false,
				'message' => 'WordPress could not <send> it.',
				'time'    => $time,
			),
		);
		update_option( 'sc_wpun_state', $state, false );
		$xpath = $this->tab();

		$expected = \WPUN\Admin\Settings_Page::format_site_time( $time );
		$this->assertSame( 'Sent ' . $expected, $this->cell( $xpath, 'email', 'last' ) );
		$this->assertSame( 'Failed ' . $expected . ': WordPress could not <send> it.', $this->cell( $xpath, 'bad', 'last' ) );
	}

	/**
	 * A real delivery from the dispatcher is shown in the list.
	 */
	public function test_a_real_delivery_is_shown() {
		$this->set_updates( '99.0.0' );
		sc_wpun()->update_checker()->do_update_check();

		$this->assertStringStartsWith( 'Sent ', $this->cell( $this->tab(), 'email', 'last' ) );
	}

	/**
	 * The list has an h2 under the h1, with no skipped level.
	 */
	public function test_the_list_has_a_heading() {
		$xpath = $this->tab();

		$this->assertSame( array( array( 1, 'Updates Notifier' ), array( 2, 'Channels' ) ), $this->headings( $xpath ) );
		$this->assert_no_skipped_level( $xpath );
	}

	/**
	 * With no channel the tab explains it and offers no table.
	 */
	public function test_an_empty_list() {
		delete_option( 'sc_wpun_channels' );
		$xpath = $this->tab();

		$this->assertSame( 0, $xpath->query( '//table' )->length );
		$this->assertSame( 1, $xpath->query( '//p[contains(@class,"sc-wpun-no-channels")]' )->length );
		$this->assertSame( array( 1, 2 ), array_column( $this->headings( $xpath ), 0 ) );
	}

	/**
	 * An instance whose module is not registered is not listed, and every action treats it as missing.
	 */
	public function test_an_unavailable_instance_is_hidden_and_untouched() {
		$this->store_slack();
		$this->assertTrue( sc_wpun_channels()->has( 'slack' ) );
		$stored = get_option( 'sc_wpun_channels' );
		$xpath  = $this->tab();

		$this->assertSame( 0, $xpath->query( '//tr[@data-id="slack"]' )->length );
		$this->assertSame( 1, $xpath->query( '//tr[@data-id]' )->length );

		foreach ( $this->actions() as $action => $methods ) {
			$this->post( $action, 'slack' );
			reset_phpmailer_instance();
			$this->assertTrue( sc_wpun()->channels_tab()->{$methods[0]}(), $action );
			$notice = $this->notice();
			$this->assertFalse( $notice['success'], $action );
			$this->assertSame( 'There is no such channel.', $notice['message'], $action );
			$this->assertSame( $stored, get_option( 'sc_wpun_channels' ), $action );
			$this->assertSame( 0, $this->sent_mail_count(), $action );
		}

		$_GET['action'] = 'delete';
		$_GET['id']     = 'slack';
		$xpath          = $this->tab();
		$this->assertSame( 0, $xpath->query( '//*[contains(@class,"sc-wpun-confirm-delete")]' )->length );
		$this->assertStringContainsString( 'There is no such channel.', $xpath->document->textContent );
		$this->assertSame( 1, $xpath->query( '//table//tr[@data-id="email"]' )->length, 'The list is shown instead.' );
	}

	/**
	 * A code-defined instance is listed with a marker and no toggle, duplicate or delete; its handlers refuse; test send works.
	 */
	public function test_a_code_defined_instance() {
		$this->define_in_code();
		$xpath = $this->tab();

		$this->assertSame( 1, $xpath->query( '//tr[@data-id="ops_code"]//*[contains(@class,"sc-wpun-code-defined")]' )->length );
		$this->assertStringContainsString( 'Defined in code', $this->cell( $xpath, 'ops_code', 'label' ) );
		$this->assertTrue( $this->has_action( $xpath, 'ops_code', Channels_Tab::TEST_ACTION ) );
		foreach ( array( Channels_Tab::TOGGLE_ACTION, Channels_Tab::DUPLICATE_ACTION, 'delete' ) as $action ) {
			$this->assertFalse( $this->has_action( $xpath, 'ops_code', $action ), $action );
		}
		$this->assertSame( 1, $xpath->query( '//tr[@data-id="ops_code"]//form//button' )->length, 'Only the test button.' );
		$this->assertStringNotContainsString( '|', $this->cell( $xpath, 'ops_code', 'label' ), 'A single action has no separator.' );

		$before = $this->snapshot();
		foreach ( array( Channels_Tab::TOGGLE_ACTION, Channels_Tab::DUPLICATE_ACTION, Channels_Tab::DELETE_ACTION ) as $action ) {
			$this->post( $action, 'ops_code' );
			$this->assertTrue( sc_wpun()->channels_tab()->{$this->actions()[ $action ][0]}(), $action );
			$notice = $this->notice();
			$this->assertFalse( $notice['success'], $action );
			$this->assertStringContainsString( 'defined in code', $notice['message'], $action );
		}
		$this->assertSame( $before, $this->snapshot() );
		$this->assertTrue( sc_wpun_channels()->is_code_defined( 'ops_code' ) );

		$_GET['action'] = 'delete';
		$_GET['id']     = 'ops_code';
		$this->assertSame( 0, $this->tab()->query( '//*[contains(@class,"sc-wpun-confirm-delete")]' )->length );

		$this->post( Channels_Tab::TEST_ACTION, 'ops_code' );
		$this->assertTrue( sc_wpun()->channels_tab()->process_test_send() );
		$this->assertTrue( $this->notice()['success'] );
		$this->assertSame( 'code@example.org', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
	}

	/**
	 * An externally supplied enabled value has no toggle, shows its source, and the handler refuses.
	 */
	public function test_an_external_enabled_is_read_only() {
		$source = new Test_Config_Source( array( 'SC_WPUN_CHANNEL_EMAIL__ENABLED' => 'true' ) );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $source ) {
				$sources[] = $source;
				return $sources;
			}
		);
		sc_wpun()->config()->flush();
		$xpath = $this->tab();

		$this->assertFalse( $this->has_action( $xpath, 'email', Channels_Tab::TOGGLE_ACTION ) );
		$this->assertSame( 0, $xpath->query( '//tr[@data-id="email"]//button[@disabled]' )->length );
		$this->assertSame( 1, $xpath->query( '//tr[@data-id="email"]//*[contains(@class,"sc-wpun-state")]' )->length );
		$this->assertStringContainsString( 'Set by', $this->cell( $xpath, 'email', 'enabled' ) );
		$this->assertStringContainsString( 'Set by Test vault', $this->cell( $xpath, 'email', 'enabled' ) );

		$before = $this->snapshot();
		$this->post( Channels_Tab::TOGGLE_ACTION, 'email' );
		$this->assertTrue( sc_wpun()->channels_tab()->process_toggle() );
		$this->assertFalse( $this->notice()['success'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	/**
	 * A bad, missing or other-instance nonce and a user who may not manage the plugin change nothing, and the handlers answer 403.
	 */
	public function test_nonce_and_capability_are_enforced_for_every_action() {
		sc_wpun_channels()->save( new Channel_Instance( 'other', 'email', 'Other', true, array( 'to' => 'o@example.org' ) ) );
		$page = sc_wpun()->channels_tab();

		foreach ( $this->actions() as $action => $methods ) {
			$before = $this->snapshot();
			$cases  = array();

			$this->post( $action, 'email' );
			$_POST['_wpnonce']  = 'bad';
			$cases['bad nonce'] = $_POST;

			$this->post( $action, 'email' );
			unset( $_POST['_wpnonce'] );
			$cases['missing nonce'] = $_POST;

			$this->post( $action, 'email', 'other' );
			$cases['nonce of another instance'] = $_POST;

			$this->post( 'sc_wpun_something_else', 'email' );
			$cases['nonce of another action'] = $_POST;

			$this->post( $action, 'email' );
			unset( $_POST['id'] );
			$cases['missing id'] = $_POST;

			foreach ( $cases as $name => $post ) {
				$_POST = $post;
				$this->assertFalse( $page->{$methods[0]}(), "$action: $name" );
				$this->assertSame( $before, $this->snapshot(), "$action: $name" );
				try {
					$page->{$methods[1]}();
					$this->fail( "$action: $name: not refused" );
				} catch ( WPDieException $e ) {
					$this->assertInstanceOf( WPDieException::class, $e );
				}
			}

			wp_set_current_user( self::$editor_id );
			$this->post( $action, 'email' );
			$this->assertFalse( $page->{$methods[0]}(), "$action: editor" );
			$this->assertSame( $before, $this->snapshot(), "$action: editor" );
			wp_set_current_user( self::$admin_id );
		}
		$this->assertNull( $this->notice() );
	}

	/**
	 * Switching an instance off and on again.
	 */
	public function test_toggle() {
		$this->post( Channels_Tab::TOGGLE_ACTION, 'email' );

		$this->assertTrue( sc_wpun()->channels_tab()->process_toggle() );
		$this->assertFalse( $this->stored_instance( 'email' )->is_enabled() );
		$notice = $this->notice();
		$this->assertTrue( $notice['success'] );
		$this->assertSame( 'Email disabled.', $notice['message'] );

		$this->post( Channels_Tab::TOGGLE_ACTION, 'email' );
		$this->assertTrue( sc_wpun()->channels_tab()->process_toggle() );
		$this->assertTrue( $this->stored_instance( 'email' )->is_enabled() );
		$this->assertSame( 'Email enabled.', $this->notice()['message'] );
		$this->assertSame( 'ops@example.org', $this->instance_setting( 'email', 'to' ), 'Settings are kept.' );
	}

	/**
	 * A copy is switched off, labelled, has the settings and routing, and takes the next free id.
	 */
	public function test_duplicate() {
		sc_wpun_channels()->save( ( new Channel_Instance( 'email', 'email', 'Email', true, array( 'to' => 'a@example.org' ) ) )->with_routing( array( 'core' => 'major' ) ) );

		$this->post( Channels_Tab::DUPLICATE_ACTION, 'email' );
		$this->assertTrue( sc_wpun()->channels_tab()->process_duplicate() );
		$copy = $this->stored_instance( 'email_copy' );
		$this->assertFalse( $copy->is_enabled() );
		$this->assertSame( 'Email (copy)', $copy->get_label() );
		$this->assertSame( 'email', $copy->get_type() );
		$this->assertSame( 'a@example.org', $copy->get_setting( 'to' ) );
		$this->assertSame( 'major', $copy->get_routing()['core'] );
		$this->assertTrue( $this->stored_instance( 'email' )->is_enabled(), 'The source is untouched.' );
		$notice = $this->notice();
		$this->assertTrue( $notice['success'] );
		$this->assertSame( 'Channel duplicated as Email (copy).', $notice['message'] );

		$this->post( Channels_Tab::DUPLICATE_ACTION, 'email' );
		sc_wpun()->channels_tab()->process_duplicate();
		$this->post( Channels_Tab::DUPLICATE_ACTION, 'email' );
		sc_wpun()->channels_tab()->process_duplicate();
		$this->assertSame( array( 'email', 'email_copy', 'email_copy_2', 'email_copy_3' ), array_keys( sc_wpun_channels()->stored_all() ) );

		$this->post( Channels_Tab::DUPLICATE_ACTION, 'email_copy' );
		sc_wpun()->channels_tab()->process_duplicate();
		$this->assertTrue( sc_wpun_channels()->has( 'email_copy_copy' ) );
	}

	/**
	 * A copy is taken from the stored instance: values from wp-config or the environment are not written to the database.
	 */
	public function test_duplicate_does_not_store_external_secrets() {
		sc_wpun()->channels()->register(
			new Test_Fields_Channel(
				'test_secret',
				array(
					'token' => array(
						'type'   => 'secret',
						'secret' => true,
					),
				)
			)
		);
		sc_wpun_channels()->save( new Channel_Instance( 'vault', 'test_secret', 'Vault', true, array( 'token' => 'ok-stored' ) ) );
		$source = new Test_Config_Source( array( 'SC_WPUN_CHANNEL_VAULT__TOKEN' => 'ok-external-secret' ) );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $source ) {
				$sources[] = $source;
				return $sources;
			}
		);
		sc_wpun()->config()->flush();
		$this->assertSame( 'ok-external-secret', sc_wpun_channels()->get( 'vault' )->get_setting( 'token' ) );

		$this->post( Channels_Tab::DUPLICATE_ACTION, 'vault' );
		$this->assertTrue( sc_wpun()->channels_tab()->process_duplicate() );

		$this->assertSame( 'ok-stored', get_option( 'sc_wpun_channels' )['vault_copy']['settings']['token'] );
		$this->assertStringNotContainsString( 'ok-external-secret', wp_json_encode( get_option( 'sc_wpun_channels' ) ) );
	}

	/**
	 * Deleting asks first, then removes the instance, its state and its last result.
	 */
	public function test_delete() {
		sc_wpun_channels()->save( new Channel_Instance( 'second', 'email', 'Second', true, array( 'to' => 'a@example.org' ) ) );
		$state                       = sc_wpun()->state()->get();
		$state['channels']['second'] = array(
			'notified'     => array( 'core' => array( 'core' => '1' ) ),
			'skip_pending' => false,
			'last_result'  => array(
				'success' => true,
				'message' => '',
				'time'    => time(),
			),
		);
		update_option( 'sc_wpun_state', $state, false );

		$_GET['action'] = 'delete';
		$_GET['id']     = 'second';
		$xpath          = $this->tab();
		$this->assertSame( 1, $xpath->query( '//*[contains(@class,"sc-wpun-confirm-delete")]' )->length );
		$this->assertStringContainsString( 'Delete the channel Second?', $xpath->document->textContent );
		$this->assertSame( array( array( 1, 'Updates Notifier' ), array( 2, 'Delete Second?' ) ), $this->headings( $xpath ) );
		$this->assert_no_skipped_level( $xpath );
		$this->assertSame( 0, $xpath->query( '//table' )->length );
		$this->assertSame( 1, $xpath->query( '//form[input[@name="action"][@value="' . Channels_Tab::DELETE_ACTION . '"]][input[@name="id"][@value="second"]]//button[@type="submit"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[text()="Cancel"]' )->length );
		$this->assertTrue( sc_wpun_channels()->has( 'second' ), 'Viewing deletes nothing.' );

		$this->post( Channels_Tab::DELETE_ACTION, 'second' );
		$this->assertTrue( sc_wpun()->channels_tab()->process_delete() );

		$this->assertFalse( sc_wpun_channels()->has( 'second' ) );
		$this->assertArrayNotHasKey( 'second', $this->stored_state()['channels'] );
		$this->assertTrue( sc_wpun_channels()->has( 'email' ) );
		$notice = $this->notice();
		$this->assertTrue( $notice['success'] );
		$this->assertSame( 'Second deleted.', $notice['message'] );

		unset( $_GET['action'], $_GET['id'] );
		$this->assertSame( 0, $this->tab()->query( '//tr[@data-id="second"]' )->length );
	}

	/**
	 * Test send goes to the chosen instance.
	 */
	public function test_test_send_per_instance() {
		sc_wpun_channels()->save( new Channel_Instance( 'second', 'email', 'Second', true, array( 'to' => 'second@example.org' ) ) );

		$this->post( Channels_Tab::TEST_ACTION, 'second' );
		$this->assertTrue( sc_wpun()->channels_tab()->process_test_send() );

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( 'second@example.org', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
		$this->assertSame( 'Test message sent through Second.', $this->notice()['message'] );
		$this->assertArrayNotHasKey( 'last_result', $this->stored_state()['channels']['second'] ?? array(), 'A test send is not a delivery.' );
	}

	/**
	 * Test send is offered only where the module supports it, and refused otherwise.
	 */
	public function test_test_send_needs_support() {
		sc_wpun()->channels()->register( new Test_Channel( 'test' ) );
		sc_wpun_channels()->save( new Channel_Instance( 'plain', 'test', 'Plain', true ) );
		$xpath = $this->tab();

		$this->assertSame( 1, $xpath->query( '//tr[@data-id="plain"]' )->length );
		$this->assertFalse( $this->has_action( $xpath, 'plain', Channels_Tab::TEST_ACTION ) );
		$this->assertTrue( $this->has_action( $xpath, 'plain', Channels_Tab::TOGGLE_ACTION ) );
		$this->assertSame( 'Test channel', $this->cell( $xpath, 'plain', 'module' ) );

		$this->post( Channels_Tab::TEST_ACTION, 'plain' );
		$this->assertTrue( sc_wpun()->channels_tab()->process_test_send() );
		$notice = $this->notice();
		$this->assertFalse( $notice['success'] );
		$this->assertSame( 'Plain can not send a test message.', $notice['message'] );
		$this->assertSame( array(), sc_wpun()->channels()->get( 'test' )->received );
	}

	/**
	 * Core-style row actions in order, with separators inside every item but the last.
	 */
	public function test_row_actions_order_and_separators() {
		$xpath = $this->tab();

		$actions = $xpath->query( '//tr[@data-id="email"]//div[@class="row-actions"]' );
		$this->assertSame( 1, $actions->length );
		$spans = $xpath->query( './span', $actions->item( 0 ) );
		$this->assertSame( array( 'edit', 'sc-wpun-test', 'sc-wpun-duplicate', 'sc-wpun-toggle', 'trash' ), array_map( static fn( $span ) => $span->getAttribute( 'class' ), iterator_to_array( $spans ) ) );
		$this->assertSame(
			array( 'Edit |', 'Send test |', 'Duplicate |', 'Disable |', 'Delete' ),
			array_map( static fn( $span ) => trim( preg_replace( '/\s+/', ' ', $span->textContent ) ), iterator_to_array( $spans ) )
		);
	}

	/**
	 * The POST buttons look like links and keep their accessible names; Delete is core's destructive link.
	 */
	public function test_row_action_buttons_links_and_names() {
		$xpath = $this->tab();

		$names = array();
		foreach ( $xpath->query( '//tr[@data-id="email"]//form//button' ) as $button ) {
			$this->assertSame( 'button-link', $button->getAttribute( 'class' ) );
			$names[] = $button->getAttribute( 'aria-label' );
		}
		$label = sc_wpun_channels()->get( 'email' )->get_label();
		$this->assertSame( array( 'Send test to ' . $label, 'Duplicate ' . $label, 'Disable ' . $label ), $names );

		$edit = $xpath->query( '//tr[@data-id="email"]//a[contains(@class,"sc-wpun-edit")]' );
		$this->assertSame( 'Edit ' . $label, $edit->item( 0 )->getAttribute( 'aria-label' ) );
		$delete = $xpath->query( '//tr[@data-id="email"]//span[@class="trash"]/a[contains(@class,"submitdelete")][contains(@class,"sc-wpun-delete")]' );
		$this->assertSame( 1, $delete->length );
		$this->assertSame( 'Delete ' . $label, $delete->item( 0 )->getAttribute( 'aria-label' ) );
	}

	/**
	 * Styling lives in the stylesheet, not in style attributes.
	 */
	public function test_no_inline_styles() {
		$this->assertSame( 0, $this->tab()->query( '//*[@style]' )->length );
	}

	/**
	 * A code-defined instance whose module can not test has no row actions at all.
	 */
	public function test_code_defined_without_actions_has_no_row_actions() {
		sc_wpun()->channels()->register( new Test_Channel( 'test' ) );
		add_filter(
			'sc_wpun_channels',
			static function ( $channels ) {
				$channels[] = array(
					'id'    => 'plain_code',
					'type'  => 'test',
					'label' => 'Plain code',
				);
				return $channels;
			}
		);
		sc_wpun()->config()->flush();
		$xpath = $this->tab();

		$this->assertSame( 1, $xpath->query( '//tr[@data-id="plain_code"]' )->length );
		$this->assertSame( 0, $xpath->query( '//tr[@data-id="plain_code"]//div[contains(concat(" ",@class," ")," row-actions ")]' )->length );
	}

	/**
	 * A code-defined instance that can test has one action and no separator.
	 */
	public function test_code_defined_with_one_action() {
		$this->define_in_code();
		$xpath = $this->tab();

		$spans = $xpath->query( '//tr[@data-id="ops_code"]//div[contains(concat(" ",@class," ")," row-actions ")]/span' );
		$this->assertSame( 1, $spans->length );
		$this->assertSame( 'Send test', trim( $spans->item( 0 )->textContent ) );
	}

	/**
	 * The responsive hooks of the core list table: primary column, column names, toggle button.
	 */
	public function test_responsive_markup() {
		$xpath = $this->tab();

		$this->assertSame( 1, $xpath->query( '//table[contains(@class,"wp-list-table")][contains(@class,"table-view-list")]' )->length );
		$this->assertSame( 1, $xpath->query( '//thead//th[contains(@class,"column-primary")]' )->length );
		$this->assertSame( 1, $xpath->query( '//tr[@data-id="email"]/td[contains(@class,"column-primary")][@data-colname="Label"]//button[@type="button"][@class="toggle-row"]' )->length );
		$names = array();
		foreach ( $xpath->query( '//tr[@data-id="email"]/td' ) as $td ) {
			$names[] = $td->getAttribute( 'data-colname' );
		}
		$this->assertSame( array( 'Label', 'Module', 'Enabled', 'Routing', 'Last delivery' ), $names );
	}

	/**
	 * The label cell is not a row header, so its actions are not read as the header of the other cells, and the actions still name the channel.
	 */
	public function test_the_label_cell_is_not_a_row_header() {
		$xpath = $this->tab();

		$this->assertSame( 0, $xpath->query( '//tbody//th' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@scope="row"]' )->length );
		$cell = $xpath->query( '//tr[@data-id="email"]/td[contains(@class,"sc-wpun-channel-label")][contains(@class,"column-primary")][contains(@class,"has-row-actions")]' );
		$this->assertSame( 1, $cell->length );
		$this->assertSame( 1, $xpath->query( './strong[.="Email"]', $cell->item( 0 ) )->length );
		$this->assertSame( 1, $xpath->query( './/*[@aria-label="Edit Email"]', $cell->item( 0 ) )->length );
	}

	/**
	 * The stylesheet loads on the settings screen only.
	 */
	public function test_stylesheet_is_enqueued_on_the_settings_screen_only() {
		wp_dequeue_style( 'sc-wpun-admin' );
		$this->assertFalse( wp_style_is( 'sc-wpun-admin' ) );

		sc_wpun()->settings_page()->admin_settings_menu();
		$hook = get_plugin_page_hookname( 'wp-updates-notifier', 'options-general.php' );
		$this->assertNotEmpty( $hook );
		$this->assertFalse( wp_style_is( 'sc-wpun-admin' ), 'Not before the screen loads.' );

		do_action( 'load-index.php' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertFalse( wp_style_is( 'sc-wpun-admin' ), 'Not on other screens.' );

		do_action( 'load-' . $hook ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertTrue( wp_style_is( 'sc-wpun-admin' ) );
		$style = wp_styles()->registered['sc-wpun-admin'];
		$this->assertStringEndsWith( 'assets/css/admin.css', $style->src );
		$this->assertSame( SC_WPUN_VERSION, $style->ver );
		$this->assertFileExists( plugin_dir_path( SC_WPUN_FILE ) . 'assets/css/admin.css' );
	}

	/**
	 * The version constant is the one in the plugin header.
	 */
	public function test_version_constant_matches_the_header() {
		$this->assertSame( get_plugin_data( SC_WPUN_FILE, false, false )['Version'], SC_WPUN_VERSION );
	}
}
