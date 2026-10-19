<?php
/**
 * Hostile update data never becomes live markup in mail or in the admin (#220).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Config_Context;
use WPUN\Config_Source;
use WPUN\Delivery_Result;
use WPUN\Notification;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * Plugin and theme names, versions, URLs and compatibility data come from third-party plugin headers and from
 * the update transients (api.wordpress.org and updaters of other plugins). The payloads travel the way real data
 * does: a plugin file with a hostile header on disk, and the same transients the update-check tests set.
 *
 * Plain text is sent as `text/plain` and is not HTML, so a literal `<` in it is correct. What must not happen is
 * markup the plugin adds itself, or HTML encoding of the values (#65).
 *
 * @group security
 */
class Test_Security_Stored_Xss extends WPUN_Security_Test_Case {

	const PLUGIN_FILE = 'wpun-xss-plugin/wpun-xss-plugin.php';
	const THEME_DIR   = 'wpun-xss-theme';

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
	 * A site with the admin hooks, one email channel and no history.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->register_admin_hooks();
		sc_wpun()->config()->flush();
		$this->save_settings();
		delete_option( 'sc_wpun_state' );
		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Remove the hostile plugin.
	 */
	public function tear_down() {
		$this->remove_plugin();
		$this->remove_theme();
		// The registry outlives a test and remembers a refused module: a module that is accepted takes the entry away.
		sc_wpun()->channels()->unregister( 'security_failing' );
		sc_wpun()->channels()->register( new Test_Security_Failing_Channel( '' ) );
		sc_wpun()->channels()->unregister( 'security_failing' );
		sc_wpun()->config()->flush();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'], $_GET['type'] );
		parent::tear_down();
	}

