<?php
/**
 * A secret never leaves the places meant for it, whichever way it was set (#220).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channel_Form;
use WPUN\Admin\Channels_Tab;
use WPUN\Admin\Check_Now;
use WPUN\Channel_Instance;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * A secret field set through the form, a `SC_WPUN_CHANNEL_<ID>__<FIELD>` constant and an environment variable
 * (with environment loading on) is looked for in everything an administrator, a log or a support request could see.
 * Where it is stored for sending (the database for the form, the channel instance in memory) is not one of those places.
 *
 * Constants can not be undefined, so each route has an instance id of its own that no other test uses.
 *
 * @group security
 */
class Test_Security_Secrets extends WPUN_Security_Test_Case {

	const TOKEN   = 'ok-SECRETTOKEN-7f3c1a';
	const WEBHOOK = 'https://hooks.example.org/SECRETPATH-91ab';

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Messages of `_doing_it_wrong()` during the test.
	 *
	 * @var string[]
	 */
	private $incorrect_usage = array();

	/**
	 * Messages of notices and warnings the plugin raised during the test.
	 *
	 * @var string[]
	 */
	private $raised = array();

	/**
	 * The PHP error log of the test.
	 *
	 * @var string
	 */
	private $log_file = '';

	/**
	 * The error log setting before the test.
	 *
	 * @var string|false
	 */
	private $old_log = false;

