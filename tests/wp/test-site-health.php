<?php
/**
 * The Site Health tests and the debug information (#80).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Scheduler;
use WPUN\Site_Health;

/**
 * Every status of every test, their boundaries, and what the site info shows and never shows.
 */
class Test_Site_Health extends WPUN_Test_Case {

	const SECRET = 'bad-SECRET-VALUE-123';
	const HOUR   = 3600;

	/**
	 * Start with hourly checks and the email channel on.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->config()->flush();
		$this->save_settings();
		$this->save_state();
		wp_clear_scheduled_hook( Scheduler::CRON_NAME );
	}

	/**
	 * Remove what tests registered.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( 'test_secret' );
		sc_wpun()->config()->flush();
		wp_clear_scheduled_hook( Scheduler::CRON_NAME );
		parent::tear_down();
	}

	/**
	 * Schedule the check with its next run at a time.
	 *
	 * @param int    $timestamp When it is due.
	 * @param string $schedule  Schedule name.
	 */
	private function schedule( $timestamp, $schedule = 'hourly' ) {
		wp_clear_scheduled_hook( Scheduler::CRON_NAME );
		wp_schedule_event( $timestamp, $schedule, Scheduler::CRON_NAME );
	}

	/**
	 * Site Health with WP-Cron switched on or off, since the constant cannot be changed in a test.
	 *
	 * @param bool $disabled Whether DISABLE_WP_CRON is set.
	 * @return Site_Health
	 */
	private function site_health( $disabled ) {
		$plugin = sc_wpun();

		return new class( $plugin->settings(), $plugin->state(), $plugin->channel_store(), $plugin->channels(), $plugin->config(), $plugin->status_report(), $disabled ) extends Site_Health {
			/**
			 * Whether DISABLE_WP_CRON is set.
			 *
			 * @var bool
			 */
			private $disabled;

			/**
			 * Constructor.
			 *
			 * @param WPUN\Settings         $settings Settings.
			 * @param WPUN\State            $state    State.
			 * @param WPUN\Channel_Store    $store    Instances.
			 * @param WPUN\Channel_Registry $registry Modules.
			 * @param WPUN\Config           $config   Resolver.
			 * @param WPUN\Status_Report    $report   Report.
			 * @param bool                  $disabled Whether DISABLE_WP_CRON is set.
			 */
			public function __construct( $settings, $state, $store, $registry, $config, $report, $disabled ) {
				parent::__construct( $settings, $state, $store, $registry, $config, $report );
				$this->disabled = $disabled;
			}

			/**
			 * Whether DISABLE_WP_CRON is set.
			 *
			 * @return bool
			 */
			protected function wp_cron_disabled(): bool {
				return $this->disabled;
			}
		};
	}

	/**
	 * The result of a test as the Site Health screen would get it.
	 *
	 * @param string           $name   Test name.
	 * @param Site_Health|null $health Site Health to ask, the plugin's own by default.
	 * @return array|null Null if the test is not added.
	 */
	private function result( $name, $health = null ) {
		$tests = null === $health ? apply_filters( 'site_status_tests', array( 'direct' => array() ) ) : $health->add_tests( array( 'direct' => array() ) );
		if ( ! isset( $tests['direct'][ $name ] ) ) {
			return null;
		}
		$result = call_user_func( $tests['direct'][ $name ]['test'] );
		$this->assertSame( $name, $result['test'] );
		$this->assertSame( 'Updates Notifier', $result['badge']['label'] );
		$this->assertSame(
			array(
				'good'        => 'blue',
				'recommended' => 'orange',
				'critical'    => 'red',
			)[ $result['status'] ],
			$result['badge']['color']
		);
		$this->assertStringContainsString( 'page=wp-updates-notifier', $result['actions'] );

		return $result;
	}

	/**
	 * Store a delivery result for an instance.
	 *
	 * @param string $id      Instance id.
	 * @param bool   $success Whether it worked.
	 * @param string $message Message.
	 */
	private function save_last_result( $id, $success, $message ) {
		$state                    = sc_wpun()->state()->get();
		$state['channels'][ $id ] = array(
			'notified'     => array(),
			'skip_pending' => false,
			'last_result'  => array(
				'success' => $success,
				'message' => $message,
				'time'    => time(),
			),
		);
		update_option( 'sc_wpun_state', $state, false );
	}

	/**
	 * Register a module with a secret field and an enabled instance of it.
	 */
	private function add_secret_instance() {
		sc_wpun()->channels()->register(
			new Test_Fields_Channel(
				'test_secret',
				array(
					'token' => array(
						'type'   => 'secret',
						'secret' => true,
					),
					'label' => array( 'type' => 'text' ),
				)
			)
		);
		sc_wpun_channels()->save( new Channel_Instance( 'vault', 'test_secret', 'Vault', true, array( 'token' => self::SECRET ) ) );
	}

