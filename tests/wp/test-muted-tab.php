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

		sc_wpun()->network_context()->delete_option( 'sc_wpun_muted' );
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

		$this->assertSame( 0, $xpath->query( '//table[not(contains(@class,"form-table"))]' )->length, 'No list of mutes; the form has its own layout table.' );
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
		sc_wpun()->network_context()->update_option(
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
		$this->assertArrayHasKey( 'hello.php', sc_wpun()->network_context()->get_option( 'sc_wpun_muted' )['plugin'], 'Still stored.' );

		sc_wpun_mutes()->mute( 'theme', 'twentytwentyfive' );
		$this->assertArrayNotHasKey( 'plugin', sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) );
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
		$this->assertSame( 1, $xpath->query( './fieldset[@class="sc-wpun-panel"]/legend/h3[normalize-space()="Mute a plugin or theme"]', $form )->length, 'The form is a panel with its heading as the title.' );
		$this->assertSame( 3, $xpath->query( './fieldset[@class="sc-wpun-panel"]/table[@class="form-table"]//tr', $form )->length );
		$this->assertSame( 1, $xpath->query( './/td/fieldset/legend[@class="screen-reader-text"][normalize-space()="What to mute"]', $form )->length );
		$this->assertSame( 2, $xpath->query( './/td/fieldset//input[@type="radio"][@name="mode"]', $form )->length );
		$this->assertSame( 1, $xpath->query( './/th/label[@for="sc-wpun-mute-snooze-until"]', $form )->length );
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
		$before = sc_wpun()->network_context()->get_option( 'sc_wpun_muted' );
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

		$this->assertSame( $before, sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) );
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
				'expires'   => null,
				'source'    => 'api',
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

	// Snoozing (#346).

	/**
	 * A snooze shows its end in the site's timezone, and a version bound as well.
	 */
	public function test_a_snooze_shows_its_end_in_the_site_timezone() {
		update_option( 'timezone_string', 'Europe/Berlin' );
		$expires = gmmktime( 12, 0, 0, 6, 1, 2036 ); // 14:00 in Berlin.
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', null, 'settings', $expires );
		sc_wpun_mutes()->mute( 'theme', get_stylesheet(), '9.9.9', 'settings', $expires );

		$berlin = wp_date( (string) get_option( 'time_format' ), $expires, new DateTimeZone( 'Europe/Berlin' ) );
		$utc    = wp_date( (string) get_option( 'time_format' ), $expires, new DateTimeZone( 'UTC' ) );
		$this->assertNotSame( $berlin, $utc );

		$cells = $this->tab()->query( '//td[contains(@class,"sc-wpun-muted-mute")]' );
		$this->assertSame( 2, $cells->length );
		$first = preg_replace( '/\s+/', ' ', trim( $cells->item( 0 )->textContent ) );
		$this->assertStringStartsWith( 'Snoozed until June 1, 2036', $first );
		$this->assertStringEndsWith( $berlin, $first );
		$this->assertStringStartsWith( 'Version 9.9.9, until June 1, 2036', preg_replace( '/\s+/', ' ', trim( $cells->item( 1 )->textContent ) ) );
	}

	/**
	 * The source is shown next to the user, and left out when unknown.
	 */
	public function test_muted_by_shows_the_source() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', null, 'cli' );
		sc_wpun_mutes()->mute( 'theme', 'gone-theme', null, 'helpdesk' );
		sc_wpun_mutes()->mute( 'theme', 'old-theme' );
		$option                                 = sc_wpun()->network_context()->get_option( 'sc_wpun_muted' );
		$option['theme']['old-theme']['source'] = null;
		unset( $option['theme']['old-theme']['source'] );
		sc_wpun()->network_context()->update_option( 'sc_wpun_muted', $option );

		$by = array();
		foreach ( $this->tab()->query( '//td[contains(@class,"sc-wpun-muted-by")]' ) as $cell ) {
			$by[] = preg_replace( '/\s+/', ' ', trim( $cell->textContent ) );
		}
		$this->assertCount( 3, $by );
		$this->assertStringStartsWith( 'Ada Admin, from WP-CLI, ', $by[0] );
		$this->assertStringStartsWith( 'Ada Admin, from helpdesk, ', $by[1] );
		$this->assertStringNotContainsString( 'from', $by[2] );
		$this->assertStringStartsWith( 'Ada Admin, ', $by[2] );
	}

	/**
	 * The form has a labelled, described snooze field.
	 */
	public function test_the_form_has_a_snooze_field() {
		$xpath = $this->tab();

		$this->assertSame( 1, $xpath->query( '//label[@for="sc-wpun-mute-snooze-until"]' )->length );
		$input = $xpath->query( '//input[@type="datetime-local"][@id="sc-wpun-mute-snooze-until"][@name="snooze_until"]' )->item( 0 );
		$this->assertNotNull( $input );
		$this->assertSame( 1, $xpath->query( '//*[@id="' . $input->getAttribute( 'aria-describedby' ) . '"]' )->length );
	}

	/**
	 * A valid time snoozes in the site's timezone; with Skip it bounds the skip; empty is no expiry.
	 */
	public function test_adding_a_snooze() {
		update_option( 'timezone_string', 'Europe/Berlin' );
		$base = array(
			'item' => 'plugin:hello.php',
			'mode' => 'always',
		);

		$this->submit( Muted_Tab::ADD_ACTION, $base + array( 'snooze_until' => '2036-06-01T09:30' ), Muted_Tab::ADD_ACTION );
		$mute = sc_wpun_mutes()->get( 'plugin', 'hello.php' );
		$this->assertSame( gmmktime( 7, 30, 0, 6, 1, 2036 ), $mute->get_expires() );
		$this->assertTrue( $mute->is_always() );
		$this->assertSame( 'settings', $mute->get_source() );
		$this->assertStringContainsString( 'are snoozed until', $this->notice()['message'] );

		sc_wpun_mutes()->unmute( 'plugin', 'hello.php' );
		$this->submit(
			Muted_Tab::ADD_ACTION,
			array_merge(
				$base,
				array(
					'mode'         => 'skip',
					'snooze_until' => '2036-06-01T09:30:15',
				)
			),
			Muted_Tab::ADD_ACTION
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$mute = sc_wpun_mutes()->get( 'plugin', 'hello.php' );
		$this->assertSame( '2.0.0', $mute->get_until() );
		$this->assertSame( gmmktime( 7, 30, 15, 6, 1, 2036 ), $mute->get_expires() );
		$this->assertStringContainsString( 'skipped, until', $this->notice()['message'] );

		sc_wpun_mutes()->unmute( 'plugin', 'hello.php' );
		$this->submit( Muted_Tab::ADD_ACTION, $base + array( 'snooze_until' => '' ), Muted_Tab::ADD_ACTION );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' )->get_expires() );
	}

	/**
	 * A past or unparsable time shows an error and saves nothing.
	 */
	public function test_a_bad_snooze_time_saves_nothing() {
		foreach ( array(
			'past'       => '2001-01-01T10:00',
			'garbage'    => 'next tuesday',
			'impossible' => '2036-02-31T10:00',
			'date only'  => '2036-06-01',
			'bad month'  => '2036-13-01T10:00',
		) as $name => $value ) {
			$this->submit(
				Muted_Tab::ADD_ACTION,
				array(
					'item'         => 'plugin:hello.php',
					'mode'         => 'always',
					'snooze_until' => $value,
				),
				Muted_Tab::ADD_ACTION
			);
			$notice = $this->notice();
			$this->assertFalse( $notice['success'], $name );
			$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ), $name );
		}
		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) );
	}

	/**
	 * The unmuted installed items, as the form offers them, and the snapshot of the stored mutes.
	 *
	 * @param array $get The request's query arguments.
	 * @return DOMXPath
	 */
	private function linked( array $get ) {
		$_GET = $get;

		return $this->tab();
	}

	/**
	 * Whether the form is empty: the first option is not chosen on purpose, Always is ticked, no time, no note.
	 *
	 * @param DOMXPath $xpath The tab.
	 */
	private function assert_form_not_prefilled( DOMXPath $xpath ) {
		$this->assertSame( 0, $xpath->query( '//option[@selected]' )->length );
		$this->assertSame( 0, $xpath->query( '//select[@autofocus or @aria-describedby]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@id="sc-wpun-mute-prefilled"]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@name="mode"][@value="always"][@checked]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@name="mode"][@value="skip"][@checked]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@name="snooze_until"][@value]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[contains(@class,"sc-wpun-muted-note")]' )->length );
	}

	/**
	 * A link to an installed, unmuted plugin chooses it, focuses the list and says so in a note the list points to.
	 */
	public function test_a_link_fills_in_the_item() {
		$xpath = $this->linked(
			array(
				'mute_type' => 'plugin',
				'mute_key'  => 'hello.php',
			)
		);

		$this->assertSame( 1, $xpath->query( '//option[@selected]' )->length );
		$this->assertSame( 1, $xpath->query( '//option[@value="plugin:hello.php"][@selected]' )->length );
		$select = $xpath->query( '//select[@id="sc-wpun-mute-item"]' )->item( 0 );
		$this->assertTrue( $select->hasAttribute( 'autofocus' ) );
		$this->assertSame( 'sc-wpun-mute-prefilled', $select->getAttribute( 'aria-describedby' ) );
		$note = $xpath->query( '//form//fieldset/*[@id="sc-wpun-mute-prefilled"]' )->item( 0 );
		$this->assertNotNull( $note, 'The note is in the form.' );
		$this->assertSame( 'Pre-filled from a link: Hello Dolly. Check and press Mute.', trim( preg_replace( '/\s+/', ' ', $note->textContent ) ) );
		$this->assertSame( 1, $xpath->query( '//input[@name="mode"][@value="always"][@checked]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@name="snooze_until"][@value]' )->length );
	}

	/**
	 * A theme is chosen the same way.
	 */
	public function test_a_link_fills_in_a_theme() {
		$themes = array_keys( wp_get_themes() );
		$xpath  = $this->linked(
			array(
				'mute_type' => 'theme',
				'mute_key'  => $themes[0],
			)
		);

		$this->assertSame( 1, $xpath->query( '//option[@selected]' )->length );
		$this->assertSame( 1, $xpath->query( '//option[@value="theme:' . $themes[0] . '"][@selected]' )->length );
	}

	/**
	 * The mode: skip is ticked even when no update is waiting, always and snooze tick Always, anything else Always.
	 */
	public function test_a_link_fills_in_the_mode() {
		$item = array(
			'mute_type' => 'plugin',
			'mute_key'  => 'hello.php',
		);

		$xpath = $this->linked( $item + array( 'mute_mode' => 'skip' ) );
		$this->assertSame( 1, $xpath->query( '//input[@name="mode"][@value="skip"][@checked]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@name="mode"][@value="always"][@checked]' )->length );

		foreach ( array( 'always', 'snooze', 'ALWAYS', 'forever', '', 'skip,always' ) as $mode ) {
			$xpath = $this->linked( $item + array( 'mute_mode' => $mode ) );
			$this->assertSame( 1, $xpath->query( '//input[@name="mode"][@value="always"][@checked]' )->length, $mode );
			$this->assertSame( 0, $xpath->query( '//input[@name="mode"][@value="skip"][@checked]' )->length, $mode );
			$this->assertSame( 1, $xpath->query( '//option[@selected]' )->length, $mode );
		}
	}

	/**
	 * A snooze length fills in the end of the snooze in the site's time zone, in a form the submit accepts.
	 */
	public function test_a_link_fills_in_the_snooze() {
		update_option( 'timezone_string', 'Europe/Berlin' );
		$item = array(
			'mute_type'   => 'plugin',
			'mute_key'    => 'hello.php',
			'mute_mode'   => 'snooze',
			'mute_snooze' => (string) WEEK_IN_SECONDS,
		);

		$before = time();
		$xpath  = $this->linked( $item );
		$after  = time();
		$value  = $xpath->query( '//input[@type="datetime-local"][@name="snooze_until"]/@value' )->item( 0 )->nodeValue;
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value );
		$end = ( new DateTimeImmutable( $value, new DateTimeZone( 'Europe/Berlin' ) ) )->getTimestamp();
		$this->assertGreaterThanOrEqual( $before + WEEK_IN_SECONDS - 60, $end );
		$this->assertLessThanOrEqual( $after + WEEK_IN_SECONDS, $end );
		$this->assertSame( 1, $xpath->query( '//input[@name="mode"][@value="always"][@checked]' )->length );

		// Submitting what the form holds snoozes until then.
		$this->submit(
			Muted_Tab::ADD_ACTION,
			array(
				'item'         => 'plugin:hello.php',
				'mode'         => 'always',
				'snooze_until' => $value,
			),
			Muted_Tab::ADD_ACTION
		);
		$this->assertSame( $end, sc_wpun_mutes()->get( 'plugin', 'hello.php' )->get_expires() );
	}

	/**
	 * A snooze length is ignored with another mode, or if the list does not offer it.
	 */
	public function test_a_link_ignores_a_snooze_it_cannot_use() {
		$item = array(
			'mute_type' => 'plugin',
			'mute_key'  => 'hello.php',
		);
		foreach ( array(
			'mode always'  => array(
				'mute_mode'   => 'always',
				'mute_snooze' => (string) WEEK_IN_SECONDS,
			),
			'mode skip'    => array(
				'mute_mode'   => 'skip',
				'mute_snooze' => (string) WEEK_IN_SECONDS,
			),
			'no mode'      => array( 'mute_snooze' => (string) WEEK_IN_SECONDS ),
			'not listed'   => array(
				'mute_mode'   => 'snooze',
				'mute_snooze' => '12345',
			),
			'leading zero' => array(
				'mute_mode'   => 'snooze',
				'mute_snooze' => '0' . WEEK_IN_SECONDS,
			),
			'not a number' => array(
				'mute_mode'   => 'snooze',
				'mute_snooze' => 'week',
			),
			'negative'     => array(
				'mute_mode'   => 'snooze',
				'mute_snooze' => '-' . WEEK_IN_SECONDS,
			),
			'decimal'      => array(
				'mute_mode'   => 'snooze',
				'mute_snooze' => WEEK_IN_SECONDS . '.0',
			),
			'huge'         => array(
				'mute_mode'   => 'snooze',
				'mute_snooze' => '99999999999999999999999',
			),
			'array'        => array(
				'mute_mode'   => 'snooze',
				'mute_snooze' => array( (string) WEEK_IN_SECONDS ),
			),
		) as $name => $args ) {
			$xpath = $this->linked( $item + $args );
			$this->assertSame( 1, $xpath->query( '//option[@selected]' )->length, $name );
			$this->assertSame( 0, $xpath->query( '//input[@name="snooze_until"][@value]' )->length, $name );
		}
	}

	/**
	 * Lengths the filter adds are accepted.
	 */
	public function test_a_link_accepts_a_length_the_filter_adds() {
		add_filter(
			'sc_wpun_snooze_durations',
			function ( $durations ) {
				return array( 4 * HOUR_IN_SECONDS => '4 hours' ) + $durations;
			}
		);
		$xpath = $this->linked(
			array(
				'mute_type'   => 'plugin',
				'mute_key'    => 'hello.php',
				'mute_mode'   => 'snooze',
				'mute_snooze' => (string) ( 4 * HOUR_IN_SECONDS ),
			)
		);
		$this->assertSame( 1, $xpath->query( '//input[@name="snooze_until"][@value]' )->length );
	}

	/**
	 * What is not an installed plugin or theme, or is not complete, is ignored without a word.
	 */
	public function test_a_link_to_something_else_is_ignored() {
		foreach ( array(
			'not installed' => array(
				'mute_type' => 'plugin',
				'mute_key'  => 'nothing/nothing.php',
			),
			'unknown type'  => array(
				'mute_type' => 'core',
				'mute_key'  => 'hello.php',
			),
			'wrong type'    => array(
				'mute_type' => 'theme',
				'mute_key'  => 'hello.php',
			),
			'no key'        => array( 'mute_type' => 'plugin' ),
			'no type'       => array( 'mute_key' => 'hello.php' ),
			'empty'         => array(
				'mute_type' => '',
				'mute_key'  => '',
			),
			'arrays'        => array(
				'mute_type' => array( 'plugin' ),
				'mute_key'  => array( 'hello.php' ),
			),
			'only the mode' => array(
				'mute_mode'   => 'skip',
				'mute_snooze' => (string) WEEK_IN_SECONDS,
			),
		) as $name => $args ) {
			$xpath = $this->linked( $args + array( 'mute_mode' => 'skip' ) );
			$this->assertSame( 0, $xpath->query( '//option[@selected]' )->length, $name );
			$this->assertSame( 0, $xpath->query( '//select[@autofocus or @aria-describedby]' )->length, $name );
			$this->assertSame( 0, $xpath->query( '//*[@id="sc-wpun-mute-prefilled"]' )->length, $name );
			$this->assertSame( 0, $xpath->query( '//*[contains(@class,"sc-wpun-muted-note")]' )->length, $name );
			$this->assertSame( 0, $xpath->query( '//input[@name="snooze_until"][@value]' )->length, $name );
		}

		// Without mode and with the mode as given, an unlinked tab has the plain form.
		$this->assert_form_not_prefilled( $this->linked( array() ) );
	}

	/**
	 * Wrong spelling of the key is not matched, and the key is read as text, not as a key.
	 */
	public function test_a_link_needs_the_exact_key() {
		$this->assert_form_not_prefilled( $this->linked( array( 'mute_type' => 'plugin', 'mute_key' => 'HELLO.PHP' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$_GET = array(
			'mute_type' => 'plugin',
			'mute_key'  => wp_slash( 'hello.php' ),
		);
		$this->assertSame( 1, $this->tab()->query( '//option[@selected]' )->length, 'The request is unslashed, as WordPress adds slashes.' );
	}

	/**
	 * An item that is muted already, in any way, gives the plain form and a note with a link to its row.
	 */
	public function test_a_link_to_a_muted_item_says_so() {
		$item  = array(
			'mute_type' => 'plugin',
			'mute_key'  => 'hello.php',
			'mute_mode' => 'skip',
		);
		$mutes = array(
			'always'  => function () {
				sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
			},
			'version' => function () {
				sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0.0' );
			},
			'snooze'  => function () {
				sc_wpun_mutes()->mute( 'plugin', 'hello.php', null, 'api', time() + DAY_IN_SECONDS );
			},
			'code'    => function () {
				add_filter(
					'sc_wpun_mutes',
					function ( $mutes ) {
						$mutes[] = new \WPUN\Mute(
							array(
								'type' => 'plugin',
								'key'  => 'hello.php',
							)
						);
						return $mutes;
					}
				);
			},
		);
		foreach ( $mutes as $name => $mute ) {
			sc_wpun()->network_context()->delete_option( 'sc_wpun_muted' );
			remove_all_filters( 'sc_wpun_mutes' );
			$mute();

			$xpath = $this->linked( $item );
			$this->assertSame( 0, $xpath->query( '//option[@selected]' )->length, $name );
			$this->assertSame( 0, $xpath->query( '//option[@value="plugin:hello.php"]' )->length, $name );
			$this->assertSame( 0, $xpath->query( '//*[@id="sc-wpun-mute-prefilled"]' )->length, $name );
			$this->assertSame( 0, $xpath->query( '//select[@autofocus or @aria-describedby]' )->length, $name );
			$this->assertSame( 1, $xpath->query( '//input[@name="mode"][@value="always"][@checked]' )->length, $name );
			$note = $xpath->query( '//*[contains(@class,"sc-wpun-muted-note")]' )->item( 0 );
			$this->assertNotNull( $note, $name );
			$this->assertStringContainsString( 'Hello Dolly is already muted.', trim( preg_replace( '/\s+/', ' ', $note->textContent ) ), $name );
			$href = $xpath->query( './/a/@href', $note )->item( 0 )->nodeValue;
			$this->assertMatchesRegularExpression( '/^#sc-wpun-muted-plugin-[0-9a-f]{12}$/', $href, $name );
			$this->assertStringNotContainsString( 'hello', $href, 'The key is not in the id.' );
			$this->assertSame( 1, $xpath->query( '//tr[@id="' . substr( $href, 1 ) . '"]' )->length, $name );
		}
	}

	/**
	 * With every item muted the panel shows, and the note too.
	 */
	public function test_a_link_to_a_muted_item_says_so_when_everything_is_muted() {
		$installed = sc_wpun()->mute_actions()->installed();
		foreach ( $installed as $type => $items ) {
			foreach ( array_keys( $items ) as $key ) {
				sc_wpun_mutes()->mute( $type, (string) $key );
			}
		}

		$xpath = $this->linked(
			array(
				'mute_type' => 'plugin',
				'mute_key'  => 'hello.php',
			)
		);
		$this->assertSame( 1, $xpath->query( '//*[contains(@class,"sc-wpun-all-muted")]' )->length );
		$this->assertSame( 0, $xpath->query( '//form[contains(@class,"sc-wpun-mute-form")]' )->length );
		$href = $xpath->query( '//*[contains(@class,"sc-wpun-muted-note")]//a/@href' )->item( 0 )->nodeValue;
		$this->assertSame( 1, $xpath->query( '//tr[@id="' . substr( $href, 1 ) . '"]' )->length );
	}

	/**
	 * Rows have ids that differ by item and do not hold the key.
	 */
	public function test_rows_have_ids() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		sc_wpun_mutes()->mute( 'plugin', 'akismet/akismet.php' );
		$ids = array();
		foreach ( $this->tab()->query( '//tr[contains(@class,"sc-wpun-muted-item")]/@id' ) as $id ) {
			$ids[] = $id->nodeValue;
		}
		$this->assertCount( 2, array_unique( $ids ) );
		$this->assertSame( 0, count( preg_grep( '/^sc-wpun-muted-plugin-[0-9a-f]{12}$/', $ids, PREG_GREP_INVERT ) ) );
	}

	/**
	 * Showing the tab, with or without a link, mutes and changes nothing.
	 */
	public function test_a_link_changes_nothing() {
		$context = sc_wpun()->network_context();
		sc_wpun_mutes()->mute( 'plugin', 'akismet/akismet.php' );
		$stored = $context->get_option( 'sc_wpun_muted' );

		$this->tab();
		$this->linked(
			array(
				'mute_type'   => 'plugin',
				'mute_key'    => 'hello.php',
				'mute_mode'   => 'snooze',
				'mute_snooze' => (string) WEEK_IN_SECONDS,
			)
		);
		$this->linked(
			array(
				'mute_type' => 'plugin',
				'mute_key'  => 'akismet/akismet.php',
			)
		);

		$this->assertSame( $stored, $context->get_option( 'sc_wpun_muted' ) );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
		$this->assertNull( sc_wpun()->notices()->take() );
	}

	/**
	 * Only a user who can mute sees the note: without the capability there is no form and no note.
	 */
	public function test_a_link_shows_nothing_without_the_mute_capability() {
		add_filter( 'sc_wpun_mute_capability', static fn() => 'a_capability_nobody_has' );
		$xpath = $this->linked(
			array(
				'mute_type' => 'plugin',
				'mute_key'  => 'hello.php',
			)
		);
		$this->assertSame( 0, $xpath->query( '//form[contains(@class,"sc-wpun-mute-form")]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[contains(@class,"notice")]' )->length );
	}
}