	/**
	 * Create the user.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * An administrator, the module with secret fields, and a log to read.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->register_admin_hooks();
		$this->save_settings();
		wp_set_current_user( self::$admin_id );
		sc_wpun()->channels()->register( new Test_Security_Secret_Channel() );
		sc_wpun()->config()->flush();
		$this->fake_http( 'wordpress.org', $this->fake_response( 200, '' ) ); // Site Health reaches for WordPress.org.

		$this->incorrect_usage = array();
		add_action(
			'doing_it_wrong_run',
			function ( $function_name, $message ) {
				$this->incorrect_usage[] = (string) $message;
			},
			10,
			2
		);

		// With WP_DEBUG on, the dispatcher reports a channel that threw with `wp_trigger_error()`.
		$this->raised = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Reads what the plugin raises.
			function ( $number, $message ) {
				$this->raised[] = (string) $message;
				return true;
			},
			E_USER_NOTICE | E_USER_WARNING | E_USER_DEPRECATED
		);

		$this->log_file = (string) tempnam( get_temp_dir(), 'wpun-log' );
		$this->old_log  = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Reads what the plugin logs.
	}

	/**
	 * Remove the module, the environment variables and the log.
	 */
	public function tear_down() {
		restore_error_handler();
		ini_set( 'error_log', (string) $this->old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Put back.
		if ( file_exists( $this->log_file ) ) {
			unlink( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A temporary file.
		}
		putenv( 'SC_WPUN_LOAD_FROM_ENV' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Put back.
		putenv( 'SC_WPUN_CHANNEL_SEC_ENV__TOKEN' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The environment route is under test.
		putenv( 'SC_WPUN_CHANNEL_SEC_ENV__WEBHOOK' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The environment route is under test.
		sc_wpun()->channels()->unregister( 'security_secret' );
		sc_wpun()->config()->flush();
		$_POST = array();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'], $_GET['type'] );
		parent::tear_down();
	}

	/**
	 * Define a constant, once per process.
	 *
	 * @param string $name  Name.
	 * @param string $value Value.
	 */
	private function define_once( string $name, string $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- A test constant, named as the plugin reads it.
		}
	}

	/**
	 * Store an instance that has the secret fields left empty, to be supplied from outside.
	 *
	 * @param string $id Instance id.
	 */
	private function store_instance( string $id ) {
		sc_wpun_channels()->save( new Channel_Instance( $id, 'security_secret', 'Secret ' . $id, true, array( 'explode' => true ) ) );
	}

	/**
	 * Set the secrets through a route and return the id of the instance that has them.
	 *
	 * @param string $route `form`, `constant`, `env`, `constant_invalid` or `constant_throwing`.
	 * @return string[] The instance id and the secret values it holds.
	 */
	private function provide( string $route ): array {
		switch ( $route ) {
			case 'form':
				$_POST = array(
					'action'   => Channel_Form::SAVE_ACTION,
					'mode'     => 'new',
					'type'     => 'security_secret',
					'label'    => 'Secform',
					'enabled'  => '1',
					'routing'  => array(
						'scope' => 'all',
						'core'  => 'all',
						'types' => array( '' ),
					),
					'settings' => array(
						'token'   => self::TOKEN,
						'webhook' => self::WEBHOOK,
						'explode' => '1',
					),
					'_wpnonce' => wp_create_nonce( Channel_Form::SAVE_ACTION . '_new_security_secret' ),
				);
				$this->assertNotNull( sc_wpun()->channel_form()->process_save() );
				$this->assertNotNull( sc_wpun_channels()->get( 'secform' ), 'The form did not save the channel.' );
				$this->assertSame( self::TOKEN, sc_wpun_channels()->get( 'secform' )->get_setting( 'token' ) );

				return array( 'secform', self::TOKEN, self::WEBHOOK );

			case 'constant':
				$this->define_once( 'SC_WPUN_CHANNEL_SEC_CONST__TOKEN', self::TOKEN );
				$this->define_once( 'SC_WPUN_CHANNEL_SEC_CONST__WEBHOOK', self::WEBHOOK );
				$this->store_instance( 'sec_const' );
				sc_wpun()->config()->flush();
				$this->assertSame( self::TOKEN, sc_wpun_channels()->get( 'sec_const' )->get_setting( 'token' ) );
				$this->assertSame( '', (string) sc_wpun_channels()->stored_all()['sec_const']->get_setting( 'token', '' ), 'The constant was written to the database.' );

				return array( 'sec_const', self::TOKEN, self::WEBHOOK );

			case 'env':
				putenv( 'SC_WPUN_CHANNEL_SEC_ENV__TOKEN=' . self::TOKEN ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The environment route is under test.
				putenv( 'SC_WPUN_CHANNEL_SEC_ENV__WEBHOOK=' . self::WEBHOOK ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The environment route is under test.
				putenv( 'SC_WPUN_LOAD_FROM_ENV=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The switch a site owner uses.
				$this->store_instance( 'sec_env' );
				sc_wpun()->config()->flush();
				$this->assertSame( self::TOKEN, sc_wpun_channels()->get( 'sec_env' )->get_setting( 'token' ) );
				$this->assertSame( '', (string) sc_wpun_channels()->stored_all()['sec_env']->get_setting( 'token', '' ), 'The variable was written to the database.' );

				return array( 'sec_env', self::TOKEN, self::WEBHOOK );

			case 'constant_invalid':
				// The module only accepts a token that starts with "ok-".
				$this->define_once( 'SC_WPUN_CHANNEL_SEC_BAD__TOKEN', 'bad-SECRETTOKEN-2d8e' );
				$this->store_instance( 'sec_bad' );
				sc_wpun()->config()->flush();

				return array( 'sec_bad', 'bad-SECRETTOKEN-2d8e' );

			default:
				// The module's validation throws an exception that contains the token.
				$this->define_once( 'SC_WPUN_CHANNEL_SEC_THROW__TOKEN', 'throw-SECRETTOKEN-5b09' );
				$this->store_instance( 'sec_throw' );
				sc_wpun()->config()->flush();

				return array( 'sec_throw', 'throw-SECRETTOKEN-5b09' );
		}
	}

	/**
	 * Routes as data sets.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function routes() {
		return array(
			'form'                => array( 'form' ),
			'constant'            => array( 'constant' ),
			'environment'         => array( 'env' ),
			'invalid constant'    => array( 'constant_invalid' ),
			'throwing validation' => array( 'constant_throwing' ),
		);
	}

	/**
	 * Draw a tab or a form.
	 *
	 * @param array $get Query arguments.
	 * @return string
	 */
	private function page( array $get ): string {
		$_GET = $get;

		return $this->capture( array( sc_wpun()->settings_page(), 'settings_page' ) );
	}

	/**
	 * Everything the secret could show up in after the instance sent, was tested and was checked.
	 *
	 * @param string $id Instance id.
	 * @return array<string, string> Text by surface.
	 */
	private function surfaces( string $id ): array {
		$surfaces = array();

		// A delivery with a pending update: the channel throws an exception with its token in the message.
		delete_option( 'sc_wpun_state' );
		$this->set_updates( '99.0.0' );
		sc_wpun()->update_checker()->do_update_check();
		$results = array();
		foreach ( sc_wpun()->update_checker()->last_results() as $result_id => $result ) {
			$results[ $result_id ] = array( $result->get_message(), $result->get_code(), $result->get_context() );
		}
		$surfaces['delivery results']          = (string) wp_json_encode( $results );
		$surfaces['stored state']              = (string) wp_json_encode( get_option( 'sc_wpun_state' ) );
		$surfaces['all options of the plugin'] = (string) wp_json_encode( array( get_option( 'sc_wpun_state' ), get_option( 'sc_wpun_settings' ) ) );

		// "Check now" and a test message: the notices the administrator is shown.
		$_POST = array( '_wpnonce' => wp_create_nonce( Check_Now::ACTION ) );
		sc_wpun()->check_now()->process();
		$notices = array( sc_wpun()->notices()->take() );

		$_POST = array(
			'id'       => $id,
			'_wpnonce' => wp_create_nonce( Channels_Tab::TEST_ACTION . '_' . $id ),
		);
		sc_wpun()->channels_tab()->process_test_send();
		$notices[]           = sc_wpun()->notices()->take();
		$surfaces['notices'] = (string) wp_json_encode( $notices );
		$_POST               = array();

		// Rendered settings.
		$surfaces['channels tab']    = $this->page( array( 'tab' => 'channels' ) );
		$surfaces['edit form']       = $this->page(
			array(
				'tab'    => 'channels',
				'action' => 'edit',
				'id'     => $id,
			)
		);
		$surfaces['delete form']     = $this->page(
			array(
				'tab'    => 'channels',
				'action' => 'delete',
				'id'     => $id,
			)
		);
		$surfaces['general tab']     = $this->page( array( 'tab' => 'general' ) );
		$surfaces['rendered notice'] = $this->capture( array( sc_wpun()->notices(), 'render' ) );

		// Site Health: the tests the plugin adds, and the debug data.
		$health = array();
		$tests  = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		foreach ( $tests['direct'] as $name => $test ) {
			$health[ $name ] = call_user_func( $test['test'] );
		}
		$surfaces['site health tests'] = (string) wp_json_encode( $health );
		require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		$surfaces['debug data'] = (string) wp_json_encode( WP_Debug_Data::debug_data() );

		// The instance when it is dumped.
		$instance = sc_wpun_channels()->get( $id );
		if ( null !== $instance ) {
			$surfaces['print_r of the instance'] = print_r( $instance, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Looks for a leak.
			ob_start();
			var_dump( $instance ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- Looks for a leak.
			$surfaces['var_dump of the instance'] = (string) ob_get_clean();
		}
		$surfaces['problems']        = (string) wp_json_encode( sc_wpun()->config()->problems() );
		$surfaces['incorrect usage'] = implode( "\n", $this->incorrect_usage );
		$surfaces['raised notices']  = implode( "\n", $this->raised );
		$surfaces['error log']       = (string) file_get_contents( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.

		return $surfaces;
	}

	/**
	 * Whether a text holds a secret in any of the ways a page, JSON or a URL would write it.
	 *
	 * @param string $text   The text.
	 * @param string $secret The secret.
	 * @return bool
	 */
	private function holds( string $text, string $secret ): bool {
		foreach ( array( $secret, esc_attr( $secret ), esc_html( $secret ), rawurlencode( $secret ), (string) wp_json_encode( $secret ), addcslashes( $secret, '/' ) ) as $form ) {
			$form = trim( $form, '"' );
			if ( '' !== $form && false !== strpos( $text, $form ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The secret is on none of the surfaces, and the form shows a placeholder where one is saved.
	 *
	 * @dataProvider routes
	 *
	 * @param string $route How the secret is set.
	 */
	public function test_a_secret_never_shows( $route ) {
		$provided = $this->provide( $route );
		$id       = array_shift( $provided );

		$surfaces = $this->surfaces( $id );

		foreach ( $surfaces as $surface => $text ) {
			foreach ( $provided as $secret ) {
				$this->assertFalse( $this->holds( $text, $secret ), "The secret of the $route route is in the $surface." );
				// The distinctive part of every secret, in case a surface cuts or wraps the value.
				$this->assertStringNotContainsString( 'SECRETTOKEN', $text, "A secret of the $route route is in the $surface." );
				$this->assertStringNotContainsString( 'SECRETPATH', $text, "A secret of the $route route is in the $surface." );
			}
		}

		// The surfaces are not empty: the channel sent and failed, or its value was reported as unusable.
		if ( in_array( $route, array( 'form', 'constant', 'env' ), true ) ) {
			$this->assertStringContainsString( $id, $surfaces['delivery results'], 'The channel did not send.' );
			$this->assertStringContainsString( 'last_result', $surfaces['stored state'], 'The failure was not recorded.' );
			$this->assertStringContainsString( 'failed with RuntimeException', $surfaces['raised notices'], 'The channel did not throw.' );
		} else {
			$this->assertStringContainsString( 'SC_WPUN_CHANNEL_', $surfaces['problems'], 'The unusable value was not reported.' );
			$this->assertStringContainsString( 'sc_wpun_config', $surfaces['site health tests'], 'Site Health does not report it.' );
		}
		$this->assertNotSame( '', $surfaces['edit form'], 'The edit form was not drawn.' );
		$this->assertStringContainsString( 'sc-wpun-channel', $surfaces['channels tab'], 'The list was not drawn.' );
	}

	/**
	 * The form route shows the placeholder, not the value; the outside routes show where the value comes from.
	 */
	public function test_the_edit_form_shows_a_placeholder_or_the_source() {
		$form  = $this->provide( 'form' );
		$page  = $this->page(
			array(
				'tab'    => 'channels',
				'action' => 'edit',
				'id'     => $form[0],
			)
		);
		$xpath = $this->parse( $page );
		$this->assertSame( 1, $xpath->query( '//input[@name="settings[token]"][@type="password"][@value=""][contains(@placeholder,"saved")]' )->length, 'The token field is not a masked, empty password input.' );
		$this->assertSame( 0, $xpath->query( '//*[contains(@value,"SECRET")]' )->length );

		$constant = $this->provide( 'constant' );
		$page     = $this->page(
			array(
				'tab'    => 'channels',
				'action' => 'edit',
				'id'     => $constant[0],
			)
		);
		$this->assertStringContainsString( 'SC_WPUN_CHANNEL_SEC_CONST__TOKEN', $page, 'The page does not say where the value comes from.' );
		$this->assertStringNotContainsString( 'SECRETTOKEN', $page );
	}

	/**
	 * A secret supplied from outside is never written to the database by the actions on the list.
	 */
	public function test_duplicating_and_toggling_do_not_store_an_outside_secret() {
		$constant = $this->provide( 'constant' );
		$id       = $constant[0];

		foreach ( array(
			Channels_Tab::DUPLICATE_ACTION => 'process_duplicate',
			Channels_Tab::TOGGLE_ACTION    => 'process_toggle',
		) as $action => $method ) {
			$_POST = array(
				'id'       => $id,
				'_wpnonce' => wp_create_nonce( $action . '_' . $id ),
			);
			$this->assertTrue( sc_wpun()->channels_tab()->$method() );
		}

		$this->assertStringNotContainsString( 'SECRETTOKEN', (string) wp_json_encode( get_option( 'sc_wpun_channels' ) ) );
		$this->assertStringNotContainsString( 'SECRETPATH', (string) wp_json_encode( get_option( 'sc_wpun_channels' ) ) );
	}

	/**
	 * A code-defined instance that holds a secret: the refused save and its `_doing_it_wrong()` message keep it out.
	 */
	public function test_the_messages_of_refused_saves_do_not_hold_a_secret() {
		$this->setExpectedIncorrectUsage( 'WPUN\Channel_Store::save' );
		add_filter(
			'sc_wpun_channels',
			static function ( $channels ) {
				$channels[] = array(
					'id'       => 'sec_code',
					'type'     => 'security_secret',
					'label'    => 'In code',
					'enabled'  => true,
					'settings' => array(
						'token' => Test_Security_Secrets::TOKEN,
					),
				);
				return $channels;
			}
		);
		sc_wpun()->config()->flush();

		$this->assertFalse( sc_wpun_channels()->save( new Channel_Instance( 'sec_code', 'security_secret', 'In code', true, array( 'token' => self::TOKEN ) ) ) );

		$this->assertNotEmpty( $this->incorrect_usage, 'The refused save was not reported.' );
		$this->assertStringNotContainsString( 'SECRETTOKEN', implode( "\n", $this->incorrect_usage ) );
		$this->assertStringNotContainsString( 'SECRETTOKEN', $this->page( array( 'tab' => 'channels' ) ) );
	}
}

/**
 * A module with secret fields that supports test messages.
 */
class Test_Security_Secret_Channel extends Test_Fields_Channel {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'security_secret',
			array(
				'token'   => array(
					'type'   => 'secret',
					'secret' => true,
				),
				'webhook' => array(
					'type'   => 'url',
					'secret' => true,
				),
				'explode' => array( 'type' => 'checkbox' ),
			)
		);
	}

	/**
	 * Supports test messages.
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return 'test_message' === $feature;
	}
}
