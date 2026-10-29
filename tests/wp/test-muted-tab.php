<?php
/**
 * The Muted tab (#82) and Status_Report::muted().
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Muted_Tab;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties are camelCase.

/**
 * The list, the form that mutes, the unmute button and their handlers.
 */
class Test_Muted_Tab extends WPUN_Test_Case {

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
		self::$admin_id  = $factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Ada Admin',
			)
		);
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Status code of the last wp_die() call, if any.
	 *
	 * @var int|null
	 */
	private $died_with = null;

	/**
	 * An administrator, nothing muted, an update waiting for hello.php.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( 'sc_wpun_muted' );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		wp_set_current_user( self::$admin_id );
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
				throw new RuntimeException( 'wpun-redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test.
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

	/**
	 * The tab's markup.
	 *
	 * @return DOMXPath
	 */
	private function tab() {
		ob_start();
		sc_wpun()->muted_tab()->render();
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . ob_get_clean() . '</body>' );

		return new DOMXPath( $dom );
	}

	/**
	 * Send a POST form to a handler.
	 *
	 * @param string $action Admin-post action.
	 * @param array  $fields Fields.
	 * @param string $token  Token action, or null for none.
	 * @return string `died` or the redirect location.
	 */
	private function submit( $action, array $fields, $token ) {
		if ( null !== $token ) {
			$fields['_wpnonce'] = wp_create_nonce( $token );
		}
		$_POST    = $fields;
		$_REQUEST = $fields;
		sc_wpun()->register_admin_hooks();

		$this->died_with = null;
		try {
			do_action( 'admin_post_' . $action );
		} catch ( WPDieException $e ) {
			return 'died';
		} catch ( RuntimeException $e ) {
			return substr( $e->getMessage(), strlen( 'wpun-redirect:' ) );
		}

		return 'returned';
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
	 * The tab is listed after Channels and draws on its own.
	 */
	public function test_the_tab_is_listed_after_channels() {
		$_GET['tab'] = 'muted';
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/tab=channels.*tab=muted.*tab=about/s', $html );
		$this->assertMatchesRegularExpression( '/nav-tab-active" href="[^"]*tab=muted"/', $html );
		$this->assertStringContainsString( 'sc-wpun-mute-form', $html );
	}

	/**
	 * With nothing muted the tab explains muting and points to the Plugins screen, and still has the form.
	 */
	public function test_the_empty_state() {
		$xpath = $this->tab();

		$this->assertSame( 0, $xpath->query( '//table' )->length );
		$empty = $xpath->query( '//p[contains(@class,"sc-wpun-no-muted")]' );
		$this->assertSame( 1, $empty->length );
		$this->assertStringContainsString( 'Nothing is muted', $empty->item( 0 )->textContent );
		$this->assertSame( 1, $xpath->query( './/a[contains(@href,"plugins.php")]', $empty->item( 0 ) )->length );
		$this->assertSame( 1, $xpath->query( '//form[contains(@class,"sc-wpun-mute-form")]' )->length );
	}

	/**
	 * The list shows a plugin with its version skipped, a plugin muted for good that is gone, and a mute set in code.
	 */
	public function test_the_list() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0.0' );
		sc_wpun_mutes()->mute( 'theme', 'gone-theme' );
		add_filter(
			'sc_wpun_mutes',
			static function ( $mutes ) {
				$mutes[] = new WPUN\Mute(
					array(
						'type' => 'plugin',
						'key'  => 'akismet/akismet.php',
					)
				);
				return $mutes;
			}
		);
		$xpath = $this->tab();

		$rows = $xpath->query( '//table[contains(@class,"wp-list-table")]//tbody/tr' );
		$this->assertSame( 3, $rows->length );
		$this->assertSame( 5, $xpath->query( '//table//thead/th | //table//thead/tr/th[@scope="col"]' )->length );

		$text = array();
		foreach ( $rows as $row ) {
			$text[] = preg_replace( '/\s+/', ' ', trim( $row->textContent ) );
		}
		$all = implode( "\n", $text );

		// Skipped version of an installed plugin, with an update waiting, muted by the administrator.
		$this->assertStringContainsString( 'Hello Dolly Plugin', $all );
		$this->assertStringContainsString( 'Version 2.0.0', $all );
		$this->assertStringContainsString( 'Ada Admin, ', $all );
		$this->assertStringContainsString( 'Update to 2.0.0 waiting', $all );
		// A theme that is not installed shows its key.
		$this->assertStringContainsString( 'gone-theme Theme, not installed', $all );
		$this->assertStringContainsString( 'Always', $all );
		$this->assertStringContainsString( 'No update waiting', $all );
		// Set in code.
		$this->assertStringContainsString( 'Set in code', $all );

		$this->assertSame( 2, $xpath->query( '//td[contains(@class,"sc-wpun-muted-unmute")]//button[@type="submit"]' )->length, 'The mute set in code has no Unmute button.' );
		$this->assertSame( 1, $xpath->query( '//td[contains(@class,"sc-wpun-muted-unmute")][not(.//form)]' )->length );
		$this->assertSame( 2, $xpath->query( '//button[@aria-label="Unmute Hello Dolly" or @aria-label="Unmute gone-theme"]' )->length );
	}

	/**
	 * An ended skip mute is hidden, and pruned from the option on the next write.
	 */
	public function test_an_ended_mute_is_hidden_then_pruned() {
		// Stored directly: the store drops an ended mute when it writes.
		update_option(
			'sc_wpun_muted',
			array(
				'plugin' => array(
					'hello.php' => array(
						'until' => '1.5.0',
						'time'  => time(),
						'user'  => self::$admin_id,
					),
				),
			),
			false
		);
		$this->assertCount( 0, sc_wpun()->status_report()->muted(), 'Version 2.0.0 is waiting: the mute ended.' );
		$this->assertSame( 1, $this->tab()->query( '//p[contains(@class,"sc-wpun-no-muted")]' )->length );
		$this->assertArrayHasKey( 'hello.php', get_option( 'sc_wpun_muted' )['plugin'], 'Still stored.' );

		sc_wpun_mutes()->mute( 'theme', 'twentytwentyfive' );
		$this->assertArrayNotHasKey( 'plugin', get_option( 'sc_wpun_muted' ) );
	}

	/**
	 * The form offers the installed, unmuted plugins and themes in groups, with an Always and a Skip choice.
	 */
	public function test_the_form() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$xpath = $this->tab();

		$form = $xpath->query( '//form[contains(@class,"sc-wpun-mute-form")]' )->item( 0 );
		$this->assertStringContainsString( 'admin-post.php', $form->getAttribute( 'action' ) );
		$this->assertSame( 'post', $form->getAttribute( 'method' ) );
		$this->assertSame( 1, $xpath->query( './/input[@type="hidden"][@name="action"][@value="sc_wpun_muted_add"]', $form )->length );
		$this->assertSame( 1, $xpath->query( './/input[@type="hidden"][@name="_wpnonce"]', $form )->length );
		$this->assertSame( 1, $xpath->query( './/label[@for="sc-wpun-mute-item"]', $form )->length );
		$this->assertSame( 1, $xpath->query( './/select[@id="sc-wpun-mute-item"][@name="item"]', $form )->length );
		$this->assertSame( 2, $xpath->query( './/optgroup', $form )->length );
		$this->assertSame( 0, $xpath->query( './/option[@value="plugin:hello.php"]', $form )->length, 'A muted plugin is not offered.' );
		$this->assertGreaterThan( 0, $xpath->query( './/optgroup[@label="Plugins"]/option[starts-with(@value,"plugin:")]', $form )->length );
		$this->assertGreaterThan( 0, $xpath->query( './/optgroup[@label="Themes"]/option[starts-with(@value,"theme:")]', $form )->length );
		$this->assertSame( 1, $xpath->query( './/fieldset/legend', $form )->length );
		$this->assertSame( 2, $xpath->query( './/fieldset//input[@type="radio"][@name="mode"]', $form )->length );
		$this->assertSame( 1, $xpath->query( './/input[@type="radio"][@value="always"][@checked]', $form )->length );
		$this->assertSame( 0, $xpath->query( './/script' )->length );
	}

	/**
	 * A user with the settings capability but not the mute capability sees the list, no form and no Unmute button.
	 */
	public function test_without_the_mute_capability_the_tab_is_read_only() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		add_filter( 'sc_wpun_mute_capability', static fn() => 'a_capability_nobody_has' );
		$xpath = $this->tab();

		$this->assertSame( 1, $xpath->query( '//table//tbody/tr' )->length );
		$this->assertSame( 0, $xpath->query( '//form' )->length );
		$this->assertSame( 0, $xpath->query( '//button[@type="submit"]' )->length );
	}

	/**
	 * The form mutes a plugin for good, from the settings source, once.
	 */
	public function test_adding_a_mute() {
		$sources = array();
		add_action(
			'sc_wpun_item_muted',
			static function ( $mute, $source ) use ( &$sources ) {
				$sources[] = $source;
			},
			10,
			2
		);

		$to = $this->submit(
			Muted_Tab::ADD_ACTION,
			array(
				'item' => 'plugin:hello.php',
				'mode' => 'always',
			),
			Muted_Tab::ADD_ACTION
		);
		$this->assertStringContainsString( 'tab=muted', $to );
		$this->assertTrue( sc_wpun_mutes()->get( 'plugin', 'hello.php' )->is_always() );
		$this->assertSame( array( 'settings' ), $sources );
		$this->assertSame( 'Update notifications for Hello Dolly are muted.', $this->notice()['message'] );
	}

	/**
	 * Skipping the waiting version stores it; without a waiting update it is rejected with a notice.
	 */
	public function test_adding_a_skip_mute() {
		$fields = array(
			'item' => 'plugin:hello.php',
			'mode' => 'skip',
		);
		$this->submit( Muted_Tab::ADD_ACTION, $fields, Muted_Tab::ADD_ACTION );
		$this->assertSame( '2.0.0', sc_wpun_mutes()->get( 'plugin', 'hello.php' )->get_until() );
		$this->notice();

		sc_wpun_mutes()->unmute( 'plugin', 'hello.php' );
		$this->set_updates();
		$this->submit( Muted_Tab::ADD_ACTION, $fields, Muted_Tab::ADD_ACTION );
		$notice = $this->notice();
		$this->assertFalse( $notice['success'] );
		$this->assertStringContainsString( 'No update is waiting', $notice['message'] );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
	}

	/**
	 * A theme can be muted; an item that is not installed, or no choice, is rejected.
	 */
	public function test_adding_a_theme_and_bad_choices() {
		$theme = get_stylesheet();
		$this->submit(
			Muted_Tab::ADD_ACTION,
			array(
				'item' => 'theme:' . $theme,
				'mode' => 'always',
			),
			Muted_Tab::ADD_ACTION
		);
		$this->assertNotNull( sc_wpun_mutes()->get( 'theme', $theme ) );
		$this->notice();

		foreach ( array( 'plugin:gone/gone.php', 'core:core', 'nonsense', '' ) as $item ) {
			$this->submit(
				Muted_Tab::ADD_ACTION,
				array(
					'item' => $item,
					'mode' => 'always',
				),
				Muted_Tab::ADD_ACTION
			);
			$notice = $this->notice();
			$this->assertFalse( $notice['success'], "'$item' was accepted." );
		}
		$this->assertCount( 1, sc_wpun_mutes()->all() );
	}

	/**
	 * A refusal by the policy filter shows its message.
	 */
	public function test_a_policy_refusal_reaches_the_notice() {
		add_filter( 'sc_wpun_pre_mute', static fn() => new WP_Error( 'policy', 'Not this one.' ) );
		$this->submit(
			Muted_Tab::ADD_ACTION,
			array(
				'item' => 'plugin:hello.php',
				'mode' => 'always',
			),
			Muted_Tab::ADD_ACTION
		);
		$this->assertSame( 'Not this one.', $this->notice()['message'] );
	}

	/**
	 * Unmuting removes the mute from the settings source, once; a code mute is refused.
	 */
	public function test_unmuting() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$sources = array();
		add_action(
			'sc_wpun_item_unmuted',
			static function ( $mute, $source ) use ( &$sources ) {
				$sources[] = $source;
			},
			10,
			2
		);

		$fields = array(
			'type' => 'plugin',
			'key'  => 'hello.php',
		);
		$to     = $this->submit( Muted_Tab::REMOVE_ACTION, $fields, Muted_Tab::REMOVE_ACTION . '|plugin|hello.php' );
		$this->assertStringContainsString( 'tab=muted', $to );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
		$this->assertSame( array( 'settings' ), $sources );
		$this->assertSame( 'Update notifications for Hello Dolly are on again.', $this->notice()['message'] );

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
		$this->submit( Muted_Tab::REMOVE_ACTION, $fields, Muted_Tab::REMOVE_ACTION . '|plugin|hello.php' );
		$this->assertFalse( $this->notice()['success'] );
		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
	}

	/**
	 * The handlers refuse a bad token, a token for another item, and users without both capabilities.
	 */
	public function test_refused_requests_answer_403() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$before = get_option( 'sc_wpun_muted' );
		$add    = array(
			'item' => 'theme:' . get_stylesheet(),
			'mode' => 'always',
		);
		$remove = array(
			'type' => 'plugin',
			'key'  => 'hello.php',
		);

		$this->assertSame( 'died', $this->submit( Muted_Tab::ADD_ACTION, $add, null ) );
		$this->assertSame( 403, $this->died_with );
		$this->assertSame( 'died', $this->submit( Muted_Tab::ADD_ACTION, $add, Muted_Tab::REMOVE_ACTION ) );
		$this->assertSame( 'died', $this->submit( Muted_Tab::REMOVE_ACTION, $remove, null ) );
		$this->assertSame( 'died', $this->submit( Muted_Tab::REMOVE_ACTION, $remove, Muted_Tab::REMOVE_ACTION . '_plugin_other.php' ) );
		$this->assertSame( 'died', $this->submit( Muted_Tab::REMOVE_ACTION, array(), Muted_Tab::REMOVE_ACTION . '__' ) );

		wp_set_current_user( self::$editor_id );
		$this->assertSame( 'died', $this->submit( Muted_Tab::ADD_ACTION, $add, Muted_Tab::ADD_ACTION ) );
		$this->assertSame( 'died', $this->submit( Muted_Tab::REMOVE_ACTION, $remove, Muted_Tab::REMOVE_ACTION . '|plugin|hello.php' ) );

		// The settings capability alone is not enough when the mute capability is another one.
		wp_set_current_user( self::$admin_id );
		add_filter( 'sc_wpun_mute_capability', static fn() => 'a_capability_nobody_has' );
		$this->assertSame( 'died', $this->submit( Muted_Tab::ADD_ACTION, $add, Muted_Tab::ADD_ACTION ) );
		$this->assertSame( 'died', $this->submit( Muted_Tab::REMOVE_ACTION, $remove, Muted_Tab::REMOVE_ACTION . '|plugin|hello.php' ) );

		$this->assertSame( $before, get_option( 'sc_wpun_muted' ) );
	}

	/**
	 * Status_Report::muted() is plain data.
	 */
	public function test_the_status_report_rows() {
		$this->assertSame( array(), sc_wpun()->status_report()->muted() );

		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0.0' );
		sc_wpun_mutes()->mute( 'theme', 'gone-theme' );
		$time = time();
		$rows = sc_wpun()->status_report()->muted();

		$this->assertCount( 2, $rows );
		$this->assertSame(
			array(
				'type'      => 'plugin',
				'key'       => 'hello.php',
				'name'      => 'Hello Dolly',
				'until'     => '2.0.0',
				'from_code' => false,
				'time'      => $rows[0]['time'],
				'user'      => self::$admin_id,
				'offered'   => '2.0.0',
			),
			$rows[0]
		);
		$this->assertEqualsWithDelta( $time, $rows[0]['time'], 5 );
		$this->assertNull( $rows[1]['name'], 'Not installed.' );
		$this->assertNull( $rows[1]['until'] );
		$this->assertNull( $rows[1]['offered'] );

		$theme = wp_get_theme()->get( 'Name' );
		sc_wpun_mutes()->mute( 'theme', get_stylesheet() );
		$names = wp_list_pluck( sc_wpun()->status_report()->muted(), 'name' );
		$this->assertContains( $theme, $names );
	}
}
