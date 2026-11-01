<?php
/**
 * Muting from the Plugins screen (#82): row actions, the update notice, row meta, bulk actions and handlers.
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Plugins_Screen_Mutes;

// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's hook names have dashes.

/**
 * The links are added while the Plugins screen loads, and their handlers check the security token and the capability.
 */
class Test_Mute_Plugins_Screen extends WPUN_Test_Case {

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
	 * Status code of the last wp_die() call, if any.
	 *
	 * @var int|null
	 */
	private $died_with = null;

	/**
	 * Where the last redirect went.
	 *
	 * @var string|null
	 */
	private $redirected_to = null;

	/**
	 * An administrator, nothing muted, an update waiting for hello.php.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->network_context()->delete_option( 'sc_wpun_muted' );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'plugins' );
		sc_wpun()->notices()->take();

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
	 * Forget the request.
	 */
	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['HTTP_REFERER'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Load the Plugins screen hooks, as `load-plugins.php` does.
	 */
	private function load_screen() {
		sc_wpun()->plugins_screen_mutes()->load();
	}

	/**
	 * The plugin row action links, as the list table builds them.
	 *
	 * @param string $file Plugin file.
	 * @return array
	 */
	private function action_links( $file = 'hello.php' ) {
		return apply_filters( 'plugin_action_links', array( 'activate' => '<a href="#">Activate</a>' ), $file, array( 'Name' => 'Hello Dolly' ), 'all' );
	}

	/**
	 * The row meta, as the list table builds it.
	 *
	 * @param string $file Plugin file.
	 * @return array
	 */
	private function row_meta( $file = 'hello.php' ) {
		return apply_filters( 'plugin_row_meta', array( 'By Someone' ), $file, array( 'Name' => 'Hello Dolly' ), 'all' );
	}

	/**
	 * What core prints in the update notice of a plugin.
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	private function update_message( $file = 'hello.php' ) {
		ob_start();
		do_action( "in_plugin_update_message-{$file}", array(), (object) array() );

		return ob_get_clean();
	}

	/**
	 * A request to a handler, with a link made for the current user.
	 *
	 * @param string $action  Admin-post action.
	 * @param array  $fields  Query fields; `_wpnonce` is added unless given.
	 * @param string $token   Token action, or null for the one the link would have.
	 * @return string `died`, `redirected` or `returned`.
	 * @throws RuntimeException Any exception the handler throws other than the redirect.
	 */
	private function click( $action, array $fields = array(), $token = null ) {
		$fields = array_merge(
			array(
				'action' => $action,
				'type'   => 'plugin',
				'key'    => 'hello.php',
			),
			$fields
		);
		if ( ! isset( $fields['_wpnonce'] ) ) {
			$fields['_wpnonce'] = wp_create_nonce( $token ?? ( $action . '|plugin|' . $fields['key'] . ( isset( $fields['version'] ) ? '|' . $fields['version'] : ( isset( $fields['seconds'] ) ? '|' . $fields['seconds'] : '' ) ) ) );
		}
		$_GET     = $fields;
		$_REQUEST = $fields;

		$this->died_with     = null;
		$this->redirected_to = null;
		ob_start();
		try {
			do_action( 'admin_post_' . $action );
		} catch ( WPDieException $e ) {
			return 'died';
		} catch ( RuntimeException $e ) {
			if ( 'wpun-redirect' !== $e->getMessage() ) {
				throw $e;
			}

			return 'redirected';
		} finally {
			ob_end_clean();
		}

		return 'returned';
	}

	/**
	 * Register the admin hooks, which the handlers hang on.
	 */
	private function register_hooks() {
		sc_wpun()->register_admin_hooks();
	}

	/**
	 * Take the notice of the current user.
	 *
	 * @return array|null
	 */
	private function notice() {
		return sc_wpun()->notices()->take();
	}

	/**
	 * The screen's hooks are not there until the Plugins screen loads.
	 */
	public function test_nothing_is_hooked_before_the_screen_loads() {
		$this->assertFalse( has_filter( 'plugin_action_links' ) );
		$this->assertFalse( has_filter( 'bulk_actions-plugins' ) );
		$this->assertFalse( has_action( 'in_plugin_update_message-hello.php' ) );

		$this->register_hooks();
		$this->assertFalse( has_filter( 'plugin_action_links' ), 'Registering the admin hooks adds no generic link filter.' );

		do_action( 'load-plugins.php' );
		$this->assertNotFalse( has_filter( 'plugin_action_links' ) );
		$this->assertNotFalse( has_filter( 'bulk_actions-plugins' ) );
		$this->assertNotFalse( has_action( 'in_plugin_update_message-hello.php' ) );
	}

	/**
	 * The row action mutes; it becomes Unmute when the plugin is muted.
	 */
	public function test_the_row_action_mutes_and_unmutes() {
		$this->load_screen();

		$links = $this->action_links();
		$this->assertArrayHasKey( 'activate', $links );
		$this->assertStringContainsString( 'Mute update notifications', $links['sc_wpun_mute'] );
		$this->assertStringContainsString( 'aria-label="Mute update notifications for Hello Dolly"', $links['sc_wpun_mute'] );
		$this->assertStringContainsString( 'admin-post.php?action=sc_wpun_mute&#038;type=plugin&#038;key=hello.php&#038;_wpnonce=', $links['sc_wpun_mute'] );
		$this->assertArrayNotHasKey( 'sc_wpun_unmute', $links );

		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$links = $this->action_links();
		$this->assertArrayNotHasKey( 'sc_wpun_mute', $links );
		$this->assertStringContainsString( 'Unmute update notifications', $links['sc_wpun_unmute'] );
		$this->assertStringContainsString( 'Update notifications muted', implode( ' ', $this->row_meta() ) );
	}

	/**
	 * The must-use and drop-in lists get nothing.
	 */
	public function test_must_use_plugins_get_no_links() {
		$this->load_screen();

		$this->assertSame(
			array( 'x' => 'y' ),
			apply_filters( 'plugin_action_links', array( 'x' => 'y' ), 'a.php', array(), 'mustuse' )
		);
		$this->assertSame( array( 'x' ), apply_filters( 'plugin_row_meta', array( 'x' ), 'a.php', array(), 'dropins' ) );
	}

	/**
	 * A mute set in code has a note and no link to remove it.
	 */
	public function test_a_code_mute_has_a_note_and_no_unmute_link() {
		add_filter(
			'sc_wpun_mutes',
			static function ( $mutes ) {
				$mutes[] = new WPUN\Mute(
					array(
						'type' => 'plugin',
						'key'  => 'hello.php',
					)
				);
				return $mutes;
			}
		);
		$this->load_screen();

		$links = $this->action_links();
		$this->assertArrayNotHasKey( 'sc_wpun_mute', $links );
		$this->assertArrayNotHasKey( 'sc_wpun_unmute', $links );
		$this->assertStringContainsString( 'set in code', implode( ' ', $this->row_meta() ) );
	}

	/**
	 * Without the capability there are no links, no bulk actions and no skip link.
	 */
	public function test_nothing_is_shown_without_the_capability() {
		wp_set_current_user( self::$editor_id );
		$this->load_screen();

		$this->assertSame( array( 'activate' ), array_keys( $this->action_links() ) );
		$this->assertSame( array( 'By Someone' ), $this->row_meta() );
		$this->assertSame( array(), apply_filters( 'bulk_actions-plugins', array() ) );
		$this->assertSame( '', $this->update_message() );
	}

	/**
	 * The capability filter grants the links to users who can update plugins, and takes them from administrators.
	 */
	public function test_the_capability_filter_decides_who_sees_the_links() {
		$editor_with_cap = static function ( $allcaps ) {
			$allcaps['edit_theme_options'] = true;
			return $allcaps;
		};
		add_filter( 'sc_wpun_mute_capability', static fn() => 'edit_theme_options' );
		add_filter( 'user_has_cap', $editor_with_cap );

		wp_set_current_user( self::$editor_id );
		$this->load_screen();
		$this->assertArrayHasKey( 'sc_wpun_mute', $this->action_links(), 'A user with the filtered capability sees the link.' );
		$this->assertArrayHasKey( 'sc_wpun_bulk_mute', apply_filters( 'bulk_actions-plugins', array() ) );
		remove_filter( 'user_has_cap', $editor_with_cap );

		// The administrator holds manage_options but not the filtered capability.
		add_filter(
			'user_has_cap',
			static function ( $allcaps ) {
				$allcaps['edit_theme_options'] = false;
				return $allcaps;
			}
		);
		wp_set_current_user( self::$admin_id );
		remove_all_filters( 'plugin_action_links' );
		$this->load_screen();
		$this->assertArrayNotHasKey( 'sc_wpun_mute', $this->action_links() );
	}

	/**
	 * A non-string capability falls back to the default.
	 */
	public function test_a_junk_capability_falls_back_to_the_default() {
		add_filter( 'sc_wpun_mute_capability', '__return_empty_string' );
		$this->assertSame( WPUN\Admin\Settings_Page::capability(), WPUN\Admin\Mute_Actions::capability() );
		add_filter( 'sc_wpun_mute_capability', '__return_false', 11 );
		$this->assertSame( WPUN\Admin\Settings_Page::capability(), WPUN\Admin\Mute_Actions::capability() );
	}

	/**
	 * The skip link is in the update notice, only while an update is waiting.
	 */
	public function test_the_update_notice_offers_to_skip_the_waiting_version() {
		// A site administrator of a network cannot update plugins; a super admin can.
		if ( is_multisite() ) {
			grant_super_admin( self::$admin_id );
		}
		$this->assertTrue( current_user_can( 'update_plugins' ) );
		$this->load_screen();

		$html = $this->update_message();
		$this->assertStringContainsString( 'Don&#039;t notify me about version 2.0.0', $html );
		$this->assertStringContainsString( 'action=sc_wpun_skip&#038;type=plugin&#038;key=hello.php&#038;version=2.0.0&#038;_wpnonce=', $html );
		$this->assertStringNotContainsString( '<p', $html, 'Core prints it inside a paragraph.' );
		$this->assertStringNotContainsString( 'Don&#039;t notify', implode( ' ', $this->row_meta() ), 'The notice has it; the row meta does not.' );

		// No update waiting: no hook for the plugin at all.
		$this->set_updates();
		remove_all_actions( 'in_plugin_update_message-hello.php' );
		$this->load_screen();
		$this->assertFalse( has_action( 'in_plugin_update_message-hello.php' ) );
	}

	/**
	 * The skip link encodes its values, so a version with a + (build metadata) still works.
	 */
	public function test_the_skip_link_works_for_a_version_with_a_plus() {
		if ( is_multisite() ) {
			grant_super_admin( self::$admin_id );
		}
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.1.0+build5' ) ) );
		$this->load_screen();
		$this->register_hooks();

		$this->assertSame( 1, preg_match( '/href="([^"]+)"/', $this->update_message(), $match ) );
		parse_str( (string) wp_parse_url( html_entity_decode( $match[1] ), PHP_URL_QUERY ), $fields );
		$this->assertSame( '2.1.0+build5', $fields['version'] );

		$this->assertSame( 'redirected', $this->click( 'sc_wpun_skip', $fields ) );
		$this->assertSame( '2.1.0+build5', sc_wpun_mutes()->get( 'plugin', 'hello.php' )->get_until() );
	}

	/**
	 * Once skipped, the notice says so and offers the undo; muted for good, it says nothing.
	 */
	public function test_the_update_notice_after_skipping() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0.0' );
		$this->load_screen();

		$html = $this->update_message();
		$this->assertStringContainsString( 'Notifications for version 2.0.0 are skipped.', $html );
		$this->assertStringContainsString( 'action=sc_wpun_unmute', $html );
		$this->assertStringNotContainsString( 'Don&#039;t notify', $html );

		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$this->assertSame( '', $this->update_message() );
	}

