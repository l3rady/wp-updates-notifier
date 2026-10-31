<?php
/**
 * The admin of a network-activated plugin (#85): the screen in the network admin, its links, saving and the Plugins screen.
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Settings_Page;
use WPUN\Network_Context;
use WPUN\Plugin;

/**
 * Where the screens, links and handlers are in network mode, and that single-site mode keeps its own.
 */
class Test_Network_Admin extends WPUN_Test_Case {

	/**
	 * A network keeps the default capability, which only super admins have.
	 *
	 * @var bool
	 */
	protected $default_capability = true;

	/**
	 * Where the last redirect went.
	 *
	 * @var string|null
	 */
	private $redirected_to = null;

	/**
	 * The status the last wp_die() was given.
	 *
	 * @var int|null
	 */
	private $died_with = null;

	/**
	 * A site that is not the main site.
	 *
	 * @var int
	 */
	private $sub_id = 0;

	/**
	 * Be a super admin in the network admin.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite.' );
		}

		require_once ABSPATH . 'wp-admin/includes/template.php';
		$this->sub_id = self::factory()->blog->create();

		$GLOBALS['submenu']            = array();
		$GLOBALS['menu']               = array();
		$GLOBALS['wp_settings_errors'] = array();
		$this->save_network_settings();

		$super_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super_admin );
		wp_set_current_user( $super_admin );
		set_current_screen( 'dashboard-network' );

		add_filter(
			'wp_die_handler',
			function () {
				return function ( $message, $title, $args ) {
					$this->died_with = (int) ( $args['response'] ?? 0 );
					throw new WPDieException( 'wp_die' );
				};
			}
		);
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->redirected_to = (string) $location;
				throw new RuntimeException( 'wpun-redirect' );
			}
		);
	}

	/**
	 * Leave the network and the request as found.
	 */
	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['HTTP_REFERER'] );
		delete_transient( 'settings_errors' );
		$GLOBALS['wp_settings_errors'] = array();
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Network settings and the email instance, as a network would store them.
	 */
	private function save_network_settings() {
		update_site_option( 'sc_wpun_settings', $this->global_settings() );
		update_site_option( 'sc_wpun_settings_ver', self::SETTINGS_VERSION );
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'email', 'email', 'Email', true, array( 'to' => 'ops@example.org' ) ) );
	}

	/**
	 * The settings screen of the network admin.
	 *
	 * @param string $tab Tab slug, if any.
	 *
	 * @return string
	 */
	private function network_url( string $tab = '' ): string {
		return network_admin_url( 'settings.php?page=wp-updates-notifier' ) . ( '' === $tab ? '' : '&tab=' . $tab );
	}

	/**
	 * Call a private method.
	 *
	 * @param object $target Object.
	 * @param string $method Method.
	 * @param array  $args   Arguments.
	 *
	 * @return mixed
	 */
	private function call( $target, string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $target, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $target, $args );
	}

	/**
	 * Whether a method of an object is hooked, whichever instance of the class hooked it.
	 *
	 * @param string $hook   Hook.
	 * @param string $class_name Class.
	 * @param string $method Method.
	 *
	 * @return bool
	 */
	private function hooked( string $hook, string $class_name, string $method ): bool {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && is_object( $function[0] ) && $function[0] instanceof $class_name && $function[1] === $method ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The settings page, drawn.
	 *
	 * @return string
	 */
	private function page_html(): string {
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();

		return (string) ob_get_clean();
	}

	/**
	 * The addresses the URL helpers give in either mode.
	 */
	public function test_the_url_helpers_follow_the_mode() {
		$context = new Network_Context();
		$this->assertSame( $this->network_url(), $context->settings_url() );
		$this->assertSame( $this->network_url( 'muted%20x' ), $context->settings_url( 'muted x' ) );
		$this->assertSame( network_admin_url( 'plugins.php' ), $context->admin_page_url( 'plugins.php' ) );

		$active = (array) get_site_option( 'active_sitewide_plugins', array() );
		unset( $active[ plugin_basename( SC_WPUN_FILE ) ] );
		update_site_option( 'active_sitewide_plugins', $active );

		$this->assertSame( admin_url( 'options-general.php?page=wp-updates-notifier' ), $context->settings_url() );
		$this->assertSame( admin_url( 'options-general.php?page=wp-updates-notifier&tab=channels' ), $context->settings_url( 'channels' ) );
		$this->assertSame( admin_url( 'plugins.php' ), $context->admin_page_url( 'plugins.php' ) );
	}

	/**
	 * The screen is under the network's Settings, not under Settings.
	 */
	public function test_the_menu_is_under_the_network_settings() {
		sc_wpun()->settings_page()->admin_settings_menu();

		$this->assertArrayNotHasKey( 'options-general.php', $GLOBALS['submenu'] );
		$entries = wp_list_filter( $GLOBALS['submenu']['settings.php'], array( 2 => 'wp-updates-notifier' ) );
		$this->assertCount( 1, $entries );
		$this->assertSame( Settings_Page::capability(), reset( $entries )[1] );
		$this->assertNotFalse( has_action( 'load-' . get_plugin_page_hookname( 'wp-updates-notifier', 'settings.php' ) ) );
	}

	/**
	 * Without network activation the screen is where it was.
	 */
	public function test_the_menu_is_under_settings_when_not_network_activated() {
		$active = (array) get_site_option( 'active_sitewide_plugins', array() );
		unset( $active[ plugin_basename( SC_WPUN_FILE ) ] );
		update_site_option( 'active_sitewide_plugins', $active );
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		grant_super_admin( get_current_user_id() );

		sc_wpun()->settings_page()->admin_settings_menu();

		$this->assertArrayNotHasKey( 'settings.php', $GLOBALS['submenu'] );
		$this->assertCount( 1, wp_list_filter( $GLOBALS['submenu']['options-general.php'], array( 2 => 'wp-updates-notifier' ) ) );
	}

	/**
	 * Booted in the network admin, the hooks are the network admin's.
	 */
	public function test_the_admin_hooks_are_the_network_admin_ones() {
		$file = plugin_basename( SC_WPUN_FILE );
		remove_all_actions( 'admin_menu' );
		remove_all_actions( 'network_admin_menu' );
		remove_all_filters( 'plugin_action_links_' . $file );

		( new Plugin() )->boot();

		$this->assertTrue( $this->hooked( 'network_admin_menu', Settings_Page::class, 'admin_settings_menu' ) );
		$this->assertFalse( $this->hooked( 'admin_menu', Settings_Page::class, 'admin_settings_menu' ) );
		$this->assertTrue( $this->hooked( 'network_admin_plugin_action_links_' . $file, Settings_Page::class, 'plugin_action_links' ) );
		$this->assertFalse( $this->hooked( 'plugin_action_links_' . $file, Settings_Page::class, 'plugin_action_links' ) );
		$this->assertTrue( $this->hooked( 'network_admin_edit_' . Settings_Page::NETWORK_SAVE_ACTION, Settings_Page::class, 'handle_network_save' ) );
	}

	/**
	 * The Settings link of the plugin's row is the network screen's.
	 */
	public function test_the_row_link_points_to_the_network_admin() {
		$links = sc_wpun()->settings_page()->plugin_action_links( array() );

		$this->assertStringContainsString( 'href="' . esc_url( $this->network_url() ) . '"', $links[0] );
	}

	/**
	 * The tabs, the Check now button and the form all use the network screen.
	 */
	public function test_the_page_links_to_the_network_admin() {
		$html = $this->page_html();

		foreach ( array( 'general', 'channels', 'muted', 'about' ) as $tab ) {
			$this->assertStringContainsString( 'href="' . esc_url( $this->network_url( $tab ) ) . '"', $html, $tab );
		}
		$this->assertStringNotContainsString( 'options-general.php', $html );
		$this->assertStringContainsString( 'action="' . esc_url( network_admin_url( 'edit.php?action=' . Settings_Page::NETWORK_SAVE_ACTION ) ) . '"', $html );
		$this->assertStringNotContainsString( 'action="' . esc_url( admin_url( 'options.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'name=\'option_page\' value=\'sc_wpun_settings\'', $html );
	}

	/**
	 * The builders of the classes that link to a tab.
	 */
	public function test_every_builder_points_to_the_network_admin() {
		$this->assertSame( $this->network_url( 'general' ), $this->call( sc_wpun()->check_now(), 'general_url' ) );
		$this->assertSame( $this->network_url( 'muted' ), $this->call( sc_wpun()->muted_tab(), 'tab_url' ) );
		$this->assertSame( $this->network_url( 'channels' ) . '&x=1', $this->call( sc_wpun()->channels_tab(), 'tab_url', array( array( 'x' => '1' ) ) ) );
		$this->assertSame( $this->network_url( 'channels' ) . '&x=1', $this->call( sc_wpun()->channel_form(), 'tab_url', array( array( 'x' => '1' ) ) ) );
		$this->assertSame( $this->network_url(), sc_wpun()->slack_notice()->settings_url() );

		ob_start();
		sc_wpun()->check_now()->render();
		$this->assertStringContainsString( esc_attr( $this->network_url( 'channels' ) ), (string) ob_get_clean() );

		ob_start();
		$this->call( sc_wpun()->muted_tab(), 'render_empty' );
		$this->assertStringContainsString( esc_attr( network_admin_url( 'plugins.php' ) ), (string) ob_get_clean() );
	}

	/**
	 * The Site Health actions of the plugin lead to the network screen.
	 */
	public function test_the_site_health_links_point_to_the_network_admin() {
		add_filter(
			'sc_wpun_config_value',
			static function ( $value, $context ) {
				return 'frequency' === $context->get_setting() ? 'bogus' : $value;
			},
			10,
			2
		);
		sc_wpun()->config()->flush();

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'sc_wpun_config', $tests['direct'] );
		$result = call_user_func( $tests['direct']['sc_wpun_config']['test'] );
		$this->assertStringContainsString( esc_attr( $this->network_url() ), $result['actions'] );

		$result = call_user_func( $tests['direct']['sc_wpun_schedule']['test'] );
		$this->assertStringNotContainsString( 'options-general.php', wp_json_encode( $result ) );
	}

	/**
	 * Only the main site reports to Site Health: a sub-site has none of the plugin's tests.
	 */
	public function test_site_health_is_registered_on_the_main_site_only() {
		remove_all_filters( 'site_status_tests' );
		remove_all_filters( 'debug_information' );
		( new Plugin() )->boot();
		$this->assertTrue( $this->hooked( 'site_status_tests', WPUN\Site_Health::class, 'add_tests' ) );
		$this->assertTrue( $this->hooked( 'site_status_tests', WPUN\Config_Report::class, 'add_site_health_test' ) );
		$this->assertTrue( $this->hooked( 'site_status_tests', WPUN\Rejected_Channels::class, 'add_site_health_test' ) );
		$this->assertTrue( $this->hooked( 'site_status_tests', WPUN\Slack_Notice::class, 'add_site_health_test' ) );
		$this->assertTrue( $this->hooked( 'debug_information', WPUN\Site_Health::class, 'add_debug_info' ) );

		remove_all_filters( 'site_status_tests' );
		remove_all_filters( 'debug_information' );
		switch_to_blog( $this->sub_id );
		( new Plugin() )->boot();
		restore_current_blog();
		$this->assertFalse( $this->hooked( 'site_status_tests', WPUN\Site_Health::class, 'add_tests' ) );
		$this->assertFalse( $this->hooked( 'site_status_tests', WPUN\Config_Report::class, 'add_site_health_test' ) );
		$this->assertFalse( $this->hooked( 'site_status_tests', WPUN\Rejected_Channels::class, 'add_site_health_test' ) );
		$this->assertFalse( $this->hooked( 'site_status_tests', WPUN\Slack_Notice::class, 'add_site_health_test' ) );
		$this->assertFalse( $this->hooked( 'debug_information', WPUN\Site_Health::class, 'add_debug_info' ) );
	}

	/**
	 * Request the network save as a user would send the form.
	 *
	 * @param array       $settings The settings fields.
	 * @param string|null $token    Security token; null for a valid one.
	 *
	 * @return string `died`, `redirected` or `returned`.
	 * @throws RuntimeException Any exception other than the redirect.
	 */
	private function save( array $settings, ?string $token = null ): string {
		$_POST                   = array(
			'option_page'      => 'sc_wpun_settings',
			'action'           => 'update',
			'_wpnonce'         => $token ?? wp_create_nonce( 'sc_wpun_settings-options' ),
			'sc_wpun_settings' => wp_slash( $settings ),
		);
		$_REQUEST                = $_POST;
		$_SERVER['HTTP_REFERER'] = $this->network_url( 'general' );

		$this->died_with     = null;
		$this->redirected_to = null;
		sc_wpun()->settings_page()->admin_settings_init();
		try {
			do_action( 'network_admin_edit_' . Settings_Page::NETWORK_SAVE_ACTION );
		} catch ( WPDieException $e ) {
			return 'died';
		} catch ( RuntimeException $e ) {
			if ( 'wpun-redirect' !== $e->getMessage() ) {
				throw $e;
			}

			return 'redirected';
		}

		return 'returned';
	}

	/**
	 * Hook the handler, as booting in the network admin does.
	 */
	private function hook_save() {
		remove_all_actions( 'network_admin_edit_' . Settings_Page::NETWORK_SAVE_ACTION );
		add_action( 'network_admin_edit_' . Settings_Page::NETWORK_SAVE_ACTION, array( sc_wpun()->settings_page(), 'handle_network_save' ) );
	}

	/**
	 * A save stores the network option, validates once, keeps "Settings saved." and goes back.
	 */
	public function test_saving_stores_the_network_option_and_goes_back() {
		$this->hook_save();
		$runs = 0;
		add_filter(
			'sanitize_option_sc_wpun_settings',
			static function ( $value ) use ( &$runs ) {
				++$runs;
				return $value;
			},
			1
		);

		$this->assertSame( 'redirected', $this->save( array( 'frequency' => 'daily' ) ) );

		$this->assertSame( 1, $runs, 'The validation runs once.' );
		$this->assertSame( 'daily', get_site_option( 'sc_wpun_settings' )['frequency'] );
		$this->assertSame( 'hourly', get_option( 'sc_wpun_settings' )['frequency'] ?? 'hourly', 'The site option is not touched.' );
		$this->assertSame( add_query_arg( 'settings-updated', 'true', $this->network_url( 'general' ) ), $this->redirected_to );

		$errors = get_transient( 'settings_errors' );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'settings_updated', $errors[0]['code'] );
		$this->assertSame( 'success', $errors[0]['type'] );
	}

	/**
	 * A value that is not valid is reported, the rest is saved, and "Settings saved." is not added.
	 */
	public function test_saving_reports_validation_errors() {
		$this->hook_save();

		$this->assertSame(
			'redirected',
			$this->save(
				array(
					'frequency'   => 'bogus',
					'notify_core' => 'none',
				)
			)
		);

		$stored = get_site_option( 'sc_wpun_settings' );
		$this->assertSame( 'hourly', $stored['frequency'], 'The old value stays.' );
		$this->assertSame( 'none', $stored['notify_core'] );
		$errors = get_transient( 'settings_errors' );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'sc_wpun_settings_main_frequency_error', $errors[0]['code'] );
		$this->assertSame( 'error', $errors[0]['type'] );
	}

	/**
	 * The messages are on the screen after the redirect, once.
	 */
	public function test_the_messages_show_on_the_screen() {
		set_transient(
			'settings_errors',
			array(
				array(
					'setting' => 'general',
					'code'    => 'settings_updated',
					'message' => 'Settings saved.',
					'type'    => 'success',
				),
			),
			30
		);
		$_GET['settings-updated'] = 'true';

		$html = $this->page_html();

		$this->assertStringContainsString( 'Settings saved.', $html );
		$this->assertFalse( get_transient( 'settings_errors' ) );
	}

	/**
	 * A request without a valid security token is refused and saves nothing.
	 */
	public function test_saving_needs_the_security_token() {
		$this->hook_save();

		$this->assertSame( 'died', $this->save( array( 'frequency' => 'daily' ), 'bad' ) );

		$this->assertSame( 403, $this->died_with );
		$this->assertSame( 'hourly', get_site_option( 'sc_wpun_settings' )['frequency'] );
	}

	/**
	 * A user without the capability is refused and saves nothing, even with a valid token.
	 */
	public function test_saving_needs_the_capability() {
		$this->hook_save();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 'died', $this->save( array( 'frequency' => 'daily' ) ) );

		$this->assertSame( 403, $this->died_with );
		$this->assertSame( 'hourly', get_site_option( 'sc_wpun_settings' )['frequency'] );
	}

	/**
	 * Without a referrer the save goes back to the General tab.
	 */
	public function test_saving_without_a_referrer_goes_to_the_general_tab() {
		$this->hook_save();
		$this->save( array( 'frequency' => 'daily' ) );
		$this->redirected_to = null;
		unset( $_SERVER['HTTP_REFERER'] );
		$_POST['sc_wpun_settings'] = array( 'frequency' => 'weekly' );
		$_POST['_wpnonce']         = wp_create_nonce( 'sc_wpun_settings-options' );
		$_REQUEST                  = $_POST;
		try {
			do_action( 'network_admin_edit_' . Settings_Page::NETWORK_SAVE_ACTION );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wpun-redirect', $e->getMessage() );
		}

		$this->assertSame( add_query_arg( 'settings-updated', 'true', $this->network_url( 'general' ) ), $this->redirected_to );
	}

	/**
	 * Network-activated, the Plugins screen of the network admin has the mutes, and a site's has none.
	 */
	public function test_the_mutes_load_on_the_network_plugins_screen_only() {
		set_current_screen( 'plugins-network' );
		sc_wpun()->plugins_screen_mutes()->load();
		$this->assertNotFalse( has_filter( 'bulk_actions-plugins-network' ) );
		$this->assertNotFalse( has_filter( 'handle_bulk_actions-plugins-network' ) );
		$this->assertNotFalse( has_filter( 'network_admin_plugin_action_links' ) );
		$this->assertFalse( has_filter( 'plugin_action_links' ) );
		$this->assertNotFalse( has_action( 'network_admin_notices', array( sc_wpun()->notices(), 'render' ) ) );
		$this->assertFalse( has_action( 'admin_notices', array( sc_wpun()->notices(), 'render' ) ) );
		$this->assertFalse( has_filter( 'bulk_actions-plugins' ) );

		remove_all_filters( 'network_admin_plugin_action_links' );
		remove_all_filters( 'bulk_actions-plugins-network' );
		remove_all_filters( 'handle_bulk_actions-plugins-network' );
		remove_action( 'network_admin_notices', array( sc_wpun()->notices(), 'render' ) );
		set_current_screen( 'plugins' );
		sc_wpun()->plugins_screen_mutes()->load();
		$this->assertFalse( has_filter( 'plugin_action_links' ) );
		$this->assertFalse( has_filter( 'bulk_actions-plugins' ) );
		$this->assertFalse( has_filter( 'handle_bulk_actions-plugins' ) );
		$this->assertFalse( has_action( 'admin_notices', array( sc_wpun()->notices(), 'render' ) ) );
	}

	/**
	 * Without network activation the site's Plugins screen has them and the network admin's does not.
	 */
	public function test_the_mutes_stay_on_the_site_plugins_screen_when_not_network_activated() {
		$active = (array) get_site_option( 'active_sitewide_plugins', array() );
		unset( $active[ plugin_basename( SC_WPUN_FILE ) ] );
		update_site_option( 'active_sitewide_plugins', $active );
		set_current_screen( 'plugins-network' );
		sc_wpun()->plugins_screen_mutes()->load();
		$this->assertFalse( has_filter( 'bulk_actions-plugins-network' ) );
		$this->assertFalse( has_action( 'network_admin_notices', array( sc_wpun()->notices(), 'render' ) ) );

		set_current_screen( 'plugins' );
		sc_wpun()->plugins_screen_mutes()->load();
		$this->assertNotFalse( has_filter( 'bulk_actions-plugins' ) );
		$this->assertNotFalse( has_filter( 'handle_bulk_actions-plugins' ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( sc_wpun()->notices(), 'render' ) ) );
	}

	/**
	 * A bulk action ends on the network Plugins screen when core gives no address.
	 */
	public function test_the_bulk_action_falls_back_to_the_network_plugins_screen() {
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$redirect = sc_wpun()->plugins_screen_mutes()->handle_bulk_actions( '', 'sc_wpun_bulk_mute', array( 'hello.php' ) );

		$this->assertSame( network_admin_url( 'plugins.php' ), $redirect );
	}

	/**
	 * A mute link's handler goes back to the network Plugins screen when there is no referrer.
	 */
	public function test_a_mute_link_falls_back_to_the_network_plugins_screen() {
		$_GET     = array(
			'action'   => 'sc_wpun_mute',
			'type'     => 'plugin',
			'key'      => 'hello.php',
			'_wpnonce' => wp_create_nonce( 'sc_wpun_mute|plugin|hello.php' ),
		);
		$_REQUEST = $_GET;
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		try {
			sc_wpun()->plugins_screen_mutes()->handle_mute();
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wpun-redirect', $e->getMessage() );
		}

		$this->assertSame( network_admin_url( 'plugins.php' ), $this->redirected_to );
	}
}
