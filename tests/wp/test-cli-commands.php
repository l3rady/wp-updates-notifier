<?php
/**
 * The `wp updates-notifier` commands (#79).
 *
 * @package wp-updates-notifier
 */

use WPUN\CLI\Channels_Command;
use WPUN\CLI\Updates_Notifier_Command;
use WPUN\Channel_Instance;
use WPUN\Status_Report;

require_once __DIR__ . '/fixtures/wp-cli.php';

/**
 * A test channel with a secret and a plain field that can send test messages, and can need a newer API.
 */
class Test_Cli_Channel extends Test_Channel {

	/**
	 * What the channel needs.
	 *
	 * @var string
	 */
	public $constraint = '';

	/**
	 * Add a secret and a plain field to the fields of the test channel.
	 *
	 * @return array
	 */
	public function get_fields(): array {
		return array_merge(
			parent::get_fields(),
			array(
				'token' => array(
					'type'  => 'secret',
					'label' => 'Token',
				),
				'note'  => array(
					'type'  => 'text',
					'label' => 'Note',
				),
			)
		);
	}

	/**
	 * Test messages are supported.
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return 'test_message' === $feature;
	}

	/**
	 * The API constraint.
	 *
	 * @return string
	 */
	public function get_api_constraint(): string {
		return $this->constraint;
	}
}

/**
 * Output and exit behaviour of each command, and that the status never shows a secret.
 */
class Test_Cli_Commands extends WPUN_Test_Case {

	const SECRET = 'SUPERSECRET-9f3a';

	/**
	 * The channel.
	 *
	 * @var Test_Cli_Channel
	 */
	private $channel;

