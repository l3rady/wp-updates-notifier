<?php
/**
 * Capability and nonce matrix over every admin-post and AJAX endpoint the plugin registers (#220).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channel_Form;
use WPUN\Admin\Channels_Tab;
use WPUN\Admin\Check_Now;
use WPUN\Admin\Muted_Tab;
use WPUN\Admin\Plugins_Screen_Mutes;
use WPUN\Channel_Instance;
use WPUN\Slack_Notice;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * The endpoints are found in `$wp_filter` after the admin hooks are registered, so a new endpoint is tested
 * without anyone writing a test: a hook without a request builder below fails the run.
 *
 * Who is refused, and who is not, follows `Settings_Page::capability()` (#328):
 *
 * - On a single site the capability is `manage_options`, which an administrator has.
 * - On multisite it is `manage_network_options`, which only a super admin has. A site administrator who is not a
 *   super admin is refused on every endpoint, so the administrator rows that expect to be let in run on a single
 *   site, and the super admin positive control runs on multisite and is skipped on a single site.
 * - "Administrator without manage_options" removes `manage_options` with the `user_has_cap` filter.
 *
 * Every refused request is otherwise valid: the nonce is created for the user who sends it, so that only the
 * check being tested can refuse it. Removing a `current_user_can()` check from a handler makes its row fail.
 *
 * @group security
 */
class Test_Security_Capability_Matrix extends WPUN_Security_Test_Case {

	/**
	 * Users by role: administrator, subscriber, editor.
	 *
	 * @var int[]
	 */
	private static $users = array();

	/**
	 * These tests need the default capability.
	 *
	 * @var bool
	 */
	protected $default_capability = true;

	/**
	 * Create the users.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$users = array(
			'administrator' => $factory->user->create( array( 'role' => 'administrator' ) ),
			'subscriber'    => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'editor'        => $factory->user->create( array( 'role' => 'editor' ) ),
		);
	}

	/**
	 * Status code of the last wp_die() call, if any.
	 *
	 * @var int|null
	 */
	private $died_with = null;

	/**
	 * Where the last redirect went, if any.
	 *
	 * @var string|null
	 */
	private $redirected_to = null;

	/**
	 * Register the admin hooks, and turn wp_die() and redirects into exceptions: a handler ends with `exit`.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->register_admin_hooks();
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
		parent::tear_down();
	}

	// Discovery.

	/**
	 * Every hook of the admin-post and AJAX families that the plugin hooks into.
	 *
	 * The family is any hook that starts with `admin_post` or `wp_ajax`, which takes in the `nopriv` variants.
	 *
	 * @return array<string, string[]> Callbacks by hook name.
	 */
	private function plugin_endpoints(): array {
		$found = array();

		foreach ( $GLOBALS['wp_filter'] as $hook => $wp_hook ) {
			if ( 1 !== preg_match( '/^(admin_post|wp_ajax)(_|$)/', (string) $hook ) ) {
				continue;
			}
			foreach ( $wp_hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $registered ) {
					$what = $this->plugin_callback( $registered['function'] );
					if ( null !== $what ) {
						$found[ (string) $hook ][] = $what;
					}
				}
			}
		}

		ksort( $found );

