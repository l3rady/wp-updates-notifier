<?php
/**
 * Whether WordPress is set to install an update by itself (#83).
 *
 * @package wp-updates-notifier
 */

use WPUN\Auto_Update_Policy;

/**
 * The policy, one case per input.
 */
class Test_Auto_Update_Policy extends WPUN_Test_Case {

	/**
	 * The policy.
	 *
	 * @var Auto_Update_Policy
	 */
	private $policy;

	/**
	 * Start from a site where automatic updates can run and nothing is switched on.
	 */
	public function set_up() {
		parent::set_up();

		$this->policy = new Auto_Update_Policy();
		remove_filter( 'automatic_updater_disabled', '__return_true' ); // WordPress's own test bootstrap disables its updater.
		add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		delete_site_option( 'auto_update_plugins' );
		delete_site_option( 'auto_update_themes' );
		delete_site_option( 'auto_update_core_major' );
		delete_site_option( 'auto_core_update_notified' );
	}

	/**
	 * A plugin update entry.
	 *
	 * @param array $extra Extra properties.
	 * @return object
	 */
	private function plugin_entry( array $extra = array() ) {
		return (object) array_merge(
			array(
				'plugin'      => 'hello.php',
				'new_version' => '9.9.9',
			),
			$extra
		);
	}

	/**
	 * Whether the policy says the plugin is updated automatically.
	 *
	 * @param array $extra Extra properties of the entry.
	 * @return bool
	 */
	private function plugin_on( array $extra = array() ) {
		return $this->policy->is_on( 'plugin', 'hello.php', $this->plugin_entry( $extra ), '9.9.9' );
	}

	/**
	 * Whether the policy says the theme is updated automatically.
	 *
	 * @param array $extra Extra properties of the entry.
	 * @return bool
	 */
	private function theme_on( array $extra = array() ) {
		return $this->policy->is_on( 'theme', 'twentytwentyfive', (object) array_merge( array( 'new_version' => '9.9.9' ), $extra ), '9.9.9' );
	}

	/**
	 * A plugin is on when it is in the site option, and off when it is not.
	 */
	public function test_a_plugin_is_on_when_in_the_site_option() {
		$this->assertFalse( $this->plugin_on() );

		update_site_option( 'auto_update_plugins', array( 'hello.php' ) );
		$this->assertTrue( $this->plugin_on() );

		update_site_option( 'auto_update_plugins', array( 'other/other.php' ) );
		$this->assertFalse( $this->plugin_on() );
	}

	/**
	 * A theme is on when it is in the site option, and off when it is not.
	 */
	public function test_a_theme_is_on_when_in_the_site_option() {
		$this->assertFalse( $this->theme_on() );

		update_site_option( 'auto_update_themes', array( 'twentytwentyfive' ) );
		$this->assertTrue( $this->theme_on() );

		update_site_option( 'auto_update_themes', array( 'other' ) );
		$this->assertFalse( $this->theme_on() );
	}

	/**
	 * The site option counts only while auto-updates are enabled for the type.
	 */
	public function test_the_site_option_is_ignored_when_the_type_is_disabled() {
		update_site_option( 'auto_update_plugins', array( 'hello.php' ) );
		update_site_option( 'auto_update_themes', array( 'twentytwentyfive' ) );

		add_filter( 'plugins_auto_update_enabled', '__return_false' );
		$this->assertFalse( $this->plugin_on() );
		$this->assertTrue( $this->theme_on() );

		add_filter( 'themes_auto_update_enabled', '__return_false' );
		$this->assertFalse( $this->theme_on() );
	}

	/**
	 * An update that WordPress.org forces is on, and one marked as not to update is off.
	 */
	public function test_forced_and_disabled_entries() {
		$this->assertTrue( $this->plugin_on( array( 'autoupdate' => true ) ) );
		$this->assertTrue( $this->theme_on( array( 'autoupdate' => true ) ) );

		update_site_option( 'auto_update_plugins', array( 'hello.php' ) );
		$this->assertFalse( $this->plugin_on( array( 'disable_autoupdate' => true ) ) );
		$this->assertFalse(
			$this->plugin_on(
				array(
					'autoupdate'         => true,
					'disable_autoupdate' => true,
				)
			)
		);
	}

