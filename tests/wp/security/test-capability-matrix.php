<?php
/**
 * Capability and nonce matrix over every admin-post and AJAX endpoint the plugin registers (#220).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channel_Form;
use WPUN\Admin\Channels_Tab;
use WPUN\Admin\Check_Now;
use WPUN\Channel_Instance;
use WPUN\Slack_Notice;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * The endpoints are found in `$wp_filter` after the admin hooks are registered, so a new endpoint is tested
 * without anyone writing a test: a hook without a request builder below fails the run.
 *
 * Who is refused, and who is not, follows `Settings_Page::capability()` (`manage_options`):
 *
 * - On a single site an administrator has it.
 * - On multisite a site administrator who is not a super admin has it on their own site too, so the matrix uses
 *   the same administrator in both runs. A super admin passes every capability check on multisite, so that case
 *   has its own positive control and is skipped on a single site.
 * - "Administrator without manage_options" removes the capability with the `user_has_cap` filter.
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
	 * Whether a callback belongs to the plugin: a method or invokable object of a plugin class, or a function or
	 * closure defined in a plugin file (not in the tests).
	 *
	 * @param mixed $callback The callback.
	 * @return string|null What it is, or null if it belongs to somebody else.
	 */
	private function plugin_callback( $callback ): ?string {
		$class_pattern = '/^(WPUN\\\\|WPUN_(?!Test_|Security_)|SC_WP_Updates_Notifier)/';

		if ( is_array( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

			return 1 === preg_match( $class_pattern, $class ) ? $class . '::' . $callback[1] : null;
		}

		if ( is_object( $callback ) && ! $callback instanceof Closure ) {
			$class = get_class( $callback );

			return 1 === preg_match( $class_pattern, $class ) ? $class . '::__invoke' : null;
		}

		if ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
			$file = (string) ( new ReflectionFunction( $callback ) )->getFileName();
			$file = (string) realpath( $file );
			$root = (string) realpath( dirname( SC_WPUN_FILE ) );
			if ( '' !== $root && 0 === strpos( $file, $root . DIRECTORY_SEPARATOR ) && false === strpos( substr( $file, strlen( $root ) ), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) {
				return ( $callback instanceof Closure ? 'closure' : (string) $callback ) . ' in ' . substr( $file, strlen( $root ) + 1 );
			}
		}

		return null;
	}

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
		$this->assertCount( 7, $found, 'The plugin registers seven admin-post endpoints: ' . implode( ', ', array_keys( $found ) ) );
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
		$row     = static function ( string $action, string $method, string $nonce, bool $bound, array $fields, callable $changes ): array {
			return array(
				'hook'    => 'admin_post_' . $action,
				'method'  => $method,
				'action'  => $action,
				'nonce'   => $nonce,
				'bound'   => $bound,
				'fields'  => $fields,
				'changes' => $changes,
			);
		};

		$specs = array(
			$row(
				$actions['dismiss'],
				'GET',
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
		delete_option( 'sc_wpun_state' );
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
	 * Everything a request could change, as it is stored.
	 *
	 * @return array
	 */
	private function snapshot(): array {
		global $wpdb;

		$options = array();
		foreach ( array( 'sc_wpun_settings', 'sc_wpun_channels', 'sc_wpun_state' ) as $name ) {
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
	private function request( array $spec, ?string $nonce, string $method, string $id = 'victim' ): string {
		$fields = $spec['fields'];
		if ( isset( $fields['id'] ) ) {
			$fields['id'] = $id;
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
	private function nonce_for( array $spec, string $id = 'victim' ): string {
		return wp_create_nonce( sprintf( $spec['nonce'], $id ) );
	}

	// The matrix.

	/**
	 * What is refused: the user or the nonce is wrong, and the request is otherwise valid.
	 *
	 * @return array<string, array{0: string, 1: string}> Who sends it and what is wrong with the nonce.
	 */
	public function refused_cases() {
		return array(
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

			$this->seed();
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
		$specs = $this->specs();
		$this->assertSame( array(), $this->coverage_problems( $this->plugin_endpoints() ) );

		foreach ( array_keys( $this->plugin_endpoints() ) as $hook ) {
			$spec = $specs[ $hook ];
			$this->seed();
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
			$this->seed();
			$this->be( 'super_admin' );

			$this->assertSame( 'redirected', $this->request( $spec, $this->nonce_for( $spec ), $spec['method'] ), "$hook refused a super admin." );
		}
	}
}
