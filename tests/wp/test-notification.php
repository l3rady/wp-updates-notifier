<?php
/**
 * The notification value objects, their renderers and the notification the update check builds.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Notification_Renderer;

/**
 * Notification, Notification_Item, Channel_Instance and Notification_Renderer.
 */
class Test_Notification extends WPUN_Test_Case {

	/**
	 * An item.
	 *
	 * @param array $overrides Values to override.
	 * @return Notification_Item
	 */
	private function item( array $overrides = array() ) {
		return new Notification_Item(
			array_merge(
				array(
					'type'            => 'plugin',
					'key'             => 'hello.php',
					'name'            => 'Hello Dolly',
					'current_version' => '1.0',
					'new_version'     => '2.0',
					'links'           => array( 'details' => 'https://example.com/hello/' ),
					'notes'           => array( 'Works with your WordPress' ),
					'flags'           => array( 'tested' => '6.5' ),
				),
				$overrides
			)
		);
	}

	/**
	 * A notification.
	 *
	 * @param array  $items Items.
	 * @param string $name  Site name.
	 * @return Notification
	 */
	private function notification( array $items, $name = 'My Site' ) {
		return new Notification( $name, 'https://example.com', 'https://example.com/wp-admin/update-core.php', $items );
	}

	/**
	 * Items keep what they were given.
	 */
	public function test_item_getters() {
		$item = $this->item();

		$this->assertSame( 'plugin', $item->get_type() );
		$this->assertSame( 'hello.php', $item->get_key() );
		$this->assertSame( 'Hello Dolly', $item->get_name() );
		$this->assertSame( '1.0', $item->get_current_version() );
		$this->assertSame( '2.0', $item->get_new_version() );
		$this->assertSame( array( 'details' => 'https://example.com/hello/' ), $item->get_links() );
		$this->assertSame( array( 'Works with your WordPress' ), $item->get_notes() );
		$this->assertSame( array( 'tested' => '6.5' ), $item->get_flags() );
	}

	/**
	 * Custom types are fine as long as they are constant-safe.
	 */
	public function test_item_types() {
		$this->assertSame( 'my_addon2', $this->item( array( 'type' => 'my_addon2' ) )->get_type() );

		foreach ( array( '', 'Plugin', 'my-type', 'a b' ) as $type ) {
			try {
				$this->item( array( 'type' => $type ) );
				$this->fail( "Type '$type' was accepted." );
			} catch ( InvalidArgumentException $e ) {
				$this->assertInstanceOf( InvalidArgumentException::class, $e );
			}
		}

		$this->expectException( InvalidArgumentException::class );
		new Notification_Item( array() );
	}

	/**
	 * Ids and types of instances must be constant-safe.
	 */
	public function test_instance_validation() {
		$instance = new Channel_Instance( 'slack_1', 'slack', 'Team', true, array( 'url' => 'https://x' ), array( 'types' => array( 'core' ) ) );

		$this->assertSame( 'slack_1', $instance->get_id() );
		$this->assertSame( 'slack', $instance->get_type() );
		$this->assertSame( 'Team', $instance->get_label() );
		$this->assertTrue( $instance->is_enabled() );
		$this->assertSame( 'https://x', $instance->get_setting( 'url' ) );
		$this->assertSame( 'fallback', $instance->get_setting( 'missing', 'fallback' ) );
		$this->assertNull( $instance->get_setting( 'missing' ) );
		$this->assertSame( array( 'types' => array( 'core' ) ), $instance->get_routing() );

		foreach ( array( array( 'Bad', 'slack' ), array( 'a-b', 'slack' ), array( '', 'slack' ), array( 'ok', 'Slack' ), array( 'ok', 'a b' ), array( 'ok', '' ) ) as $arguments ) {
			try {
				new Channel_Instance( $arguments[0], $arguments[1], 'Label', true );
				$this->fail( 'Accepted id ' . $arguments[0] . ' and type ' . $arguments[1] );
			} catch ( InvalidArgumentException $e ) {
				$this->assertInstanceOf( InvalidArgumentException::class, $e );
			}
		}
	}

	/**
	 * Selecting items copies the notification and keeps its context.
	 */
	public function test_with_items() {
		$plugin       = $this->item();
		$theme        = $this->item( array( 'type' => 'theme' ) );
		$notification = new Notification( 'Site', 'https://example.com', 'https://example.com/u', array( $plugin, $theme ), array( 'is_reminder' => true ) );

		$this->assertSame( 'cron', $notification->get_source() );
		$this->assertTrue( $notification->is_reminder() );
		$this->assertFalse( $notification->is_test() );
		$this->assertSame( array( $theme ), $notification->get_items_of_type( 'theme' ) );

		$subset = $notification->with_items( array( $theme ) );

		$this->assertNotSame( $notification, $subset );
		$this->assertSame( array( $plugin, $theme ), $notification->get_items() );
		$this->assertSame( array( $theme ), $subset->get_items() );
		$this->assertSame( 'Site', $subset->get_site_name() );
		$this->assertSame( 'https://example.com/u', $subset->get_updates_url() );
		$this->assertTrue( $subset->is_reminder() );
		$this->assertTrue( $subset->has_items() );
		$this->assertFalse( $subset->with_items( array() )->has_items() );
	}