	/**
	 * Remove the plugin file written by a test.
	 */
	private function remove_plugin() {
		$file = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
		if ( file_exists( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A fixture.
			rmdir( dirname( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A fixture.
		}
		wp_clean_plugins_cache();
	}

	/**
	 * The directory of the hostile theme: a directory of its own, as the themes directory is not writable.
	 *
	 * @return string
	 */
	private function theme_root(): string {
		return trailingslashit( get_temp_dir() ) . 'wpun-xss-themes';
	}

	/**
	 * Remove the theme written by a test.
	 */
	private function remove_theme() {
		$dir = $this->theme_root() . '/' . self::THEME_DIR;
		if ( is_dir( $dir ) ) {
			unlink( $dir . '/style.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A fixture.
			unlink( $dir . '/index.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A fixture.
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A fixture.
			rmdir( $this->theme_root() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A fixture.
		}
		$GLOBALS['wp_theme_directories'] = array_values( array_diff( (array) $GLOBALS['wp_theme_directories'], array( $this->theme_root() ) ) );
		wp_clean_themes_cache();
	}

	/**
	 * Write a theme whose header fields are hostile and make WordPress look for themes there.
	 *
	 * @param string $payload Value for every header field.
	 */
	private function write_theme( string $payload ) {
		$dir = $this->theme_root() . '/' . self::THEME_DIR;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- A fixture.
		}
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixture.
			$dir . '/style.css',
			"/*\nTheme Name: {$payload}\nTheme URI: {$payload}\nVersion: {$payload}\nAuthor: {$payload}\nAuthor URI: {$payload}\n*/\n"
		);
		file_put_contents( $dir . '/index.php', "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixture. A theme needs a template.
		register_theme_directory( $this->theme_root() );
		wp_clean_themes_cache();
		$this->assertArrayHasKey( self::THEME_DIR, wp_get_themes(), 'WordPress does not see the hostile theme.' );
	}

	/**
	 * Write a plugin whose header fields are hostile.
	 *
	 * @param string $payload Value for every header field.
	 */
	private function write_plugin( string $payload ) {
		$dir = WP_PLUGIN_DIR . '/' . dirname( self::PLUGIN_FILE );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- A fixture.
		}
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixture.
			WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE,
			"<?php\n/*\nPlugin Name: {$payload}\nPlugin URI: {$payload}\nVersion: {$payload}\nAuthor: {$payload}\nAuthor URI: {$payload}\n*/\n"
		);
		wp_clean_plugins_cache();
	}

	/**
	 * Offer updates for the hostile plugin, a hostile theme and core, all with the payload in every field.
	 *
	 * @param string $payload The payload.
	 */
	private function offer_updates( string $payload ) {
		$this->write_plugin( $payload );
		$this->write_theme( $payload );
		$this->set_updates(
			'99.0.0-' . $payload,
			array(
				self::PLUGIN_FILE => array(
					'new_version'  => $payload,
					'url'          => $payload,
					'id'           => '',
					'slug'         => $payload,
					'tested'       => $payload,
					'requires_php' => '99.0' . $payload,
					'requires'     => '99.0' . $payload,
				),
			),
			array(
				self::THEME_DIR => array(
					'new_version' => $payload,
					'url'         => $payload,
				),
			)
		);
	}

	/**
	 * Run the check with the email channel in a format and return the mail.
	 *
	 * @param string $format `html` or `text`.
	 * @return object The mail.
	 */
	private function mail_in( string $format ) {
		delete_option( 'sc_wpun_state' );
		reset_phpmailer_instance();
		$this->save_settings( array( 'email_format' => $format ) );
		sc_wpun()->config()->flush();
		sc_wpun()->update_checker()->do_update_check();

		$mail = $this->sent_mail();
		$this->assertNotFalse( $mail, 'No mail was sent.' );

		return $mail;
	}

	/**
	 * The HTML email has no live markup from names, versions, URLs or compatibility data.
	 *
	 * @dataProvider payloads_provider
	 *
	 * @param string $payload The payload.
	 */
	public function test_the_html_email_has_no_live_markup( $payload ) {
		$this->offer_updates( $payload );
		$mail = $this->mail_in( 'html' );

		$this->assertSame( 'text/html', tests_retrieve_phpmailer_instance()->ContentType );
		$this->assertStringContainsString( self::MARKER, $mail->body, 'The hostile plugin is not in the mail.' );
		$this->assert_no_live_markup( $mail->body, 'HTML email' );
	}

	/**
	 * The plain text email is sent as text/plain, adds no markup of its own and does not HTML-encode values.
	 *
	 * @dataProvider payloads_provider
	 *
	 * @param string $payload The payload.
	 */
	public function test_the_plain_text_email_is_text_and_not_double_encoded( $payload ) {
		$this->offer_updates( $payload );
		$mail = $this->mail_in( 'text' );

		$this->assertSame( 'text/plain', tests_retrieve_phpmailer_instance()->ContentType );
		$this->assertStringContainsString( self::MARKER, $mail->body, 'The hostile plugin is not in the mail.' );

		// Values stay as given: HTML encoding would turn their quotes into entities, and encoding again would turn `&amp;` into `&amp;amp;`.
		// WordPress itself cleans a theme's header, which can leave `&gt;`, so only quotes and `&amp;amp;` are looked for.
		$this->assertDoesNotMatchRegularExpression( '/&(amp;)+(amp|lt|gt|quot|#0?39|#\d+);/', $mail->body, 'Double-encoded entities.' );
		if ( false === strpos( $payload, '&' ) ) {
			$this->assertDoesNotMatchRegularExpression( '/&(quot|#0?39);/', $mail->body, 'Values were HTML-encoded.' );
		}

		// The plugin's own lines carry no markup: take the payload out and no tag is left.
		$flat = trim( preg_replace( '/[\x00-\x20\x7f]+/', ' ', $payload ) );
		$rest = str_replace( array( $payload, $flat ), '', $mail->body );
		$this->assertStringNotContainsString( '<', $rest, 'The plugin added markup to the plain text email.' );
	}

	/**
	 * Entities in a name are text: in plain text they stay exactly as given (once), in HTML they stay inert.
	 */
	public function test_entities_are_not_decoded_or_encoded_again() {
		$payload = self::payloads()['entities'];
		$this->offer_updates( $payload );

		$text = $this->mail_in( 'text' );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &#60;b&#62; wpun_xss', $text->body );
		$this->assertStringNotContainsString( '&amp;lt;', $text->body );

		$html = $this->mail_in( 'html' );
		$this->assert_no_live_markup( $html->body, 'HTML email' );
		$this->assertStringNotContainsString( '<script', $html->body );
	}

	/**
	 * A right-to-left override is not markup: the list around it stays intact. Whether the character is kept is a
	 * display question (it can make a name read backwards), not an injection, so it is not asserted either way.
	 */
	public function test_a_right_to_left_override_does_not_break_the_html() {
		$payload = self::payloads()['rtl_override'];
		$this->offer_updates( $payload );
		$mail = $this->mail_in( 'html' );

		$this->assert_no_live_markup( $mail->body, 'HTML email' );
		$this->assertGreaterThan( 1, $this->parse( $mail->body )->query( '//li' )->length, 'The list of updates is damaged.' );
	}

	/**
	 * The plugin adds nothing to the update notice. Core's notice is for users who may update, and the plugin
	 * removes it for everyone else; the plugin prints none of the update data itself.
	 */
	public function test_the_update_nag_has_no_update_data_for_users_who_cannot_update() {
		$payload = self::payloads()['img'];
		$this->offer_updates( $payload );
		$this->save_settings();

		wp_set_current_user( self::$editor_id );
		$this->assertFalse( current_user_can( 'update_core' ) );
		add_action( 'admin_notices', 'update_nag', 3 );
		sc_wpun()->update_nag()->remove_update_nag_for_nonadmins();

		$html = $this->capture(
			static function () {
				do_action( 'admin_notices' );
			}
		);
		$this->assertStringNotContainsString( self::MARKER, $html );
		$this->assertFalse( has_action( 'admin_notices', 'update_nag' ), 'The update notice is still shown to a user who cannot update.' );
	}

	/**
	 * A channel that fails with a hostile message and a hostile label is drawn escaped in the list and its last
	 * result, and in the notice after Check now.
	 *
	 * @dataProvider payloads_provider
	 *
	 * @param string $payload The payload.
	 */
	public function test_the_channels_list_and_last_result_are_escaped( $payload ) {
		sc_wpun()->channels()->register( new Test_Security_Failing_Channel( $payload ) );
		sc_wpun_channels()->save( new Channel_Instance( 'hostile', 'security_failing', $payload, true, array(), array() ) );
		$this->set_updates( '99.0.0' );
		sc_wpun()->update_checker()->do_update_check();

		$this->assertStringContainsString( 'Failed', $this->capture( array( sc_wpun()->channels_tab(), 'render' ) ), 'The channel did not fail.' );
		$_GET['tab'] = 'channels';
		$html        = $this->capture( array( sc_wpun()->channels_tab(), 'render' ) );
		$this->assert_no_live_markup( $html, 'Channels tab' );

		// The notice after "Check now" names the failure.
		delete_option( 'sc_wpun_state' );
		$_POST['_wpnonce'] = wp_create_nonce( 'sc_wpun_check_now' );
		$this->assertTrue( sc_wpun()->check_now()->process() );
		unset( $_POST['_wpnonce'] );
		$notice = $this->capture( array( sc_wpun()->notices(), 'render' ) );
		$this->assertStringContainsString( 'sc-wpun-notice', $notice );
		$this->assert_no_live_markup( $notice, 'Notice' );

		sc_wpun()->channels()->unregister( 'security_failing' );
	}

	/**
	 * Every tab, and every form of the Channels tab, with hostile stored values and hostile request parameters.
	 *
	 * @dataProvider payloads_provider
	 *
	 * @param string $payload The payload.
	 */
	public function test_every_settings_tab_is_escaped( $payload ) {
		sc_wpun_channels()->save(
			new Channel_Instance(
				'email',
				'email',
				$payload,
				true,
				array(
					'to'             => 'ops@example.org',
					'from'           => 'notifier@example.org',
					'reply_to'       => '',
					'format'         => 'text',
					'subject_prefix' => $payload,
				)
			)
		);
		// Stored values reach the page without passing a save: as after a restore, an import or a direct database write.
		update_option( 'sc_wpun_settings', array_merge( (array) get_option( 'sc_wpun_settings' ), array( 'notify_plugins' => $payload ) ) );
		sc_wpun()->channels()->register( new Test_Security_Failing_Channel( $payload ) );
		sc_wpun_channels()->save( new Channel_Instance( 'hostile', 'security_failing', $payload, false, array() ) );

		// The Channels tab is listed once the page has been drawn, so it is named too.
		$tabs = array_unique( array_merge( array( 'general', 'channels' ), array_keys( sc_wpun()->settings_page()->tabs() ) ) );

		$requests = array();
		foreach ( $tabs as $tab ) {
			$requests[ 'tab ' . $tab ] = array( 'tab' => $tab );
		}
		$requests['tab given as payload']      = array( 'tab' => $payload );
		$requests['channels list']             = array( 'tab' => 'channels' );
		$requests['picker']                    = array(
			'tab'    => 'channels',
			'action' => 'add',
		);
		$requests['new form']                  = array(
			'tab'    => 'channels',
			'action' => 'new',
			'type'   => 'email',
		);
		$requests['new form, type as payload'] = array(
			'tab'    => 'channels',
			'action' => 'new',
			'type'   => $payload,
		);
		$requests['edit form']                 = array(
			'tab'    => 'channels',
			'action' => 'edit',
			'id'     => 'email',
		);
		$requests['edit form, id as payload']  = array(
			'tab'    => 'channels',
			'action' => 'edit',
			'id'     => $payload,
		);
		$requests['edit form of hostile']      = array(
			'tab'    => 'channels',
			'action' => 'edit',
			'id'     => 'hostile',
		);
		$requests['delete confirmation']       = array(
			'tab'    => 'channels',
			'action' => 'delete',
			'id'     => 'email',
		);
		$requests['action given as payload']   = array(
			'tab'    => 'channels',
			'action' => $payload,
		);

		foreach ( $requests as $name => $get ) {
			$_GET = $get;
			$html = $this->capture( array( sc_wpun()->settings_page(), 'settings_page' ) );
			$this->assertNotSame( '', $html, "$name: nothing was drawn." );
			$this->assert_no_live_markup( $html, "Settings page, $name" );
		}
		$_GET = array();
	}

	/**
	 * The add-on requirement row under a plugin in the plugin list takes its text from the add-on's header.
	 *
	 * @dataProvider payloads_provider
	 *
	 * @param string $payload The payload.
	 */
	public function test_the_add_on_row_in_the_plugin_list_is_escaped( $payload ) {
		$html = $this->capture(
			static function () use ( $payload ) {
				sc_wpun()->plugin_requirements()->render_row( 'evil/evil.php', array( WPUN\Plugin::API_HEADER => $payload ) );
			}
		);

		$this->assert_no_live_markup( $html, 'Plugin list row' );
	}

	/**
	 * The Site Health tests the plugin adds escape what they show. The plugin adds no `debug_information` section.
	 *
	 * @dataProvider payloads_provider
	 *
	 * @param string $payload The payload.
	 */
	public function test_the_site_health_tests_are_escaped( $payload ) {
		// A requirement that can not be read is reported with `_doing_it_wrong()`, whose message is shown in the admin.
		$this->setExpectedIncorrectUsage( 'WPUN\Version_Constraint::satisfies' );
		$reported = array();
		add_action(
			'doing_it_wrong_run',
			static function ( $function_name, $message ) use ( &$reported ) {
				$reported[] = $message;
			},
			10,
			2
		);

		// A module that needs an API version that is not there, with a hostile name and requirement.
		$rejected = new class( $payload ) extends Test_Security_Failing_Channel {
			/**
			 * An API version nobody has, written as the payload.
			 *
			 * @return string
			 */
			public function get_api_constraint(): string {
				return '^99.0 ' . $this->payload;
			}
		};
		sc_wpun()->channels()->register( $rejected );
		$this->assertArrayHasKey( 'security_failing', sc_wpun()->channels()->get_rejected() );

		// A source that describes itself with the payload, and a value it supplies that is not valid.
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $payload ) {
				$sources[] = new Test_Security_Hostile_Source( $payload );
				return $sources;
			}
		);
		sc_wpun()->config()->flush();
		sc_wpun_channels()->all(); // Problems are found while the values are resolved.
		$this->assertNotEmpty( sc_wpun()->config()->problems(), 'The source did not cause a problem.' );

		// Slack settings with no Slack module.
		sc_wpun_channels()->save( new Channel_Instance( 'slack', 'slack', $payload, true, array() ) );

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'sc_wpun_rejected_channels', $tests['direct'] );
		$this->assertArrayHasKey( 'sc_wpun_config', $tests['direct'] );
		$this->assertArrayHasKey( 'sc_wpun_slack', $tests['direct'] );

		foreach ( array( 'sc_wpun_rejected_channels', 'sc_wpun_config', 'sc_wpun_slack' ) as $name ) {
			$result = call_user_func( $tests['direct'][ $name ]['test'] );
			foreach ( array( 'label', 'description', 'actions' ) as $part ) {
				$this->assert_no_live_markup( (string) $result[ $part ], "Site Health $name $part" );
			}
		}

		// The same data in the notices on the settings page.
		$html = $this->capture( array( sc_wpun()->settings_page(), 'settings_page' ) );
		$this->assertStringContainsString( 'sc-wpun-config-problems', $html );
		$this->assert_no_live_markup( $html, 'Settings page notices' );

		$this->assertNotEmpty( $reported, 'The unreadable requirement was not reported.' );
		foreach ( $reported as $message ) {
			$this->assert_no_live_markup( $message, 'Message of _doing_it_wrong()' );
		}
	}

