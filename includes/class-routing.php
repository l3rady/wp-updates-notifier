<?php
/**
 * Deciding which items a channel instance receives.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * The routing rules of a channel instance, kept in Channel_Instance::get_routing().
 *
 * Known keys:
 *
 * - `types` (string[]): item types the instance receives, for example `array( 'core', 'plugin' )`.
 *   Missing or empty means every type, including types added by add-ons.
 * - `scope` (`all`|`active`): for `plugin` and `theme` items. `active` keeps only items whose
 *   `active` flag is true. Applies on top of the global "active only" settings. Default `all`.
 * - `core` (`all`|`major`): for `core` items. `major` keeps only updates that change major.minor.
 *   Default `all`.
 * - `remind` (`never`|`daily`|`weekly`): repeat every update of the instance that is still waiting,
 *   in one message, at that interval until it is installed. New updates are always sent at once.
 *   Default `never`.
 *
 * Other keys are kept and ignored, so add-ons can store their own rules.
 *
 * @since 2.0.0
 */
final class Routing {

	/**
	 * The known keys as enum field schemas, for drawing a form and for checking values.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array<string, mixed>> Keyed `scope`, `core`, `remind` and `types`.
	 */
	public static function fields(): array {
		return self::choice_fields() + array(
			'types' => array(
				'type'        => 'enum',
				'label'       => __( 'Item types', 'wp-updates-notifier' ),
				'description' => __( 'Leave every box unticked to receive all types, including ones added later.', 'wp-updates-notifier' ),
				'options'     => Item_Types::all(),
				'multiple'    => true,
				'display'     => 'checkboxes',
				'default'     => array(),
			),
		);
	}

	/**
	 * The `scope`, `core` and `remind` fields, which need no look at the registered item types.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function choice_fields(): array {
		return array(
			'scope'  => array(
				'type'        => 'enum',
				'label'       => __( 'Plugins and themes', 'wp-updates-notifier' ),
				'description' => __( 'Applies after the plugin and theme settings on the General tab.', 'wp-updates-notifier' ),
				'options'     => array(
					'all'    => __( 'All', 'wp-updates-notifier' ),
					'active' => __( 'Only active ones', 'wp-updates-notifier' ),
				),
				'default'     => 'all',
				'display'     => 'radio',
			),
			'core'   => array(
				'type'        => 'enum',
				'label'       => __( 'WordPress updates', 'wp-updates-notifier' ),
				'description' => __( 'Applies after the WordPress updates setting on the General tab.', 'wp-updates-notifier' ),
				'options'     => array(
					'all'   => __( 'All', 'wp-updates-notifier' ),
					'major' => __( 'Only major releases', 'wp-updates-notifier' ),
				),
				'default'     => 'all',
				'display'     => 'radio',
			),
			'remind' => array(
				'type'        => 'enum',
				'label'       => __( 'Reminders', 'wp-updates-notifier' ),
				'description' => __( 'Repeat every update that is still waiting, in one message, until it is installed. New updates are still sent at once.', 'wp-updates-notifier' ),
				'options'     => array(
					'never'  => __( 'Never', 'wp-updates-notifier' ),
					'daily'  => __( 'Daily', 'wp-updates-notifier' ),
					'weekly' => __( 'Weekly', 'wp-updates-notifier' ),
				),
				'default'     => 'never',
				'display'     => 'radio',
			),
		);
	}

	/**
	 * The routing with the known keys checked and defaults applied; other keys are kept.
	 *
	 * @since 2.0.0
	 *
	 * @param array<mixed> $routing Stored routing.
	 *
	 * @return array<mixed> With `types` (string[]), `scope`, `core` and `remind`.
	 */
	public static function normalise( array $routing ): array {
		// Types stay as stored, even ones nobody registers any more: dropping the type of a deactivated add-on
		// would turn `array( 'php' )` into an empty list, which means every type.
		$types = array();
		if ( isset( $routing['types'] ) && is_array( $routing['types'] ) ) {
			foreach ( $routing['types'] as $type ) {
				if ( is_string( $type ) && '' !== $type && ! in_array( $type, $types, true ) ) {
					$types[] = $type;
				}
			}
		}

		$fields            = self::choice_fields();
		$routing['types']  = $types;
		$routing['scope']  = Enum_Field::sanitize( $routing['scope'] ?? null, $fields['scope'] )['value'];
		$routing['core']   = Enum_Field::sanitize( $routing['core'] ?? null, $fields['core'] )['value'];
		$routing['remind'] = Enum_Field::sanitize( $routing['remind'] ?? null, $fields['remind'] )['value'];

		return $routing;
	}

	/**
	 * Whether an instance is to receive an item.
	 *
	 * @since 2.0.0
	 *
	 * @param Notification_Item $item     The item.
	 * @param Channel_Instance  $instance The instance.
	 *
	 * @return bool
	 */
	public static function should_receive( Notification_Item $item, Channel_Instance $instance ): bool {
		$routing = self::normalise( $instance->get_routing() );
		$type    = $item->get_type();
		$receive = true;

		if ( array() !== $routing['types'] && ! in_array( $type, $routing['types'], true ) ) {
			$receive = false;
		} elseif ( 'active' === $routing['scope'] && in_array( $type, array( 'plugin', 'theme' ), true ) && true !== ( $item->get_flags()['active'] ?? false ) ) {
			$receive = false;
		} elseif ( 'major' === $routing['core'] && 'core' === $type && ! self::is_major_core_update( $item->get_current_version(), $item->get_new_version() ) ) {
			$receive = false;
		}

		/**
		 * Filters whether a channel instance receives an item, for rules of your own.
		 *
		 * Runs after the instance's own rules, so it can veto an item the rules let through and
		 * allow one they blocked.
		 *
		 * Example, send only plugins named "security" to the instance called "security_team":
		 *
		 *     add_filter(
		 *         'sc_wpun_channel_should_receive_item',
		 *         function ( $receive, $item, $instance ) {
		 *             if ( 'security_team' === $instance->get_id() && 'plugin' === $item->get_type() ) {
		 *                 return false !== stripos( $item->get_name(), 'security' );
		 *             }
		 *             return $receive;
		 *         },
		 *         10,
		 *         3
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param bool              $receive  Whether the instance receives the item.
		 * @param Notification_Item $item     The item.
		 * @param Channel_Instance  $instance The instance.
		 */
		return (bool) apply_filters( 'sc_wpun_channel_should_receive_item', $receive, $item, $instance );
	}

	/**
	 * Whether going from one WordPress version to another changes the major.minor version.
	 *
	 * Any `-suffix` is ignored: 6.7 to 6.7.1 is not major, 6.8.3 to 6.9 is, and so is 6.8 to 6.9-RC1.
	 *
	 * @since 2.0.0
	 *
	 * @param string $current Installed version.
	 * @param string $available Version available.
	 *
	 * @return bool
	 */
	public static function is_major_core_update( string $current, string $available ): bool {
		return self::major_minor( $current ) !== self::major_minor( $available );
	}

	/**
	 * The major.minor part of a version, for example 6.5 from 6.5.3 or 6.6-RC1.
	 *
	 * @since 2.0.0
	 *
	 * @param string $version Version.
	 *
	 * @return string
	 */
	public static function major_minor( string $version ): string {
		$parts = explode( '.', (string) preg_replace( '/-.*$/', '', $version ) );

		return implode( '.', array_slice( $parts, 0, 2 ) );
	}
}
