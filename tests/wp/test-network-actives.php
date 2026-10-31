<?php
/**
 * Reading what is active across a network cheaply (#85).
 *
 * @package wp-updates-notifier
 */

use WPUN\Network_Actives;

/**
 * Network_Actives reads three options per site in batches, without switching sites, and gives up on large networks.
 */
class Test_Network_Actives extends WPUN_Test_Case {

	/**
	 * A site that is not the main site.
	 *
	 * @var int
	 */
	private $sub_id = 0;

	/**
	 * Network-activate the plugin, as far as the plugin can tell, and make another site.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite.' );
		}

		$this->sub_id = self::factory()->blog->create();
		switch_to_blog( $this->sub_id );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' ); // Creating a site runs `init` on it.
		restore_current_blog();

		$active                                    = (array) get_site_option( 'active_sitewide_plugins', array() );
		$active[ plugin_basename( SC_WPUN_FILE ) ] = time();
		update_site_option( 'active_sitewide_plugins', $active );
		$this->fake_http( 'api.wordpress.org/plugins/info', $this->fake_response( 200, wp_json_encode( array( 'name' => 'Hello Dolly' ) ) ) );
	}

	/**
	 * Leave the network as found.
	 */
	public function tear_down() {
		if ( is_multisite() ) {
			$active = (array) get_site_option( 'active_sitewide_plugins', array() );
			unset( $active[ plugin_basename( SC_WPUN_FILE ) ] );
			update_site_option( 'active_sitewide_plugins', $active );
		}
		parent::tear_down();
	}

	/**
	 * Network settings and the email instance, as a network would store them.
	 *
	 * @param array $overrides Settings to override.
	 */
	private function save_network_settings( array $overrides = array() ) {
		update_site_option( 'sc_wpun_settings', $this->global_settings( $overrides ) );
		update_site_option( 'sc_wpun_settings_ver', self::SETTINGS_VERSION );
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'email', 'email', 'Email', true, array( 'to' => 'ops@example.org' ) ) );
	}

	/**
	 * Every site's plugins and themes, plus the network-activated plugins, without switching to any site.
	 */
	public function test_it_reads_every_site_without_switching() {
		update_blog_option( $this->sub_id, 'active_plugins', array( 'hello.php', 'my plugin/my-plugin.php', 'ünïcode/ünïcode.php' ) );
		update_blog_option( $this->sub_id, 'stylesheet', 'child-theme' );
		update_blog_option( $this->sub_id, 'template', 'parent-theme' );
		$switches = 0;
		$count    = static function () use ( &$switches ) {
			++$switches;
		};
		add_action( 'switch_blog', $count );

		$actives = ( new Network_Actives() )->collect();

		remove_action( 'switch_blog', $count );
		$this->assertSame( 0, $switches );
		$this->assertContains( 'hello.php', $actives['plugins'] );
		$this->assertContains( 'my plugin/my-plugin.php', $actives['plugins'] );
		$this->assertContains( 'ünïcode/ünïcode.php', $actives['plugins'] );
		$this->assertContains( plugin_basename( SC_WPUN_FILE ), $actives['plugins'], 'Network-activated plugins count.' );
		$this->assertContains( 'child-theme', $actives['themes'] );
		$this->assertContains( 'parent-theme', $actives['themes'] );
		$this->assertContains( get_blog_option( get_main_site_id(), 'stylesheet' ), $actives['themes'] );
	}

	/**
	 * Sites are read a batch per query.
	 */
	public function test_sites_are_read_a_batch_per_query() {
		$queries = 0;
		$count   = static function ( $query ) use ( &$queries ) {
			if ( false !== strpos( $query, "option_name IN ('active_plugins', 'stylesheet', 'template')" ) ) {
				++$queries;
			}
			return $query;
		};
		add_filter( 'query', $count );

		( new Network_Actives() )->collect();

		remove_filter( 'query', $count );
		$this->assertSame(
			(int) ceil(
				count(
					get_sites(
						array(
							'fields' => 'ids',
							'number' => 0,
						)
					)
				) / Network_Actives::BATCH
			),
			$queries
		);
	}

	/**
	 * A large network is not read: everything installed counts as active.
	 */
	public function test_a_large_network_is_not_read() {
		add_filter( 'wp_is_large_network', '__return_true' );

		$this->assertNull( ( new Network_Actives() )->collect() );
	}

	/**
	 * A site whose options cannot be read makes everything installed count, rather than miss an update.
	 */
	public function test_a_failed_read_counts_everything() {
		$break = static function ( $query ) {
			return false !== strpos( $query, "option_name IN ('active_plugins', 'stylesheet', 'template')" ) ? str_replace( 'options`', 'no_such_table`', $query ) : $query;
		};
		add_filter( 'query', $break );
		$suppressed = $GLOBALS['wpdb']->suppress_errors( true );

		$actives = ( new Network_Actives() )->collect();

		$GLOBALS['wpdb']->suppress_errors( $suppressed );
		remove_filter( 'query', $break );
		$this->assertNull( $actives );
	}

	/**
	 * On a large network "active only" reports an installed plugin that no site uses.
	 */
	public function test_active_only_reports_every_installed_plugin_on_a_large_network() {
		$this->save_network_settings( array( 'notify_plugins' => 'active' ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '9.9.9' ) ) );

		sc_wpun()->update_checker()->do_update_check();
		$this->assertSame( 0, $this->sent_mail_count(), 'Active nowhere.' );

		add_filter( 'wp_is_large_network', '__return_true' );
		sc_wpun()->update_checker()->do_update_check();
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * One check reads the network once, however many times it asks what is active.
	 */
	public function test_a_check_reads_the_network_once() {
		$this->save_network_settings(
			array(
				'notify_plugins' => 'active',
				'notify_themes'  => 'active',
			)
		);
		$slugs = array_keys( wp_get_themes() );
		update_blog_option( $this->sub_id, 'active_plugins', array( 'hello.php' ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '9.9.9' ) ), array( $slugs[0] => array( 'new_version' => '99.3' ) ) );
		$queries = 0;
		$count   = static function ( $query ) use ( &$queries ) {
			if ( false !== strpos( $query, "option_name IN ('active_plugins', 'stylesheet', 'template')" ) ) {
				++$queries;
			}
			return $query;
		};
		add_filter( 'query', $count );

		sc_wpun()->update_checker()->do_update_check();

		remove_filter( 'query', $count );
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame(
			(int) ceil(
				count(
					get_sites(
						array(
							'fields' => 'ids',
							'number' => 0,
						)
					)
				) / Network_Actives::BATCH
			),
			$queries
		);
	}
}
