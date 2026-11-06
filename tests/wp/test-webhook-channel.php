<?php
/**
 * The built-in webhook channel (#99).
 *
 * @package wp-updates-notifier
 */

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use WPUN\Admin\Field_Renderer;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Modules\Webhook\Webhook_Channel;
use WPUN\Notification;
use WPUN\Notification_Item;

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

/**
 * Fields, validation, signing, delivery and the `updates.resolved` message of the webhook channel.
 */
class Test_Webhook_Channel extends WPUN_Test_Case {

	/**
	 * The secret address: the path and the query are secret too.
	 */
	const URL = 'https://hooks.example.org/in/SECRET-PATH-123?token=SECRET-QUERY-456';

	/**
	 * The signing secret of the Standard Webhooks test vector.
	 */
	const SECRET = 'whsec_' . self::VECTOR;

	/**
	 * The base64 part of that secret. It is published in the Standard Webhooks specification, so it protects nothing.
	 */
	const VECTOR = 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

	/**
	 * A fixture plugin for the resolved messages.
	 */
	const PLUGIN = 'wpun-webhook-fixture/wpun-webhook-fixture.php';

	/**
	 * Settings that matter in a test.
	 *
	 * @var string[]
	 */
	private $env = array();

	/**
	 * Register the channel, start with no instances and no webhook leftovers.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! sc_wpun()->channels()->has( 'webhook' ) ) {
			sc_wpun()->channels()->register( new Webhook_Channel() );
		}
		sc_wpun()->config()->flush();
		$this->save_settings( array( 'email_notifications' => 0 ) );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		$this->write_plugin( '1.0' );
	}

	/**
	 * Clean up the environment and the fixture plugin.
	 */
	public function tear_down() {
		foreach ( $this->env as $name ) {
			putenv( $name ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unset the variable a test set.
		}
		$this->env = array();
		$this->remove_dir( WP_PLUGIN_DIR . '/wpun-webhook-fixture' );
		wp_clean_plugins_cache();
		sc_wpun()->config()->flush();

		parent::tear_down();
	}

	// Helpers.

	/**
	 * A webhook instance, stored.
	 *
	 * @param array  $settings Settings to add or replace.
	 * @param string $id       Instance id.
	 * @param bool   $enabled  Whether it is on.
	 * @return Channel_Instance
	 */
	private function instance( array $settings = array(), $id = 'hook', $enabled = true ) {
		$instance = new Channel_Instance( $id, 'webhook', 'Hook', $enabled, array_merge( array( 'url' => self::URL ), $settings ) );
		sc_wpun_channels()->save( $instance );

		return $instance;
	}

	/**
	 * A notification of plugin updates.
	 *
	 * @param int   $count   Number of plugins.
	 * @param array $context Notification context.
	 * @return Notification
	 */
	private function notification( $count = 2, array $context = array() ) {
		$items = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$items[] = new Notification_Item(
				array(
					'type'            => 'plugin',
					'key'             => 'plugin-' . $i . '/plugin-' . $i . '.php',
					'name'            => 'Plugin ' . $i,
					'current_version' => '1.0',
					'new_version'     => '2.0',
					'links'           => 1 === $i ? array(
						'details'  => 'https://example.org/plugin-1/',
						'download' => 'https://example.org/plugin-1.zip',
					) : array(),
					'notes'           => 1 === $i ? array( 'Tested up to 6.9.' ) : array(),
					'flags'           => 1 === $i ? array(
						'active'       => true,
						'tested'       => '6.9',
						'requires_php' => '7.4',
					) : array(),
				)
			);
		}

		return new Notification( 'My &amp; Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', $items, $context );
	}

	/**
	 * Fake the answer of the receiver.
	 *
	 * @param int|WP_Error $response Status code, or an error.
	 * @param array        $headers  Response headers.
	 * @param string       $body     Response body.
	 */
	private function receiver_answers( $response, array $headers = array(), $body = '' ) {
		if ( is_int( $response ) ) {
			$response            = $this->fake_response( $response, $body );
			$response['headers'] = $headers;
		}
		$this->fake_http( 'hooks.example.org', $response );
	}

	/**
	 * The requests the receiver got.
	 *
	 * @return array
	 */
	private function received() {
		return $this->requests_to( 'hooks.example.org' );
	}

	/**
	 * The one request the receiver got.
	 *
	 * @return array
	 */
	private function request() {
		$this->assertCount( 1, $this->received() );

		return $this->received()[0];
	}

	/**
	 * The JSON body of the one request, as an array.
	 *
	 * @return array
	 */
	private function payload() {
		return json_decode( $this->request()['args']['body'], true );
	}

	/**
	 * Send through the dispatcher, the way the update check does.
	 *
	 * @param Notification     $notification What to send.
	 * @param Channel_Instance $instance     The instance.
	 * @return Delivery_Result
	 */
	private function send( Notification $notification, Channel_Instance $instance ) {
		return sc_wpun()->dispatcher()->send_one( $notification, $instance );
	}

