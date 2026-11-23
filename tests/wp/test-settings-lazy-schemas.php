<?php
/**
 * The settings schemas are built only when a setting is supplied from outside the database.
 *
 * @package wp-updates-notifier
 */

/**
 * The schemas translate their labels, and `Scheduler::maybe_schedule()` reads the settings on `init` of every
 * request. With nothing supplied, that read must not translate anything, or it loads the translation file for no
 * reason.
 */
class Test_Settings_Lazy_Schemas extends WPUN_Test_Case {

	/**
	 * Translation calls for the plugin's text domain since the filters were added.
	 *
	 * @var string[]
	 */
	private $calls = array();

	/**
	 * Count calls to every translation function, and forget the loaded translations.
	 */
	public function set_up() {
		parent::set_up();

		$this->calls = array();
		foreach ( array( 'gettext', 'ngettext', 'gettext_with_context', 'ngettext_with_context' ) as $function ) {
			add_filter(
				$function . '_wp-updates-notifier',
				function ( $translation ) use ( $function ) {
					$this->calls[] = $function . ': ' . ( is_string( $translation ) ? $translation : '' );
					return $translation;
				}
			);
		}
		unload_textdomain( 'wp-updates-notifier' );
	}

	/**
	 * Forget what the test supplied.
	 */
	public function tear_down() {
		wp_clear_scheduled_hook( WPUN\Scheduler::CRON_NAME );
		parent::tear_down();
	}

	/**
	 * Supply values through a configuration source, which the test removes again.
	 *
	 * @param array<string, mixed> $values Value by key.
	 */
	private function supply( array $values ) {
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
	 * Nothing supplies a value: reading the settings translates nothing.
	 */
	public function test_reading_the_settings_translates_nothing_when_nothing_is_supplied() {
		$settings = sc_wpun()->settings()->get();

		$this->assertSame( 'hourly', $settings['frequency'] );
		$this->assertSame( array(), $this->calls );
		$this->assertFalse( is_textdomain_loaded( 'wp-updates-notifier' ) );
	}

	/**
	 * What `init` does on every request once the event is scheduled.
	 */
	public function test_the_schedule_check_on_init_translates_nothing() {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', WPUN\Scheduler::CRON_NAME );
		sc_wpun()->config()->flush();
		$this->calls = array();

		sc_wpun()->scheduler()->maybe_schedule();

		$this->assertSame( array(), $this->calls );
		$this->assertFalse( is_textdomain_loaded( 'wp-updates-notifier' ) );
	}

	/**
	 * A supplied value still wins and is typed.
	 */
	public function test_a_supplied_value_is_still_used() {
		$this->supply( array( 'SC_WPUN_SETTING_NOTIFY_THEMES' => 'none' ) );

		$this->assertSame( 'none', sc_wpun()->settings()->get()['notify_themes'] );
		$this->assertSame( 'all', sc_wpun()->settings()->get()['notify_plugins'] );
	}

	/**
	 * A supplied value that cannot be used is still reported, and the stored value is kept.
	 */
	public function test_an_invalid_supplied_value_is_still_reported() {
		$this->supply( array( 'SC_WPUN_SETTING_FREQUENCY' => 'every-blue-moon' ) );

		$this->assertSame( 'hourly', sc_wpun()->settings()->get()['frequency'] );
		$keys = array_column( sc_wpun()->config()->problems(), 'key' );
		$this->assertContains( 'SC_WPUN_SETTING_FREQUENCY', $keys );
	}
}