	/**
	 * Register the channel, start without instances or state and with a clean fake WP-CLI.
	 */
	public function set_up() {
		parent::set_up();

		WP_CLI::reset();
		$this->channel = new Test_Cli_Channel( 'clicmd' );
		sc_wpun()->channels()->unregister( 'clicmd' );
		sc_wpun()->channels()->register( $this->channel );
		delete_option( 'sc_wpun_channels' );
		delete_option( 'sc_wpun_state' );
		update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'daily' ) ) );
	}

	/**
	 * Forget the module.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( 'clicmd' );
		sc_wpun()->channels()->unregister( 'gatedcmd' );
		parent::tear_down();
	}

	/**
	 * Save an instance.
	 *
	 * @param string $id      Id.
	 * @param bool   $enabled Whether it is on.
	 * @param string $mode    What it does: succeed or fail.
	 * @param string $type    Module.
	 */
	private function add( $id = 'a', $enabled = true, $mode = 'succeed', $type = 'clicmd' ) {
		sc_wpun_channels()->save(
			new Channel_Instance(
				$id,
				$type,
				ucfirst( $id ) . ' label',
				$enabled,
				array(
					'behaviour' => $mode,
					'token'     => self::SECRET,
					'note'      => 'a note',
				)
			)
		);
	}

	/**
	 * A plugin and a core update are waiting.
	 */
	private function waiting() {
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
	}

	/**
	 * Run a command and return the message of the error it ended with, or null if it did not.
	 *
	 * @param string $method Method of the command.
	 * @param array  $assoc  Flags.
	 * @return string|null
	 */
	private function fails( $method, array $assoc = array() ) {
		try {
			( new Updates_Notifier_Command() )->$method( array(), $assoc );
		} catch ( Test_Cli_Error $e ) {
			return $e->getMessage();
		}

		return null;
	}

	/**
	 * Run a command that must not end with an error.
	 *
	 * @param string $method Method of the command.
	 * @param array  $assoc  Flags.
	 * @return string What it printed.
	 */
	private function run_command( $method, array $assoc = array() ) {
		( new Updates_Notifier_Command() )->$method( array(), $assoc );

		return WP_CLI::text();
	}

	/**
	 * The commands are registered, only by register_cli().
	 */
	public function test_register_cli_adds_both_commands() {
		sc_wpun()->register_cli();

		$this->assertSame(
			array(
				'updates-notifier'          => Updates_Notifier_Command::class,
				'updates-notifier channels' => Channels_Command::class,
			),
			WP_CLI::$commands
		);
		$this->assertFalse( defined( 'WP_CLI' ), 'The shared process is not a WP-CLI one.' );
	}

	/**
	 * A real check prints a line per instance and ends with a success.
	 */
	public function test_check_sends_and_reports() {
		$this->add( 'a' );
		$this->add( 'off', false );
		$this->waiting();

		$out = $this->run_command( 'check' );

		$this->assertStringContainsString( 'A label (a): sent 2 items', $out );
		$this->assertStringContainsString( 'Off label (off): switched off', $out );
		$this->assertStringContainsString( 'Success: Check finished. 1 channel instance was sent a message.', $out );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );

		WP_CLI::reset();
		$this->assertStringContainsString( 'A label (a): nothing new', $this->run_command( 'check' ) );
	}

	/**
	 * A dry run prints what would happen and the message, and sends and saves nothing.
	 */
	public function test_check_dry_run_prints_the_message_and_changes_nothing() {
		$this->add( 'a' );
		$this->waiting();

		$out = $this->run_command( 'check', array( 'dry-run' => true ) );

		$this->assertStringContainsString( 'A label (a): would send 2 items', $out );
		$this->assertStringContainsString( '99.0.0', $out );
		$this->assertStringContainsString( 'Success: Dry run finished, nothing was sent or changed. 1 channel instance would be sent a message.', $out );
		$this->assertSame( array(), $this->channel->received );
		$this->assertFalse( get_option( 'sc_wpun_state' ) );
	}

	/**
	 * A forced check sends what was notified again.
	 */
	public function test_check_force_sends_again() {
		$this->add( 'a' );
		$this->waiting();
		$this->run_command( 'check' );
		WP_CLI::reset();

		$this->assertStringContainsString( 'sent 2 items', $this->run_command( 'check', array( 'force' => true ) ) );
		$this->assertCount( 2, $this->channel->received['a'] );
	}

	/**
	 * The lines for waiting, skipped, reminder and nothing.
	 */
	public function test_check_describes_each_status() {
		sc_wpun_channels()->save( new Channel_Instance( 'rem', 'clicmd', 'Rem', true, array(), array( 'remind' => 'daily' ) ) );
		$this->add( 'wait' );
		$this->add( 'skipped' );
		$this->waiting();
		$this->save_channel_state( 'skipped', array(), true );
		$this->save_channel_state(
			'rem',
			array(
				'core'   => array( 'core' => '99.0.0' ),
				'plugin' => array( 'hello.php' => '2.0.0' ),
			)
		);
		$state                                     = $this->stored_state();
		$state['channels']['rem']['last_reminder'] = time() - 3 * DAY_IN_SECONDS;
		$state['channels']['wait']                 = array(
			'notified'     => array(),
			'skip_pending' => false,
			'retry_at'     => time() + 600,
		);
		update_option( 'sc_wpun_state', $state, false );

		$out = $this->run_command( 'check', array( 'dry-run' => true ) );

		$this->assertStringContainsString( 'Rem (rem): would send a reminder with 2 items', $out );
		$this->assertStringContainsString( 'Wait label (wait): waiting until ' . Status_Report::format_time( $state['channels']['wait']['retry_at'] ), $out );
		$this->assertStringContainsString( 'Skipped label (skipped): recorded without sending', $out );
	}

	/**
	 * A failed instance makes the command end with an error, after printing every line.
	 */
	public function test_check_exits_with_an_error_when_an_instance_fails() {
		$this->add( 'good' );
		$this->add( 'bad', true, 'fail' );
		$this->waiting();

		$error = $this->fails( 'check' );

		$this->assertSame( '1 channel instance failed.', $error );
		$this->assertStringContainsString( 'Good label (good): sent 2 items', WP_CLI::text() );
		$this->assertStringContainsString( 'Bad label (bad): failed: It failed.', WP_CLI::text() );
		$this->assertStringNotContainsString( 'Success', WP_CLI::text() );
	}

	/**
	 * A routing callback that throws is a failure; a dry run says the routing failed.
	 */
	public function test_check_reports_a_routing_failure() {
		$this->add( 'a' );
		$this->waiting();
		add_filter(
			'sc_wpun_channel_should_receive_item',
			static function () {
				throw new RuntimeException( 'No.' );
			}
		);
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Swallowing the notice the dispatcher raises under WP_DEBUG.
			static fn() => true,
			E_USER_NOTICE
		);
		try {
			$this->assertSame( '1 channel instance failed.', $this->fails( 'check', array( 'dry-run' => true ) ) );
			$this->assertStringContainsString( 'failed: the routing failed', WP_CLI::text() );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * With no instance switched on and available the check warns and does not fail.
	 */
	public function test_check_warns_without_a_usable_instance() {
		$this->add( 'off', false );
		$this->add( 'gone', true, 'succeed', 'nomodule' );
		$this->waiting();

		$out = $this->run_command( 'check' );

		$this->assertStringContainsString( 'Warning: No channel instance is switched on and available', $out );
		$this->assertStringNotContainsString( 'Success', $out );
		$this->assertFalse( get_option( 'sc_wpun_state' ) );
	}

	/**
	 * A test message goes through one instance.
	 */
	public function test_test_channel_sends() {
		$this->add( 'a' );
		$this->add( 'b' );

		$out = $this->run_command( 'test', array( 'channel' => 'a' ) );

		$this->assertStringContainsString( 'Success: Test message sent through A label.', $out );
		$this->assertCount( 1, $this->channel->received['a'] );
		$this->assertTrue( $this->channel->received['a'][0]->is_test() );
		$this->assertArrayNotHasKey( 'b', $this->channel->received );
	}

	/**
	 * Each way a single test is refused is an error with the reason.
	 */
	public function test_test_channel_refusals() {
		$this->add( 'off', false );
		$this->add( 'gone', true, 'succeed', 'nomodule' );
		$this->add( 'bad', true, 'fail' );
		$gated             = new Test_Cli_Channel( 'gatedcmd' );
		$gated->constraint = '>=99';
		sc_wpun()->channels()->register( $gated );
		$this->add( 'gated', true, 'succeed', 'gatedcmd' );
		$plain = new Test_Channel( 'plaincmd' );
		sc_wpun()->channels()->register( $plain );
		$this->add( 'plain', true, 'succeed', 'plaincmd' );

		$this->assertSame( 'There is no channel instance "nope".', $this->fails( 'test', array( 'channel' => 'nope' ) ) );
		$this->assertSame( 'Off label is switched off.', $this->fails( 'test', array( 'channel' => 'off' ) ) );
		$this->assertSame( 'Gone label cannot be used: unknown module.', $this->fails( 'test', array( 'channel' => 'gone' ) ) );
		$this->assertStringStartsWith( 'Gated label cannot be used: rejected: ', (string) $this->fails( 'test', array( 'channel' => 'gated' ) ) );
		$this->assertSame( 'Plain label cannot send a test message.', $this->fails( 'test', array( 'channel' => 'plain' ) ) );
		$this->assertSame( 'The test message could not be sent through Bad label: It failed.', $this->fails( 'test', array( 'channel' => 'bad' ) ) );
		$this->assertArrayNotHasKey( 'off', $this->channel->received );
		$this->assertArrayNotHasKey( 'gone', $this->channel->received );

		sc_wpun()->channels()->unregister( 'plaincmd' );
	}

	/**
	 * Without --channel every testable instance is tested, and a failure ends in an error.
	 */
	public function test_test_all() {
		$this->add( 'a' );
		$this->add( 'b' );
		$this->add( 'off', false );
		$this->add( 'gone', true, 'succeed', 'nomodule' );

		$out = $this->run_command( 'test' );

		$this->assertStringContainsString( 'A label (a): test message sent', $out );
		$this->assertStringContainsString( 'B label (b): test message sent', $out );
		$this->assertStringContainsString( 'Success: 2 test messages sent.', $out );
		$this->assertSame( array( 'a', 'b' ), array_keys( $this->channel->received ) );

		$this->add( 'bad', true, 'fail' );
		WP_CLI::reset();
		$this->assertSame( '1 test message could not be sent.', $this->fails( 'test' ) );
		$this->assertStringContainsString( 'Bad label (bad): It failed.', WP_CLI::text() );
	}

	/**
	 * Without a testable instance the test is an error.
	 */
	public function test_test_all_without_instances_is_an_error() {
		$this->add( 'off', false );

		$this->assertSame( 'No channel instance is switched on, available and able to send a test message.', $this->fails( 'test' ) );
	}

	/**
	 * The status table shows the settings, schedule, channels, fields and pending updates, and no secret.
	 */
	public function test_status_table_never_shows_a_secret() {
		$this->add( 'a' );
		$this->add( 'gone', true, 'succeed', 'nomodule' );
		$this->waiting();
		$this->save_channel_state( 'a', array( 'plugin' => array( 'hello.php' => '2.0.0' ) ) );

		$out = $this->run_command( 'status' );

		$this->assertStringNotContainsString( self::SECRET, $out );
		$this->assertStringContainsString( "frequency\tdaily\tdatabase", $out );
		$this->assertStringContainsString( "notify_core\tall\tdatabase", $out );
		$this->assertStringNotContainsString( 'show_about', $out );
		$this->assertStringContainsString( "token\tset\tdatabase", $out );
		$this->assertStringContainsString( "note\ta note\tdatabase", $out );
		$this->assertStringContainsString( "a\tA label\tclicmd\tavailable\tyes\tno", $out );
		$this->assertStringContainsString( "gone\tGone label\tnomodule\tunknown module\tyes", $out );
		$this->assertStringContainsString( 'Settings of a', $out );
		$this->assertStringNotContainsString( 'Settings of gone', $out );
		$this->assertStringContainsString( 'a (send)', $out );
		$this->assertMatchesRegularExpression( '/plugin\t[^\t]*\t[^\t]*\t2\.0\.0\tnotified\tno/', $out );
		$this->assertMatchesRegularExpression( '/core\tWordPress\t[^\t]*\t99\.0\.0\tnew\tno/', $out );
		$this->assertStringContainsString( 'never', $out );
	}

	/**
	 * The JSON status has the same data, and no secret either.
	 */
	public function test_status_json() {
		$this->add( 'a' );
		$this->add( 'gone', true, 'succeed', 'nomodule' );
		$this->waiting();

		$out  = $this->run_command( 'status', array( 'format' => 'json' ) );
		$data = json_decode( $out, true );

		$this->assertStringNotContainsString( self::SECRET, $out );
		$this->assertSame( array( 'settings', 'schedule', 'channels', 'muted', 'pending' ), array_keys( $data ) );
		$this->assertSame( 'daily', $data['schedule']['frequency'] );
		$this->assertSame( 'a', $data['channels'][0]['id'] );
		$this->assertSame( 'available', $data['channels'][0]['availability'] );
		$fields = array_column( $data['channels'][0]['fields'], 'value', 'field' );
		$this->assertSame( 'set', $fields['token'] );
		$this->assertSame( 'a note', $fields['note'] );
		$this->assertSame( array(), $data['channels'][1]['fields'], 'No module, no schema, no values.' );
		$this->assertSame( 'unknown module', $data['channels'][1]['availability'] );
	}

	/**
	 * An unset secret says so, and a rejected module is reported with its reason and no fields.
	 */
	public function test_status_unset_secret_and_rejected_module() {
		$gated             = new Test_Cli_Channel( 'gatedcmd' );
		$gated->constraint = '>=99';
		sc_wpun()->channels()->register( $gated );
		sc_wpun_channels()->save( new Channel_Instance( 'plain', 'clicmd', 'Plain', true, array( 'note' => 'n' ) ) );
		$this->add( 'gated', true, 'succeed', 'gatedcmd' );

		$data = sc_wpun()->status_report()->channels();

		$this->assertSame( 'not set', array_column( $data[0]['fields'], 'value', 'field' )['token'] );
		$this->assertStringStartsWith( 'rejected: ', $data[1]['availability'] );
		$this->assertSame( array(), $data[1]['fields'] );
		$this->assertStringNotContainsString( self::SECRET, WP_CLI::text() . wp_json_encode( $data ) );
	}

	/**
	 * Delivery results show with the last failure and the retry time.
	 */
	public function test_status_shows_the_last_result() {
		sc_wpun_channels()->save(
			new Channel_Instance(
				'bad',
				'clicmd',
				'Bad',
				true,
				array(
					'behaviour'   => 'limit',
					'retry_after' => 120,
				)
			)
		);
		$this->waiting();
		try {
			$this->fails( 'check' );
		} catch ( Test_Cli_Error $e ) {
			unset( $e );
		}

		$channel = sc_wpun()->status_report()->channels()[0];

		$this->assertFalse( $channel['last_result']['success'] );
		$this->assertSame( 'It is limiting requests.', $channel['last_failure']['message'] );
		$this->assertNotNull( $channel['retry_at'] );
	}

	/**
	 * The schedule says when the next check is due, and what to do when the checks are manual.
	 */
	public function test_status_schedule() {
		$report = sc_wpun()->status_report();
		sc_wpun()->scheduler()->enable_cron( 'daily' );
		$this->assertStringContainsString( gmdate( 'Y' ), $report->schedule()['next_run'] );

		wp_clear_scheduled_hook( WPUN\Scheduler::CRON_NAME );
		$this->assertSame( 'not scheduled', $report->schedule()['next_run'] );

		$this->save_state( 1700000000 );
		$this->assertStringContainsString( '2023-11-1', $report->schedule()['last_check'] );
	}

	/**
	 * The channels list shows the columns and honours the format and fields.
	 */
	public function test_channels_list() {
		$this->add( 'a' );
		$this->add( 'off', false );

		( new Channels_Command() )->list_( array(), array() );
		$out = WP_CLI::text();
		$this->assertStringContainsString( "id\tlabel\tmodule\tavailability\tenabled\tcode_defined\tlast_result", $out );
		$this->assertStringContainsString( "a\tA label\tclicmd\tavailable\tyes\tno\t", $out );
		$this->assertStringContainsString( "off\tOff label\tclicmd\tavailable\tno\tno\t", $out );
		$this->assertStringNotContainsString( self::SECRET, $out );

		WP_CLI::reset();
		( new Channels_Command() )->list_(
			array(),
			array(
				'format' => 'json',
				'fields' => 'id,enabled',
			)
		);
		$this->assertSame(
			array(
				array(
					'id'      => 'a',
					'enabled' => 'yes',
				),
				array(
					'id'      => 'off',
					'enabled' => 'no',
				),
			),
			json_decode( WP_CLI::$output[0], true )
		);
	}

	/**
	 * A last result shows in the list.
	 */
	public function test_channels_list_shows_the_last_result() {
		$this->add( 'a' );
		$this->waiting();
		$this->run_command( 'check' );
		WP_CLI::reset();

		( new Channels_Command() )->list_( array(), array() );

		$this->assertMatchesRegularExpression( '/a\tA label\tclicmd\tavailable\tyes\tno\tsuccess, \d{4}-\d\d-\d\d .*: Delivered\./', WP_CLI::text() );
	}

	/**
	 * Run mute, unmute or muted with arguments and return the message of the error it ended with, or null.
	 *
	 * @param string $method Method of the command.
	 * @param array  $args   Positional arguments.
	 * @param array  $assoc  Flags.
	 * @return string|null
	 */
	private function fails_with( $method, array $args, array $assoc = array() ) {
		try {
			( new Updates_Notifier_Command() )->$method( $args, $assoc );
		} catch ( Test_Cli_Error $e ) {
			return $e->getMessage();
		}

		return null;
	}

	/**
	 * Plugins are found by directory, by file, by single file name with or without .php, and themes by stylesheet.
	 */
	public function test_mute_resolves_names_like_wp_plugin() {
		delete_option( 'sc_wpun_muted' );
		$events = array();
		add_action(
			'sc_wpun_item_muted',
			function ( $mute, $source ) use ( &$events ) {
				$events[] = array( $mute->get_key(), $source );
			},
			10,
			2
		);
		$theme = get_stylesheet();
		$own   = plugin_basename( SC_WPUN_FILE ); // A plugin in a directory.

		$this->assertNull( $this->fails_with( 'mute', array( 'plugin', 'hello', dirname( $own ) ) ) );
		$this->assertNull( $this->fails_with( 'mute', array( 'theme', $theme ) ) );

		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', $own ) );
		$this->assertNotNull( sc_wpun_mutes()->get( 'theme', $theme ) );
		$this->assertSame( array( 'hello.php', $own, $theme ), array_column( $events, 0 ) );
		$this->assertSame( array( 'cli' ), array_unique( array_column( $events, 1 ) ) );
		$this->assertStringContainsString( 'are muted', WP_CLI::text() );
	}

	/**
	 * An unknown name is an error, but the other names are still handled first.
	 */
	public function test_mute_unknown_name_fails_after_handling_the_rest() {
		delete_option( 'sc_wpun_muted' );

		$error = $this->fails_with( 'mute', array( 'plugin', 'nope', 'hello.php' ) );

		$this->assertSame( '1 of 2 could not be changed.', $error );
		$this->assertStringContainsString( 'Error: The plugin "nope" is not installed.', WP_CLI::text() );
		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );

		WP_CLI::reset();
		$this->assertNotNull( $this->fails_with( 'mute', array( 'theme', 'nope' ) ) );
		$this->assertStringContainsString( 'The theme "nope" is not installed.', WP_CLI::text() );
		$this->assertNotNull( $this->fails_with( 'mute', array( 'widget', 'x' ) ) );
		$this->assertNotNull( $this->fails_with( 'mute', array( 'plugin' ) ) );
	}

	/**
	 * Muting twice the same way is a warning; a different way changes the mute.
	 */
	public function test_mute_again_warns_and_until_next_version_skips_the_waiting_version() {
		delete_option( 'sc_wpun_muted' );
		$this->waiting();

		$this->assertNull( $this->fails_with( 'mute', array( 'plugin', 'hello' ), array( 'until-next-version' => true ) ) );
		$this->assertSame( '2.0.0', sc_wpun_mutes()->get( 'plugin', 'hello.php' )->get_until() );
		$this->assertStringContainsString( 'version 2.0.0 are skipped', WP_CLI::text() );

		WP_CLI::reset();
		$this->assertNull( $this->fails_with( 'mute', array( 'plugin', 'hello' ), array( 'until-next-version' => true ) ) );
		$this->assertSame( 'Warning: Hello Dolly is already muted that way.', WP_CLI::text() );

		WP_CLI::reset();
		$this->assertNull( $this->fails_with( 'mute', array( 'plugin', 'hello' ) ) );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' )->get_until(), 'Muting for good replaces the skip.' );

		WP_CLI::reset();
		$this->assertNull( $this->fails_with( 'mute', array( 'plugin', 'hello' ) ) );
		$this->assertStringContainsString( 'Warning: ', WP_CLI::text() );
	}

	/**
	 * Skipping a version needs an update to be waiting.
	 */
	public function test_mute_until_next_version_without_an_update_fails() {
		delete_option( 'sc_wpun_muted' );
		$this->set_updates();

		$error = $this->fails_with( 'mute', array( 'plugin', 'hello' ), array( 'until-next-version' => true ) );

		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'No update is waiting for', WP_CLI::text() );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
	}

	/**
	 * A filter that refuses a mute shows its message and fails.
	 */
	public function test_mute_refusal_is_an_error_with_the_message() {
		delete_option( 'sc_wpun_muted' );
		$refuse = function () {
			return new WP_Error( 'policy', 'Security plugins stay on.' );
		};
		add_filter( 'sc_wpun_pre_mute', $refuse );

		$error = $this->fails_with( 'mute', array( 'plugin', 'hello' ) );
		remove_filter( 'sc_wpun_pre_mute', $refuse );

		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'Error: Security plugins stay on.', WP_CLI::text() );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
	}

	/**
	 * Unmute removes the mute with source cli, warns when there is none, and reports a refusal.
	 */
	public function test_unmute() {
		delete_option( 'sc_wpun_muted' );
		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$sources = array();
		add_action(
			'sc_wpun_item_unmuted',
			function ( $mute, $source ) use ( &$sources ) {
				$sources[] = $source;
			},
			10,
			2
		);

		$this->assertNull( $this->fails_with( 'unmute', array( 'plugin', 'hello' ) ) );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
		$this->assertSame( array( 'cli' ), $sources );
		$this->assertStringContainsString( 'are on again', WP_CLI::text() );

		WP_CLI::reset();
		$this->assertNull( $this->fails_with( 'unmute', array( 'plugin', 'hello' ) ) );
		$this->assertSame( 'Warning: Hello Dolly is not muted.', WP_CLI::text() );

		WP_CLI::reset();
		$this->assertSame( '1 of 1 could not be changed.', $this->fails_with( 'unmute', array( 'plugin', 'nope' ) ) );

		sc_wpun_mutes()->mute( 'plugin', 'hello.php' );
		$refuse = function () {
			return new WP_Error( 'policy', 'Ask the maintainer.' );
		};
		add_filter( 'sc_wpun_pre_unmute', $refuse );
		WP_CLI::reset();
		$this->assertNotNull( $this->fails_with( 'unmute', array( 'plugin', 'hello' ) ) );
		remove_filter( 'sc_wpun_pre_unmute', $refuse );
		$this->assertStringContainsString( 'Error: Ask the maintainer.', WP_CLI::text() );
		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
	}

	/**
	 * The list shows each mute, the count format counts, and status has the Muted section.
	 */
	public function test_muted_list_count_and_status_section() {
		delete_option( 'sc_wpun_muted' );
		$this->waiting();
		$user_id = self::factory()->user->create( array( 'user_login' => 'muter' ) );
		wp_set_current_user( $user_id );
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '2.0.0' );

		$out = $this->run_command( 'muted' );
		$this->assertStringContainsString( "type\tname\tkey\tmute\tset_in_code\tmuted_by\tmuted_at\tupdate", $out );
		$this->assertMatchesRegularExpression( '/plugin\tHello Dolly\thello\.php\tversion 2\.0\.0\tno\tmuter\t\d{4}-\d\d-\d\d [^\t]+\t2\.0\.0/', $out );

		WP_CLI::reset();
		$this->assertSame( '1', $this->run_command( 'muted', array( 'format' => 'count' ) ) );

		WP_CLI::reset();
		$status = $this->run_command( 'status' );
		$this->assertStringContainsString( "Muted\ntype\tname\tkey", $status );
		$this->assertStringContainsString( "hello.php\tversion 2.0.0", $status );

		WP_CLI::reset();
		$data = json_decode( $this->run_command( 'status', array( 'format' => 'json' ) ), true );
		$this->assertSame( 'hello.php', $data['muted'][0]['key'] );
	}
}
