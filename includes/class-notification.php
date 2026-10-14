<?php
/**
 * A notification about updates.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * What a channel is asked to deliver: the site and the updates found.
 *
 * Immutable. Part of the extension API.
 *
 * @since 2.0.0
 */
final class Notification {
	/**
	 * Site name, as WordPress returns it (it may contain HTML entities).
	 *
	 * @var string
	 */
	private $site_name;

	/**
	 * Site address.
	 *
	 * @var string
	 */
	private $site_url;

	/**
	 * Address of the WordPress updates screen.
	 *
	 * @var string
	 */
	private $updates_url;

	/**
	 * Items.
	 *
	 * @var Notification_Item[]
	 */
	private $items;

	/**
	 * Whether this is a test notification.
	 *
	 * @var bool
	 */
	private $is_test;

	/**
	 * Whether this repeats an earlier notification.
	 *
	 * @var bool
	 */
	private $is_reminder;

	/**
	 * What started the notification, for example "cron".
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $site_name   Site name.
	 * @param string               $site_url    Site address.
	 * @param string               $updates_url Address of the updates screen.
	 * @param Notification_Item[]  $items       Items.
	 * @param array<string, mixed> $context     {
	 *     Optional. Why the notification is sent.
	 *
	 *     @type bool   $is_test     Whether this is a test. Default false.
	 *     @type bool   $is_reminder Whether this repeats an earlier notification. Default false.
	 *     @type string $source      What started it. Default "cron".
	 * }
	 */
	public function __construct( string $site_name, string $site_url, string $updates_url, array $items, array $context = array() ) {
		$this->site_name   = $site_name;
		$this->site_url    = $site_url;
		$this->updates_url = $updates_url;
		$this->items       = array_values(
			array_filter(
				$items,
				static fn( $item ) => $item instanceof Notification_Item
			)
		);
		$this->is_test     = ! empty( $context['is_test'] );
		$this->is_reminder = ! empty( $context['is_reminder'] );
		$this->source      = isset( $context['source'] ) && is_string( $context['source'] ) ? $context['source'] : 'cron';
	}

	/**
	 * Site name, as WordPress returns it (it may contain HTML entities).
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_site_name(): string {
		return $this->site_name;
	}

	/**
	 * Site address.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_site_url(): string {
		return $this->site_url;
	}

	/**
	 * Address of the WordPress updates screen.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_updates_url(): string {
		return $this->updates_url;
	}

	/**
	 * All items.
	 *
	 * @since 2.0.0
	 *
	 * @return Notification_Item[]
	 */
	public function get_items(): array {
		return $this->items;
	}

	/**
	 * The items of one type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Type, for example "plugin".
	 *
	 * @return Notification_Item[]
	 */
	public function get_items_of_type( string $type ): array {
		return array_values(
			array_filter(
				$this->items,
				static fn( Notification_Item $item ) => $item->get_type() === $type
			)
		);
	}

	/**
	 * Whether there is anything to report.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function has_items(): bool {
		return array() !== $this->items;
	}

	/**
	 * Whether this is a test notification.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_test(): bool {
		return $this->is_test;
	}

	/**
	 * Whether this repeats an earlier notification.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_reminder(): bool {
		return $this->is_reminder;
	}

	/**
	 * What started the notification, for example "cron".
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_source(): string {
		return $this->source;
	}

	/**
	 * A copy of this notification with other items, for sending a subset to a channel.
	 *
	 * @since 2.0.0
	 *
	 * @param Notification_Item[] $items Items.
	 *
	 * @return Notification
	 */
	public function with_items( array $items ): Notification {
		return new self(
			$this->site_name,
			$this->site_url,
			$this->updates_url,
			$items,
			array(
				'is_test'     => $this->is_test,
				'is_reminder' => $this->is_reminder,
				'source'      => $this->source,
			)
		);
	}
}
