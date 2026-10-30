<?php
/**
 * A check runs WordPress's automatic updates before it reads plugin and theme updates (#83, ADR 0020).
 *
 * @package wp-updates-notifier
 */

/**
 * The version check at the start of a check.
 */
class Test_Auto_Updates_First extends WPUN_Test_Case {

	/**
	 * What happened, in order: `auto_update`, `plugins_read` and `themes_read`.
	 *
	 * @var string[]
	 */
	private $log = array();

	/**
	 * Core's own callback must not run: the real updater never touches the test install.
	 */
	public function set_up() {
		parent::set_up();

		$this->log = array();
		remove_action( 'wp_maybe_auto_update', 'wp_maybe_auto_update' );
		add_filter( 'pre_site_transient_update_plugins', array( $this, 'log_plugins_read' ) );
		add_filter( 'pre_site_transient_update_themes', array( $this, 'log_themes_read' ) );
	}

	/**
	 * Remove the hooks the tests add.
	 */
	public function tear_down() {
		remove_filter( 'pre_site_transient_update_plugins', array( $this, 'log_plugins_read' ) );
		remove_filter( 'pre_site_transient_update_themes', array( $this, 'log_themes_read' ) );
		remove_filter( 'wp_doing_cron', '__return_true' );
		remove_filter( 'wp_doing_cron', '__return_false' );
		parent::tear_down();
	}

	/**
	 * Records a read of the plugin update data without changing it.
	 *
	 * @param mixed $value Short-circuit value.
	 * @return mixed
	 */
	public function log_plugins_read( $value ) {
		$this->log[] = 'plugins_read';
		return $value;
	}

	/**
	 * Records a read of the theme update data without changing it.
	 *
	 * @param mixed $value Short-circuit value.
	 * @return mixed
	 */
	public function log_themes_read( $value ) {
		$this->log[] = 'themes_read';
		return $value;
	}

	/**
	 * Sets update data with a plugin and a theme waiting, and core's data stale so the version check asks the API.
	 *
	 * @param array $settings Setting overrides.
	 */
	private function prepare( array $settings = array() ) {
		$this->save_settings( $settings );
		$this->set_updates(
			null,
			array( 'hello.php' => array( 'new_version' => '9.9.9' ) ),
			array( 'twentytwentyfive' => array( 'new_version' => '9.9.9' ) )
		);
		$core               = get_site_transient( 'update_core' );
		$core->last_checked = 0;
		set_site_transient( 'update_core', $core );
		$this->fake_http(
			'api.wordpress.org/core/version-check',
			$this->fake_response(
				200,
				wp_json_encode(
					array(
						'offers'       => array(
							array(
								'response' => 'latest',
								'current'  => wp_get_wp_version(),
								'version'  => wp_get_wp_version(),
								'locale'   => 'en_US',
							),
						),
						'translations' => array(),
					)
				)
			)
		);
	}

	/**
	 * Stands in for WordPress's updater: logs, and "installs" the plugin when asked to.
	 *
	 * @param bool $installs Whether the plugin update is installed.
	 */
	private function simulate_updater( bool $installs ) {
		add_action(
			'wp_maybe_auto_update',
			function () use ( $installs ) {
				$this->log[] = 'auto_update';
				if ( $installs ) {
					$plugins           = get_site_transient( 'update_plugins' );
					$plugins->response = array();
					set_site_transient( 'update_plugins', $plugins );
				}
			}
		);
	}

	/**
	 * Runs a check as the cron event does.
	 *
	 * @param bool $cron Whether WordPress is running WP-Cron.
	 */
	private function run_as( bool $cron ) {
		add_filter( 'wp_doing_cron', $cron ? '__return_true' : '__return_false' );
		sc_wpun()->update_checker()->run_check( $cron ? 'cron' : 'cli' );
	}

	/**
	 * Assert the updater ran once, before any plugin or theme data was read.
	 */
	private function assert_updater_ran_first() {
		$this->assertSame( 1, count( array_keys( $this->log, 'auto_update', true ) ), 'WordPress\'s updater runs once.' );
		$this->assertSame( 'auto_update', $this->log[0], 'Nothing is read before it.' );
	}

	/**
	 * Provides the settings that switch one thing off, and the default.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public function settings_provider() {
		return array(
			'defaults'     => array( array() ),
			'core none'    => array( array( 'notify_core' => 'none' ) ),
			'plugins none' => array( array( 'notify_plugins' => 'none' ) ),
			'themes none'  => array( array( 'notify_themes' => 'none' ) ),
			'only core on' => array(
				array(
					'notify_plugins' => 'none',
					'notify_themes'  => 'none',
				),
			),
		);
	}

	/**
	 * Under WP-Cron the updater runs first, whatever is switched off.
	 *
	 * @dataProvider settings_provider
	 *
	 * @param array $settings Setting overrides.
	 */
	public function test_the_updater_runs_before_plugins_and_themes_are_read( array $settings ) {
		$this->prepare( $settings );
		$this->simulate_updater( false );

		$this->run_as( true );

		$this->assert_updater_ran_first();
		$this->assertCount( 1, $this->requests_to( 'api.wordpress.org/core/version-check' ), 'The version check asked the API.' );
	}

	/**
	 * A plugin the updater installs is not reported.
	 */
	public function test_an_update_installed_by_the_updater_is_not_reported() {
		$this->prepare(
			array(
				'notify_core'   => 'none',
				'notify_themes' => 'none',
			)
		);
		$this->simulate_updater( true );

		$this->run_as( true );

		$this->assert_updater_ran_first();
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * A plugin the updater leaves waiting is reported.
	 */
	public function test_an_update_the_updater_left_waiting_is_reported() {
		$this->prepare( array( 'notify_core' => 'none' ) );
		$this->simulate_updater( false );

		$this->run_as( true );

		$this->assert_updater_ran_first();
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertStringContainsString( '9.9.9', $this->sent_mail()->body );
	}

	/**
	 * Outside WP-Cron the version check refreshes the data and the updater does not run.
	 */
	public function test_outside_wp_cron_the_updater_does_not_run() {
		$this->prepare( array( 'notify_core' => 'none' ) );
		$this->simulate_updater( true );

		$this->run_as( false );

		$this->assertNotContains( 'auto_update', $this->log );
		$this->assertCount( 1, $this->requests_to( 'api.wordpress.org/core/version-check' ), 'The version check still asked the API.' );
		$this->assertSame( 1, $this->sent_mail_count(), 'The update is still waiting, so it is reported.' );
	}

	/**
	 * The check asks for the version check once, even with core notifications on.
	 */
	public function test_the_version_check_runs_once_with_core_notifications_on() {
		$this->prepare();
		$asked = 0;
		add_action(
			'wp_version_check',
			static function () use ( &$asked ) {
				++$asked;
			}
		);

		$this->run_as( false );

		$this->assertSame( 1, $asked );
	}
}