	/**
	 * Check a request is signed the way Standard Webhooks says, with code that is not the module's.
	 *
	 * @param array  $request A request of the receiver.
	 * @param string $secret  The signing secret.
	 * @param array  $names   Names of the id, timestamp and signature headers.
	 */
	private function assert_signed( array $request, $secret = self::SECRET, array $names = array( 'webhook-id', 'webhook-timestamp', 'webhook-signature' ) ) {
		$headers  = $request['args']['headers'];
		$key      = base64_decode( substr( $secret, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The test checks a signature.
		$signed   = $headers[ $names[0] ] . '.' . $headers[ $names[1] ] . '.' . $request['args']['body'];
		$expected = 'v1,' . base64_encode( hash_hmac( 'sha256', $signed, $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The test checks a signature.

		$this->assertSame( $expected, $headers[ $names[2] ] );
	}

	/**
	 * Validate a JSON text against the published schema.
	 *
	 * @param string $json The body that was sent.
	 */
	private function assert_matches_schema( $json ) {
		$result = $this->schema_result( json_decode( $json ) );

		$this->assertTrue( $result->isValid(), $result->isValid() ? '' : wp_json_encode( ( new ErrorFormatter() )->format( $result->error() ) ) );
	}

	/**
	 * Validate data against the published schema.
	 *
	 * @param mixed $data Decoded JSON (objects, not arrays).
	 * @return Opis\JsonSchema\ValidationResult
	 */
	private function schema_result( $data ) {
		$schema = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/developers/webhook-payload.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		return ( new Validator() )->validate( $data, $schema );
	}

	/**
	 * Settings the form would submit, and the validation result.
	 *
	 * @param array $settings Submitted settings.
	 * @param array $stored   Stored settings.
	 * @return array|WP_Error
	 */
	private function validate( array $settings, array $stored = array() ) {
		return ( new Webhook_Channel() )->validate_settings( array_merge( array( 'url' => self::URL ), $settings ), $stored );
	}

	/**
	 * Assert a validation error and the field it names.
	 *
	 * @param array|WP_Error $result The result.
	 * @param string         $field  The field at fault.
	 * @return WP_Error
	 */
	private function assert_error( $result, $field ) {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'field' => $field ), $result->get_error_data() );

		return $result;
	}

	/**
	 * Write the fixture plugin with a version.
	 *
	 * @param string $version Version header.
	 */
	private function write_plugin( $version ) {
		wp_mkdir_p( WP_PLUGIN_DIR . '/wpun-webhook-fixture' );
		file_put_contents( WP_PLUGIN_DIR . '/' . self::PLUGIN, "<?php\n/*\nPlugin Name: Webhook Fixture\nVersion: $version\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		wp_clean_plugins_cache();
	}

	/**
	 * Delete a fixture directory.
	 *
	 * @param string $dir Directory.
	 */
	private function remove_dir( $dir ) {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
		}
	}

	/**
	 * Run an update check with the fixture plugin waiting.
	 */
	private function check_with_update() {
		$this->set_updates( null, array( self::PLUGIN => array( 'new_version' => '2.0' ) ) );
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * Run an update check with nothing waiting.
	 */
	private function check_nothing_pending() {
		$this->set_updates();
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * Report the update as sent, then install it and check again: the state of a resolved update.
	 *
	 * @param Channel_Instance $instance The instance.
	 */
	private function report_then_install( Channel_Instance $instance ) {
		$this->receiver_answers( 200 );
		$this->assertSame( 'hook', $instance->get_id() );
		$this->check_with_update();
		$this->assertCount( 1, $this->received(), 'The update was reported.' );
		$this->reset_http();
		$this->receiver_answers( 200 );
		$this->write_plugin( '2.0' );
	}

	/**
	 * Set an environment variable for the test.
	 *
	 * @param string $name  Name.
	 * @param string $value Value.
	 */
	private function set_env( $name, $value ) {
		putenv( $name . '=' . $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Environment variables are a configuration source.
		$this->env[] = $name;
		add_filter( 'sc_wpun_load_from_env', '__return_true' );
		sc_wpun()->config()->flush();
	}

	// Registration and fields.

	/**
	 * The module registers the channel through the public action, for API 1.1 and later.
	 */
	public function test_it_registers_through_the_action() {
		$registry = new WPUN\Channel_Registry();

		$this->assertTrue( $registry->has( 'webhook' ) );
		$channel = $registry->get( 'webhook' );
		$this->assertInstanceOf( Webhook_Channel::class, $channel );
		$this->assertSame( '^1.1', $channel->get_api_constraint() );
		$this->assertTrue( sc_wpun_api_satisfies( $channel->get_api_constraint() ) );
		$this->assertTrue( $channel->supports( 'test_message' ) );
		$this->assertFalse( $channel->supports( 'something_else' ) );
		$this->assertSame( 'Webhook', $channel->get_label() );
		$this->assertNotSame( '', $channel->get_description() );
	}

	/**
	 * The fields and the collapsed Advanced group are as decided.
	 */
	public function test_the_fields_and_groups() {
		$channel = new Webhook_Channel();
		$fields  = $channel->get_fields();

		$this->assertSame( array( 'url', 'signing_secret', 'send_resolved', 'header_names', 'id_header', 'timestamp_header', 'signature_header', 'headers', 'timeout' ), array_keys( $fields ) );
		$this->assertSame( 'url', $fields['url']['type'] );
		$this->assertTrue( $fields['url']['required'] );
		$this->assertTrue( $fields['url']['secret'] );
		$this->assertTrue( $fields['url']['https_only'] );
		$this->assertSame( 'secret', $fields['signing_secret']['type'] );
		$this->assertSame( 'boolean', $fields['send_resolved']['type'] );
		$this->assertTrue( $fields['send_resolved']['default'] );
		$this->assertSame( 'enum', $fields['header_names']['type'] );
		$this->assertSame( 'radio', $fields['header_names']['display'] );
		$this->assertSame( 'standard', $fields['header_names']['default'] );
		$this->assertSame( array( 'standard', 'custom' ), array_keys( $fields['header_names']['options'] ) );
		foreach ( array( 'id_header' => 'webhook-id', 'timestamp_header' => 'webhook-timestamp', 'signature_header' => 'webhook-signature' ) as $name => $default ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->assertSame( 'text', $fields[ $name ]['type'] );
			$this->assertSame( $default, $fields[ $name ]['default'] );
			$this->assertSame( array( 'header_names' => 'custom' ), $fields[ $name ]['applies_when'] );
			$this->assertSame( 'advanced', $fields[ $name ]['group'] );
		}
		$this->assertSame( 'textarea', $fields['headers']['type'] );
		$this->assertTrue( $fields['headers']['secret'] );
		$this->assertSame( 'advanced', $fields['headers']['group'] );
		$this->assertSame( 'number', $fields['timeout']['type'] );
		$this->assertSame( array( 1, 10, 5 ), array( $fields['timeout']['min'], $fields['timeout']['max'], $fields['timeout']['default'] ) );
		$this->assertSame( 'advanced', $fields['timeout']['group'] );
		$this->assertTrue( $channel->get_field_groups()['advanced']['collapsed'] );
	}

	/**
	 * The edit form draws the Advanced group closed, with the header names as a note until custom is chosen.
	 */
	public function test_the_form_draws_the_group() {
		$this->instance( array( 'headers' => 'X-Api-Key: SECRET-HEADER-VALUE' ) );
		$_GET['tab']    = 'channels';
		$_GET['action'] = 'edit';
		$_GET['id']     = 'hook';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'] );

		$this->assertStringContainsString( 'Advanced', $html );
		$this->assertStringContainsString( 'Used only when', $html );
		$this->assertStringContainsString( '<textarea', $html );
		$this->assertStringNotContainsString( 'SECRET-HEADER-VALUE', $html );
		$this->assertStringNotContainsString( 'SECRET-PATH-123', $html );
	}

	// Validation.

	/**
	 * The address must be https, and is required.
	 */
	public function test_the_address_is_https_only_and_required() {
		$error = $this->assert_error( $this->validate( array( 'url' => 'http://hooks.example.org/in' ) ), 'url' );
		$this->assertStringContainsString( 'https://', $error->get_error_message() );
		$this->assertStringNotContainsString( 'http://hooks', $error->get_error_message() );
		$this->assert_error( $this->validate( array( 'url' => '' ) ), 'url' );
		$this->assert_error( $this->validate( array( 'url' => 'ftp://example.org' ) ), 'url' );

		$this->assertSame( self::URL, $this->validate( array() )['url'] );
		$this->assertSame( self::URL, ( new Webhook_Channel() )->validate_settings( array(), array( 'url' => self::URL ) )['url'], 'Empty keeps the saved address.' );
	}

	/**
	 * A site can allow http by removing `https_only` with the fields filter, and the channel follows.
	 */
	public function test_a_site_can_turn_https_only_off() {
		add_filter(
			'sc_wpun_channel_fields',
			static function ( $fields, $type ) {
				if ( 'webhook' === $type ) {
					unset( $fields['url']['https_only'] );
				}
				return $fields;
			},
			10,
			2
		);

		$this->assertSame( 'http://hooks.example.org/in', $this->validate( array( 'url' => 'http://hooks.example.org/in' ) )['url'] );

		$this->receiver_answers( 200 );
		$instance = $this->instance( array( 'url' => 'http://hooks.example.org/in' ) );
		$this->assertTrue( $this->send( $this->notification(), $instance )->is_success() );
	}

	/**
	 * Only `whsec_` and base64 of 24 to 64 bytes is a signing secret, and the message says how to make one.
	 */
	public function test_the_signing_secret_format() {
		$bytes = static fn( $length ) => 'whsec_' . base64_encode( str_repeat( 'k', $length ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test secrets.

		foreach ( array( self::SECRET, $bytes( 24 ), $bytes( 64 ), $bytes( 32 ) ) as $good ) {
			$this->assertSame( $good, $this->validate( array( 'signing_secret' => $good ) )['signing_secret'], $good );
		}
		foreach ( array( 'plain-text-secret', 'whsec_', 'whsec_abc', $bytes( 23 ), $bytes( 65 ), 'whsec_!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!', 'WHSEC_' . self::VECTOR, self::VECTOR ) as $bad ) {
			$error = $this->assert_error( $this->validate( array( 'signing_secret' => $bad ) ), 'signing_secret' );
			$this->assertStringContainsString( 'openssl rand -base64 32', $error->get_error_message(), $bad );
			$this->assertStringContainsString( "printf 'whsec_%s\\n'", $error->get_error_message() );
			if ( strlen( $bad ) > 12 ) {
				$this->assertStringNotContainsString( $bad, $error->get_error_message(), 'The secret is not repeated.' );
			}
		}

		$this->assertArrayNotHasKey( 'signing_secret', $this->validate( array() ), 'Optional, and nothing stored is nothing kept.' );
		$this->assertSame( self::SECRET, ( new Webhook_Channel() )->validate_settings( array( 'url' => self::URL ), array( 'signing_secret' => self::SECRET ) )['signing_secret'], 'Empty keeps the saved secret.' );
	}

	/**
	 * The timeout is a whole number from 1 to 10.
	 */
	public function test_the_timeout_bounds() {
		foreach ( array( 1, 5, 10, '7' ) as $good ) {
			$this->assertSame( (int) $good, $this->validate( array( 'timeout' => $good ) )['timeout'] );
		}
		foreach ( array( 0, 11, -1, 'slow', 2.5 ) as $bad ) {
			$this->assert_error( $this->validate( array( 'timeout' => $bad ) ), 'timeout' );
		}
	}

	/**
	 * Custom names are tokens of at most 64 characters, and not a header the plugin sets.
	 */
	public function test_header_names_are_checked() {
		$custom = array( 'header_names' => 'custom' );

		$ok = $this->validate(
			$custom + array(
				'id_header'        => 'X-Hook-Id',
				'timestamp_header' => 'X-Hook-Time',
				'signature_header' => 'X-Hook-Sig',
			)
		);
		$this->assertSame( 'X-Hook-Sig', $ok['signature_header'] );

		foreach ( array( 'bad name', 'bad:name', "bad\nname", str_repeat( 'a', 65 ), 'Content-Type', 'content-length', 'HOST', 'User-Agent' ) as $bad ) {
			$this->assert_error( $this->validate( $custom + array( 'id_header' => $bad ) ), 'id_header' );
		}
		$this->assertSame( 64, strlen( $this->validate( $custom + array( 'id_header' => str_repeat( 'a', 64 ) ) )['id_header'] ) );

		$this->assertStringContainsString( 'different', $this->assert_error( $this->validate( $custom + array( 'timestamp_header' => 'webhook-id' ) ), 'timestamp_header' )->get_error_message() );
		$this->assert_error( $this->validate( $custom + array( 'id_header' => 'X-One', 'timestamp_header' => 'x-ONE' ) ), 'timestamp_header' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assert_error( $this->validate( $custom + array( 'signature_header' => 'WEBHOOK-ID' ) ), 'signature_header' );
		$this->assert_error( $this->validate( $custom + array( 'id_header' => 'X-Same', 'timestamp_header' => 'X-Same' ) ), 'timestamp_header' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * The cross-field checks apply only to custom names: with the standard ones, the stored custom names are ignored (`applies_when`).
	 */
	public function test_the_name_checks_apply_only_to_custom_names() {
		$same = array(
			'id_header'        => 'X-Same',
			'timestamp_header' => 'x-same',
		);

		$this->assertIsArray( $this->validate( array( 'header_names' => 'standard' ) + $same ) );
		$this->assertIsArray( $this->validate( $same, array( 'header_names' => 'standard' ) ) );
		$this->assert_error( $this->validate( array( 'header_names' => 'custom' ) + $same ), 'timestamp_header' );
		$this->assert_error( $this->validate( $same, array( 'header_names' => 'custom' ) ), 'timestamp_header' );
		$this->assert_error( $this->validate( array( 'header_names' => 'sometimes' ) ), 'header_names' );
	}

	/**
	 * The extra headers are parsed, with errors that name the line and never show a value.
	 */
	public function test_the_extra_headers_are_checked() {
		$list = "Authorization: Bearer SECRET-TKN\n\nX-Api-Key: SECRET-KEY";
		$ok   = $this->validate( array( 'headers' => $list ) );
		$this->assertSame( $list, $ok['headers'] );

		$bad = array(
			"X-One: a\nno colon SECRET-VALUE"      => 2,
			"X-One: a\nbad name: SECRET-VALUE"     => 2,
			"X-One: a\nHost: SECRET-VALUE"         => 2,
			"X-One: a\nuser-agent: SECRET-VALUE"   => 2,
			"X-One: a\nCONTENT-TYPE: SECRET-VALUE" => 2,
			"X-One: a\nWebhook-ID: SECRET-VALUE"   => 2,
			"X-One: a\nwebhook-signature: SECRET"  => 2,
			"X-One: a\nx-one: SECRET-VALUE"        => 2,
			"X-One: a\n\n\nX-Empty:   "            => 4,
			': SECRET-VALUE'                       => 1,
		);
		foreach ( $bad as $value => $line ) {
			$error = $this->assert_error( $this->validate( array( 'headers' => $value ) ), 'headers' );
			$this->assertStringContainsString( 'line ' . $line, strtolower( $error->get_error_message() ), $value );
			$this->assertStringNotContainsString( 'SECRET', $error->get_error_message() );
		}

		$twenty = implode( "\n", array_map( static fn( $i ) => "X-H$i: v", range( 1, 20 ) ) );
		$this->assertSame( $twenty, $this->validate( array( 'headers' => $twenty ) )['headers'] );
		$error = $this->assert_error( $this->validate( array( 'headers' => $twenty . "\nX-H21: v" ) ), 'headers' );
		$this->assertStringContainsString( '20', $error->get_error_message() );
	}

	/**
	 * A credential keeps its % escapes and its <, and a line break cannot start a header.
	 */
	public function test_the_extra_headers_keep_credentials_intact() {
		$value = "X-Api-Key: a%2Fb\r\nAuthorization: Bearer abc<def>\x07\r\n";
		$clean = $this->validate( array( 'headers' => $value ) );

		$this->assertSame( "X-Api-Key: a%2Fb\nAuthorization: Bearer abc<def>", $clean['headers'] );
		$this->assertSame( array( 'X-Api-Key' => 'a%2Fb', 'Authorization' => 'Bearer abc<def>' ), $this->sent_extra_headers( $clean['headers'] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assert_error( $this->validate( array( 'headers' => array( 'X-One: a' ) ) ), 'headers' );
	}

	/**
	 * The extra headers a send puts on the request.
	 *
	 * @param string $headers The setting.
	 * @return array
	 */
	private function sent_extra_headers( $headers ) {
		$this->receiver_answers( 200 );
		$this->send( $this->notification( 0 ), $this->instance( array( 'headers' => $headers ) ) );
		$sent = $this->request()['args']['headers'];

		return array_diff_key( $sent, array_flip( array( 'Content-Type', 'User-Agent', 'webhook-id', 'webhook-timestamp' ) ) );
	}

	/**
	 * A blank submission keeps the saved list, and the collision check uses it.
	 */
	public function test_the_name_collision_check_uses_the_saved_headers() {
		$stored = array(
			'url'     => self::URL,
			'headers' => "X-Api-Key: k\nX-Hook-Sig: s",
		);
		$custom = array(
			'header_names'     => 'custom',
			'signature_header' => 'x-hook-sig',
		);

		$kept = ( new Webhook_Channel() )->validate_settings( array( 'url' => self::URL ), $stored );
		$this->assertSame( $stored['headers'], $kept['headers'] );

		$result = ( new Webhook_Channel() )->validate_settings( array( 'url' => self::URL, 'headers' => '' ) + $custom, $stored ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$error  = $this->assert_error( $result, 'signature_header' );
		$this->assertStringContainsString( 'Line 2', $error->get_error_message() );
		$this->assertStringNotContainsString( 'x-hook-sig', strtolower( $error->get_error_message() ) );

		$cleared = ( new Webhook_Channel() )->validate_settings( array( 'url' => self::URL, 'headers' => '', 'headers__clear' => '1' ) + $custom, $stored ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( '', $cleared['headers'], 'Cleared, so there is nothing to collide with.' );

		$typed = ( new Webhook_Channel() )->validate_settings( array( 'url' => self::URL, 'headers' => 'X-Other: 1' ) + $custom, $stored ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'X-Other: 1', $typed['headers'] );

		$from_form = ( new Webhook_Channel() )->validate_settings( array( 'url' => self::URL, 'headers' => '   ' ) + $custom, $stored ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assert_error( $from_form, 'signature_header' );

		$this->assert_error( $this->validate( array( 'headers' => 'X-Hook-Sig: s' ) + $custom ), 'signature_header' );
	}

	/**
	 * A secret list draws an empty textarea with the saved note and the clear checkbox, never the value.
	 */
	public function test_the_extra_headers_field_is_drawn_like_a_secret() {
		$field = ( new Webhook_Channel() )->get_fields()['headers'];

		$html = Field_Renderer::field( $field, 'settings[headers]', 'f', 'X-Api-Key: SECRET-VALUE' );
		$this->assertStringContainsString( '<textarea', $html );
		$this->assertStringContainsString( '></textarea>', $html, 'Empty.' );
		$this->assertStringNotContainsString( 'SECRET-VALUE', $html );
		$this->assertStringContainsString( 'name="settings[headers__clear]"', $html );
		$this->assertStringContainsString( 'aria-describedby="f-saved"', $html );

		$empty = Field_Renderer::field( $field, 'settings[headers]', 'f', '' );
		$this->assertStringContainsString( '<textarea', $empty );
		$this->assertStringNotContainsString( '__clear', $empty );
	}

	// Signing.

	/**
	 * The signature of the Standard Webhooks test vector.
	 */
	public function test_the_spec_test_vector() {
		$key = Webhook_Channel::decode_secret( self::SECRET );

		$this->assertSame( 24, strlen( $key ) );
		$this->assertSame(
			'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
			Webhook_Channel::sign( 'msg_p5jXN8AQM9LWM0D4loKWxJek', 1614265330, '{"test": 2432232314}', $key )
		);
		$this->assertNull( Webhook_Channel::decode_secret( 'whsec_' ) );
		$this->assertNull( Webhook_Channel::decode_secret( self::VECTOR ) );
	}

	/**
	 * What is sent is what is signed: sign, send, then verify the bytes of the request.
	 */
	public function test_a_message_round_trip() {
		$this->receiver_answers( 200 );
		$instance = $this->instance( array( 'signing_secret' => self::SECRET ) );

		$result = ( new Test_Webhook_Fixed_Channel() )->send( $this->notification(), $instance );

		$this->assertTrue( $result->is_success() );
		$request = $this->request();
		$headers = $request['args']['headers'];
		$this->assertSame( Test_Webhook_Fixed_Channel::ID, $headers['webhook-id'] );
		$this->assertSame( '1614265330', $headers['webhook-timestamp'] );
		$this->assertSame( Webhook_Channel::sign( Test_Webhook_Fixed_Channel::ID, 1614265330, $request['args']['body'], Webhook_Channel::decode_secret( self::SECRET ) ), $headers['webhook-signature'] );
		$this->assert_signed( $request );
		$this->assertSame( '2021-02-25T15:02:10+00:00', json_decode( $request['args']['body'], true )['timestamp'] );
	}

	/**
	 * Every message gets its own id, with no dot in it, and the real clock.
	 */
	public function test_ids_are_new_for_every_message() {
		$this->receiver_answers( 200 );
		$instance = $this->instance( array( 'signing_secret' => self::SECRET ) );
		$before   = time();

		$this->send( $this->notification(), $instance );
		$this->send( $this->notification(), $instance );

		$ids = array_map( static fn( $request ) => $request['args']['headers']['webhook-id'], $this->received() );
		$this->assertCount( 2, $ids );
		$this->assertNotSame( $ids[0], $ids[1] );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^msg_[A-Za-z0-9_-]{16,}$/D', $id );
			$this->assertStringNotContainsString( '.', $id );
		}
		$this->assertGreaterThanOrEqual( $before, (int) $this->received()[0]['args']['headers']['webhook-timestamp'] );
		$this->assertLessThanOrEqual( time(), (int) $this->received()[0]['args']['headers']['webhook-timestamp'] );
	}

	/**
	 * Without a secret the id and time are sent, but no signature.
	 */
	public function test_no_secret_means_no_signature() {
		$this->receiver_answers( 200 );

		$this->assertTrue( $this->send( $this->notification(), $this->instance() )->is_success() );

		$headers = $this->request()['args']['headers'];
		$this->assertArrayHasKey( 'webhook-id', $headers );
		$this->assertArrayHasKey( 'webhook-timestamp', $headers );
		$this->assertArrayNotHasKey( 'webhook-signature', $headers );
	}

	/**
	 * The payload the filters changed is the one that is signed and sent.
	 */
	public function test_the_filtered_payload_is_signed() {
		$this->receiver_answers( 200 );
		add_filter(
			'sc_wpun_channel_payload_webhook',
			static function ( $payload ) {
				$payload['data']['site']['name'] = 'Changed é / name';
				$payload['extra']                = array( 'a' => 1 );
				return $payload;
			}
		);

		$this->send( $this->notification(), $this->instance( array( 'signing_secret' => self::SECRET ) ) );

		$request = $this->request();
		$this->assertSame( 'Changed é / name', $this->payload()['data']['site']['name'] );
		$this->assertSame( array( 'a' => 1 ), $this->payload()['extra'] );
		$this->assertStringContainsString( 'Changed é / name', $request['args']['body'], 'Unicode and slashes are not escaped.' );
		$this->assert_signed( $request );
	}

	/**
	 * A filter that returns something that is not an array is ignored.
	 */
	public function test_a_filter_that_returns_the_wrong_shape_is_ignored() {
		$this->receiver_answers( 200 );
		add_filter( 'sc_wpun_channel_payload', '__return_false' );

		$this->assertTrue( $this->send( $this->notification(), $this->instance( array( 'signing_secret' => self::SECRET ) ) )->is_success() );

		$this->assertSame( 'updates.available', $this->payload()['type'] );
		$this->assert_signed( $this->request() );
	}

	/**
	 * A payload that cannot be turned into JSON is a failure, and nothing is sent or signed.
	 */
	public function test_a_payload_that_cannot_be_encoded_is_a_failure() {
		add_filter(
			'sc_wpun_channel_payload',
			static function ( $payload ) {
				$payload['loop'] = NAN;
				return $payload;
			}
		);

		$result = $this->send( $this->notification(), $this->instance( array( 'signing_secret' => self::SECRET ) ) );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'encode_failed', $result->get_code() );
		$this->assertSame( array(), $this->http_requests );
	}

	// Headers.

	/**
	 * The standard names are the default; custom names are used only when chosen.
	 */
	public function test_header_names() {
		$this->receiver_answers( 200 );
		$custom = array(
			'signing_secret'   => self::SECRET,
			'id_header'        => 'X-Hook-Id',
			'timestamp_header' => 'X-Hook-Time',
			'signature_header' => 'X-Hook-Sig',
		);

		$this->send( $this->notification(), $this->instance( $custom ) );
		$headers = $this->request()['args']['headers'];
		$this->assertSame( array( 'Content-Type', 'User-Agent', 'webhook-id', 'webhook-timestamp', 'webhook-signature' ), array_keys( $headers ), 'The stored custom names are ignored with the standard ones.' );

		$this->reset_http();
		$this->receiver_answers( 200 );
		$this->send( $this->notification(), $this->instance( $custom + array( 'header_names' => 'custom' ) ) );
		$request = $this->request();
		$this->assertSame( array( 'Content-Type', 'User-Agent', 'X-Hook-Id', 'X-Hook-Time', 'X-Hook-Sig' ), array_keys( $request['args']['headers'] ) );
		$this->assert_signed( $request, self::SECRET, array( 'X-Hook-Id', 'X-Hook-Time', 'X-Hook-Sig' ) );
	}

	/**
	 * Custom names that are empty, repeated or reserved (set by other means) fall back to the standard names.
	 */
	public function test_unusable_custom_names_fall_back() {
		$this->receiver_answers( 200 );

		$this->send(
			$this->notification(),
			$this->instance(
				array(
					'header_names'     => 'custom',
					'id_header'        => '',
					'timestamp_header' => 'Host',
					'signature_header' => 'X-Hook-Sig',
					'signing_secret'   => self::SECRET,
				)
			)
		);

		$headers = $this->request()['args']['headers'];
		$this->assertArrayHasKey( 'webhook-id', $headers );
		$this->assertArrayHasKey( 'webhook-timestamp', $headers );
		$this->assertArrayHasKey( 'X-Hook-Sig', $headers );
		$this->assertArrayNotHasKey( 'Host', $headers );
	}

	/**
	 * The extra headers are sent, and the filter sees the final headers last.
	 */
	public function test_the_extra_headers_and_the_headers_filter() {
		$this->receiver_answers( 200 );
		$calls = array();
		add_filter(
			'sc_wpun_webhook_headers',
			function ( $headers, $notification, $instance, $body ) use ( &$calls ) {
				$calls[]            = array( $headers, $notification, $instance, $body );
				$headers['X-Site']  = 'production';
				$headers['X-Bad']   = "line\r\nbreak";
				$headers[]          = 'dropped';
				$headers['X-Array'] = array( 'a' );
				unset( $headers['User-Agent'] );
				return $headers;
			},
			10,
			4
		);
		$instance     = $this->instance(
			array(
				'headers'        => "Authorization: Bearer SECRET-TKN\nX-Api-Key: a%2Fb",
				'signing_secret' => self::SECRET,
			)
		);
		$notification = $this->notification();

		$this->send( $notification, $instance );

		$this->assertCount( 1, $calls );
		list( $seen, $seen_notification, $seen_instance, $seen_body ) = $calls[0];
		$this->assertSame( 'Bearer SECRET-TKN', $seen['Authorization'] );
		$this->assertSame( 'a%2Fb', $seen['X-Api-Key'] );
		$this->assertSame( 'application/json', $seen['Content-Type'] );
		$this->assertArrayHasKey( 'webhook-signature', $seen, 'The signature headers are there already.' );
		$this->assertSame( $notification, $seen_notification );
		$this->assertSame( 'hook', $seen_instance->get_id() );
		$request = $this->request();
		$this->assertSame( $request['args']['body'], $seen_body );

		$sent = $request['args']['headers'];
		$this->assertSame( 'production', $sent['X-Site'] );
		$this->assertSame( 'Bearer SECRET-TKN', $sent['Authorization'] );
		$this->assertArrayNotHasKey( 'User-Agent', $sent, 'What the filter removed stays removed.' );
		$this->assertArrayNotHasKey( 'X-Bad', $sent );
		$this->assertArrayNotHasKey( 'X-Array', $sent );
		$this->assertNotContains( 'dropped', $sent );
	}

	/**
	 * A headers filter that does not return an array is ignored.
	 */
	public function test_a_headers_filter_with_the_wrong_shape_is_ignored() {
		$this->receiver_answers( 200 );
		add_filter( 'sc_wpun_webhook_headers', '__return_false' );

		$this->assertTrue( $this->send( $this->notification(), $this->instance() )->is_success() );

		$this->assertArrayHasKey( 'Content-Type', $this->request()['args']['headers'] );
	}

	/**
	 * Extra headers that cannot be read, which no form saves, stop the send.
	 */
	public function test_bad_extra_headers_stop_the_send() {
		foreach ( array( 'not a header SECRET-VALUE', 'Host: example.org', "X-One: a\nX-One: b" ) as $headers ) {
			$result = ( new Webhook_Channel() )->send( $this->notification(), new Channel_Instance( 'hook', 'webhook', 'Hook', true, array( 'url' => self::URL, 'headers' => $headers ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'invalid_headers', $result->get_code() );
			$this->assertStringNotContainsString( 'SECRET', $result->get_message() );
		}
		$this->assertSame( array(), $this->http_requests );
	}

	// Payload.

	/**
	 * The payload is the envelope, matches the schema, and holds every item field.
	 */
	public function test_the_payload() {
		$this->receiver_answers( 200 );

		$this->send( $this->notification( 2, array( 'source' => 'manual' ) ), $this->instance( array( 'signing_secret' => self::SECRET ) ) );

		$request = $this->request();
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assert_matches_schema( $request['args']['body'] );
		$payload = json_decode( $request['args']['body'], true );
		$this->assertSame( array( 'type', 'timestamp', 'schema_version', 'data' ), array_keys( $payload ) );
		$this->assertSame( 'updates.available', $payload['type'] );
		$this->assertSame( 1, $payload['schema_version'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/D', $payload['timestamp'] );
		$this->assertSame(
			array(
				'name'        => 'My & Site',
				'url'         => 'https://example.org',
				'updates_url' => 'https://example.org/wp-admin/update-core.php',
				'network'     => is_multisite(),
			),
			$payload['data']['site']
		);
		$this->assertSame(
			array(
				'source'      => 'manual',
				'is_test'     => false,
				'is_reminder' => false,
			),
			$payload['data']['notification']
		);
		$this->assertSame(
			array(
				'type'            => 'plugin',
				'key'             => 'plugin-1/plugin-1.php',
				'name'            => 'Plugin 1',
				'current_version' => '1.0',
				'new_version'     => '2.0',
				'links'           => array(
					'details'  => 'https://example.org/plugin-1/',
					'download' => 'https://example.org/plugin-1.zip',
				),
				'notes'           => array( 'Tested up to 6.9.' ),
				'flags'           => array(
					'active'       => true,
					'tested'       => '6.9',
					'requires_php' => '7.4',
				),
			),
			$payload['data']['items'][0]
		);
		$this->assertStringContainsString( '"links":{},"notes":[],"flags":{}', $request['args']['body'], 'Empty maps are objects.' );
		$this->assertStringContainsString( 'https://example.org/plugin-1/', $request['args']['body'], 'Slashes are not escaped.' );
		$this->assertArrayNotHasKey( 'notified_at', $payload['data']['items'][0] );
	}

	/**
	 * In network mode the site fields are the network's, and the payload says so.
	 */
	public function test_the_network_flag() {
		$this->receiver_answers( 200 );
		$instance = $this->instance();

		$this->send( $this->notification(), $instance );
		$this->assertSame( sc_wpun_is_network(), $this->payload()['data']['site']['network'] );
		$this->assertSame( is_multisite(), $this->payload()['data']['site']['network'] );

		if ( is_multisite() ) {
			$this->set_network_activated( false );
			$this->reset_http();
			$this->receiver_answers( 200 );
			$this->send( $this->notification(), $instance );
			$this->assertFalse( $this->payload()['data']['site']['network'] );
			$this->assert_matches_schema( $this->request()['args']['body'] );
		}
	}

	/**
	 * A test notification is `updates.test`, a reminder is `updates.reminder`.
	 */
	public function test_the_message_types() {
		$instance = $this->instance();

		$this->receiver_answers( 200 );
		$result = sc_wpun()->dispatcher()->send_test( $instance );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'updates.test', $this->payload()['type'] );
		$this->assertTrue( $this->payload()['data']['notification']['is_test'] );
		$this->assertSame( 'test', $this->payload()['data']['notification']['source'] );
		$this->assertSame( array(), $this->payload()['data']['items'] );
		$this->assert_matches_schema( $this->request()['args']['body'] );

		$this->reset_http();
		$this->receiver_answers( 200 );
		$this->send( $this->notification( 1, array( 'is_reminder' => true ) ), $instance );
		$this->assertSame( 'updates.reminder', $this->payload()['type'] );
		$this->assertTrue( $this->payload()['data']['notification']['is_reminder'] );
		$this->assert_matches_schema( $this->request()['args']['body'] );
	}

	/**
	 * The schema is not satisfied by anything: it refuses a wrong type, a wrong version and an array where an object is due.
	 */
	public function test_the_schema_refuses_bad_payloads() {
		$this->receiver_answers( 200 );
		$this->send( $this->notification(), $this->instance() );
		$good = json_decode( $this->request()['args']['body'] );
		$this->assertTrue( $this->schema_result( $good )->isValid() );

		$mutations = array(
			'type'        => static function ( $p ) {
				$p->type = 'updates.other';
			},
			'version'     => static function ( $p ) {
				$p->schema_version = 2;
			},
			'timestamp'   => static function ( $p ) {
				$p->timestamp = 'yesterday';
			},
			'links array' => static function ( $p ) {
				$p->data->items[1]->links = array();
			},
			'no site'     => static function ( $p ) {
				unset( $p->data->site );
			},
			'resolved'    => static function ( $p ) {
				$p->type = 'updates.resolved';
			},
			'flag object' => static function ( $p ) {
				$p->data->items[0]->flags->active = array( 1 );
			},
		);
		foreach ( $mutations as $name => $mutate ) {
			$copy = json_decode( (string) wp_json_encode( $good ) );
			$mutate( $copy );
			$this->assertFalse( $this->schema_result( $copy )->isValid(), $name );
		}
	}

	// Delivery.

	/**
	 * A 2xx answer is a success, with the request as decided.
	 */
	public function test_a_successful_delivery() {
		foreach ( array( 200, 201, 204 ) as $status ) {
			$this->reset_http();
			$this->receiver_answers( $status );

			$result = $this->send( $this->notification(), $this->instance( array( 'timeout' => 7 ) ) );

			$this->assertTrue( $result->is_success(), (string) $status );
			$request = $this->request();
			$this->assertSame( self::URL, $request['url'] );
			$this->assertSame( 'POST', $request['args']['method'] );
			$this->assertSame( 7, $request['args']['timeout'] );
			$this->assertSame( 0, $request['args']['redirection'] );
			$this->assertMatchesRegularExpression( '#^WP-Updates-Notifier/\d+\.\d+\.\d+(-[\w.]+)?; ' . preg_quote( home_url(), '#' ) . '$#D', $request['args']['headers']['User-Agent'] );
		}
	}

	/**
	 * The user agent carries the version of the plugin header.
	 */
	public function test_the_user_agent_has_the_plugin_version() {
		$this->receiver_answers( 200 );
		$data = get_file_data( SC_WPUN_FILE, array( 'Version' => 'Version' ) );

		$this->send( $this->notification(), $this->instance() );

		$this->assertSame( 'WP-Updates-Notifier/' . $data['Version'] . '; ' . home_url(), $this->request()['args']['headers']['User-Agent'] );
	}

	/**
	 * The timeout is the setting (default 5), kept within 1 and 10.
	 */
	public function test_the_timeout() {
		$this->receiver_answers( 200 );
		foreach ( array( array( null, 5 ), array( 3, 3 ), array( 99, 10 ), array( 0, 1 ), array( 'x', 5 ) ) as $case ) {
			$this->reset_http();
			$this->receiver_answers( 200 );
			$settings = null === $case[0] ? array() : array( 'timeout' => $case[0] );

			$this->send( $this->notification(), $this->instance( $settings ) );

			$this->assertSame( $case[1], $this->request()['args']['timeout'] );
		}
	}

	/**
	 * A redirect is a failure: it is not followed, and the body would be lost.
	 */
	public function test_a_redirect_is_a_failure() {
		$this->receiver_answers( 302, array( 'location' => 'https://other.example.org/SECRET' ) );

		$result = $this->send( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 302, $result->get_status() );
		$this->assertSame( 'redirected', $result->get_code() );
		$this->assertStringContainsString( '302', $result->get_message() );
		$this->assertStringNotContainsString( 'other.example.org', $result->get_message() );
		$this->assertCount( 1, $this->received() );
		$this->assertSame( 0, $this->request()['args']['redirection'] );
	}

	/**
	 * A 4xx or 5xx is a failure with the status and an excerpt of the answer, with secrets removed.
	 */
	public function test_an_error_status_has_a_safe_excerpt() {
		$instance = $this->instance(
			array(
				'signing_secret' => self::SECRET,
				'headers'        => "Authorization: Bearer SECRET-TKN-789\nX-Api-Key: a%2Fb",
			)
		);
		$body     = "<html><body><h1>Bad   request</h1>\n<script>x()</script><p>I got " . self::URL . ' and ' . str_replace( '/', '\\/', self::URL )
			. ' path /in/SECRET-PATH-123 query token=SECRET-QUERY-456 Bearer SECRET-TKN-789 key a%2Fb secret ' . self::SECRET . ' b64 ' . self::VECTOR
			. ' &#83;ECRET-TKN-789 end</p></body></html>';

		foreach ( array( 400, 404, 500, 503 ) as $status ) {
			$this->reset_http();
			$this->receiver_answers( $status, array(), $body );

			$result = $this->send( $this->notification(), $instance );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( $status, $result->get_status() );
			$this->assertSame( 'bad_status', $result->get_code() );
			$this->assertNull( $result->get_retry_after() );
			$this->assertStringContainsString( (string) $status, $result->get_message() );
			$excerpt = $result->get_context()['body_excerpt'];
			$this->assertStringContainsString( 'Bad request', $excerpt, 'Tags are stripped and white space collapsed.' );
			$this->assertStringNotContainsString( '<', $excerpt );
			$this->assertLessThanOrEqual( 200, mb_strlen( $excerpt ) );
			foreach ( array( 'hooks.example.org', 'SECRET', 'MfKQ9r8', 'a%2Fb', 'in/', 'token=' ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $excerpt . $result->get_message() . wp_json_encode( $result->get_context() ), $secret );
			}
		}
	}

	/**
	 * A credential added by the headers filter is removed from the excerpt too, and the answer is read only in part.
	 */
	public function test_filter_added_headers_are_redacted() {
		$add = static function ( $headers ) {
			$headers['Authorization'] = 'Bearer VAULT-TKN-42';
			return $headers;
		};
		add_filter( 'sc_wpun_webhook_headers', $add );
		$this->receiver_answers( 500, array(), 'echo Bearer VAULT-TKN-42 and VAULT-TKN-42' );

		$result = $this->send( $this->notification(), $this->instance() );
		remove_filter( 'sc_wpun_webhook_headers', $add );

		$this->assertStringNotContainsString( 'VAULT', $result->get_context()['body_excerpt'] );
		$this->assertSame( 64 * KB_IN_BYTES, $this->request()['args']['limit_response_size'] );
	}

	/**
	 * The excerpt is cut at 200 characters, and a secret cut by the limit does not show.
	 */
	public function test_the_excerpt_is_capped() {
		$instance = $this->instance( array( 'headers' => 'X-Api-Key: SECRET-LONG-VALUE-123' ) );
		$this->receiver_answers( 500, array(), str_repeat( 'é ', 400 ) );

		$result = $this->send( $this->notification(), $instance );

		$this->assertSame( 200, mb_strlen( $result->get_context()['body_excerpt'] ) );

		$this->reset_http();
		$this->receiver_answers( 500, array(), str_repeat( 'x', 190 ) . 'SECRET-LONG-VALUE-123' );
		$excerpt = $this->send( $this->notification(), $instance )->get_context()['body_excerpt'];
		$this->assertStringNotContainsString( 'SECRET', $excerpt );
		$this->assertStringNotContainsString( 'LONG-VALUE', $excerpt );
		$this->assertLessThanOrEqual( 200, mb_strlen( $excerpt ) );

		$this->reset_http();
		$this->receiver_answers( 500 );
		$this->assertSame( '', $this->send( $this->notification(), $instance )->get_context()['body_excerpt'] );
	}

	/**
	 * A path of just "/" does not hide every slash of the answer.
	 */
	public function test_a_bare_slash_path_is_not_redacted() {
		$this->receiver_answers( 500, array(), 'see /docs/page for help' );

		$result = $this->send( $this->notification(), $this->instance( array( 'url' => 'https://hooks.example.org/' ) ) );

		$this->assertSame( 'see /docs/page for help', $result->get_context()['body_excerpt'] );
	}

	/**
	 * A transport error is a failure with a fixed message, and its text (which holds the address) is dropped.
	 */
	public function test_a_wp_error_is_a_failure_without_the_address() {
		$this->receiver_answers( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out for ' . self::URL ) );

		$result = $this->send( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'http_error', $result->get_code() );
		$this->assertNull( $result->get_status() );
		$this->assertSame( 'The request failed.', $result->get_message() );
		$this->assertSame( array( 'error_code' => 'http_request_failed' ), $result->get_context() );
	}

	/**
	 * A 429 passes on Retry-After, in seconds or as a date; without a usable one there is none.
	 */
	public function test_rate_limiting() {
		$this->receiver_answers( 429, array( 'retry-after' => '30' ) );
		$result = $this->send( $this->notification(), $this->instance() );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 429, $result->get_status() );
		$this->assertSame( 'rate_limited', $result->get_code() );
		$this->assertSame( 30, $result->get_retry_after() );

		$this->reset_http();
		$this->receiver_answers( 429, array( 'retry-after' => gmdate( 'D, d M Y H:i:s \G\M\T', time() + 120 ) ) );
		$this->assertEqualsWithDelta( 120, $this->send( $this->notification(), $this->instance() )->get_retry_after(), 2 );

		$this->reset_http();
		$this->receiver_answers( 429 );
		$this->assertNull( $this->send( $this->notification(), $this->instance() )->get_retry_after() );
	}

	/**
	 * A rate limited update check defers the instance, and the updates go out after the wait.
	 */
	public function test_a_rate_limited_check_is_retried_after_the_wait() {
		$this->instance();
		$this->receiver_answers( 429, array( 'retry-after' => '60' ) );
		$this->check_with_update();

		$this->assertSame( 60, sc_wpun()->update_checker()->last_results()['hook']->get_retry_after() );
		$this->assertNotNull( sc_wpun_instance_waiting_until( 'hook' ) );
		$this->assertSame( '', $this->notified_version( 'plugin', self::PLUGIN, 'hook' ) );

		$state                                 = $this->stored_state();
		$state['channels']['hook']['retry_at'] = time() - 1;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		$this->reset_http();
		$this->receiver_answers( 200 );
		$this->check_with_update();

		$this->assertCount( 1, $this->received() );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', self::PLUGIN, 'hook' ) );
	}

	/**
	 * Without a usable address nothing is sent. The address is also checked again here, as settings can arrive by other means.
	 */
	public function test_an_unusable_address_is_a_failure_without_a_request() {
		foreach ( array( '', 'not a url', 'ftp://hooks.example.org/in', 'http://hooks.example.org/in', 'javascript:alert(1)', 'https:///nohost' ) as $url ) {
			$instance = new Channel_Instance( 'hook', 'webhook', 'Hook', true, array( 'url' => $url ) );

			$result = ( new Webhook_Channel() )->send( $this->notification(), $instance );

			$this->assertFalse( $result->is_success(), $url );
			$this->assertSame( 'not_configured', $result->get_code() );
		}
		$this->assertSame( array(), $this->http_requests );

		$result = ( new Webhook_Channel() )->send( $this->notification(), new Channel_Instance( 'hook', 'webhook', 'Hook', true, array( 'url' => array( 'x' ) ) ) );
		$this->assertSame( 'not_configured', $result->get_code() );
	}

	/**
	 * A signing secret that is not valid, which no form saves, fails the send: nothing is signed with a bad key.
	 */
	public function test_an_invalid_secret_is_a_failure_without_a_request() {
		foreach ( array( 'plain-secret-SECRET', 'whsec_short', 'whsec_' . str_repeat( 'QQ', 50 ) ) as $secret ) {
			$instance = new Channel_Instance( 'hook', 'webhook', 'Hook', true, array( 'url' => self::URL, 'signing_secret' => $secret ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

			$result = ( new Webhook_Channel() )->send( $this->notification(), $instance );

			$this->assertFalse( $result->is_success(), $secret );
			$this->assertSame( 'invalid_secret', $result->get_code() );
			$this->assertStringNotContainsString( 'SECRET', $result->get_message() );
		}
		$this->assertSame( array(), $this->http_requests );
	}

	// Configuration sources.

	/**
	 * The signing secret can come from an environment variable, and it is used to sign.
	 */
	public function test_the_secret_from_the_environment_signs() {
		$this->set_env( 'SC_WPUN_CHANNEL_HOOK__SIGNING_SECRET', self::SECRET );
		$this->receiver_answers( 200 );
		$this->instance();

		$instance = sc_wpun_channels()->get( 'hook' );
		$this->assertSame( self::SECRET, $instance->get_setting( 'signing_secret' ) );
		$this->assertTrue( $this->send( $this->notification(), $instance )->is_success() );

		$this->assert_signed( $this->request() );
	}

	/**
	 * Supplied values go through the same checks as the form: bad ones are refused, reported, and nothing is delivered through the instance.
	 */
	public function test_bad_supplied_values_are_refused() {
		$cases = array(
			'SIGNING_SECRET' => 'plain-text-SECRET',
			'URL'            => 'http://hooks.example.org/in',
			'HEADERS'        => "Host: example.org\nX-A: SECRET-VALUE",
			'TIMEOUT'        => '99',
			'HEADER_NAMES'   => 'sometimes',
		);
		foreach ( $cases as $field => $value ) {
			$this->set_env( 'SC_WPUN_CHANNEL_HOOK__' . $field, $value );
			$this->instance();

			$this->assertContains( 'SC_WPUN_CHANNEL_HOOK__' . $field, sc_wpun_channels()->problems( 'hook' ), $field );
			$stored = sc_wpun_channels()->get( 'hook' );
			$this->assertNotSame( $value, $stored->get_setting( strtolower( $field ) ), $field );

			putenv( 'SC_WPUN_CHANNEL_HOOK__' . $field ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unset the variable a test set.
			sc_wpun()->config()->flush();
		}
		$this->assertSame( array(), $this->http_requests );
	}

	/**
	 * A supplied list of headers is cleaned like a typed one, so credentials survive.
	 */
	public function test_supplied_headers_keep_credentials() {
		$this->set_env( 'SC_WPUN_CHANNEL_HOOK__HEADERS', "X-Api-Key: a%2Fb\nAuthorization: Bearer abc<def" );
		$this->receiver_answers( 200 );
		$this->instance();

		$this->assertTrue( $this->send( $this->notification(), sc_wpun_channels()->get( 'hook' ) )->is_success() );

		$headers = $this->request()['args']['headers'];
		$this->assertSame( 'a%2Fb', $headers['X-Api-Key'] );
		$this->assertSame( 'Bearer abc<def', $headers['Authorization'] );
	}

	// No secret anywhere.

	/**
	 * No result, notice, Site Health result or Site Info entry holds the address, the secret or a header value.
	 */
	public function test_no_secret_in_any_output() {
		$this->instance(
			array(
				'signing_secret' => self::SECRET,
				'headers'        => 'Authorization: Bearer SECRET-TKN-789',
			)
		);
		$needles = array( 'SECRET-PATH', 'SECRET-QUERY', 'SECRET-TKN', 'MfKQ9r8', 'hooks.example.org' );
		$answers = array(
			new WP_Error( 'http_request_failed', self::URL . ' Bearer SECRET-TKN-789' ),
			$this->fake_response( 500, 'echo ' . self::URL . ' ' . self::SECRET . ' Bearer SECRET-TKN-789' ),
			$this->fake_response( 429 ),
			$this->fake_response( 302 ),
		);

		foreach ( $answers as $answer ) {
			$this->reset_http();
			$this->receiver_answers( $answer );
			$this->set_updates( null, array( self::PLUGIN => array( 'new_version' => '2.0' ) ) );
			sc_wpun()->update_checker()->do_update_check();

			$result = sc_wpun()->update_checker()->last_results()['hook'];
			$this->assertFalse( $result->is_success() );
			$seen = $result->get_message() . wp_json_encode( $result->get_context() ) . wp_json_encode( $this->stored_state() );

			$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
			foreach ( array( 'sc_wpun_channels', 'sc_wpun_channel_modules', 'sc_wpun_channel_delivery' ) as $name ) {
				$seen .= wp_json_encode( call_user_func( $tests['direct'][ $name ]['test'] ) );
			}
			$seen .= wp_json_encode( apply_filters( 'debug_information', array() ) );
			$seen .= wp_json_encode( $this->stored_state() );

			foreach ( $needles as $needle ) {
				$this->assertStringNotContainsString( $needle, $seen, $needle );
			}
			$state                                 = $this->stored_state();
			$state['channels']['hook']['retry_at'] = time() - 1;
			sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		}
	}

	// updates.resolved.

	/**
	 * One message after the check, with `resolution` and `notified_at`, signed, matching the schema.
	 */
	public function test_a_resolved_message_is_sent_after_the_check() {
		$instance = $this->instance( array( 'signing_secret' => self::SECRET ) );
		$this->report_then_install( $instance );
		$sent = array();
		add_action(
			'sc_wpun_webhook_resolved_sent',
			static function ( $result, $items, $instance ) use ( &$sent ) {
				$sent[] = array( $result, $items, $instance );
			},
			10,
			3
		);

		$this->check_nothing_pending();

		$request = $this->request();
		$this->assert_matches_schema( $request['args']['body'] );
		$this->assert_signed( $request );
		$payload = json_decode( $request['args']['body'], true );
		$this->assertSame( 'updates.resolved', $payload['type'] );
		$this->assertSame( 'cron', $payload['data']['notification']['source'] );
		$this->assertCount( 1, $payload['data']['items'] );
		$item = $payload['data']['items'][0];
		$this->assertSame( self::PLUGIN, $item['key'] );
		$this->assertSame( 'updated', $item['resolution'] );
		$this->assertSame( '2.0', $item['new_version'] );
		$this->assertSame( 'Webhook Fixture', $item['name'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/D', $item['notified_at'] );
		$this->assertArrayNotHasKey( 'download', $item['links'] );

		$this->assertCount( 1, $sent );
		$this->assertTrue( $sent[0][0]->is_success() );
		$this->assertContainsOnlyInstancesOf( Notification_Item::class, $sent[0][1] );
		$this->assertSame( self::PLUGIN, $sent[0][1][0]->get_key() );
		$this->assertSame( 'hook', $sent[0][2]->get_id() );

		$this->check_nothing_pending();
		$this->assertCount( 1, $this->received(), 'Not again.' );
	}

	/**
	 * Several resolved items go in one message, and a removed plugin says so; an entry without details has no time.
	 */
	public function test_resolved_items_share_one_message() {
		$instance = $this->instance();
		$this->report_then_install( $instance );
		$state = $this->stored_state();
		$state['channels']['hook']['notified']['plugin']['gone/gone.php'] = '3.0';
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );

		$this->check_nothing_pending();

		$payload = $this->payload();
		$this->assertCount( 2, $payload['data']['items'] );
		$by_key = array_column( $payload['data']['items'], null, 'key' );
		$this->assertSame( 'updated', $by_key[ self::PLUGIN ]['resolution'] );
		$this->assertSame( 'removed', $by_key['gone/gone.php']['resolution'] );
		$this->assertNull( $by_key['gone/gone.php']['notified_at'] );
		$this->assert_matches_schema( $this->request()['args']['body'] );
	}

	/**
	 * Nothing is sent when nothing was resolved, when it is switched off per instance, or for a switched-off or waiting instance.
	 */
	public function test_resolved_messages_are_not_sent_when_they_should_not() {
		// Nothing resolved.
		$this->instance();
		$this->receiver_answers( 200 );
		$this->check_nothing_pending();
		$this->assertCount( 0, $this->received() );

		// Switched off for the instance.
		$this->report_then_install( $this->instance( array( 'send_resolved' => false ) ) );
		$this->check_nothing_pending();
		$this->assertCount( 0, $this->received(), 'send_resolved is off.' );
	}

	/**
	 * A switched-off instance and a waiting one get no resolved message, though the hook fires for them.
	 */
	public function test_resolved_messages_skip_off_and_waiting_instances() {
		$this->instance();
		$this->report_then_install( sc_wpun_channels()->get( 'hook' ) );
		sc_wpun_channels()->save( new Channel_Instance( 'hook', 'webhook', 'Hook', false, array( 'url' => self::URL ) ) );
		$this->check_nothing_pending();
		$this->assertCount( 0, $this->received(), 'Switched off.' );

		$this->write_plugin( '1.0' );
		$this->instance();
		$this->reset_http();
		$this->report_then_install( sc_wpun_channels()->get( 'hook' ) );
		$state                                 = $this->stored_state();
		$state['channels']['hook']['retry_at'] = time() + 3600;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		$this->assertNotNull( sc_wpun_instance_waiting_until( 'hook' ) );
		$this->check_nothing_pending();
		$this->assertCount( 0, $this->received(), 'Waiting.' );
	}

	/**
	 * Like the dispatcher, nothing goes out for an instance with an invalid supplied value (the stored address would be used)
	 * or whose module is no longer registered.
	 */
	public function test_resolved_messages_skip_instances_with_problems() {
		$this->instance();
		$this->report_then_install( sc_wpun_channels()->get( 'hook' ) );
		$this->set_env( 'SC_WPUN_CHANNEL_HOOK__URL', 'http://hooks.example.org/new' );
		sc_wpun()->config()->flush();
		$this->assertSame( array( 'misconfigured' ), sc_wpun_instance_problems( 'hook' ) );
		$this->check_nothing_pending();
		$this->assertCount( 0, $this->received(), 'Misconfigured: the stored address is not used.' );
		putenv( 'SC_WPUN_CHANNEL_HOOK__URL' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Unset the variable a test set.
		sc_wpun()->config()->flush();

		$this->write_plugin( '1.0' );
		$this->instance();
		$this->reset_http();
		$this->report_then_install( sc_wpun_channels()->get( 'hook' ) );
		$this->assertSame( array(), sc_wpun_instance_problems( 'hook' ) );
		sc_wpun()->channels()->unregister( 'webhook' );
		$this->assertSame( array( 'unavailable' ), sc_wpun_instance_problems( 'hook' ) );
		$this->check_nothing_pending();
		$this->assertCount( 0, $this->received(), 'Unregistered.' );
	}

	/**
	 * The helper has nothing to say about an unknown instance.
	 */
	public function test_instance_problems_of_an_unknown_instance() {
		$this->assertSame( array(), sc_wpun_instance_problems( 'nope' ) );
	}

	/**
	 * Only webhook instances are served: the item reported to an email instance sends nothing.
	 */
	public function test_resolved_messages_are_for_webhook_instances_only() {
		$this->instance( array(), 'hook', false );
		sc_wpun_channels()->save( new Channel_Instance( 'mail', 'email', 'Mail', true, array( 'to' => 'ops@example.org' ) ) );
		$this->receiver_answers( 200 );
		$this->check_with_update();
		$this->write_plugin( '2.0' );
		reset_phpmailer_instance();

		$this->check_nothing_pending();

		$this->assertCount( 0, $this->received() );
	}

	/**
	 * Each webhook instance gets its own message with only its items.
	 */
	public function test_each_instance_gets_its_own_resolved_message() {
		$this->instance( array( 'url' => 'https://hooks.example.org/one' ), 'hook' );
		$this->instance( array( 'url' => 'https://hooks.example.org/two' ), 'hook_two' );
		$this->receiver_answers( 200 );
		$this->check_with_update();
		$this->assertCount( 2, $this->received() );
		$this->reset_http();
		$this->receiver_answers( 200 );
		$this->write_plugin( '2.0' );

		$this->check_nothing_pending();

		$urls = array_map( static fn( $request ) => $request['url'], $this->received() );
		sort( $urls );
		$this->assertSame( array( 'https://hooks.example.org/one', 'https://hooks.example.org/two' ), $urls );
	}

	/**
	 * Nothing is sent in a dry run, and a test send never sends a resolved message.
	 */
	public function test_no_resolved_message_in_a_dry_run_or_a_test() {
		$instance = $this->instance();
		$this->report_then_install( $instance );
		$this->set_updates();

		sc_wpun()->update_checker()->run_check( 'cli', true );
		$this->assertCount( 0, $this->received(), 'A dry run sends nothing.' );

		sc_wpun()->dispatcher()->send_test( $instance );
		$this->assertCount( 1, $this->received() );
		$this->assertSame( 'updates.test', $this->payload()['type'] );

		$this->reset_http();
		$this->receiver_answers( 200 );
		sc_wpun()->update_checker()->run_check( 'cli' );
		$this->assertSame( 'updates.resolved', $this->payload()['type'], 'The real run after it sends it.' );
	}

	/**
	 * A failed resolved message is not retried, is reported to the action, and shows no secret.
	 */
	public function test_a_failed_resolved_message_is_not_retried() {
		$instance = $this->instance( array( 'headers' => 'X-Api-Key: SECRET-VALUE-1' ) );
		$this->report_then_install( $instance );
		$this->reset_http();
		$this->receiver_answers( 500, array(), 'echo SECRET-VALUE-1' );
		$sent = array();
		add_action(
			'sc_wpun_webhook_resolved_sent',
			static function ( $result ) use ( &$sent ) {
				$sent[] = $result;
			}
		);

		$this->check_nothing_pending();

		$this->assertCount( 1, $this->received() );
		$this->assertCount( 1, $sent );
		$this->assertFalse( $sent[0]->is_success() );
		$this->assertSame( 500, $sent[0]->get_status() );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $sent[0]->get_context() ) . $sent[0]->get_message() );
		$this->assertNull( sc_wpun_instance_waiting_until( 'hook' ), 'Not deferred either.' );

		$this->check_nothing_pending();
		$this->check_nothing_pending();
		$this->assertCount( 1, $this->received(), 'Lost, not retried.' );
	}

	/**
	 * A 429 for a resolved message does not defer the instance: it is a courtesy.
	 */
	public function test_a_rate_limited_resolved_message_defers_nothing() {
		$this->report_then_install( $this->instance() );
		$this->reset_http();
		$this->receiver_answers( 429, array( 'retry-after' => '600' ) );
		$sent = array();
		add_action(
			'sc_wpun_webhook_resolved_sent',
			static function ( $result ) use ( &$sent ) {
				$sent[] = $result;
			}
		);

		$this->check_nothing_pending();

		$this->assertSame( 600, $sent[0]->get_retry_after() );
		$this->assertNull( sc_wpun_instance_waiting_until( 'hook' ) );
	}

	/**
	 * The filters see the notification of the check, copied with the resolved items, and can change what is sent.
	 */
	public function test_the_resolved_message_goes_through_the_filters() {
		$this->report_then_install( $this->instance( array( 'signing_secret' => self::SECRET ) ) );
		$seen = array();
		add_filter(
			'sc_wpun_channel_payload_webhook',
			static function ( $payload, $notification ) use ( &$seen ) {
				$seen['payload']             = $notification;
				$payload['data']['filtered'] = true;
				return $payload;
			},
			10,
			2
		);
		add_filter(
			'sc_wpun_webhook_headers',
			static function ( $headers, $notification ) use ( &$seen ) {
				$seen['headers']   = $notification;
				$headers['X-Site'] = 'production';
				return $headers;
			},
			10,
			2
		);

		$this->check_nothing_pending();

		$this->assertInstanceOf( Notification::class, $seen['payload'] );
		$this->assertSame( self::PLUGIN, $seen['payload']->get_items()[0]->get_key() );
		$this->assertSame( $seen['payload']->get_source(), $seen['headers']->get_source() );
		$this->assertTrue( $this->payload()['data']['filtered'] );
		$this->assertSame( 'production', $this->request()['args']['headers']['X-Site'] );
		$this->assert_signed( $this->request() );
	}

	/**
	 * What cannot be sent is reported to the action as a failure, and an exception cannot break the check.
	 */
	public function test_the_resolved_message_survives_problems() {
		$this->report_then_install( $this->instance() );
		add_filter(
			'sc_wpun_channel_payload_webhook',
			static function () {
				throw new RuntimeException( 'Boom SECRET-PATH-123' );
			}
		);
		$sent = array();
		add_action(
			'sc_wpun_webhook_resolved_sent',
			static function ( $result ) use ( &$sent ) {
				$sent[] = $result;
			}
		);

		$this->check_nothing_pending();

		$this->assertCount( 1, $sent );
		$this->assertSame( 'exception', $sent[0]->get_code() );
		$this->assertSame( array( 'exception' => 'RuntimeException' ), $sent[0]->get_context() );
		$this->assertStringNotContainsString( 'SECRET', $sent[0]->get_message() );
		$this->assertCount( 0, $this->received() );
	}

	/**
	 * What was collected is forgotten after every check, and listeners given the wrong things do nothing.
	 */
	public function test_the_listeners_are_forgiving_and_clear_the_buffer() {
		$instance = $this->instance();
		$item     = new Notification_Item(
			array(
				'type' => 'plugin',
				'key'  => 'a/a.php',
			)
		);
		$this->receiver_answers( 200 );

		// Collected, then a check that is not given a notification: nothing is sent, and the items are dropped.
		Webhook_Channel::collect_resolved( $item, 'updated', $instance );
		Webhook_Channel::send_resolved( null );
		Webhook_Channel::send_resolved( $this->notification( 0 ) );
		$this->assertCount( 0, $this->received() );

		// An instance of another channel is not collected.
		Webhook_Channel::collect_resolved( $item, 'updated', new Channel_Instance( 'mail', 'email', 'Mail', true ) );
		Webhook_Channel::send_resolved( $this->notification( 0 ) );
		$this->assertCount( 0, $this->received() );

		// The module's own listeners ignore what is not an item or an instance.
		do_action( 'sc_wpun_item_resolved', 'not an item', 'updated', $instance );
		do_action( 'sc_wpun_item_resolved', $item, 7, $instance );
		do_action( 'sc_wpun_item_resolved', $item, 'updated', 'not an instance' );
		do_action( 'sc_wpun_after_update_check', 'not a notification', array() );
		$this->assertCount( 0, $this->received() );
		$this->assertSame( 1, count( array_filter( $GLOBALS['wp_filter']['sc_wpun_item_resolved']->callbacks[10] ?? array(), static fn( $callback ) => $callback['function'] instanceof Closure && str_ends_with( (string) ( new ReflectionFunction( $callback['function'] ) )->getFileName(), '/modules/webhook/webhook.php' ) ) ), 'Added once, at load.' );
	}

	/**
	 * Building the registry again does not add the listeners again.
	 */
	public function test_a_new_registry_adds_no_listeners() {
		$before = $GLOBALS['wp_filter']['sc_wpun_after_update_check']->callbacks ?? array();

		new WPUN\Channel_Registry();
		new WPUN\Channel_Registry();

		$this->assertEquals( $before, $GLOBALS['wp_filter']['sc_wpun_after_update_check']->callbacks ?? array() );
	}

	/**
	 * Nothing is waiting for a webhook that stays a hidden setting: `send_resolved` is on by default.
	 */
	public function test_resolved_is_on_by_default() {
		$instance = $this->instance();

		$this->assertNull( $instance->get_setting( 'send_resolved' ) );
		$this->assertTrue( ( new Webhook_Channel() )->get_fields()['send_resolved']['default'] );
		$this->report_then_install( $instance );

		$this->check_nothing_pending();

		$this->assertSame( 'updates.resolved', $this->payload()['type'] );
	}
}
