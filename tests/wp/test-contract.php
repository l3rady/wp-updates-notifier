<?php
/**
 * What existing sites and other plugins depend on.
 *
 * The plugin has tens of thousands of active installs, and code that hooks into it. These tests
 * pin the names and behaviour that must keep working through every refactor: change one of them
 * only on purpose, with an upgrade path and a changelog entry.
 *
 * @package wp-updates-notifier
 */

/**
 * Public contract of the plugin.
 */
class Test_Contract extends WPUN_Test_Case {

	/**
	 * Active plugins are stored by path, so renaming the main file deactivates the plugin on every
	 * site that updates. 2.0.0 moved it back to wp-updates-notifier.php (#90) on purpose; don't
	 * move it again.
	 */
	public function test_the_main_file_is_a_valid_plugin_at_the_expected_path() {
		$file = WP_PLUGIN_DIR . '/wp-updates-notifier/wp-updates-notifier.php';

		$this->assertFileExists( $file );

		$data = get_plugin_data( $file, false, false );
		$this->assertSame( 'WP Updates Notifier', $data['Name'] );
		$this->assertSame( 'wp-updates-notifier', $data['TextDomain'] );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $data['Version'], 'release-please needs a full semantic version, and its markers must not break the header.' );
	}

	/**
	 * The readme.txt that bin/readme.php generates into the zip. It is not committed.
	 *
	 * @return string
	 */
	private static function readme(): string {
		require_once dirname( __DIR__, 2 ) . '/bin/readme.php';

		return wpun_render_readme( dirname( __DIR__, 2 ) );
	}

	/**
	 * The requirements in the plugin header and in the generated readme.txt agree (WordPress.org reads both).
	 */
	public function test_the_plugin_header_and_readme_agree_on_requirements() {
		$data   = get_plugin_data( WP_PLUGIN_DIR . '/wp-updates-notifier/wp-updates-notifier.php', false, false );
		$readme = self::readme();

		$this->assertSame( '7.4', $data['RequiresPHP'] );
		$this->assertSame( '6.7', $data['RequiresWP'] );
		$this->assertMatchesRegularExpression( '/^Requires PHP:\s*7\.4$/m', $readme );
		$this->assertMatchesRegularExpression( '/^Requires at least:\s*6\.7$/m', $readme );
	}

	/**
	 * The licence is stated the same way everywhere, and the licence text ships with the plugin.
	 */
	public function test_the_licence_is_stated_consistently() {
		$dir      = WP_PLUGIN_DIR . '/wp-updates-notifier';
		$header   = get_file_data(
			$dir . '/wp-updates-notifier.php',
			array(
				'License'    => 'License',
				'LicenseURI' => 'License URI',
			)
		);
		$readme   = self::readme();
		$composer = json_decode( file_get_contents( $dir . '/composer.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		$this->assertSame( 'GPL-2.0-or-later', $header['License'] );
		$this->assertSame( 'https://www.gnu.org/licenses/gpl-2.0.html', $header['LicenseURI'] );
		$this->assertMatchesRegularExpression( '/^License:\s*GPL-2\.0-or-later$/m', $readme );
		$this->assertMatchesRegularExpression( '#^License URI:\s*https://www\.gnu\.org/licenses/gpl-2\.0\.html$#m', $readme );
		$this->assertSame( 'GPL-2.0-or-later', $composer['license'] );
		$this->assertFileExists( $dir . '/LICENSE' );
		$this->assertStringContainsString( 'GNU GENERAL PUBLIC LICENSE', file_get_contents( $dir . '/LICENSE' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$this->assertStringContainsString( 'Version 2, June 1991', file_get_contents( $dir . '/LICENSE' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	}

	/**
	 * Option names and the cron hook are stored in sites' databases.
	 */
	public function test_stored_names_are_unchanged() {
		$this->assertSame( 'sc_wpun_settings', SC_WP_Updates_Notifier::OPT_FIELD );
		$this->assertSame( 'sc_wpun_settings_ver', SC_WP_Updates_Notifier::OPT_VERSION_FIELD );
		$this->assertSame( 'sc_wpun_update_check', SC_WP_Updates_Notifier::CRON_NAME );
	}

	/**
	 * Hooks the plugin registers for itself.
	 */
	public function test_the_plugin_registers_its_hooks() {
		$this->assertNotFalse( has_action( 'sc_wpun_update_check' ), 'The cron event needs a callback.' );
		$this->assertNotFalse( has_action( 'sc_wpun_enable_cron' ) );
		$this->assertNotFalse( has_action( 'sc_wpun_disable_cron' ) );
		$this->assertFalse( has_filter( 'auto_core_update_email' ), 'WordPress\'s own auto-update emails are left alone.' );
		$this->assertFalse( has_filter( 'plugin_action_links' ), 'The settings link uses the filter specific to the plugin.' );
		// Delivery state is per instance now, so the plugin leaves these public filters to third parties.
		$this->assertFalse( has_filter( 'sc_wpun_plugins_need_update' ) );
		$this->assertFalse( has_filter( 'sc_wpun_themes_need_update' ) );
		$this->assertNotFalse( has_action( 'sc_wpun_channel_deleted' ) );
		$this->assertNotFalse( has_action( 'deleted_plugin' ), 'Deleting a plugin removes its mute.' );
		$this->assertNotFalse( has_action( 'deleted_theme' ), 'Deleting a theme removes its mute.' );
	}

	/**
	 * Admin requests also register the hooks of the settings page and the update nag.
	 */
	public function test_the_plugin_registers_its_admin_hooks_in_admin() {
		set_current_screen( 'dashboard' );
		( new \WPUN\Plugin() )->boot();

		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'admin_init' ) );
		$this->assertNotFalse( has_filter( 'plugin_action_links_' . plugin_basename( SC_WPUN_FILE ) ) );
		$this->assertNotFalse( has_filter( 'plugin_row_meta' ) );
		$this->assertNotFalse( has_filter( 'option_page_capability_sc_wpun_settings' ) );
		$this->assertNotFalse( has_action( 'admin_post_sc_wpun_send_test' ) );
	}

	/**
	 * The settings page lives under Settings with this slug, which other code and bookmarks link to.
	 */
	public function test_the_settings_page_slug() {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->plugin()->admin_settings_menu();

		$slugs = wp_list_pluck( $submenu['options-general.php'], 2 );
		$this->assertContains( 'wp-updates-notifier', $slugs );
	}

	/**
	 * Third parties can enable and disable the schedule through the documented actions.
	 */
	public function test_the_cron_actions_schedule_and_unschedule() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );

		do_action( 'sc_wpun_enable_cron', 'daily' );
		$this->assertNotFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );
		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );

		do_action( 'sc_wpun_disable_cron' );
		$this->assertFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );
	}

	/**
	 * Changing the schedule replaces the event instead of adding a second one.
	 */
	public function test_changing_the_frequency_leaves_one_event() {
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );

		do_action( 'sc_wpun_enable_cron', 'hourly' );
		do_action( 'sc_wpun_enable_cron', 'daily' );

		$events = array_filter(
			_get_cron_array(),
			static function ( $hooks ) {
				return isset( $hooks['sc_wpun_update_check'] );
			}
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * Every settings read and write goes through the documented filters.
	 */
	public function test_the_options_filters_are_applied() {
		$this->save_settings( array( 'frequency' => 'daily' ) );

		add_filter(
			'sc_wpun_get_options_filter',
			static function ( $options, $field ) {
				if ( 'sc_wpun_settings' === $field ) {
					$options['frequency'] = 'weekly';
				}
				return $options;
			},
			10,
			2
		);

		$this->assertSame( 'weekly', $this->read_setting( 'frequency' ) );

		$put = array();
		add_filter(
			'sc_wpun_put_options_filter',
			static function ( $settings, $field ) use ( &$put ) {
				$put[] = $field;
				return $settings;
			},
			10,
			2
		);
		$this->set_updates();
		$this->plugin()->do_update_check();
		$this->assertContains( 'sc_wpun_state', $put, 'Saving the run state must run the put filter.' );

		update_option( 'sc_wpun_settings_ver', '7.0' );
		sc_wpun()->settings()->maybe_upgrade();
		$this->assertContains( 'sc_wpun_settings', $put, 'Saving settings must run the put filter.' );
	}

	/**
	 * The email text can be changed with the documented filters.
	 */
	public function test_the_email_filters_are_applied() {
		$this->save_settings();
		$this->set_updates( '99.0.0' );

		add_filter(
			'sc_wpun_email_subject',
			static function () {
				return 'Custom subject';
			}
		);
		add_filter(
			'sc_wpun_email_content',
			static function () {
				return 'Custom content';
			}
		);

		$this->plugin()->do_update_check();

		$mail = $this->sent_mail();
		$this->assertSame( 'Custom subject', $mail->subject );
		$this->assertStringContainsString( 'Custom content', $mail->body );
	}

	/**
	 * Reads a setting through the plugin's filtered getter (private), as the plugin itself does.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private function read_setting( $key ) {
		$options = sc_wpun()->settings()->read_option( 'sc_wpun_settings' );

		return $options[ $key ];
	}
}
