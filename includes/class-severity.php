<?php
/**
 * How important an update is.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * The severity of an update: `high`, `medium` or `low`.
 *
 * WordPress itself and a bump of the first part of the version (4.2 to 5.0) are high, a bump of the second part
 * (4.2 to 4.3) is medium and anything else (4.2.1 to 4.2.2) is low. A version that does not start with a number is
 * medium, as nothing can be said about it. A channel maps the severity to its own styling: a marker, a colour or an accent.
 *
 * Read the severity of an item with `Notification_Item::get_severity()`, which also applies the `sc_wpun_item_severity` filter.
 *
 * @since 3.0.0
 */
final class Severity {

	const HIGH   = 'high';
	const MEDIUM = 'medium';
	const LOW    = 'low';

	/**
	 * The highest of some severities, or `low` when there are none.
	 *
	 * @since 3.0.0
	 *
	 * @param string[] $severities Severities. A value that is not a severity counts as none.
	 *
	 * @return string
	 */
	public static function highest( array $severities ): string {
		$highest = self::LOW;
		foreach ( $severities as $severity ) {
			if ( self::rank( (string) $severity ) > self::rank( $highest ) ) {
				$highest = (string) $severity;
			}
		}

		return $highest;
	}

	/**
	 * A number to compare severities by: 3 for high, 2 for medium, 1 for low and 0 for anything else.
	 *
	 * @since 3.0.0
	 *
	 * @param string $severity Severity.
	 *
	 * @return int
	 */
	public static function rank( string $severity ): int {
		switch ( $severity ) {
			case self::HIGH:
				return 3;
			case self::MEDIUM:
				return 2;
			case self::LOW:
				return 1;
		}

		return 0;
	}

	/**
	 * Whether a value is one of the three severities.
	 *
	 * @since 3.0.0
	 *
	 * @param string $severity Value.
	 *
	 * @return bool
	 */
	public static function is_valid( string $severity ): bool {
		return self::rank( $severity ) > 0;
	}

	/**
	 * The severity the versions of an item give, before the `sc_wpun_item_severity` filter.
	 *
	 * @since 3.0.0
	 *
	 * @param Notification_Item $item The item.
	 *
	 * @return string
	 */
	public static function of_versions( Notification_Item $item ): string {
		if ( 'core' === $item->get_type() ) {
			return self::HIGH;
		}

		$current = self::parts( $item->get_current_version() );
		$new     = self::parts( $item->get_new_version() );
		if ( null === $current || null === $new ) {
			return self::MEDIUM;
		}

		if ( $current[0] !== $new[0] ) {
			return self::HIGH;
		}
		if ( $current[1] !== $new[1] ) {
			return self::MEDIUM;
		}

		return self::LOW;
	}

	/**
	 * The first two numbers of the leading dotted part of a version, a missing one counting as 0.
	 *
	 * @param string $version Version, for example `5.7.2` or `2.0-beta1`.
	 *
	 * @return int[]|null Null when the version does not start with a number.
	 */
	private static function parts( string $version ): ?array {
		if ( 1 !== preg_match( '/^\d+(\.\d+)*/', trim( $version ), $matches ) ) {
			return null;
		}

		$numbers = explode( '.', $matches[0] );

		return array( (int) $numbers[0], isset( $numbers[1] ) ? (int) $numbers[1] : 0 );
	}
}