	/**
	 * Plain text is for people and chat: no HTML entities.
	 */
	public function test_plain_text_is_not_escaped() {
		$text = Notification_Renderer::plain_text( $this->notification( array( $this->item( array( 'name' => 'Fish & Chips <3' ) ) ), 'Bob &amp; Sons' ) );

		$this->assertStringContainsString( 'Bob & Sons', $text );
		$this->assertStringContainsString( 'Fish & Chips <3: 1.0 → 2.0', $text );
		$this->assertStringContainsString( 'Details: https://example.com/hello/', $text );
		$this->assertStringContainsString( 'Works with your WordPress', $text );
		$this->assertStringContainsString( 'https://example.com/wp-admin/update-core.php', $text );
		$this->assertStringNotContainsString( '&amp;', $text );
	}

	/**
	 * HTML escapes every value.
	 */
	public function test_html_escapes_values() {
		$html = Notification_Renderer::html(
			$this->notification(
				array(
					$this->item(
						array(
							'name'  => 'Evil <b>Plugin</b>',
							'links' => array( 'details' => 'https://example.com/?a=1&b="2"' ),
							'notes' => array( '<script>alert(1)</script>' ),
						)
					),
				),
				'Bob &amp; Sons'
			)
		);

		$this->assertStringContainsString( 'Evil &lt;b&gt;Plugin&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>Plugin', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'Bob &amp; Sons', $html );
		$this->assertStringNotContainsString( 'Bob &amp;amp; Sons', $html );
		$this->assertStringContainsString( 'href="https://example.com/?a=1&#038;b=2"', $html );
	}

	/**
	 * Markdown escapes the characters that would format.
	 */
	public function test_markdown_escapes_special_characters() {
		$markdown = Notification_Renderer::markdown( $this->notification( array( $this->item( array( 'name' => '*Bold* plugin_name [x]' ) ) ) ) );

		$this->assertStringContainsString( '\*Bold\* plugin\_name \[x\]', $markdown );
		$this->assertStringContainsString( '[Details](https://example.com/hello/)', $markdown );
		$this->assertStringContainsString( '1\.0 → 2\.0', $markdown );
	}

	/**
	 * The update check produces items of the right types, versions and links.
	 */
	public function test_update_check_builds_the_notification() {
		$this->fake_http( 'api.wordpress.org/plugins/info', $this->fake_response( 200, wp_json_encode( array( 'slug' => 'hello-dolly' ) ) ) );
		$this->save_settings();
		$this->set_updates(
			'99.0.0',
			array(
				'hello.php' => array(
					'slug'        => 'hello-dolly',
					'new_version' => '2.0.0',
					'tested'      => '99.0',
				),
			),
			array(
				get_stylesheet() => array(
					'new_version' => '3.1.0',
					'url'         => 'https://example.com/theme/',
				),
			)
		);

		sc_wpun()->update_checker()->do_update_check();

		$notification = sc_wpun()->update_checker()->last_notification();
		$this->assertInstanceOf( Notification::class, $notification );
		$this->assertSame( 'cron', $notification->get_source() );
		$this->assertSame( home_url(), $notification->get_site_url() );
		$this->assertSame( admin_url( 'update-core.php' ), $notification->get_updates_url() );
		$this->assertSame( array( 'core', 'plugin', 'theme' ), array_map( array( $this, 'type_of' ), $notification->get_items() ) );

		$core = $notification->get_items_of_type( 'core' )[0];
		$this->assertSame( 'core', $core->get_key() );
		$this->assertSame( wp_get_wp_version(), $core->get_current_version() );
		$this->assertSame( '99.0.0', $core->get_new_version() );

		$plugin = $notification->get_items_of_type( 'plugin' )[0];
		$this->assertSame( 'hello.php', $plugin->get_key() );
		$this->assertSame( 'Hello Dolly', $plugin->get_name() );
		$this->assertSame( '1.7.2', $plugin->get_current_version() );
		$this->assertSame( '2.0.0', $plugin->get_new_version() );
		$this->assertSame(
			array(
				'details'   => 'https://wordpress.org/plugins/hello-dolly/',
				'changelog' => 'https://wordpress.org/plugins/hello-dolly/#developers',
			),
			$plugin->get_links()
		);
		$this->assertCount( 1, $plugin->get_notes() );
		$this->assertSame(
			array(
				'tested' => '99.0',
				'active' => false,
			),
			$plugin->get_flags()
		);
		$this->assertSame( array( 'active' => true ), $notification->get_items_of_type( 'theme' )[0]->get_flags() );

		$theme = $notification->get_items_of_type( 'theme' )[0];
		$this->assertSame( get_stylesheet(), $theme->get_key() );
		$this->assertSame( '3.1.0', $theme->get_new_version() );
		$this->assertSame( array( 'details' => 'https://example.com/theme/' ), $theme->get_links() );
	}

	/**
	 * Type of an item, for array_map.
	 *
	 * @param Notification_Item $item Item.
	 * @return string
	 */
	public function type_of( Notification_Item $item ) {
		return $item->get_type();
	}
}