	/**
	 * Add a source of configuration values.
	 *
	 * @param array $values Values by key.
	 */
	private function add_source( array $values ) {
		$source = new Test_Config_Source( $values );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $source ) {
				$sources[] = $source;
				return $sources;
			}
		);
		sc_wpun()->config()->flush();
	}

	/**
	 * The site info section, serialised.
	 *
	 * @return string
	 */
	private function debug_text() {
		return wp_json_encode( apply_filters( 'debug_information', array() )[ Site_Health::SECTION ] );
	}

	// The schedule.

	/**
	 * A scheduled check that is on time is good, and the description has the frequency, next and last check.
	 */
	public function test_schedule_is_good_when_on_time() {
		$this->schedule( time() + self::HOUR );
		$this->save_state( time() - 60 );

		$result = $this->result( 'sc_wpun_schedule' );

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'Frequency: hourly', $result['description'] );
		$this->assertStringContainsString( 'Next check: ', $result['description'] );
		$this->assertStringNotContainsString( 'Last check: never', $result['description'] );
		$this->assertEmpty( $this->http_requests, 'No request.' );
	}

	/**
	 * Without the event nothing is checked.
	 */
	public function test_schedule_is_critical_when_not_scheduled() {
		$this->save_state( time() - 60 );

		$result = $this->result( 'sc_wpun_schedule' );

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'Next check: not scheduled', $result['description'] );
	}

	/**
	 * A check that was never made and is more than twice its interval late is critical; a minute inside the limit is not.
	 */
	public function test_schedule_overdue_boundary_without_a_last_check() {
		$this->schedule( time() - 2 * self::HOUR + 60 );
		$this->assertSame( 'good', $this->result( 'sc_wpun_schedule' )['status'], 'Just inside twice the interval.' );
		$this->assertStringContainsString( 'Last check: never', $this->result( 'sc_wpun_schedule' )['description'] );

		$this->schedule( time() - 2 * self::HOUR - 60 );
		$this->assertSame( 'critical', $this->result( 'sc_wpun_schedule' )['status'], 'Just outside twice the interval.' );
	}

	/**
	 * A recent check proves checks run even though the event never fires (a server cron runs them); an old one does not.
	 */
	public function test_schedule_last_check_boundary() {
		$this->schedule( time() - 10 * self::HOUR );

		$this->save_state( time() - 3 * self::HOUR + 60 );
		$this->assertSame( 'good', $this->result( 'sc_wpun_schedule' )['status'], 'Just inside three intervals.' );

		$this->save_state( time() - 3 * self::HOUR - 60 );
		$this->assertSame( 'critical', $this->result( 'sc_wpun_schedule' )['status'], 'Just outside three intervals.' );

		$this->save_state( false );
		$this->assertSame( 'critical', $this->result( 'sc_wpun_schedule' )['status'], 'Never checked.' );
	}

	/**
	 * The limits follow the frequency.
	 */
	public function test_schedule_uses_the_interval_of_the_frequency() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		$this->schedule( time() - 2 * DAY_IN_SECONDS + 60, 'daily' );

		$this->assertSame( 'good', $this->result( 'sc_wpun_schedule' )['status'] );

		$this->schedule( time() - 2 * DAY_IN_SECONDS - 60, 'daily' );
		$this->assertSame( 'critical', $this->result( 'sc_wpun_schedule' )['status'] );
	}

	/**
	 * A frequency that is no longer offered raises no notice: the event keeps its interval, or the default is used.
	 */
	public function test_schedule_with_a_frequency_that_is_not_offered() {
		$this->save_settings( array( 'frequency' => 'weekly' ) );
		add_filter( 'sc_wpun_frequencies', static fn() => array( 'hourly', 'daily' ) );

		// The event keeps the interval WordPress stored with it.
		$this->schedule( time() - 2 * WEEK_IN_SECONDS + 60, 'weekly' );
		$this->assertSame( 'good', $this->result( 'sc_wpun_schedule' )['status'] );
		$this->schedule( time() - 2 * WEEK_IN_SECONDS - 60, 'weekly' );
		$this->assertSame( 'critical', $this->result( 'sc_wpun_schedule' )['status'] );

		// Without an event it is critical, and nothing is undefined.
		wp_clear_scheduled_hook( Scheduler::CRON_NAME );
		$this->assertSame( 'critical', $this->result( 'sc_wpun_schedule' )['status'] );

		// An event whose schedule has gone is measured with the default frequency.
		wp_schedule_event( time() - 2 * self::HOUR - 60, 'hourly', Scheduler::CRON_NAME );
		$this->assertSame( 'critical', $this->result( 'sc_wpun_schedule' )['status'] );
	}

	// WP-Cron.

	/**
	 * The test is only there while WP-Cron is switched off.
	 */
	public function test_wp_cron_test_is_only_added_when_disabled() {
		$this->assertNull( $this->result( 'sc_wpun_wp_cron', $this->site_health( false ) ) );
		$this->assertNotNull( $this->result( 'sc_wpun_wp_cron', $this->site_health( true ) ) );
	}

	/**
	 * Switched off with no recent check is a recommendation; a recent check from a server cron makes it good.
	 */
	public function test_wp_cron_disabled_statuses() {
		$health = $this->site_health( true );

		$this->save_state( time() - 3 * self::HOUR - 60 );
		$result = $this->result( 'sc_wpun_wp_cron', $health );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'wp updates-notifier check', $result['description'] );

		$this->save_state( false );
		$this->assertSame( 'recommended', $this->result( 'sc_wpun_wp_cron', $health )['status'] );

		$this->save_state( time() - 3 * self::HOUR + 60 );
		$result = $this->result( 'sc_wpun_wp_cron', $health );
		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'server cron', $result['description'] );
	}

	// Channels.

	/**
	 * A channel that is on and available is good; nothing on is a recommendation, not critical.
	 */
	public function test_channels_statuses() {
		$this->assertSame( 'good', $this->result( 'sc_wpun_channels' )['status'] );

		$this->save_settings( array( 'email_notifications' => 0 ) );
		$result = $this->result( 'sc_wpun_channels' );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'nothing will be sent', $result['description'] );
	}

	/**
	 * An enabled instance without its module cannot send, so it does not count.
	 */
	public function test_channels_ignores_an_instance_without_its_module() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		sc_wpun_channels()->save( new Channel_Instance( 'gone', 'gone_module', 'Gone', true ) );

		$this->assertSame( 'recommended', $this->result( 'sc_wpun_channels' )['status'] );
	}

	/**
	 * Instances that are on but whose module is missing are listed with the reason.
	 */
	public function test_channel_modules_statuses() {
		$this->assertSame( 'good', $this->result( 'sc_wpun_channel_modules' )['status'] );

		sc_wpun_channels()->save( new Channel_Instance( 'gone', 'gone_module', 'Gone <b>one</b>', true ) );
		sc_wpun_channels()->save( new Channel_Instance( 'off', 'off_module', 'Switched off', false ) );
		$result = $this->result( 'sc_wpun_channel_modules' );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'Gone &lt;b&gt;one&lt;/b&gt;', $result['description'] );
		$this->assertStringContainsString( 'unknown module', $result['description'] );
		$this->assertStringNotContainsString( 'Switched off', $result['description'], 'A channel that is off is not a problem.' );
	}

	/**
	 * A Slack instance is left to the Slack test while there is no Slack module.
	 */
	public function test_channel_modules_leaves_slack_to_its_own_test() {
		sc_wpun_channels()->save( new Channel_Instance( 'slack', 'slack', 'Slack', true ) );

		$this->assertSame( 'good', $this->result( 'sc_wpun_channel_modules' )['status'] );
		$this->assertNotNull( $this->result( 'sc_wpun_slack' ), 'The Slack test reports it.' );
	}

	/**
	 * A failed last delivery of an enabled instance is listed with its message; a later success clears it.
	 */
	public function test_channel_delivery_statuses() {
		$this->assertSame( 'good', $this->result( 'sc_wpun_channel_delivery' )['status'] );

		$this->save_last_result( 'email', true, 'Sent.' );
		$this->assertSame( 'good', $this->result( 'sc_wpun_channel_delivery' )['status'] );

		$this->save_last_result( 'email', false, 'The server said <no>.' );
		$result = $this->result( 'sc_wpun_channel_delivery' );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'The server said &lt;no&gt;.', $result['description'] );
		$this->assertStringContainsString( 'Email', $result['description'] );

		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->save_last_result( 'email', false, 'Old failure.' );
		$this->assertSame( 'good', $this->result( 'sc_wpun_channel_delivery' )['status'], 'A channel that is off is not reported.' );
		$this->assertEmpty( $this->http_requests, 'No request.' );
	}

	// Debug information.

	/**
	 * The section is public, not private, and lists settings with their source, the schedule and the channels.
	 */
	public function test_debug_info_lists_settings_schedule_and_channels() {
		$this->schedule( time() + self::HOUR );
		$this->save_state( time() - 60 );
		$this->save_last_result( 'email', false, 'It failed.' );

		$section = apply_filters( 'debug_information', array() )[ Site_Health::SECTION ];

		$this->assertSame( 'WP Updates Notifier', $section['label'] );
		$this->assertArrayNotHasKey( 'private', $section );
		$this->assertSame( 'hourly (database)', $section['fields']['setting_frequency']['value'] );
		$this->assertSame( 'hourly', $section['fields']['schedule_frequency']['value'] );
		$this->assertStringNotContainsString( 'never', $section['fields']['schedule_last']['value'] );
		$this->assertContains( $section['fields']['wp_cron_disabled']['debug'], array( 'true', 'false' ) );
		$this->assertStringContainsString( 'Module: email', $section['fields']['channel_email']['value'], 'Shown in the site language.' );
		$this->assertStringContainsString( 'Enabled: Yes', $section['fields']['channel_email']['value'] );
		$channel = $section['fields']['channel_email']['debug'];
		$this->assertStringContainsString( 'module: email', $channel, 'Copied in English.' );
		$this->assertStringContainsString( 'availability: available', $channel );
		$this->assertStringContainsString( 'enabled: yes', $channel );
		$this->assertStringContainsString( 'failure', $channel );
		$this->assertStringContainsString( 'It failed.', $channel );
		$this->assertStringContainsString( 'format: text (database)', $channel );
		$this->assertEmpty( $this->http_requests, 'No request.' );
	}

	/**
	 * The source of a setting shows, and a value that is not valid is listed by key and source only.
	 */
	public function test_debug_info_shows_sources_and_config_problems() {
		$this->add_source(
			array(
				'SC_WPUN_SETTING_FREQUENCY'   => 'daily',
				'SC_WPUN_SETTING_NOTIFY_CORE' => 'every-so-often',
			)
		);

		$section = apply_filters( 'debug_information', array() )[ Site_Health::SECTION ];

		$this->assertStringStartsWith( 'daily (', $section['fields']['setting_frequency']['value'] );
		$this->assertStringNotContainsString( '(database)', $section['fields']['setting_frequency']['value'] );
		$this->assertSame( 'daily (Test vault)', $section['fields']['setting_frequency']['value'] );
		$this->assertSame( 'all (database)', $section['fields']['setting_notify_core']['value'], 'The invalid value is not used.' );
		$this->assertSame( 'Configuration problem', $section['fields']['problem_0']['label'] );
		$this->assertStringContainsString( 'SC_WPUN_SETTING_NOTIFY_CORE', $section['fields']['problem_0']['value'] );
		$this->assertStringNotContainsString( 'every-so-often', $this->debug_text() );
	}

	/**
	 * A secret is set or not set, whatever the source.
	 */
	public function test_debug_info_never_shows_a_secret() {
		$this->add_secret_instance();

		$text = $this->debug_text();
		$this->assertStringContainsString( 'token: set (database)', $text );
		$this->assertStringNotContainsString( self::SECRET, $text );

		sc_wpun_channels()->save( new Channel_Instance( 'vault', 'test_secret', 'Vault', true ) );
		$this->add_source( array( 'SC_WPUN_CHANNEL_VAULT__TOKEN' => self::SECRET ) );
		$text = $this->debug_text();
		$this->assertStringContainsString( 'token:', $text );
		$this->assertStringNotContainsString( self::SECRET, $text );
	}

	/**
	 * No email address is left anywhere in the section: not in values, labels, messages or encoded lists.
	 */
	public function test_debug_info_masks_email_addresses() {
		$this->save_settings(
			array(
				'notify_to'       => 'ops@example.org, admin@example.com',
				'notify_from'     => 'Notifier <notifier@example.org>',
				'notify_reply_to' => 'reply.to+tag@sub.example.co.uk',
			)
		);
		sc_wpun_channels()->save( new Channel_Instance( 'person', 'gone_module', 'Mail to boss@example.net', true ) );
		$this->save_last_result( 'email', false, 'Invalid address: (From): wordpress@localhost' );
		$this->add_source( array( 'SC_WPUN_SETTING_FREQUENCY' => 'bad@example.org' ) );

		$text = $this->debug_text();

		$this->assertDoesNotMatchRegularExpression( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+/', $text, 'No address at all.' );
		foreach ( array( 'ops', 'admin@', 'notifier@', 'reply.to', 'boss', 'wordpress@', 'bad@' ) as $part ) {
			$this->assertStringNotContainsString( $part . ( '@' === substr( $part, -1 ) ? '' : '@' ), $text );
		}
		$this->assertStringContainsString( '[email redacted]', $text );
		$this->assertStringContainsString( 'Invalid address: (From): [email redacted]', $text );
	}

	/**
	 * DISABLE_WP_CRON shows in the site info.
	 */
	public function test_debug_info_shows_wp_cron_switched_off() {
		$info = $this->site_health( true )->add_debug_info( array() );

		$this->assertSame( 'true', $info[ Site_Health::SECTION ]['fields']['wp_cron_disabled']['debug'] );
	}

	/**
	 * Something other than arrays passes through untouched.
	 */
	public function test_filters_pass_other_values_through() {
		$health = $this->site_health( false );

		$this->assertSame( 'x', $health->add_tests( 'x' ) );
		$this->assertSame( 'x', $health->add_debug_info( 'x' ) );
	}
}
