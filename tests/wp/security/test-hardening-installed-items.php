<?php
/**
 * The update check reads files only for plugins and themes that are installed (#325).
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification_Item;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * The keys of the update_plugins and update_themes transients, and the entries that filters add, name files and
 * folders. They come from api.wordpress.org and from the updaters of other plugins, so a key such as
 * `../../wp-config.php` must never be opened.
 *
 * @group security
 */
class Test_Hardening_Installed_Items extends WPUN_Security_Test_Case {

	/**
	 * A site that reports plugin and theme updates.
	 */
	public function set_up() {
		parent::set_up();

		$this->save_settings();
		delete_option( 'sc_wpun_state' );

		// Core refreshes the transients on these hooks and would drop the stray keys the tests plant.
		remove_all_actions( 'wp_update_plugins' );
		remove_all_actions( 'wp_update_themes' );
	}

	/**
	 * Run the check and return the items it built.
	 *
	 * @return Notification_Item[]
	 */
	private function checked_items(): array {
		$items = array();
		add_filter(
			'sc_wpun_notification',
			static function ( $notification ) use ( &$items ) {
				$items = $notification->get_items();
				return $notification;
			}
		);
		sc_wpun()->update_checker()->do_update_check();

		return $items;
	}

	/**
	 * The keys of the items.
	 *
	 * @param Notification_Item[] $items Items.
	 * @return string[]
	 */
	private function keys( array $items ): array {
		return array_map(
			static function ( $item ) {
				return $item->get_key();
			},
			$items
		);
	}

	/**
	 * Keys that are not an installed plugin.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function stray_plugin_keys() {
		return array(
			'traversal to a file'   => array( '../../wp-config.php' ),
			'traversal to a plugin' => array( '../evil/evil.php' ),
			'missing plugin'        => array( 'not-installed/not-installed.php' ),
		);
	}

	/**
	 * A key in the transient that is not an installed plugin gives no item, and an installed one still does.
	 *
	 * @dataProvider stray_plugin_keys
	 *
	 * @param string $key The key.
	 */
	public function test_a_plugin_key_that_is_not_installed_is_skipped( string $key ) {
		$this->set_updates(
			null,
			array(
				'hello.php' => array(),
				$key        => array(),
			)
		);

		$this->assertSame( array( 'hello.php' ), $this->keys( $this->checked_items() ) );
	}

	/**
	 * A stray entry added by the filter is not read either.
	 *
	 * @dataProvider stray_plugin_keys
	 *
	 * @param string $key The key.
	 */
	public function test_a_plugin_key_added_by_the_filter_is_skipped( string $key ) {
		$this->set_updates( null, array( 'hello.php' => array() ) );
		add_filter(
			'sc_wpun_plugins_need_update',
			static function ( $plugins ) use ( $key ) {
				$plugins[ $key ] = (object) array(
					'new_version' => '2.0',
					'url'         => '',
					'slug'        => 'evil',
					'id'          => '',
				);
				return $plugins;
			}
		);

		$this->assertSame( array( 'hello.php' ), $this->keys( $this->checked_items() ) );
	}

	/**
	 * A theme that is not installed gives no item, and an installed one still does.
	 */
	public function test_a_theme_that_is_not_installed_is_skipped() {
		$installed = (string) array_key_first( wp_get_themes() );
		$this->set_updates(
			null,
			array(),
			array(
				$installed      => array(),
				'../evil-theme' => array(),
				'not-installed' => array(),
				'..'            => array(), // A real directory, but not a theme.
				'../plugins'    => array(),
			)
		);

		$this->assertSame( array( $installed ), $this->keys( $this->checked_items() ) );
	}

	/**
	 * A theme added by the filter that is not installed is skipped too.
	 */
	public function test_a_theme_added_by_the_filter_is_skipped() {
		$installed = (string) array_key_first( wp_get_themes() );
		$this->set_updates( null, array(), array( $installed => array() ) );
		add_filter(
			'sc_wpun_themes_need_update',
			static function ( $themes ) {
				$themes['../evil-theme'] = array(
					'new_version' => '2.0',
					'url'         => '',
				);
				return $themes;
			}
		);

		$this->assertSame( array( $installed ), $this->keys( $this->checked_items() ) );
	}

	/**
	 * A run with only stray keys reports nothing.
	 */
	public function test_only_stray_keys_report_nothing() {
		$this->set_updates( null, array( '../../wp-config.php' => array() ), array( 'not-installed' => array() ) );

		$this->assertSame( array(), $this->checked_items() );
	}
}
