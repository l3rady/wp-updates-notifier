<?php
/**
 * The "mute" link label of Notification_Renderer.
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Notification_Renderer;

/**
 * The renderer words a link labelled "mute".
 */
class Test_Renderer_Mute_Label extends WPUN_Test_Case {

	/**
	 * A notification whose plugin has a mute link.
	 *
	 * @return Notification
	 */
	private function notification() {
		$item = new Notification_Item(
			array(
				'type'            => 'plugin',
				'key'             => 'hello/hello.php',
				'name'            => 'Hello',
				'current_version' => '1.0',
				'new_version'     => '2.0',
				'links'           => array( 'mute' => 'https://example.org/wp-admin/options-general.php?page=x&tab=muted' ),
			)
		);

		return new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $item ) );
	}

	/**
	 * Plain text, Markdown and HTML each word the link.
	 */
	public function test_the_mute_label_in_each_format() {
		$this->assertStringContainsString( 'Mute in WordPress: https://example.org/wp-admin/options-general.php?page=x&tab=muted', Notification_Renderer::plain_text( $this->notification() ) );
		$this->assertStringContainsString( '[Mute Hello in WordPress](https://example.org/wp-admin/options-general.php?page=x&tab=muted)', Notification_Renderer::markdown( $this->notification() ) );
		$this->assertStringContainsString( '>Mute Hello in WordPress</a>', Notification_Renderer::html( $this->notification() ) );
	}
}