	/**
	 * The auto_update_plugin and auto_update_theme filters have the last word, with the item named.
	 */
	public function test_core_filters_decide_both_ways() {
		$seen = array();
		add_filter(
			'auto_update_plugin',
			static function ( $update, $item ) use ( &$seen ) {
				$seen[] = $item->plugin;
				return $update;
			},
			10,
			2
		);
		$this->plugin_on();
		$this->assertSame( array( 'hello.php' ), $seen );

		add_filter( 'auto_update_plugin', '__return_true' );
		add_filter( 'auto_update_theme', '__return_true' );
		$this->assertTrue( $this->plugin_on() );
		$this->assertTrue( $this->theme_on() );

		update_site_option( 'auto_update_plugins', array( 'hello.php' ) );
		update_site_option( 'auto_update_themes', array( 'twentytwentyfive' ) );
		remove_all_filters( 'auto_update_plugin' );
		remove_all_filters( 'auto_update_theme' );
		add_filter( 'auto_update_plugin', '__return_false' );
		add_filter( 'auto_update_theme', '__return_false' );
		$this->assertFalse( $this->plugin_on() );
		$this->assertFalse( $this->theme_on() );
	}

	/**
	 * Disabled updaters turn everything off, whatever else is set.
	 */
	public function test_a_disabled_updater_turns_everything_off() {
		update_site_option( 'auto_update_plugins', array( 'hello.php' ) );
		add_filter( 'auto_update_plugin', '__return_true' );
		$this->assertTrue( $this->plugin_on() );

		add_filter( 'automatic_updater_disabled', '__return_true' );
		$this->assertFalse( $this->plugin_on() );
		$this->assertFalse( $this->policy->is_on( 'core', 'core', $this->core_offer( $this->minor() ), $this->minor() ) );
		$this->assertSame( 'disabled', $this->policy->blocked_reason() );
	}

	/**
	 * DISALLOW_FILE_MODS, seen through the file_mod_allowed filter, turns everything off.
	 */
	public function test_file_mods_disallowed_turns_everything_off() {
		update_site_option( 'auto_update_plugins', array( 'hello.php' ) );
		add_filter( 'file_mod_allowed', '__return_false' );

		$this->assertFalse( $this->plugin_on() );
		$this->assertSame( 'file_mods', $this->policy->blocked_reason() );
	}

	/**
	 * A version control checkout turns everything off.
	 */
	public function test_a_version_control_checkout_turns_everything_off() {
		update_site_option( 'auto_update_plugins', array( 'hello.php' ) );
		remove_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );
		add_filter( 'automatic_updates_is_vcs_checkout', '__return_true' );

