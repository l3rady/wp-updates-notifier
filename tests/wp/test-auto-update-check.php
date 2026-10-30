<?php
/**
 * The auto_update flag and note on the items of a check (#83).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;

/**
 * Flagged items carry the flag and the note, in the message, the dry run, reminders and Site Health.
 */
class Test_Auto_Update_Check extends WPUN_Test_Case {

	const NOTE = 'Automatic updates are on, but WordPress has not installed this update yet.';

	/**
	 * The fake channel.
	 *
	 * @var Test_Channel
	 */
	private $channel;

	/**
	 * One fake channel, no mutes, and a site where nothing is automatic.
	 */
	public function set_up() {
		parent::set_up();

		$this->channel = new Test_Channel( 'autotest' );
		sc_wpun()->channels()->unregister( 'autotest' );
		sc_wpun()->channels()->register( $this->channel );
		delete_option( 'sc_wpun_channels' );
		delete_option( 'sc_wpun_state' );
		delete_option( 'sc_wpun_muted' );
		update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'daily' ) ) );
		sc_wpun_channels()->save( new Channel_Instance( 'a', 'autotest', 'A', true, array( 'behaviour' => 'succeed' ), array( 'remind' => 'daily' ) ) );
		remove_filter( 'automatic_updater_disabled', '__return_true' ); // WordPress's own test bootstrap disables its updater.
		add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		delete_site_option( 'auto_update_plugins' );
		delete_site_option( 'auto_update_themes' );
	}

	/**
	 * Switch automatic updates on for the plugin and core minor releases.
	 */
	private function auto_on() {
		add_filter( 'auto_update_plugin', '__return_true' );
		add_filter( 'auto_update_theme', '__return_true' );
		add_filter( 'allow_minor_auto_core_updates', '__return_true' );
		add_filter( 'allow_dev_auto_core_updates', '__return_true' );
	}

	/**
	 * Waiting: a minor core release, a plugin and a theme.
	 */
	private function waiting() {
		$branch = implode( '.', array_slice( preg_split( '/[.-]/', wp_get_wp_version() ), 0, 2 ) );
		$this->set_updates(
			$branch . '.99',
			array( 'hello.php' => array( 'new_version' => '2.0.0' ) ),
			array( 'twentytwentyfive' => array( 'new_version' => '9.9.9' ) )
		);
	}

	/**
	 * Run the check for the CLI.
	 *
	 * @param bool $dry_run Dry run.
	 * @param bool $force   Force.
	 * @return array
	 */
	private function check( $dry_run = false, $force = false ) {
		$this->waiting();

		return sc_wpun()->update_checker()->run_check( 'cli', $dry_run, $force );
	}

	/**
	 * The pending items of the plan of an instance, by key.
	 *
	 * @param array $plans The plans.
	 * @return \WPUN\Notification_Item[]
	 */
	private function items( array $plans ) {
		$items = array();
		foreach ( $plans['a']['pending'] as $item ) {
			$items[ $item->get_key() ] = $item;
		}

		return $items;
	}

	/**
	 * With automatic updates on, core, plugin and theme carry the flag and the note after the compatibility notes.
	 */
	public function test_flagged_items_carry_the_flag_and_the_note() {
		$this->auto_on();

		$items = $this->items( $this->check( true ) );

		foreach ( array( 'core', 'hello.php', 'twentytwentyfive' ) as $key ) {
			$this->assertTrue( $items[ $key ]->get_flags()['auto_update'], $key );
			$this->assertSame( self::NOTE, array_slice( $items[ $key ]->get_notes(), -1 )[0], $key );
		}
		$this->assertGreaterThan( 1, count( $items['hello.php']->get_notes() ), 'The compatibility notes come first.' );
	}

	/**
	 * Without it the flag is false and there is no note.
	 */
	public function test_unflagged_items_have_a_false_flag_and_no_note() {
		add_filter( 'allow_minor_auto_core_updates', '__return_false' );

		$items = $this->items( $this->check( true ) );

		foreach ( array( 'core', 'hello.php', 'twentytwentyfive' ) as $key ) {
			$this->assertFalse( $items[ $key ]->get_flags()['auto_update'], $key );
			$this->assertNotContains( self::NOTE, $items[ $key ]->get_notes(), $key );
		}
	}

	/**
	 * Items added with sc_wpun_update_items get no flag from the plugin.
	 */
	public function test_items_from_the_filter_are_untouched() {
		$this->auto_on();
		add_filter(
			'sc_wpun_update_items',
			static function ( $items ) {
				$items[] = new \WPUN\Notification_Item(
					array(
						'type'        => 'php',
						'key'         => 'php',
						'name'        => 'PHP',
						'new_version' => '9',
					)
				);
				return $items;
			}
		);

		$items = $this->items( $this->check( true ) );

		$this->assertSame( array(), $items['php']->get_flags() );
		$this->assertSame( array(), $items['php']->get_notes() );
	}

	/**
	 * The public filter adds the flag to an item WordPress would not update, and removes it from one it would.
	 */
	public function test_the_public_filter_changes_the_flag() {
		add_filter( 'allow_minor_auto_core_updates', '__return_false' );
		add_filter(
			'sc_wpun_auto_update_enabled',
			static function ( $enabled, $type ) {
				return 'plugin' === $type;
			},
			10,
			2
		);

		$items = $this->items( $this->check( true ) );

		$this->assertTrue( $items['hello.php']->get_flags()['auto_update'] );
		$this->assertContains( self::NOTE, $items['hello.php']->get_notes() );
		$this->assertFalse( $items['twentytwentyfive']->get_flags()['auto_update'] );
	}

	/**
	 * A muted flagged item stays muted.
	 */
	public function test_a_muted_flagged_item_stays_muted() {
		$this->auto_on();
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );

		$items = $this->items( $this->check( true ) );

		$this->assertArrayNotHasKey( 'hello.php', $items );
		$this->assertArrayHasKey( 'twentytwentyfive', $items );
	}

	/**
	 * A dry run and a forced run show the same flag and note.
	 */
	public function test_dry_run_and_force_show_the_same() {
		$this->auto_on();

		$dry   = $this->items( $this->check( true ) );
		$force = $this->items( $this->check( false, true ) );

		foreach ( $dry as $key => $item ) {
			$this->assertSame( $item->get_flags(), $force[ $key ]->get_flags(), $key );
			$this->assertSame( $item->get_notes(), $force[ $key ]->get_notes(), $key );
		}
	}

	/**
	 * A reminder repeats the note.
	 */
	public function test_a_reminder_repeats_the_note() {
		$this->auto_on();
		$this->check();
		$state                                   = $this->stored_state();
		$state['channels']['a']['last_reminder'] = time() - 3 * DAY_IN_SECONDS;
		update_option( 'sc_wpun_state', $state, false );

		$this->check();

		$received = $this->channel->received['a'];
		$this->assertCount( 2, $received );
		$this->assertTrue( $received[1]->is_reminder() );
		foreach ( $received[1]->get_items() as $item ) {
			$this->assertContains( self::NOTE, $item->get_notes(), $item->get_key() );
		}
	}

	/**
	 * The email carries the note.
	 */
	public function test_the_email_contains_the_note() {
		$this->auto_on();
		update_option( 'sc_wpun_channels', array() );
		$this->save_settings();
		$this->waiting();

		sc_wpun()->update_checker()->do_update_check();

		$this->assertStringContainsString( self::NOTE, $this->sent_mail()->body );
	}

	/**
	 * The status report has the flag.
	 */
	public function test_the_status_report_has_the_flag() {
		add_filter( 'auto_update_plugin', '__return_true' );
		add_filter( 'allow_minor_auto_core_updates', '__return_false' );
		$this->waiting();

		$rows = array();
		foreach ( sc_wpun()->status_report()->pending()[0]['items'] as $row ) {
			$rows[ $row['key'] ] = $row['auto_update'];
		}

		$this->assertTrue( $rows['hello.php'] );
		$this->assertFalse( $rows['core'] );
	}

	/**
	 * Site Health says whether automatic updates can run, and why not.
	 */
	public function test_site_health_shows_each_state() {
		$field = static function () {
			return apply_filters( 'debug_information', array() )[ \WPUN\Site_Health::SECTION ]['fields']['auto_updates'];
		};

		$this->assertSame( 'WordPress automatic updates', $field()['label'] );
		$this->assertSame( 'Can run', $field()['value'] );
		$this->assertSame( 'Can run', $field()['debug'] );

		add_filter( 'automatic_updater_disabled', '__return_true' );
		$this->assertSame( 'Disabled by AUTOMATIC_UPDATER_DISABLED or the automatic_updater_disabled filter', $field()['value'] );
		remove_filter( 'automatic_updater_disabled', '__return_true' );

		add_filter( 'file_mod_allowed', '__return_false' );
		$this->assertSame( 'Disabled by DISALLOW_FILE_MODS', $field()['value'] );
		remove_filter( 'file_mod_allowed', '__return_false' );

		remove_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		add_filter( 'automatic_updates_is_vcs_checkout', '__return_true' );
		$this->assertSame( 'Version control checkout', $field()['value'] );
		$this->assertSame( 'Version control checkout', $field()['debug'] );
	}
}