	/**
	 * Core hides its update notice from users who cannot update plugins (and with DISALLOW_FILE_MODS); the link moves to the row meta.
	 */
	public function test_without_update_plugins_the_skip_link_is_in_the_row_meta() {
		add_filter(
			'user_has_cap',
			static function ( $allcaps ) {
				$allcaps['update_plugins'] = false;
				return $allcaps;
			}
		);
		$this->assertFalse( current_user_can( 'update_plugins' ) );
		$this->load_screen();

		$meta = implode( ' ', $this->row_meta() );
		$this->assertStringContainsString( 'Don&#039;t notify me about version 2.0.0', $meta );
		$this->assertStringContainsString( 'action=sc_wpun_skip', $meta );

		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0.0' );
		$meta = implode( ' ', $this->row_meta() );
		$this->assertStringContainsString( 'Notifications for version 2.0.0 are skipped', $meta );
		$this->assertStringContainsString( 'action=sc_wpun_unmute', $meta );
		$this->assertStringNotContainsString( 'Don&#039;t notify', $meta );
	}

	/**
	 * The network Plugins screen is left alone.
	 */
	public function test_the_network_plugins_screen_is_left_alone() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		set_current_screen( 'plugins-network' );
		grant_super_admin( self::$admin_id );
		$this->assertTrue( is_network_admin() );
		$this->load_screen();

