<?php
/**
 * Channel fields supplied from outside the database, and channels defined in code (#107).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Config_Context;

/**
 * External channel fields, the store's handling of them, and code-defined channels.
 */
class Test_Config_Channels extends WPUN_Test_Case {

	const SECRET = 'bad-SECRET-VALUE-123';

	/**
	 * Start clean.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->config()->flush();
		$this->save_settings();
	}

	/**
	 * Remove what tests registered.
	 */
	public function tear_down() {
		foreach ( array( 'test_secret', 'odd' ) as $type ) {
			sc_wpun()->channels()->unregister( $type );
		}
		sc_wpun()->config()->flush();
		parent::tear_down();
	}

	/**
	 * Register a test source.
	 *
	 * @param array $values Values by key.
	 * @return Test_Config_Source
	 */
	private function add_source( array $values ) {
		$source = new Test_Config_Source( $values );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $source ) {
				$sources[] = $source;
				return $sources;
			}
		);
		sc_wpun()->config()->flush();

		return $source;
	}

	/**
	 * Register the secret channel type.
	 *
	 * @return Test_Fields_Channel
	 */
	private function register_secret_channel() {
		$channel = new Test_Fields_Channel(
			'test_secret',
			array(
				'token'   => array(
					'type'   => 'secret',
					'secret' => true,
				),
				'explode' => array( 'type' => 'checkbox' ),
				'label'   => array( 'type' => 'text' ),
			)
		);
		sc_wpun()->channels()->register( $channel );

		return $channel;
	}

	/**
	 * Run the update check with a core update pending.
	 *
	 * @return array Results by instance id.
	 */
	private function run_check() {
		reset_phpmailer_instance();
		$this->set_updates( '99.0.0' );
		sc_wpun()->update_checker()->do_update_check();

		return sc_wpun()->update_checker()->last_results();
	}

	/**
	 * The raw stored option, as text.
	 *
	 * @return string
	 */
	private function raw_option() {
		return wp_json_encode( get_option( 'sc_wpun_channels' ) );
	}

	/**
	 * Instance fields resolve with the filter first, then sources, then the database.
	 */
	public function test_precedence_for_an_instance_field() {
		$store = sc_wpun_channels();
		$this->assertSame( 'notifier@example.org', $store->get( 'email' )->get_setting( 'from' ) );
		$this->assertNull( $store->external_source( 'email', 'from' ) );

		$this->add_source( array( 'SC_WPUN_CHANNEL_EMAIL__FROM' => 'vault@example.org' ) );
		$this->assertSame( 'vault@example.org', $store->get( 'email' )->get_setting( 'from' ) );
		$this->assertSame( 'Test vault', $store->external_source( 'email', 'from' ) );
		$this->assertSame( 'notifier@example.org', $store->stored_all()['email']->get_setting( 'from' ) );

		add_filter(
			'sc_wpun_config_value',
			static function ( $value, $context ) {
				return 'from' === $context->get_field() ? 'filter@example.org' : $value;
			},
			10,
			2
		);
		sc_wpun()->config()->flush();
		$this->assertSame( 'filter@example.org', $store->get( 'email' )->get_setting( 'from' ) );

		remove_all_filters( 'sc_wpun_config_value' );
		add_filter( 'sc_wpun_config_value', '__return_null' );
		sc_wpun()->config()->flush();
		$this->assertSame( 'notifier@example.org', $store->get( 'email' )->get_setting( 'from' ), 'Null falls back to the stored value.' );
	}

	/**
	 * The enabled switch can be forced either way from outside, and an invalid value makes the instance misconfigured.
	 */
	public function test_enabled_from_outside() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$source = $this->add_source( array( 'SC_WPUN_CHANNEL_EMAIL__ENABLED' => 'true' ) );
		$this->assertTrue( sc_wpun_channels()->get( 'email' )->is_enabled() );
		$this->assertFalse( sc_wpun_channels()->stored_all()['email']->is_enabled() );

		$this->save_settings( array( 'email_notifications' => 1 ) );
		$source->values['SC_WPUN_CHANNEL_EMAIL__ENABLED'] = 'off';
		sc_wpun()->config()->flush();
		$this->assertFalse( sc_wpun_channels()->get( 'email' )->is_enabled() );

		$source->values['SC_WPUN_CHANNEL_EMAIL__ENABLED'] = 'maybe';
		sc_wpun()->config()->flush();
		$this->assertSame( array( 'SC_WPUN_CHANNEL_EMAIL__ENABLED' ), sc_wpun_channels()->problems( 'email' ) );
	}

	/**
	 * Saving an instance read from the store never writes the external value, and still saves the rest.
	 */
	public function test_save_never_writes_external_values() {
		$this->add_source(
			array(
				'SC_WPUN_CHANNEL_EMAIL__TO'      => 'vault@example.org',
				'SC_WPUN_CHANNEL_EMAIL__ENABLED' => '0',
			)
		);
		$store    = sc_wpun_channels();
		$instance = $store->get( 'email' );
		$this->assertSame( 'vault@example.org', $instance->get_setting( 'to' ) );

		$this->assertTrue( $store->save( $instance->with_enabled( true )->with_settings( array_merge( $instance->get_settings(), array( 'from' => 'x@example.com' ) ) ) ) );

		$raw = get_option( 'sc_wpun_channels' )['email'];
		$this->assertSame( 'ops@example.org', $raw['settings']['to'] );
		$this->assertSame( 'x@example.com', $raw['settings']['from'] );
		$this->assertTrue( $raw['enabled'], 'The stored switch is kept, not the external one.' );
		$this->assertStringNotContainsString( 'vault@example.org', $this->raw_option() );
	}

	/**
	 * A field supplied from outside that was never stored is left out.
	 */
	public function test_an_external_field_that_was_never_stored_stays_out() {
		sc_wpun_channels()->save( new Channel_Instance( 'fresh', 'email', 'Fresh', true, array( 'from' => 'a@example.org' ) ) );
		sc_wpun_channels()->delete( 'fresh' );
		$this->add_source( array( 'SC_WPUN_CHANNEL_FRESH__TO' => 'vault@example.org' ) );

		sc_wpun_channels()->save( new Channel_Instance( 'fresh', 'email', 'Fresh', true, array( 'to' => 'vault@example.org' ) ) );

		$this->assertArrayNotHasKey( 'to', get_option( 'sc_wpun_channels' )['fresh']['settings'] );
	}

	/**
	 * An invalid external recipient makes the instance misconfigured: nothing is sent, the problem is shown without the value, and the next run retries.
	 */
	public function test_an_invalid_external_value_makes_the_instance_misconfigured() {
		$source = $this->add_source( array( 'SC_WPUN_CHANNEL_EMAIL__TO' => 'not an address ' . self::SECRET ) );

		$results = $this->run_check();

		$this->assertSame( 'misconfigured', $results['email']->get_code() );
		$this->assertStringContainsString( 'SC_WPUN_CHANNEL_EMAIL__TO', $results['email']->get_message() );
		$this->assertStringNotContainsString( self::SECRET, $results['email']->get_message() );
		$this->assertSame( 0, $this->sent_mail_count() );
		$problems = sc_wpun()->config()->problems();
		$this->assertSame( 'SC_WPUN_CHANNEL_EMAIL__TO', $problems[0]['key'] );
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( $problems ) );
		$this->assertSame( '', $this->notified_version( 'core', 'core' ) );

		$source->values = array();
		sc_wpun()->config()->flush();
		$this->run_check();
		$this->assertSame( 1, $this->sent_mail_count(), 'Retried once it is fixed.' );
	}

	/**
	 * A channel defined by the filter delivers, is read-only, overrides a stored instance, and keeps its delivery state.
	 */
	public function test_a_code_defined_channel() {
		add_filter(
			'sc_wpun_channels',
			static function ( $channels ) {
				$channels[] = array(
					'id'       => 'ops_code',
					'type'     => 'email',
					'label'    => 'Ops',
					'settings' => array( 'to' => 'code@example.org' ),
				);
				$channels[] = new Channel_Instance( 'email', 'email', 'Overridden', true, array( 'to' => 'override@example.org' ) );
				return $channels;
			}
		);
		sc_wpun()->config()->flush();
		$store = sc_wpun_channels();

		$this->assertTrue( $store->is_code_defined( 'ops_code' ) );
		$this->assertSame( 'override@example.org', $store->get( 'email' )->get_setting( 'to' ) );
		$this->assertSame( 'ops@example.org', $store->stored_all()['email']->get_setting( 'to' ), 'The stored one is untouched.' );
		$this->assertArrayNotHasKey( 'ops_code', $store->stored_all() );

		$this->run_check();
		$this->assertSame( 2, $this->sent_mail_count() );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'ops_code' ) );
		$this->run_check();
		$this->assertSame( 0, $this->sent_mail_count(), 'State is kept for code-defined instances.' );

		$this->setExpectedIncorrectUsage( 'WPUN\Channel_Store::save' );
		$this->setExpectedIncorrectUsage( 'WPUN\Channel_Store::delete' );
		$this->assertFalse( $store->save( $store->get( 'ops_code' ) ) );
		$this->assertFalse( $store->delete( 'ops_code' ) );
		$this->assertFalse( $store->delete( 'email' ), 'A stored one with the same id stays.' );
		$this->assertArrayHasKey( 'email', get_option( 'sc_wpun_channels' ) );
	}

	/**
	 * Channels from JSON, through the filter that stands in for the constant: valid, bad JSON, and an invalid entry.
	 */
	public function test_code_defined_channels_from_json() {
		add_filter(
			'sc_wpun_channels_json',
			static function () {
				return '[{"id":"json_one","type":"email","label":"One","settings":{"to":"one@example.org"}},{"id":"Bad__id","type":"email"},{"type":"email"}]';
			}
		);
		sc_wpun()->config()->flush();

		$store = sc_wpun_channels();
		$this->assertSame( 'one@example.org', $store->get( 'json_one' )->get_setting( 'to' ) );
		$this->assertTrue( $store->get( 'json_one' )->is_enabled() );
		$this->assertCount( 2, array_filter( sc_wpun()->config()->problems(), static fn( $problem ) => 'SC_WPUN_CHANNELS' === $problem['key'] ) );

		remove_all_filters( 'sc_wpun_channels_json' );
		add_filter( 'sc_wpun_channels_json', static fn() => '{ not json SECRET' );
		sc_wpun()->config()->flush();
		$this->assertFalse( $store->is_code_defined( 'json_one' ) );
		$problems = sc_wpun()->config()->problems();
		$this->assertSame( 'SC_WPUN_CHANNELS', $problems[0]['key'] );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $problems ) );
	}

	/**
	 * A field name that cannot be in a constant name, and the reserved name, are skipped with a notice.
	 */
	public function test_bad_field_names_are_skipped() {
		$this->setExpectedIncorrectUsage( 'WPUN\Channel_Store::external_schemas' );
		sc_wpun()->channels()->register(
			new Test_Fields_Channel(
				'odd',
				array(
					'bad__name' => array( 'type' => 'text' ),
					'enabled'   => array( 'type' => 'text' ),
					'good'      => array( 'type' => 'text' ),
				)
			)
		);
		sc_wpun_channels()->save( new Channel_Instance( 'odd1', 'odd', 'Odd', true ) );
		$this->add_source(
			array(
				'SC_WPUN_CHANNEL_ODD1__BAD__NAME' => 'x',
				'SC_WPUN_CHANNEL_ODD1__GOOD'      => 'yes',
			)
		);

		$instance = sc_wpun_channels()->get( 'odd1' );

		$this->assertSame( 'yes', $instance->get_setting( 'good' ) );
		$this->assertNull( $instance->get_setting( 'bad__name' ) );
		$this->assertNull( sc_wpun_channels()->external_source( 'odd1', 'bad__name' ) );
	}

	/**
	 * An instance whose channel is not registered resolves only the enabled switch.
	 */
	public function test_an_unregistered_type_resolves_only_enabled() {
		sc_wpun_channels()->save( new Channel_Instance( 'slack', 'slack', 'Slack', false, array( 'webhook_url' => 'https://stored.example.org' ) ) );
		$this->add_source(
			array(
				'SC_WPUN_CHANNEL_SLACK__ENABLED'     => '1',
				'SC_WPUN_CHANNEL_SLACK__WEBHOOK_URL' => 'https://hooks.example.org/secret',
			)
		);

		$slack = sc_wpun_channels()->get( 'slack' );

		$this->assertTrue( $slack->is_enabled() );
		$this->assertSame( 'https://stored.example.org', $slack->get_setting( 'webhook_url' ) );
	}

	/**
	 * The test send works with an externally supplied recipient.
	 */
	public function test_a_test_send_uses_the_external_recipient() {
		$this->add_source( array( 'SC_WPUN_CHANNEL_EMAIL__TO' => 'vault@example.org' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['_wpnonce'] = wp_create_nonce( 'sc_wpun_send_test_email' );
		$_POST['id']       = 'email';
		reset_phpmailer_instance();

		$this->assertTrue( sc_wpun()->channels_tab()->process_test_send() );

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( 'vault@example.org', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
		unset( $_POST['_wpnonce'], $_POST['id'] );
	}

	/**
	 * The email section shows external fields read-only, and a code-defined email as not editable.
	 */
	public function test_the_email_section_is_read_only() {
		$this->add_source( array( 'SC_WPUN_CHANNEL_EMAIL__TO' => 'vault@example.org' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		sc_wpun()->settings_page()->admin_settings_init();

		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$page = ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="sc_wpun_settings\[notify_to\]"[^>]*disabled/', $page );
		$this->assertDoesNotMatchRegularExpression( '/name="sc_wpun_settings\[notify_from\]"[^>]*disabled/', $page );
		$this->assertStringContainsString( 'Set by Test vault', $page );

		// Saving the form leaves the external value out of the database.
		sc_wpun()->settings_page()->sc_wpun_settings_validate(
			$this->settings(
				array(
					'notify_to'   => 'typed@example.org',
					'notify_from' => 'new@example.org',
				)
			)
		);
		$raw = get_option( 'sc_wpun_channels' )['email']['settings'];
		$this->assertSame( 'ops@example.org', $raw['to'] );
		$this->assertSame( 'new@example.org', $raw['from'] );

		add_filter(
			'sc_wpun_channels',
			static fn( $channels ) => array_merge( $channels, array( new Channel_Instance( 'email', 'email', 'Code', true, array( 'to' => 'code@example.org' ) ) ) )
		);
		sc_wpun()->config()->flush();
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$page = ob_get_clean();
		$this->assertStringContainsString( 'defined in code and can&#039;t be changed here', $page );
		$this->assertMatchesRegularExpression( '/name="sc_wpun_settings\[notify_from\]"[^>]*disabled/', $page );
		sc_wpun()->settings_page()->sc_wpun_settings_validate( $this->settings( array( 'notify_from' => 'other@example.org' ) ) );
		$this->assertSame( 'new@example.org', get_option( 'sc_wpun_channels' )['email']['settings']['from'], 'Nothing is saved for a code-defined email.' );
	}

	/**
	 * A secret supplied from outside never reaches the database, the page, problems, Site Health or the log.
	 */
	public function test_a_secret_never_leaks() {
		$this->register_secret_channel();
		sc_wpun_channels()->save( new Channel_Instance( 'vault', 'test_secret', 'Vault', true, array( 'explode' => true ) ) );
		$this->add_source( array( 'SC_WPUN_CHANNEL_VAULT__TOKEN' => self::SECRET ) );
		$store = sc_wpun_channels();

		$instance = $store->get( 'vault' );
		$this->assertNull( $instance->get_setting( 'token' ), 'A rejected value is not put into the instance.' );
		$this->assertSame( array( 'SC_WPUN_CHANNEL_VAULT__TOKEN' ), $store->problems( 'vault' ), 'It does not start with ok-.' );

		$store->save( $instance->with_settings( array_merge( $instance->get_settings(), array( 'label' => 'changed' ) ) ) );
		$this->assertStringNotContainsString( self::SECRET, $this->raw_option() );
		$this->assertSame( 'changed', get_option( 'sc_wpun_channels' )['vault']['settings']['label'] );

		// The read-only helper shows no value for a secret field, and does for a plain one.
		$page = sc_wpun()->settings_page();
		$this->assertSame( '', $page->value_attribute( array( 'type' => 'secret' ), self::SECRET ) );
		$this->assertSame( '', $page->value_attribute( array( 'secret' => true ), self::SECRET ) );
		$this->assertStringContainsString( 'value="plain"', $page->value_attribute( array( 'type' => 'text' ), 'plain' ) );

		// Problems, the settings page notice and Site Health.
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( sc_wpun()->config()->problems() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'SC_WPUN_CHANNEL_VAULT__TOKEN', $html );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( call_user_func( $tests['direct']['sc_wpun_config']['test'] ) ) );

		// The dispatcher refuses it, naming the key only.
		$result = sc_wpun()->dispatcher()->send_one( new WPUN\Notification( 'S', 'http://example.org', 'http://example.org/u', array() ), $store->get( 'vault' ) );
		$this->assertSame( 'misconfigured', $result->get_code() );
		$this->assertStringNotContainsString( self::SECRET, $result->get_message() );
	}

	/**
	 * A valid secret works, and an exception that contains it is not logged with the message.
	 */
	public function test_a_valid_secret_is_used_and_not_logged() {
		$this->register_secret_channel();
		sc_wpun_channels()->save( new Channel_Instance( 'vault', 'test_secret', 'Vault', true, array( 'explode' => true ) ) );
		$this->add_source( array( 'SC_WPUN_CHANNEL_VAULT__TOKEN' => 'ok-SECRET-TOKEN' ) );
		$this->assertSame( array(), sc_wpun_channels()->problems( 'vault' ) );
		$notices = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing the notice raised under WP_DEBUG.
			static function ( $number, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			},
			E_USER_NOTICE
		);
		try {
			$result = sc_wpun()->dispatcher()->send_one( new WPUN\Notification( 'S', 'http://example.org', 'http://example.org/u', array() ), sc_wpun_channels()->get( 'vault' ) );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 'exception', $result->get_code() );
		$this->assertNotEmpty( $notices );
		$this->assertStringNotContainsString( 'SECRET', implode( ' ', $notices ) );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $result->get_context() ) . $result->get_message() );
	}

	/**
	 * An empty secret supplied from outside wins over the stored one: keeping a saved secret is for form submissions only.
	 */
	public function test_an_empty_external_secret_beats_the_stored_one() {
		sc_wpun()->channels()->register(
			new Test_Fields_Channel(
				'test_secret',
				array( 'key' => array( 'type' => 'secret' ) )
			)
		);
		sc_wpun_channels()->save( new Channel_Instance( 'vault', 'test_secret', 'Vault', true, array( 'key' => 'stored-key' ) ) );
		$this->assertSame( 'stored-key', sc_wpun_channels()->get( 'vault' )->get_setting( 'key' ) );

		$this->add_source( array( 'SC_WPUN_CHANNEL_VAULT__KEY' => '' ) );
		$this->assertSame( '', sc_wpun_channels()->get( 'vault' )->get_setting( 'key' ) );
		$this->assertSame( array(), sc_wpun_channels()->problems( 'vault' ) );
	}

	/**
	 * The context carries the site, so a filter can answer differently per site.
	 */
	public function test_values_can_differ_per_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}
		add_filter(
			'sc_wpun_config_value',
			static function ( $value, $context ) {
				return 'to' === $context->get_field() ? 'site' . $context->get_site_id() . '@example.org' : $value;
			},
			10,
			2
		);
		$config = sc_wpun()->config();
		$config->flush();
		$first = $config->resolve( Config_Context::channel_field( 'email', 'to' ) )->get_value();
		$blog  = self::factory()->blog->create();
		switch_to_blog( $blog );
		$second = $config->resolve( Config_Context::channel_field( 'email', 'to' ) )->get_value();
		restore_current_blog();

		$this->assertSame( 'site' . get_current_blog_id() . '@example.org', $first );
		$this->assertSame( 'site' . $blog . '@example.org', $second );
	}

	/**
	 * A programmatic update of the global settings does not touch the email instance, whatever is external.
	 *
	 * @dataProvider external_email_fields
	 *
	 * @param string $field Field supplied from outside.
	 * @param string $value The supplied value.
	 */
	public function test_a_programmatic_update_leaves_the_email_instance_alone( $field, $value ) {
		$this->add_source( array( 'SC_WPUN_CHANNEL_EMAIL__' . $field => $value ) );
		$before = get_option( 'sc_wpun_channels' );

		$valid = sc_wpun()->settings_page()->sc_wpun_settings_validate( array( 'frequency' => 'daily' ) );

		$this->assertSame( 'daily', $valid['frequency'] );
		$this->assertSame( $before, get_option( 'sc_wpun_channels' ) );
	}

	/**
	 * Fields to supply from outside.
	 *
	 * @return array
	 */
	public function external_email_fields() {
		return array(
			'enabled'  => array( 'ENABLED', '0' ),
			'to'       => array( 'TO', 'vault@example.org' ),
			'from'     => array( 'FROM', 'vault@example.org' ),
			'reply to' => array( 'REPLY_TO', 'vault@example.org' ),
		);
	}

	/**
	 * A rejected value is not used by the auto-update mail or the instance either.
	 */
	public function test_a_rejected_value_is_not_used_anywhere() {
		$this->add_source( array( 'SC_WPUN_CHANNEL_EMAIL__TO' => 'not an address' ) );

		$this->assertSame( 'ops@example.org', sc_wpun_channels()->get( 'email' )->get_setting( 'to' ) );
		$this->assertSame( array( 'SC_WPUN_CHANNEL_EMAIL__TO' ), sc_wpun_channels()->problems( 'email' ) );

		$email = apply_filters(
			'auto_core_update_email',
			array(
				'to'      => 'admin@example.org',
				'subject' => 'S',
				'body'    => 'B',
				'headers' => '',
			),
			'success',
			new stdClass(),
			null
		);
		$this->assertSame( 'ops@example.org', $email['to'], 'The stored recipient, never the rejected one.' );
	}

	/**
	 * With an invalid external recipient, a normal form save shows no error for the field it does not control.
	 */
	public function test_no_form_error_for_an_external_field() {
		$this->add_source( array( 'SC_WPUN_CHANNEL_EMAIL__TO' => 'not an address' ) );
		global $wp_settings_errors;
		$wp_settings_errors = array();

		sc_wpun()->settings_page()->sc_wpun_settings_validate( $this->settings( array( 'notify_to' => 'typed@example.org' ) ) );

		$this->assertSame( array(), wp_list_filter( get_settings_errors(), array( 'setting' => 'sc_wpun_settings_email_notifications_notify_to' ) ) );
	}

	/**
	 * A frequency posted as an array is an error, not a TypeError.
	 */
	public function test_an_array_frequency_is_rejected() {
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$valid = sc_wpun()->settings_page()->sc_wpun_settings_validate( array( 'frequency' => array( 'x' ) ) );

		$this->assertSame( 'hourly', $valid['frequency'] );
		$this->assertNotEmpty( wp_list_filter( get_settings_errors(), array( 'setting' => 'sc_wpun_settings_main_frequency' ) ) );
	}

	/**
	 * Add-on code that throws while checking a value is reported by class only, and never breaks all().
	 */
	public function test_throwing_add_on_code_is_contained() {
		$boom = static function () {
			throw new RuntimeException( 'sanitizer blew up on ' . self::SECRET ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test.
		};
		sc_wpun()->channels()->register(
			new Test_Fields_Channel(
				'test_secret',
				array(
					'token' => array( 'type' => 'secret' ),
					'boom'  => array(
						'type'              => 'text',
						'sanitize_callback' => $boom,
					),
				)
			)
		);
		sc_wpun_channels()->save( new Channel_Instance( 'throwing', 'test_secret', 'T', true, array( 'token' => 'ok-stored' ) ) );
		sc_wpun_channels()->save( new Channel_Instance( 'fine', 'test_secret', 'F', true, array( 'token' => 'ok-fine' ) ) );
		$this->add_source(
			array(
				'SC_WPUN_CHANNEL_THROWING__BOOM' => self::SECRET,
				'SC_WPUN_CHANNEL_FINE__TOKEN'    => 'throw-' . self::SECRET,
			)
		);

		$all = sc_wpun_channels()->all();

		$this->assertArrayHasKey( 'email', $all );
		$this->assertSame( array( 'SC_WPUN_CHANNEL_THROWING__BOOM' ), sc_wpun_channels()->problems( 'throwing' ) );
		$this->assertSame( array( 'SC_WPUN_CHANNEL_FINE__TOKEN' ), sc_wpun_channels()->problems( 'fine' ) );
		$this->assertSame( 'ok-fine', $all['fine']->get_setting( 'token' ), 'The rejected value is put back.' );
		$problems = wp_json_encode( sc_wpun()->config()->problems() );
		$this->assertStringContainsString( 'RuntimeException', $problems );
		$this->assertStringNotContainsString( 'SECRET', $problems );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$this->assertStringNotContainsString( 'SECRET', ob_get_clean() );
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( call_user_func( $tests['direct']['sc_wpun_config']['test'] ) ) );
	}

	/**
	 * The 1.x settings upgrade keeps the migrated values in the database when a field is external or the instance is defined in code.
	 *
	 * @dataProvider migration_cases
	 *
	 * @param string $situation Which situation.
	 */
	public function test_the_migration_writes_database_values( $situation ) {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';
		if ( 'external' === $situation ) {
			$this->add_source(
				array(
					'SC_WPUN_CHANNEL_EMAIL__TO'      => 'vault@example.org',
					'SC_WPUN_CHANNEL_EMAIL__ENABLED' => '0',
				)
			);
		} else {
			add_filter(
				'sc_wpun_channels',
				static fn( $channels ) => array_merge( $channels, array( new Channel_Instance( 'email', 'email', 'Code', true, array( 'to' => 'code@example.org' ) ) ) )
			);
			sc_wpun()->config()->flush();
		}
		update_option( 'sc_wpun_settings', $fixtures['1.6.0']['options'] );
		update_option( 'sc_wpun_settings_ver', $fixtures['1.6.0']['version'] );
		delete_option( 'sc_wpun_channels' );

		sc_wpun()->settings()->maybe_upgrade();

		$email = get_option( 'sc_wpun_channels' )['email'];
		$this->assertSame( 'ops@example.org', $email['settings']['to'] );
		$this->assertSame( 'ops@example.org', $email['settings']['from'] );
		$this->assertTrue( $email['enabled'] );
		$this->assertArrayHasKey( 'slack', get_option( 'sc_wpun_channels' ) );
	}

	/**
	 * Situations for the migration.
	 *
	 * @return array
	 */
	public function migration_cases() {
		return array(
			'external fields' => array( 'external' ),
			'code defined'    => array( 'code' ),
		);
	}

	/**
	 * Edge cases of the code-defined list: a non-string value, the enabled cast, and duplicate ids.
	 */
	public function test_code_defined_edge_cases() {
		add_filter( 'sc_wpun_channels_json', static fn() => array( 'not a string' ) );
		sc_wpun()->config()->flush();
		sc_wpun_channels()->all();
		$problems = sc_wpun()->config()->problems();
		$this->assertSame( 'SC_WPUN_CHANNELS', $problems[0]['key'] );
		$this->assertStringContainsString( 'JSON string', $problems[0]['message'] );

		remove_all_filters( 'sc_wpun_channels_json' );
		add_filter(
			'sc_wpun_channels_json',
			static fn() => wp_json_encode(
				array(
					array(
						'id'      => 'off_false',
						'type'    => 'email',
						'enabled' => 'false',
					),
					array(
						'id'      => 'off_zero',
						'type'    => 'email',
						'enabled' => '0',
					),
					array(
						'id'      => 'off_no',
						'type'    => 'email',
						'enabled' => 'no',
					),
					array(
						'id'      => 'maybe',
						'type'    => 'email',
						'enabled' => 'maybe',
					),
					array(
						'id'       => 'dup',
						'type'     => 'email',
						'settings' => array( 'to' => 'first@example.org' ),
					),
					array(
						'id'       => 'dup',
						'type'     => 'email',
						'settings' => array( 'to' => 'second@example.org' ),
					),
				)
			)
		);
		sc_wpun()->config()->flush();
		$store = sc_wpun_channels();

		foreach ( array( 'off_false', 'off_zero', 'off_no' ) as $id ) {
			$this->assertFalse( $store->get( $id )->is_enabled(), $id );
		}
		$this->assertNull( $store->get( 'maybe' ) );
		$this->assertSame( 'first@example.org', $store->get( 'dup' )->get_setting( 'to' ) );
		$this->assertCount( 2, array_filter( sc_wpun()->config()->problems(), static fn( $problem ) => 'SC_WPUN_CHANNELS' === $problem['key'] ) );
	}
}
