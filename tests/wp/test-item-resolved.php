<?php
/**
 * The action for a reported update that is no longer pending (#348).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Notification_Item;

/**
 * `sc_wpun_item_resolved` is fired by the update check, once per item per instance.
 */
class Test_Item_Resolved extends WPUN_Test_Case {

	const PLUGIN = 'wpun-resolved-fixture/wpun-resolved-fixture.php';
	const THEME  = 'wpun-resolved-theme';

	/**
	 * Fresh settings and state, and a fixture plugin at version 1.0.
	 */
	public function set_up() {
		parent::set_up();

		$this->save_settings( array( 'download_links' => 1 ) );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_muted' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		$this->write_plugin( '1.0' );
		$this->write_theme( '1.0' );
	}

	/**
	 * Remove the fixture files.
	 */
	public function tear_down() {
		$this->remove_dir( WP_PLUGIN_DIR . '/wpun-resolved-fixture' );
		$this->remove_dir( get_theme_root() . '/' . self::THEME );
		wp_clean_plugins_cache();
		wp_clean_themes_cache();

		parent::tear_down();
	}

	/**
	 * Write the fixture plugin with a version.
	 *
	 * @param string $version Version header.
	 */
	private function write_plugin( $version ) {
		wp_mkdir_p( WP_PLUGIN_DIR . '/wpun-resolved-fixture' );
		file_put_contents( WP_PLUGIN_DIR . '/' . self::PLUGIN, "<?php\n/*\nPlugin Name: Resolved Fixture\nVersion: $version\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		wp_clean_plugins_cache();
	}

	/**
	 * Write the fixture theme with a version.
	 *
	 * @param string $version Version header.
	 */
	private function write_theme( $version ) {
		wp_mkdir_p( get_theme_root() . '/' . self::THEME );
		file_put_contents( get_theme_root() . '/' . self::THEME . '/style.css', "/*\nTheme Name: Resolved Theme\nVersion: $version\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( get_theme_root() . '/' . self::THEME . '/index.php', "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		wp_clean_themes_cache();
	}

	/**
	 * Delete a fixture directory.
	 *
	 * @param string $dir Directory.
	 */
	private function remove_dir( $dir ) {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
		}
	}

	/**
	 * Run a real check with the fixture plugin waiting.
	 *
	 * @param string $version Version waiting.
	 * @param string $package Package address.
	 */
	private function notify_plugin( $version = '2.0', $package = '' ) {
		$this->set_updates(
			null,
			array(
				self::PLUGIN => array(
					'new_version' => $version,
					'package'     => $package,
				),
			)
		); // phpcs:ignore Universal.Arrays.MixedKeyedUnkeyedArray
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * Run a check where nothing is pending.
	 */
	private function check_nothing_pending() {
		$this->set_updates();
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * Record the calls of the action.
	 *
	 * @return array Reference to the calls.
	 */
	private function &record() {
		$calls = array();
		add_action(
			'sc_wpun_item_resolved',
			function () use ( &$calls ) {
				$calls[] = func_get_args();
			},
			10,
			3
		);

		return $calls;
	}

	/**
	 * Save an extra email instance.
	 *
	 * @param string $id      Id.
	 * @param bool   $enabled Whether it is on.
	 */
	private function add_email( $id, $enabled = true ) {
		sc_wpun_channels()->save( new Channel_Instance( $id, 'email', ucfirst( $id ), $enabled, array( 'to' => $id . '@example.com' ) ) );
	}

	/**
	 * An update installed by the upgrader (the pending update disappears, the plugin is at the version) is reported once.
	 */
	public function test_an_installed_update_is_resolved_once() {
		$calls = &$this->record();
		$start = time();
		$this->notify_plugin( '2.0', 'https://example.com/pkg.zip?token=secret' );
		$this->assertCount( 0, $calls );

		$this->write_plugin( '2.0' );
		$this->check_nothing_pending();

		$this->assertCount( 1, $calls );
		list( $item, $how, $instance ) = $calls[0];
		$this->assertInstanceOf( Notification_Item::class, $item );
		$this->assertSame( 'updated', $how );
		$this->assertSame( 'email', $instance->get_id() );
		$this->assertSame( 'plugin', $item->get_type() );
		$this->assertSame( self::PLUGIN, $item->get_key() );
		$this->assertSame( 'Resolved Fixture', $item->get_name() );
		$this->assertSame( '1.0', $item->get_current_version() );
		$this->assertSame( '2.0', $item->get_new_version() );
		$this->assertArrayHasKey( 'details', $item->get_links() );
		$this->assertArrayNotHasKey( 'download', $item->get_links() );
		$this->assertGreaterThanOrEqual( $start, $item->get_notified_at() );
		$this->assertLessThanOrEqual( time(), $item->get_notified_at() );

		$this->assertSame( array(), $this->channel_notified()['plugin'] ?? array() );
		$this->assertArrayNotHasKey( 'notified_meta', $this->stored_state()['channels']['email'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $this->stored_state() ) );

		$this->check_nothing_pending();
		$this->assertCount( 1, $calls, 'Not again.' );
	}

	/**
	 * A version changed in the plugin file (FTP, a host) is found, whatever the update data says.
	 */
	public function test_a_version_edited_in_the_file_is_resolved() {
		$calls = &$this->record();
		$this->notify_plugin( '2.0' );

		$this->write_plugin( '2.5' );
		$this->check_nothing_pending();

		$this->assertCount( 1, $calls );
		$this->assertSame( 'updated', $calls[0][1] );
		$this->assertSame( '2.0', $calls[0][0]->get_new_version() );
	}

	/**
	 * A plugin that was deleted is reported as removed.
	 */
	public function test_a_deleted_plugin_is_removed() {
		$calls = &$this->record();
		$this->notify_plugin( '2.0' );

		$this->remove_dir( WP_PLUGIN_DIR . '/wpun-resolved-fixture' );
		wp_clean_plugins_cache();
		$this->check_nothing_pending();

		$this->assertCount( 1, $calls );
		$this->assertSame( 'removed', $calls[0][1] );
		$this->assertSame( 'Resolved Fixture', $calls[0][0]->get_name() );
	}

	/**
	 * Themes are updated or removed the same way.
	 */
	public function test_a_theme_is_updated_or_removed() {
		$calls = &$this->record();
		$this->set_updates( null, array(), array( self::THEME => array( 'new_version' => '2.0' ) ) );
		sc_wpun()->update_checker()->do_update_check();
		$this->assertSame( '2.0', $this->notified_version( 'theme', self::THEME ) );

		$this->write_theme( '2.0' );
		$this->check_nothing_pending();
		$this->assertCount( 1, $calls );
		$this->assertSame( 'updated', $calls[0][1] );
		$this->assertSame( 'theme', $calls[0][0]->get_type() );
		$this->assertSame( 'Resolved Theme', $calls[0][0]->get_name() );

		$this->set_updates( null, array(), array( self::THEME => array( 'new_version' => '3.0' ) ) );
		sc_wpun()->update_checker()->do_update_check();
		$this->remove_dir( get_theme_root() . '/' . self::THEME );
		wp_clean_themes_cache();
		$this->check_nothing_pending();
		$this->assertCount( 2, $calls );
		$this->assertSame( 'removed', $calls[1][1] );
		$this->assertSame( '3.0', $calls[1][0]->get_new_version() );
	}

	/**
	 * Core is resolved when WordPress is at or past the reported version.
	 */
	public function test_core_is_resolved() {
		$calls = &$this->record();
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => wp_get_wp_version() ) ) );

		$this->check_nothing_pending();

		$this->assertCount( 1, $calls );
		$this->assertSame( 'core', $calls[0][1] );
		$this->assertSame( 'WordPress', $calls[0][0]->get_name() );
		$this->assertSame( wp_get_wp_version(), $calls[0][0]->get_new_version() );
		$this->assertNull( $calls[0][0]->get_notified_at() );
	}