		$this->assertFalse( has_filter( 'plugin_action_links' ) );
		$this->assertFalse( has_filter( 'handle_bulk_actions-plugins' ) );
	}

	/**
	 * The mute link mutes, says so, and returns to the page it came from.
	 */
	public function test_the_mute_handler_mutes_and_goes_back() {
		$this->register_hooks();
		$_SERVER['HTTP_REFERER'] = admin_url( 'plugins.php?plugin_status=active' );
		$fired                   = array();
		add_action(
			'sc_wpun_item_muted',
			static function ( $mute, $source ) use ( &$fired ) {
				$fired[] = array( $mute->get_key(), $source );
			},
			10,
			2
		);

		$this->assertSame( 'redirected', $this->click( 'sc_wpun_mute' ) );
		$this->assertSame( admin_url( 'plugins.php?plugin_status=active' ), $this->redirected_to );
		$this->assertTrue( sc_wpun_mutes()->get( 'plugin', 'hello.php' )->is_always() );
		$this->assertSame( array( array( 'hello.php', 'plugins_screen' ) ), $fired, 'Fired once.' );
		$notice = $this->notice();
		$this->assertTrue( $notice['success'] );
		$this->assertSame( 'Update notifications for Hello Dolly are muted.', $notice['message'] );
	}

	/**
	 * The skip link stores the version waiting and the unmute link removes it, each firing its action once.
	 */
	public function test_the_skip_and_unmute_handlers() {
		$this->register_hooks();
		$unmuted = array();
		add_action(
			'sc_wpun_item_unmuted',
			static function ( $mute, $source ) use ( &$unmuted ) {
				$unmuted[] = $source;
			},
			10,
			2
		);

		$this->assertSame( 'redirected', $this->click( 'sc_wpun_skip', array( 'version' => '2.0.0' ) ) );
		$this->assertSame( '2.0.0', sc_wpun_mutes()->get( 'plugin', 'hello.php' )->get_until() );
		$this->assertSame( 'Update notifications for Hello Dolly version 2.0.0 are skipped.', $this->notice()['message'] );

		$this->assertSame( 'redirected', $this->click( 'sc_wpun_unmute' ) );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
		$this->assertSame( array( 'plugins_screen' ), $unmuted );
		$this->assertSame( 'Update notifications for Hello Dolly are on again.', $this->notice()['message'] );
	}

	/**
	 * A foreign referrer is not followed.
	 */
	public function test_the_redirect_only_goes_to_a_safe_referrer() {
		$this->register_hooks();

		$_SERVER['HTTP_REFERER'] = 'https://evil.example.com/plugins.php';
		$this->click( 'sc_wpun_mute' );
		$this->assertSame( admin_url( 'plugins.php' ), $this->redirected_to );

		unset( $_SERVER['HTTP_REFERER'] );
		$this->click( 'sc_wpun_unmute' );
		$this->assertSame( admin_url( 'plugins.php' ), $this->redirected_to );
	}

	/**
	 * A bad or missing token, a token for another action, plugin or version, and a missing capability give a 403 and change nothing.
	 */
	public function test_refused_requests_change_nothing() {
		$this->register_hooks();

		$cases = array(
			'bad token'              => array( 'sc_wpun_mute', array( '_wpnonce' => 'abcdef1234' ) ),
			'wrong action token'     => array( 'sc_wpun_mute', array(), 'sc_wpun_unmute|plugin|hello.php' ),
			'wrong plugin token'     => array( 'sc_wpun_mute', array(), 'sc_wpun_mute_plugin_other.php' ),
			'wrong version token'    => array( 'sc_wpun_skip', array( 'version' => '2.0.0' ), 'sc_wpun_skip|plugin|hello.php|1.0.0' ),
			'skip without a version' => array( 'sc_wpun_skip', array(), 'sc_wpun_skip|plugin|hello.php' ),
			'type is not a plugin'   => array( 'sc_wpun_mute', array( 'type' => 'theme' ) ),
		);
		foreach ( $cases as $name => $case ) {
			$this->assertSame( 'died', $this->click( $case[0], $case[1], $case[2] ?? null ), $name );
			$this->assertSame( 403, $this->died_with, $name );
			$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ), "$name wrote the option." );
		}

		// No token at all.
		$_GET = array(
			'action' => 'sc_wpun_mute',
			'type'   => 'plugin',
			'key'    => 'hello.php',
		);
		$this->assertFalse( sc_wpun()->plugins_screen_mutes()->process_mute() );

		// A valid token of a user without the capability.
		wp_set_current_user( self::$editor_id );
		$this->assertSame( 'died', $this->click( 'sc_wpun_mute' ) );
		$this->assertSame( 403, $this->died_with );
		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) );
	}

	/**
	 * The capability filter grants the handlers to a user and revokes them from an administrator.
	 */
	public function test_the_capability_filter_decides_who_may_use_the_handlers() {
		$this->register_hooks();
		add_filter( 'sc_wpun_mute_capability', static fn() => 'edit_theme_options' );
		add_filter(
			'user_has_cap',
			static function ( $allcaps ) {
				$allcaps['edit_theme_options'] = ( get_current_user_id() === self::$editor_id );
				return $allcaps;
			}
		);

		$this->assertSame( 'died', $this->click( 'sc_wpun_mute' ), 'The administrator no longer may.' );
		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) );

		wp_set_current_user( self::$editor_id );
		$this->assertSame( 'redirected', $this->click( 'sc_wpun_mute' ), 'The editor now may.' );
		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
	}

	/**
	 * An unknown plugin is rejected with a notice, and nothing is stored.
	 */
	public function test_an_unknown_plugin_is_rejected() {
		$this->register_hooks();

		$this->assertSame( 'redirected', $this->click( 'sc_wpun_mute', array( 'key' => 'gone/gone.php' ) ) );
		$notice = $this->notice();
		$this->assertFalse( $notice['success'] );
		$this->assertSame( 'That plugin or theme is not installed.', $notice['message'] );
		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) );
	}

	/**
	 * Skipping needs an update to be waiting, and the version in the link to still be the one offered.
	 */
	public function test_skip_is_rejected_without_an_update_or_for_a_stale_version() {
		$this->register_hooks();

		$this->assertSame( 'redirected', $this->click( 'sc_wpun_skip', array( 'version' => '1.0.0' ) ) );
		$notice = $this->notice();
		$this->assertFalse( $notice['success'] );
		$this->assertStringContainsString( 'Reload the page', $notice['message'], 'A newer version arrived since the page loaded.' );
		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) );

		$this->set_updates();
		$this->assertSame( 'redirected', $this->click( 'sc_wpun_skip', array( 'version' => '2.0.0' ) ) );
		$notice = $this->notice();
		$this->assertFalse( $notice['success'] );
		$this->assertStringContainsString( 'No update is waiting for Hello Dolly', $notice['message'] );
		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) );
	}

	/**
	 * A refusal by sc_wpun_pre_mute or sc_wpun_pre_unmute shows its message.
	 */
	public function test_a_policy_refusal_reaches_the_notice() {
		$this->register_hooks();
		add_filter( 'sc_wpun_pre_mute', static fn() => new WP_Error( 'policy', 'Security updates must be reported.' ) );

		$this->click( 'sc_wpun_mute' );
		$notice = $this->notice();
		$this->assertFalse( $notice['success'] );
		$this->assertSame( 'Security updates must be reported.', $notice['message'] );

		remove_all_filters( 'sc_wpun_pre_mute' );
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		add_filter( 'sc_wpun_pre_unmute', static fn() => new WP_Error( 'policy', 'Ask the maintainer.' ) );
		$this->click( 'sc_wpun_unmute' );
		$this->assertSame( 'Ask the maintainer.', $this->notice()['message'] );
		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
	}

	/**
	 * A mute set in code cannot be removed from the link.
	 */
	public function test_a_code_mute_cannot_be_unmuted_by_the_link() {
		$this->register_hooks();
		add_filter(
			'sc_wpun_mutes',
			static function ( $mutes ) {
				$mutes[] = new WPUN\Mute(
					array(
						'type' => 'plugin',
						'key'  => 'hello.php',
					)
				);
				return $mutes;
			}
		);

		$this->click( 'sc_wpun_unmute' );
		$notice = $this->notice();
		$this->assertFalse( $notice['success'] );
		$this->assertStringContainsString( 'set in code', $notice['message'] );
	}

	/**
	 * The bulk actions are offered, and mute or unmute the selection with a summary.
	 */
	public function test_bulk_actions() {
		$this->load_screen();
		$actions = apply_filters( 'bulk_actions-plugins', array( 'activate-selected' => 'Activate' ) );
		$this->assertSame( 'Mute update notifications', $actions['sc_wpun_bulk_mute'] );
		$this->assertSame( 'Unmute update notifications', $actions['sc_wpun_bulk_unmute'] );

		$fired = array();
		add_action(
			'sc_wpun_item_muted',
			static function ( $mute, $source ) use ( &$fired ) {
				$fired[] = $source;
			},
			10,
			2
		);
		$back = admin_url( 'plugins.php' );
		$this->assertSame( $back, apply_filters( 'handle_bulk_actions-plugins', $back, 'sc_wpun_bulk_mute', array( 'hello.php', 'gone/gone.php' ) ) );
		$this->assertSame( array( 'plugins_screen' ), $fired );
		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
		$notice = $this->notice();
		$this->assertFalse( $notice['success'], 'One plugin was not installed.' );
		$this->assertStringContainsString( 'Update notifications muted for 1 plugin.', $notice['message'] );
		$this->assertStringContainsString( 'not installed', $notice['message'] );

		apply_filters( 'handle_bulk_actions-plugins', $back, 'sc_wpun_bulk_unmute', array( 'hello.php', 'akismet/akismet.php' ) );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
		$notice = $this->notice();
		$this->assertTrue( $notice['success'], 'A plugin that is not muted is left alone without complaint.' );
		$this->assertSame( 'Update notifications are on again for 1 plugin.', $notice['message'] );

		$this->assertSame( 'x', apply_filters( 'handle_bulk_actions-plugins', 'x', 'activate-selected', array( 'hello.php' ) ), 'Other actions are left to core.' );
	}

	/**
	 * Without the capability the bulk handler answers 403 and changes nothing.
	 */
	public function test_bulk_actions_need_the_capability() {
		wp_set_current_user( self::$editor_id );
		$this->load_screen();

		$this->expectException( WPDieException::class );
		try {
			apply_filters( 'handle_bulk_actions-plugins', '', 'sc_wpun_bulk_mute', array( 'hello.php' ) );
		} finally {
			$this->assertSame( 403, $this->died_with );
			$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) );
		}
	}

	/**
	 * The notice is shown once on the Plugins screen.
	 */
	public function test_the_notice_is_shown_on_the_plugins_screen() {
		$this->load_screen();
		sc_wpun()->notices()->add( true, 'Done it.' );

		ob_start();
		do_action( 'admin_notices' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Done it.', $html );

		ob_start();
		do_action( 'admin_notices' );
		$this->assertStringNotContainsString( 'Done it.', ob_get_clean(), 'Shown once.' );
		$this->assertSame( 'sc_wpun_mute', Plugins_Screen_Mutes::MUTE_ACTION );
	}

	// Snoozing (#346).

	/**
	 * Let the current user see core's update notice.
	 */
	private function can_update_plugins() {
		if ( is_multisite() ) {
			grant_super_admin( self::$admin_id );
		}
		$this->assertTrue( current_user_can( 'update_plugins' ) );
	}

	/**
	 * Take away the right to update plugins, so core hides its notice.
	 */
	private function cannot_update_plugins() {
		add_filter(
			'user_has_cap',
			static function ( $allcaps ) {
				$allcaps['update_plugins'] = false;
				return $allcaps;
			}
		);
	}

	/**
	 * The notice offers a snooze link for each length, after the skip link.
	 */
	public function test_the_update_notice_offers_snooze_links() {
		$this->can_update_plugins();
		$this->load_screen();

		$html = $this->update_message();
		$this->assertStringContainsString( 'Snooze:', $html );
		$this->assertGreaterThan( strpos( $html, 'sc_wpun_skip' ), strpos( $html, 'Snooze:' ) );
		foreach ( array(
			DAY_IN_SECONDS      => '1 day',
			WEEK_IN_SECONDS     => '1 week',
			30 * DAY_IN_SECONDS => '30 days',
		) as $seconds => $label ) {
			$this->assertStringContainsString( 'action=sc_wpun_snooze&#038;type=plugin&#038;key=hello.php&#038;seconds=' . $seconds . '&#038;_wpnonce=', $html );
			$this->assertStringContainsString( '>' . $label . '</a>', $html );
		}
		$this->assertStringNotContainsString( 'Snooze:', implode( ' ', $this->row_meta() ), 'The notice has them; the row meta does not.' );
		$this->assertStringNotContainsString( 'sc_wpun_snooze', implode( ' ', $this->action_links() ), 'Not a row action.' );
	}

	/**
	 * When core hides its notice the snooze links are in the row meta.
	 */
	public function test_without_update_plugins_the_snooze_links_are_in_the_row_meta() {
		$this->cannot_update_plugins();
		$this->load_screen();

		$meta = implode( ' ', $this->row_meta() );
		$this->assertStringContainsString( 'Snooze:', $meta );
		$this->assertStringContainsString( 'action=sc_wpun_snooze', $meta );

		$this->set_updates();
		$this->assertStringNotContainsString( 'Snooze:', implode( ' ', $this->row_meta() ), 'No update is waiting.' );
	}

	/**
	 * A muted plugin gets no snooze links, and a snoozed one says so and offers the undo.
	 */
	public function test_the_snoozed_note_and_undo() {
		$this->can_update_plugins();
		$this->load_screen();

		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$this->assertStringNotContainsString( 'Snooze:', $this->update_message() );

		$expires = time() + WEEK_IN_SECONDS;
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', null, 'api', $expires );
		$when = WPUN\Admin\Settings_Page::format_site_time( $expires );
		$html = $this->update_message();
		$this->assertStringContainsString( 'Notifications snoozed until ' . $when . '.', $html );
		$this->assertStringContainsString( 'action=sc_wpun_unmute', $html );
		$this->assertStringNotContainsString( 'Snooze:', $html );
		$this->assertStringContainsString( 'Notifications snoozed until ' . $when, implode( ' ', $this->row_meta() ) );

		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0.0', 'api', $expires );
		$this->assertStringContainsString( 'Notifications for version 2.0.0 are skipped until ' . $when . '.', $this->update_message() );
		$this->assertStringContainsString( 'action=sc_wpun_unmute', $this->update_message() );
		$this->assertStringContainsString( 'Unmute', implode( ' ', $this->action_links() ), 'The row action stays.' );
	}

	/**
	 * Row meta of a snoozed plugin offers the undo when core hides its notice.
	 */
	public function test_the_row_meta_of_a_snoozed_plugin_offers_the_undo() {
		$this->cannot_update_plugins();
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', null, 'api', time() + HOUR_IN_SECONDS );
		$this->load_screen();

		$meta = implode( ' ', $this->row_meta() );
		$this->assertStringContainsString( 'Notifications snoozed until', $meta );
		$this->assertStringContainsString( 'action=sc_wpun_unmute', $meta );
	}

	/**
	 * A snooze link mutes until about now plus its length, with the source, and says so.
	 */
	public function test_the_snooze_handler() {
		$this->register_hooks();
		$fired = array();
		add_action(
			'sc_wpun_item_muted',
			static function ( $mute, $source ) use ( &$fired ) {
				$fired[] = $source;
			},
			10,
			2
		);

		$before = time();
		$this->assertSame( 'redirected', $this->click( 'sc_wpun_snooze', array( 'seconds' => (string) WEEK_IN_SECONDS ) ) );
		$mute = sc_wpun_mutes()->get( 'plugin', 'hello.php' );
		$this->assertGreaterThanOrEqual( $before + WEEK_IN_SECONDS, $mute->get_expires() );
		$this->assertLessThanOrEqual( time() + WEEK_IN_SECONDS, $mute->get_expires() );
		$this->assertTrue( $mute->is_always() );
		$this->assertSame( 'plugins_screen', $mute->get_source() );
		$this->assertSame( array( 'plugins_screen' ), $fired );

		$notice = $this->notice();
		$this->assertTrue( $notice['success'] ?? true );
		$this->assertStringContainsString( 'Update notifications for Hello Dolly are snoozed until', $notice['message'] );
	}

	/**
	 * Only lengths from the filtered list are accepted, and the filter can add one.
	 */
	public function test_the_snooze_handler_accepts_only_listed_lengths() {
		$this->register_hooks();

		$this->assertSame( 'redirected', $this->click( 'sc_wpun_snooze', array( 'seconds' => '12345' ) ), 'A valid token for a length that is not offered.' );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
		$this->assertFalse( $this->notice()['success'] ?? true );

		foreach ( array( '0', '-5', '1.5', 'abc', '1e5', '' ) as $bad ) {
			$this->click( 'sc_wpun_snooze', array( 'seconds' => $bad ) );
			$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ), "'$bad' was accepted." );
		}
		$this->notice();

		add_filter(
			'sc_wpun_snooze_durations',
			static function ( $durations ) {
				$durations[ 4 * HOUR_IN_SECONDS ] = '4 hours';
				return $durations;
			}
		);
		$this->assertSame( 'redirected', $this->click( 'sc_wpun_snooze', array( 'seconds' => (string) ( 4 * HOUR_IN_SECONDS ) ) ) );
		$this->assertEqualsWithDelta( time() + 4 * HOUR_IN_SECONDS, sc_wpun_mutes()->get( 'plugin', 'hello.php' )->get_expires(), 5 );

		$this->can_update_plugins();
		$this->load_screen();
		$this->assertStringContainsString( 'seconds=' . ( 4 * HOUR_IN_SECONDS ), $this->update_message( 'other.php' ) . implode( ' ', $this->row_meta() ) . $this->snooze_notice_html() );
	}

	/**
	 * The notice html for a plugin with no mute, after unmuting hello.php.
	 *
	 * @return string
	 */
	private function snooze_notice_html() {
		sc_wpun_mutes()->unmute( 'plugin', 'hello.php' );

		return $this->update_message();
	}

	/**
	 * A bad token, a token for another length or plugin, and a missing capability give a 403 and change nothing.
	 */
	public function test_refused_snooze_requests_change_nothing() {
		$this->register_hooks();
		$week = (string) WEEK_IN_SECONDS;

		$cases = array(
			'bad token'          => array(
			array(
			'seconds'  => $week,
			'_wpnonce' => 'abcdef1234',
				),
				null,
			), // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
			'token of a skip'    => array( array( 'seconds' => $week ), 'sc_wpun_skip|plugin|hello.php|' . $week ),
			'other length token' => array( array( 'seconds' => $week ), 'sc_wpun_snooze|plugin|hello.php|' . DAY_IN_SECONDS ),
			'no seconds'         => array( array(), 'sc_wpun_snooze|plugin|hello.php' ),
			'other plugin token' => array( array( 'seconds' => $week ), 'sc_wpun_snooze|plugin|other.php|' . $week ),
		);
		foreach ( $cases as $name => $case ) {
			$this->assertSame( 'died', $this->click( 'sc_wpun_snooze', $case[0], $case[1] ), $name );
			$this->assertSame( 403, $this->died_with, $name );
			$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ), "$name wrote the option." );
		}

		wp_set_current_user( self::$editor_id );
		$this->assertSame( 'died', $this->click( 'sc_wpun_snooze', array( 'seconds' => $week ) ) );
		$this->assertSame( 403, $this->died_with );
		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) );
	}
}