		return $found;
	}

	/**
	 * What is wrong with the coverage of a set of endpoints.
	 *
	 * @param array<string, string[]> $found Callbacks by hook name.
	 * @return string[]
	 */
	private function coverage_problems( array $found ): array {
		$problems = array();
		$specs    = $this->specs();

		foreach ( $found as $hook => $callbacks ) {
			if ( ! isset( $specs[ $hook ] ) ) {
				$problems[] = sprintf( 'The plugin registers %s (%s) but the capability matrix has no request builder for it. Add one to specs() in tests/wp/security/test-capability-matrix.php.', $hook, implode( ', ', $callbacks ) );
			}
		}
		foreach ( array_keys( $specs ) as $hook ) {
			if ( ! isset( $found[ $hook ] ) ) {
				$problems[] = sprintf( 'The matrix has a request builder for %s but the plugin no longer registers it. Remove the builder.', $hook );
			}
		}

		return $problems;
	}

	/**
	 * The plugin registers the endpoints the issue lists, and discovery finds them (an empty result must not pass).
	 */
	public function test_discovery_finds_the_known_endpoints() {
		$found = $this->plugin_endpoints();

		foreach ( $this->actions() as $name => $action ) {
			$this->assertArrayHasKey( 'admin_post_' . $action, $found, "Endpoint $name was not discovered." );
		}
		$this->assertCount( 12, $found, 'The plugin registers twelve admin-post endpoints: ' . implode( ', ', array_keys( $found ) ) );
	}

	/**
	 * Every discovered hook has a request builder, and every builder has a hook.
	 */
	public function test_every_endpoint_has_a_request_builder() {
		$this->assertSame( array(), $this->coverage_problems( $this->plugin_endpoints() ) );
	}

	/**
	 * A hook without a builder is reported, however the callback is written.
	 */
	public function test_an_endpoint_without_a_builder_fails_the_coverage_check() {
		$probes = array(
			'admin_post_wpun_probe_method'  => array( sc_wpun()->check_now(), 'handle' ),
			'wp_ajax_wpun_probe_function'   => 'sc_wpun',
			'wp_ajax_nopriv_wpun_probe'     => array( Check_Now::class, 'handle' ),
			'admin_post_nopriv_wpun_probe'  => 'sc_wpun',
			'admin_post_wpun_probe_foreign' => '__return_true',
		);
		foreach ( $probes as $hook => $callback ) {
			add_action( $hook, $callback );
		}

		$problems = $this->coverage_problems( $this->plugin_endpoints() );

		$this->assertCount( 4, $problems, implode( "\n", $problems ) );
		$this->assertStringContainsString( 'wp_ajax_wpun_probe_function', implode( "\n", $problems ) );
		$this->assertStringNotContainsString( 'wpun_probe_foreign', implode( "\n", $problems ), 'A core callback on a similar hook is not the plugin\'s.' );

		// The same failure message, as a test run would show it.
		$this->assertStringContainsString( 'has no request builder', $problems[0] );
	}

	/**
	 * No endpoint is open to logged-out visitors: no `nopriv` hook, for admin-post or AJAX.
	 */
	public function test_no_endpoint_is_open_to_visitors() {
		$nopriv = array_filter(
			array_keys( $this->plugin_endpoints() ),
			static function ( $hook ) {
				return false !== strpos( $hook, '_nopriv' );
			}
		);

		$this->assertSame( array(), array_values( $nopriv ), 'The plugin registers a hook for visitors who are not logged in.' );
	}

	// Requests.

	/**
	 * The admin-post action names.
	 *
	 * @return array<string, string>
	 */
	private function actions(): array {
		return array(
			'dismiss'   => Slack_Notice::DISMISS_ACTION,
			'test'      => Channels_Tab::TEST_ACTION,
			'toggle'    => Channels_Tab::TOGGLE_ACTION,
			'duplicate' => Channels_Tab::DUPLICATE_ACTION,
			'delete'    => Channels_Tab::DELETE_ACTION,
			'check_now' => Check_Now::ACTION,
			'save'      => Channel_Form::SAVE_ACTION,
			'mute'      => Plugins_Screen_Mutes::MUTE_ACTION,
			'unmute'    => Plugins_Screen_Mutes::UNMUTE_ACTION,
			'skip'      => Plugins_Screen_Mutes::SKIP_ACTION,
			'muted_add' => Muted_Tab::ADD_ACTION,
			'muted_del' => Muted_Tab::REMOVE_ACTION,
		);
	}

	/**
	 * How to make a valid request to each endpoint, and what it changes.
	 *
	 * - `hook`: the hook name.
	 * - `method`: the request method, which is the superglobal the handler reads.
	 * - `nonce`: the nonce action; `%s` is the id of the channel the nonce is bound to, if it is.
	 * - `bound`: whether the nonce is bound to a channel id.
	 * - `fields`: the other request fields.
	 * - `changes`: asserts the effect of a valid request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function specs(): array {
		$actions = $this->actions();
		$row     = static function ( string $action, string $method, string $nonce, bool $bound, array $fields, callable $changes, ?callable $setup = null, string $bound_id = 'victim' ): array {
			return array(
				'setup'    => $setup,
				'bound_id' => $bound_id,
				'hook'     => 'admin_post_' . $action,
				'method'   => $method,
				'action'   => $action,
				'nonce'    => $nonce,
				'bound'    => $bound,
				'fields'   => $fields,
				'changes'  => $changes,
			);
		};

		// The Plugins screen links bind the plugin file in `key`; the matrix passes it where it passes a channel id.
		$specs = array(
			$row(
				$actions['dismiss'],
				'POST',
				$actions['dismiss'],
				false,
				array(),
				function () {
					$this->assertSame( '1', get_user_meta( self::$users['administrator'], Slack_Notice::DISMISS_META, true ), 'The notice was not dismissed.' );
				}
			),
			$row(
				$actions['test'],
				'POST',
				$actions['test'] . '_%s',
				true,
				array( 'id' => 'victim' ),
				function () {
					$this->assertSame( 1, $this->sent_mail_count(), 'The test message was not sent.' );
				}
			),
			$row(
				$actions['toggle'],
				'POST',
				$actions['toggle'] . '_%s',
				true,
				array( 'id' => 'victim' ),
				function () {
					$this->assertFalse( sc_wpun_channels()->get( 'victim' )->is_enabled(), 'The channel was not switched off.' );
				}
			),
			$row(
				$actions['duplicate'],
				'POST',
				$actions['duplicate'] . '_%s',
				true,
				array( 'id' => 'victim' ),
				function () {
					$this->assertNotNull( sc_wpun_channels()->get( 'victim_copy' ), 'The channel was not duplicated.' );
				}
			),
			$row(
				$actions['delete'],
				'POST',
				$actions['delete'] . '_%s',
				true,
				array( 'id' => 'victim' ),
				function () {
					$this->assertNull( sc_wpun_channels()->get( 'victim' ), 'The channel was not deleted.' );
				}
			),
			$row(
				$actions['check_now'],
				'POST',
				$actions['check_now'],
				false,
				array(),
				function () {
					$this->assertGreaterThanOrEqual( 1, $this->sent_mail_count(), 'The check did not send.' );
					$this->assertNotEmpty( $this->stored_state()['last_check_time'], 'The check did not run.' );
				}
			),
			$row(
				$actions['save'],
				'POST',
				$actions['save'] . '_edit_%s',
				true,
				array(
					'mode'     => 'edit',
					'id'       => 'victim',
					'label'    => 'Renamed',
					'enabled'  => '1',
					'routing'  => array(
						'scope' => 'all',
						'core'  => 'all',
						'types' => array( '' ),
					),
					'settings' => array(
						'to'             => 'ops@example.org',
						'from'           => '',
						'reply_to'       => '',
						'format'         => 'text',
						'subject_prefix' => '',
					),
				),
				function () {
					$this->assertSame( 'Renamed', sc_wpun_channels()->get( 'victim' )->get_label(), 'The channel was not saved.' );
				}
			),
			$row(
				$actions['mute'],
				'GET',
				$actions['mute'] . '|plugin|%s',
				true,
				array(
					'type' => 'plugin',
					'key'  => 'hello.php',
				),
				function () {
					$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ), 'The plugin was not muted.' );
				},
				null,
				'hello.php'
			),
			$row(
				$actions['unmute'],
				'GET',
				$actions['unmute'] . '|plugin|%s',
				true,
				array(
					'type' => 'plugin',
					'key'  => 'hello.php',
				),
				function () {
					$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ), 'The plugin was not unmuted.' );
				},
				function () {
					sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
				},
				'hello.php'
			),
			$row(
				$actions['skip'],
				'GET',
				$actions['skip'] . '|plugin|%s|2.0.0',
				true,
				array(
					'type'    => 'plugin',
					'key'     => 'hello.php',
					'version' => '2.0.0',
				),
				function () {
					$this->assertSame( '2.0.0', sc_wpun_mutes()->get( 'plugin', 'hello.php' )->get_until(), 'The version was not skipped.' );
				},
				null,
				'hello.php'
			),
			$row(
				$actions['muted_add'],
				'POST',
				$actions['muted_add'],
				false,
				array(
					'item' => 'plugin:hello.php',
					'mode' => 'always',
				),
				function () {
					$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ), 'The plugin was not muted.' );
				},
				null,
				'hello.php'
			),
			$row(
				$actions['muted_del'],
				'POST',
				$actions['muted_del'] . '|plugin|%s',
				true,
				array(
					'type' => 'plugin',
					'key'  => 'hello.php',
				),
				function () {
					$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ), 'The plugin was not unmuted.' );
				},
				function () {
					sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
				},
				'hello.php'
			),
		);

		$by_hook = array();
		foreach ( $specs as $spec ) {
			$by_hook[ $spec['hook'] ] = $spec;
		}

		return $by_hook;
	}

	/**
	 * A site with something to lose: the default email channel, a second one the actions work on, an update to
	 * send, no history and no stored notices.
	 */
	private function seed() {
		sc_wpun()->config()->flush();
		$this->save_settings();
		sc_wpun_channels()->save( new Channel_Instance( 'victim', 'email', 'Victim', true, array( 'to' => 'ops@example.org' ) ) );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		$this->reset_http();
		$this->fake_http( 'api.wordpress.org/plugins/info', $this->fake_response( 200, wp_json_encode( array( 'name' => 'X', 'slug' => 'x', 'version' => '9', 'tested' => '99' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		foreach ( self::$users as $id ) {
			foreach ( array_keys( get_user_meta( $id ) ) as $key ) {
				if ( 0 === strpos( (string) $key, 'sc_wpun' ) ) {
					delete_user_meta( $id, $key );
				}
			}
		}
		reset_phpmailer_instance();
	}

	/**
	 * Seed the site, then do what the endpoint's request needs first.
	 *
	 * @param array $spec Row of specs().
	 */
	private function prepare( array $spec ) {
		$this->seed();
		sc_wpun()->network_context()->delete_option( 'sc_wpun_muted' );
		if ( null !== $spec['setup'] ) {
			wp_set_current_user( self::$users['administrator'] );
			$spec['setup']();
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Everything a request could change, as it is stored.
	 *
	 * @return array
	 */
	private function snapshot(): array {
		global $wpdb;

		$options = array();
		foreach ( array( 'sc_wpun_settings', 'sc_wpun_channels', 'sc_wpun_state', 'sc_wpun_muted' ) as $name ) {
			$options[ $name ] = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$meta = array();
		foreach ( self::$users as $role => $id ) {
			$meta[ $role ] = array_filter(
				get_user_meta( $id ),
				static function ( $key ) {
					return 0 === strpos( (string) $key, 'sc_wpun' );
				},
				ARRAY_FILTER_USE_KEY
			);
		}

		return array(
			'options'  => $options,
			'usermeta' => $meta,
			'mail'     => $this->sent_mail_count(),
			'http'     => $this->http_requests,
		);
	}

	/**
	 * Set the current user.
	 *
	 * @param string $who `logged_out`, `administrator`, `subscriber`, `editor`, `administrator_without_cap` or `super_admin`.
	 * @return int User id, 0 for a visitor.
	 */
	private function be( string $who ): int {
		if ( 'logged_out' === $who ) {
			wp_set_current_user( 0 );

			return 0;
		}

		if ( 'super_admin' === $who ) {
			$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			grant_super_admin( $id );
			wp_set_current_user( $id );

			return $id;
		}

		$role = 'administrator_without_cap' === $who ? 'administrator' : $who;
		$id   = self::$users[ $role ];
		wp_set_current_user( $id );
		if ( 'administrator_without_cap' === $who ) {
			add_filter(
				'user_has_cap',
				static function ( $allcaps ) {
					$allcaps['manage_options'] = false;

					return $allcaps;
				}
			);
			$this->assertFalse( current_user_can( 'manage_options' ), 'The capability was not removed.' );
		}

		return $id;
	}

	/**
	 * Make a request to an endpoint and say how it ended.
	 *
	 * @param array  $spec   The endpoint's row of specs().
	 * @param string $nonce  The nonce to send, or null for none.
	 * @param string $method The superglobal to put the fields in: `GET` or `POST`.
	 * @param string $id     Channel id to send, if the endpoint takes one.
	 * @return string `died`, `redirected` or `returned`.
	 * @throws RuntimeException Any exception the handler throws other than the redirect.
	 */
	private function request( array $spec, ?string $nonce, string $method, ?string $id = null ): string {
		$id     = $id ?? $spec['bound_id'];
		$fields = $spec['fields'];
		if ( isset( $fields['id'] ) ) {
			$fields['id'] = $id;
		}
		if ( isset( $fields['key'] ) ) {
			$fields['key'] = $id;
		}
		$fields['action'] = $spec['action'];
		if ( null !== $nonce ) {
			$fields['_wpnonce'] = $nonce;
		}

		$_GET                      = 'GET' === $method ? $fields : array();
		$_POST                     = 'POST' === $method ? $fields : array();
		$_REQUEST                  = $fields;
		$_SERVER['REQUEST_METHOD'] = $method;

		$this->died_with     = null;
		$this->redirected_to = null;

		ob_start();
		try {
			do_action( $spec['hook'] );
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
	 * The nonce a request needs, created for the current user.
	 *
	 * @param array  $spec Row of specs().
	 * @param string $id   Channel id the nonce is bound to.
	 * @return string
	 */
	private function nonce_for( array $spec, ?string $id = null ): string {
		return wp_create_nonce( sprintf( $spec['nonce'], $id ?? $spec['bound_id'] ) );
	}

	// The matrix.

	/**
	 * What is refused: the user or the nonce is wrong, and the request is otherwise valid.
	 *
	 * @return array<string, array{0: string, 1: string}> Who sends it and what is wrong with the nonce.
	 */
	public function refused_cases() {
		$cases = array(
			'logged out'                              => array( 'logged_out', 'valid' ),
			'subscriber'                              => array( 'subscriber', 'valid' ),
			'editor'                                  => array( 'editor', 'valid' ),
			'administrator without manage_options'    => array( 'administrator_without_cap', 'valid' ),
			'administrator, no nonce'                 => array( 'administrator', 'none' ),
			'administrator, empty nonce'              => array( 'administrator', 'empty' ),
			'administrator, nonce of another action'  => array( 'administrator', 'other_action' ),
			'administrator, nonce of another channel' => array( 'administrator', 'other_id' ),
			'administrator, nonce of another user'    => array( 'administrator', 'other_user' ),
			'administrator, fields in the wrong superglobal' => array( 'administrator', 'wrong_method' ),
		);

		// A site administrator who is not a super admin holds manage_options but not manage_network_options.
		if ( is_multisite() ) {
			$cases['site administrator, not a super admin'] = array( 'administrator', 'valid' );
		}

		return $cases;
	}

	/**
	 * A refused request dies with 403 and changes nothing, for every endpoint.
	 *
	 * @dataProvider refused_cases
	 *
	 * @param string $who   Who sends the request.
	 * @param string $nonce What is wrong with it.
	 */
	public function test_a_refused_request_changes_nothing( $who, $nonce ) {
		$specs  = $this->specs();
		$tested = 0;
		$this->assertNotEmpty( $this->plugin_endpoints() );
		$this->assertSame( array(), $this->coverage_problems( $this->plugin_endpoints() ) );

		foreach ( array_keys( $this->plugin_endpoints() ) as $hook ) {
			$spec = $specs[ $hook ];
			if ( 'other_id' === $nonce && ! $spec['bound'] ) {
				continue; // The nonce is not bound to a channel.
			}

			$this->prepare( $spec );
			$user = $this->be( $who );
			$send = $this->nonce_for( $spec );
			$as   = $spec['method'];
			switch ( $nonce ) {
				case 'none':
					$send = null;
					break;
				case 'empty':
					$send = '';
					break;
				case 'other_action':
					// The valid nonce of the next endpoint, for the same channel.
					$keys = array_keys( $specs );
					$next = $specs[ $keys[ ( array_search( $hook, $keys, true ) + 1 ) % count( $keys ) ] ];
					$send = $this->nonce_for( $next );
					break;
				case 'other_id':
					$send = $this->nonce_for( $spec, 'other' );
					break;
				case 'other_user':
					wp_set_current_user( self::$users['editor'] );
					$send = $this->nonce_for( $spec );
					wp_set_current_user( $user );
					break;
				case 'wrong_method':
					$as = 'GET' === $spec['method'] ? 'POST' : 'GET';
					break;
			}
			if ( in_array( $nonce, array( 'other_action', 'other_id', 'other_user' ), true ) ) {
				$this->assertNotSame( $this->nonce_for( $spec ), $send, "$hook: the nonce meant to be wrong is the right one." );
			}

			$before  = $this->snapshot();
			$outcome = $this->request( $spec, $send, $as );
			$after   = $this->snapshot();

			$this->assertSame( 'died', $outcome, "$hook ($who, $nonce nonce) was not refused." );
			$this->assertSame( 403, $this->died_with, "$hook ($who, $nonce nonce) did not answer 403." );
			$this->assertSame( $before['options'], $after['options'], "$hook ($who, $nonce nonce) changed an option." );
			$this->assertSame( $before['usermeta'], $after['usermeta'], "$hook ($who, $nonce nonce) changed user meta." );
			$this->assertSame( 0, $after['mail'], "$hook ($who, $nonce nonce) sent mail." );
			$this->assertSame( array(), $after['http'], "$hook ($who, $nonce nonce) made an HTTP request." );
			$this->assertNull( $this->redirected_to, "$hook ($who, $nonce nonce) redirected." );
			++$tested;
		}

		$this->assertGreaterThanOrEqual( 5, $tested, 'The matrix tested too few endpoints.' );
	}

	/**
	 * The same endpoints work for an administrator with the right nonce, so the refusals above are not vacuous.
	 */
	public function test_an_administrator_with_the_right_nonce_is_let_in() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On multisite only a super admin is let in, see the super admin test.' );
		}

		$specs = $this->specs();
		$this->assertSame( array(), $this->coverage_problems( $this->plugin_endpoints() ) );

		foreach ( array_keys( $this->plugin_endpoints() ) as $hook ) {
			$spec = $specs[ $hook ];
			$this->prepare( $spec );
			$this->be( 'administrator' );

			$before  = $this->snapshot();
			$outcome = $this->request( $spec, $this->nonce_for( $spec ), $spec['method'] );
			$after   = $this->snapshot();

			$this->assertSame( 'redirected', $outcome, "$hook did not accept an administrator with the right nonce." );
			$this->assertNull( $this->died_with, "$hook died." );
			$this->assertNotSame( $before, $after, "$hook accepted the request but changed nothing." );
			$spec['changes']();
		}
	}

	/**
	 * On multisite a super admin passes the capability check too.
	 */
	public function test_a_super_admin_is_let_in_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Super admins exist on multisite only.' );
		}

		$specs = $this->specs();
		foreach ( array_keys( $this->plugin_endpoints() ) as $hook ) {
			$spec = $specs[ $hook ];
			$this->prepare( $spec );
			$this->be( 'super_admin' );

			$this->assertSame( 'redirected', $this->request( $spec, $this->nonce_for( $spec ), $spec['method'] ), "$hook refused a super admin." );
		}
	}
}