	/**
	 * Core not yet at the reported version is not resolved.
	 */
	public function test_core_older_than_notified_is_not_resolved() {
		$calls = &$this->record();
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '99.0' ) ) );

		$this->check_nothing_pending();

		$this->assertCount( 0, $calls );
		$this->assertSame( array(), $this->channel_notified()['core'] ?? array() );
	}

	/**
	 * A reminder or a forced send keeps the time; a newer version sets a new one.
	 */
	public function test_the_notified_time_changes_only_with_the_version() {
		$this->notify_plugin( '2.0' );
		$state = $this->stored_state();
		$state['channels']['email']['notified_meta']['plugin'][ self::PLUGIN ]['time'] = time() - 1000;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );

		sc_wpun()->update_checker()->run_check( 'cli', false, true );
		$meta = $this->stored_state()['channels']['email']['notified_meta']['plugin'][ self::PLUGIN ];
		$this->assertLessThan( time() - 900, $meta['time'], 'A forced send of the same version keeps the time.' );

		$this->notify_plugin( '3.0' );
		$meta = $this->stored_state()['channels']['email']['notified_meta']['plugin'][ self::PLUGIN ];
		$this->assertGreaterThan( time() - 100, $meta['time'] );
	}

	/**
	 * An entry stored before the details were kept still fires, with the installed name and no time.
	 */
	public function test_an_entry_without_details_fires() {
		$calls = &$this->record();
		$this->write_plugin( '2.0' );
		$this->save_channel_state( 'email', array( 'plugin' => array( self::PLUGIN => '2.0' ) ) );

		$this->check_nothing_pending();

		$this->assertCount( 1, $calls );
		$item = $calls[0][0];
		$this->assertNull( $item->get_notified_at() );
		$this->assertSame( 'Resolved Fixture', $item->get_name() );
		$this->assertSame( '', $item->get_current_version() );
		$this->assertSame( array(), $item->get_links() );
	}

	/**
	 * A removed entry without details is named by its key.
	 */
	public function test_a_removed_entry_without_details_is_named_by_its_key() {
		$calls = &$this->record();
		$this->save_channel_state( 'email', array( 'plugin' => array( 'gone/gone.php' => '2.0' ) ) );

		$this->check_nothing_pending();

		$this->assertCount( 1, $calls );
		$this->assertSame( 'gone/gone.php', $calls[0][0]->get_name() );
		$this->assertSame( 'removed', $calls[0][1] );
	}

	/**
	 * A switched-off instance fires; one that never reported the item does not.
	 */
	public function test_it_fires_for_the_instances_that_reported_the_item() {
		$this->add_email( 'off', false );
		$this->add_email( 'other' );
		$this->save_channel_state( 'off', array( 'plugin' => array( self::PLUGIN => '2.0' ) ) );
		$this->save_channel_state( 'other', array() );
		$this->write_plugin( '2.0' );
		$calls = &$this->record();

		$this->check_nothing_pending();

		$this->assertCount( 1, $calls );
		$this->assertSame( 'off', $calls[0][2]->get_id() );
	}

	/**
	 * A newer version waiting means the item is still pending.
	 */
	public function test_a_superseded_item_is_not_resolved() {
		$calls = &$this->record();
		$this->notify_plugin( '2.0' );
		$this->notify_plugin( '3.0' );

		$this->assertCount( 0, $calls );
		$this->assertSame( '3.0', $this->notified_version( 'plugin', self::PLUGIN ) );
	}

	/**
	 * Muted and snoozed items are still pending, so they are not resolved.
	 */
	public function test_muted_and_snoozed_items_are_not_resolved() {
		$calls = &$this->record();
		$this->notify_plugin( '2.0' );

		sc_wpun_mutes()->mute( 'plugin', self::PLUGIN );
		$this->notify_plugin( '2.0' );
		sc_wpun_mutes()->unmute( 'plugin', self::PLUGIN );
		sc_wpun_mutes()->mute( 'plugin', self::PLUGIN, null, 'api', time() + 3600 );
		$this->notify_plugin( '2.0' );

		$this->assertCount( 0, $calls );
	}

	/**
	 * Put a snooze straight into the stored mutes, to control the time.
	 *
	 * @param int $expires Expiry.
	 */
	private function snooze( $expires ) {
		sc_wpun()->network_context()->update_option(
			'sc_wpun_muted',
			array(
				'plugin' => array(
					self::PLUGIN => array(
						'until'   => null,
						'time'    => 1,
						'user'    => 0,
						'expires' => $expires,
					),
				),
			)
		);
	}

	/**
	 * An update installed while the item is snoozed is resolved, with the time it was first reported.
	 */
	public function test_an_update_installed_during_a_snooze_is_resolved() {
		$calls = &$this->record();
		$this->notify_plugin( '2.0' );
		$time = $this->stored_state()['channels']['email']['notified_meta']['plugin'][ self::PLUGIN ]['time'];
		$this->snooze( time() + 3600 );
		$this->notify_plugin( '2.0' );
		$this->assertCount( 0, $calls );

		$this->write_plugin( '2.0' );
		$this->check_nothing_pending();

		$this->assertCount( 1, $calls );
		$this->assertSame( 'updated', $calls[0][1] );
		$this->assertSame( $time, $calls[0][0]->get_notified_at() );
		$this->assertSame( 'Resolved Fixture', $calls[0][0]->get_name() );
	}

	/**
	 * A snoozed entry stored without details is flagged with empty ones, and resolves with no time.
	 */
	public function test_a_snoozed_entry_without_details_resolves() {
		$calls = &$this->record();
		$this->save_channel_state( 'email', array( 'plugin' => array( self::PLUGIN => '2.0' ) ) );
		$this->snooze( time() + 3600 );
		$this->notify_plugin( '2.0' );
		$this->assertTrue( $this->stored_state()['channels']['email']['notified_meta']['plugin'][ self::PLUGIN ]['snoozed'] );

		$this->write_plugin( '2.0' );
		$this->check_nothing_pending();

		$this->assertCount( 1, $calls );
		$this->assertNull( $calls[0][0]->get_notified_at() );
	}

	/**
	 * After the snooze the item is sent again, which clears the flag and starts its time again.
	 */
	public function test_the_time_starts_again_after_a_snooze() {
		$this->notify_plugin( '2.0' );
		$state = $this->stored_state();
		$state['channels']['email']['notified_meta']['plugin'][ self::PLUGIN ]['time'] = time() - 5000;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		$this->snooze( time() + 3600 );
		$this->notify_plugin( '2.0' );
		$this->snooze( time() - 1 );
		$this->notify_plugin( '2.0' );

		$meta = $this->stored_state()['channels']['email']['notified_meta']['plugin'][ self::PLUGIN ];
		$this->assertGreaterThan( time() - 100, $meta['time'] );
		$this->assertArrayNotHasKey( 'snoozed', $meta );
	}

	/**
	 * Nothing fires in a dry run, and the real run after it does.
	 */
	public function test_a_dry_run_does_not_fire() {
		$calls = &$this->record();
		$this->notify_plugin( '2.0' );
		$this->write_plugin( '2.0' );
		$this->set_updates();

		sc_wpun()->update_checker()->run_check( 'cli', true );
		$this->assertCount( 0, $calls );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', self::PLUGIN ) );

		sc_wpun()->update_checker()->run_check( 'cli' );
		$this->assertCount( 1, $calls );
	}

	/**
	 * An installed version older than the notified one is not resolved: the update data may just be missing.
	 */
	public function test_an_older_installed_version_is_not_resolved() {
		$calls = &$this->record();
		$this->notify_plugin( '2.0' );

		$this->check_nothing_pending();

		$this->assertCount( 0, $calls );
		$this->assertSame( array(), $this->channel_notified()['plugin'] ?? array(), 'Forgotten silently, as before.' );
	}

	/**
	 * Without usable update data every notified plugin is forgotten, and none is reported.
	 */
	public function test_missing_update_data_resolves_nothing() {
		$calls = &$this->record();
		$this->save_channel_state( 'email', array( 'plugin' => array( self::PLUGIN => '2.0' ) ) );
		$this->set_updates();
		remove_all_actions( 'wp_update_plugins' ); // As if the refresh failed and left the transient as it was.
		set_site_transient( 'update_plugins', 'junk' );

		sc_wpun()->update_checker()->do_update_check();

		$this->assertCount( 0, $calls );
	}

	/**
	 * Items of an add-on type are forgotten silently.
	 */
	public function test_add_on_types_are_not_reported() {
		add_filter(
			'sc_wpun_item_types',
			static function ( $types ) {
				$types['php'] = 'PHP';
				return $types;
			}
		);
		$calls = &$this->record();
		$this->save_channel_state( 'email', array( 'php' => array( 'php' => '8.4' ) ) );

		$this->check_nothing_pending();

		$this->assertCount( 0, $calls );
		$this->assertSame( array(), $this->channel_notified()['php'] ?? array() );
	}
}