		$this->assertFalse( $this->plugin_on() );
		$this->assertFalse( $this->policy->is_on( 'core', 'core', $this->core_offer( $this->minor() ), $this->minor() ) );
		$this->assertSame( 'vcs', $this->policy->blocked_reason() );
	}

	/**
	 * On a site where updates can run there is no reason.
	 */
	public function test_no_reason_when_updates_can_run() {
		$this->assertSame( '', $this->policy->blocked_reason() );
	}

	/**
	 * The branch of the running WordPress, for example 7.1.
	 *
	 * @return string
	 */
	private function branch() {
		return implode( '.', array_slice( preg_split( '/[.-]/', wp_get_wp_version() ), 0, 2 ) );
	}

	/**
	 * A later release of the same branch.
	 *
	 * @return string
	 */
	private function minor() {
		return $this->branch() . '.99';
	}

	/**
	 * The first release of the next branch.
	 *
	 * @return string
	 */
	private function major() {
		list( $major, $minor ) = explode( '.', $this->branch() );

		return $major . '.' . ( (int) $minor + 1 ) . '.0';
	}

	/**
	 * A core upgrade offer.
	 *
	 * @param string $version Version.
	 * @return object
	 */
	private function core_offer( $version ) {
		return (object) array(
			'response' => 'upgrade',
			'current'  => $version,
			'version'  => $version,
		);
	}

	/**
	 * Whether the policy says core updates to the version by itself.
	 *
	 * @param string $version Version.
	 * @return bool
	 */
	private function core_on( $version ) {
		return $this->policy->is_on( 'core', 'core', $this->core_offer( $version ), $version );
	}

	/**
	 * Minor releases follow allow_minor_auto_core_updates.
	 */
	public function test_core_minor() {
		add_filter( 'allow_minor_auto_core_updates', '__return_true' );
		$this->assertTrue( $this->core_on( $this->minor() ) );

		remove_filter( 'allow_minor_auto_core_updates', '__return_true' );
		add_filter( 'allow_minor_auto_core_updates', '__return_false' );
		$this->assertFalse( $this->core_on( $this->minor() ) );
	}

	/**
	 * Major releases are off unless a filter or the site option allows them.
	 */
	public function test_core_major() {
		add_filter( 'allow_major_auto_core_updates', '__return_false' );
		$this->assertFalse( $this->core_on( $this->major() ) );

		remove_filter( 'allow_major_auto_core_updates', '__return_false' );
		add_filter( 'allow_major_auto_core_updates', '__return_true' );
		$this->assertTrue( $this->core_on( $this->major() ) );

		remove_filter( 'allow_major_auto_core_updates', '__return_true' );
		update_site_option( 'auto_update_core_major', 'enabled' );
		$this->assertTrue( $this->core_on( $this->major() ) );
	}

	/**
	 * A development version updates only when allow_dev_auto_core_updates says so.
	 */
	public function test_core_development() {
		$this->assertStringContainsString( '-', wp_get_wp_version(), 'The test site runs a development version.' );

		add_filter( 'allow_minor_auto_core_updates', '__return_true' );
		add_filter( 'allow_dev_auto_core_updates', '__return_false' );
		$this->assertFalse( $this->core_on( $this->minor() ) );

		remove_filter( 'allow_dev_auto_core_updates', '__return_false' );
		add_filter( 'allow_dev_auto_core_updates', '__return_true' );
		$this->assertTrue( $this->core_on( $this->minor() ) );
	}

	/**
	 * The auto_update_core filter has the last word and receives the offer.
	 */
	public function test_the_core_filter_decides_both_ways() {
		$offer = $this->core_offer( $this->minor() );
		$seen  = null;
		add_filter(
			'auto_update_core',
			static function ( $update, $item ) use ( &$seen ) {
				$seen = $item;
				return $update;
			},
			10,
			2
		);
		$this->policy->is_on( 'core', 'core', $offer, $this->minor() );
		$this->assertSame( $offer, $seen );

		add_filter( 'auto_update_core', '__return_false', 20 );
		$this->assertFalse( $this->core_on( $this->minor() ) );

		remove_filter( 'auto_update_core', '__return_false', 20 );
		add_filter( 'auto_update_core', '__return_true', 20 );
		$this->assertTrue( $this->core_on( $this->major() ) );
	}

	/**
	 * Deciding the core flag sends no mail and leaves core's notification record alone.
	 */
	public function test_deciding_the_core_flag_sends_no_email() {
		add_filter( 'allow_minor_auto_core_updates', '__return_false' );
		add_filter( 'allow_major_auto_core_updates', '__return_false' );
		$core_mail = 0;
		add_filter(
			'auto_core_update_email',
			static function ( $email ) use ( &$core_mail ) {
				++$core_mail;
				return $email;
			}
		);

		$this->core_on( $this->minor() );
		$this->core_on( $this->major() );
		add_filter( 'automatic_updater_disabled', '__return_true' );
		$this->core_on( $this->minor() );

		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( 0, $core_mail );
		$this->assertFalse( get_site_option( 'auto_core_update_notified' ) );
	}

	/**
	 * The public filter adds the flag, removes it and receives the item.
	 */
	public function test_the_public_filter_adds_and_removes() {
		$args = null;
		add_filter(
			'sc_wpun_auto_update_enabled',
			static function ( $enabled, ...$rest ) use ( &$args ) {
				$args = $rest;
				return true;
			},
			10,
			4
		);
		$this->assertTrue( $this->plugin_on() );
		$this->assertSame( array( 'plugin', 'hello.php', '9.9.9' ), $args );

		remove_all_filters( 'sc_wpun_auto_update_enabled' );
		update_site_option( 'auto_update_plugins', array( 'hello.php' ) );
		add_filter( 'sc_wpun_auto_update_enabled', '__return_false' );
		$this->assertFalse( $this->plugin_on() );

		remove_all_filters( 'sc_wpun_auto_update_enabled' );
		add_filter( 'sc_wpun_auto_update_enabled', '__return_zero' );
		$this->assertFalse( $this->plugin_on(), 'The result is a bool.' );
	}

	/**
	 * Items of another type have no automatic updates to follow.
	 */
	public function test_other_types_are_off() {
		$this->assertFalse( $this->policy->is_on( 'php', 'php', (object) array(), '9' ) );
	}
}