	/**
	 * The plugin adds nothing to Site Health's debug data, so neither update data nor a secret can reach it (the
	 * secret routes are in test-secrets.php). Core's own sections list installed plugins and are not the plugin's.
	 */
	public function test_the_plugin_adds_no_debug_data() {
		$this->offer_updates( self::payloads()['script'] );
		$this->fake_http( 'wordpress.org', $this->fake_response( 200, '' ) ); // Site Health checks that WordPress.org can be reached.
		require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';

		$this->assertFalse( has_filter( 'debug_information' ), 'Something adds to the debug data. Add its secrets and payloads to the tests.' );
		foreach ( array_keys( WP_Debug_Data::debug_data() ) as $section ) {
			$this->assertDoesNotMatchRegularExpression( '/wpun|updates-notifier/i', (string) $section );
		}
	}
}

/**
 * A module that always fails, with its payload as the reason. Not part of the real test fixtures.
 */
class Test_Security_Failing_Channel extends Channel {

	/**
	 * The payload.
	 *
	 * @var string
	 */
	protected $payload;

	/**
	 * Constructor.
	 *
	 * @param string $payload Value to fail with.
	 */
	public function __construct( string $payload ) {
		$this->payload = $payload;
	}

	/**
	 * Type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'security_failing';
	}

	/**
	 * Label: the payload.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->payload;
	}

	/**
	 * Fail with the payload.
	 *
	 * @param Notification     $notification Notification.
	 * @param Channel_Instance $instance     Instance.
	 * @return Delivery_Result
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		return Delivery_Result::failure( $this->payload, 'hostile' );
	}
}

/**
 * A source that describes itself with the payload, and supplies a value that is not valid.
 */
class Test_Security_Hostile_Source implements Config_Source {

	/**
	 * The payload.
	 *
	 * @var string
	 */
	private $payload;

	/**
	 * Constructor.
	 *
	 * @param string $payload Value to describe itself with.
	 */
	public function __construct( string $payload ) {
		$this->payload = $payload;
	}

	/**
	 * An enabled flag that is not a flag.
	 *
	 * @param string         $key     Key.
	 * @param Config_Context $context Context.
	 * @return mixed
	 */
	public function get( string $key, Config_Context $context ) {
		return 'SC_WPUN_CHANNEL_EMAIL__ENABLED' === $key ? 'maybe' : null;
	}

	/**
	 * The payload.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public function get_label( string $key ): string {
		return $this->payload;
	}
}
