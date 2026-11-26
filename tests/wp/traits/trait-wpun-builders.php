<?php
/**
 * Builders for the plugin's value objects.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Notification;
use WPUN\Notification_Item;

/**
 * Notification items, notifications and channel instances with sensible defaults.
 */
trait WPUN_Builders {

	/**
	 * An item: the WordPress core update from 6.0 to 6.1, unless overridden.
	 *
	 * @param array $overrides Item fields to override.
	 * @return Notification_Item
	 */
	protected function make_item( array $overrides = array() ): Notification_Item {
		return new Notification_Item(
			array_merge(
				array(
					'type'            => 'core',
					'key'             => 'core',
					'name'            => 'WordPress',
					'current_version' => '6.0',
					'new_version'     => '6.1',
				),
				$overrides
			)
		);
	}

	/**
	 * A notification for a test site.
	 *
	 * @param Notification_Item[]|null $items   Items, or null for one default item.
	 * @param array                    $context Notification context.
	 * @return Notification
	 */
	protected function make_notification( $items = null, array $context = array() ): Notification {
		return new Notification(
			'Site',
			'http://example.org',
			'http://example.org/wp-admin/update-core.php',
			null === $items ? array( $this->make_item() ) : $items,
			$context
		);
	}

	/**
	 * An enabled channel instance.
	 *
	 * @param string      $type     Channel type.
	 * @param array       $settings Instance settings.
	 * @param string|null $id       Instance id, or null to use the type.
	 * @param bool        $save     Whether to store the instance.
	 * @return Channel_Instance
	 */
	protected function make_instance( string $type, array $settings = array(), ?string $id = null, bool $save = false ): Channel_Instance {
		$instance = new Channel_Instance( null === $id ? $type : $id, $type, ucfirst( $type ), true, $settings );

		if ( $save ) {
			sc_wpun_channels()->save( $instance );
		}

		return $instance;
	}
}
