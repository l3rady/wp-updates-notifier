<?php
/**
 * The check frequencies on offer.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * The cron schedules the plugin offers as check frequencies.
 *
 * Kept apart from the Scheduler so the settings can validate a frequency without depending on it.
 *
 * @internal
 */
final class Frequencies {

	/**
	 * The frequencies the plugin offers, shortest first.
	 *
	 * A curated list rather than every schedule other plugins register, so the
	 * choice does not change from site to site and cannot disappear with another
	 * plugin. Schedules shorter than an hour are never offered: each check also
	 * asks api.wordpress.org for update data.
	 *
	 * @return array<string, array{interval: int, display: string}> Schedules keyed by name.
	 */
	public static function all(): array {
		/**
		 * Filters the names of the cron schedules offered as check frequencies.
		 *
		 * Each must be a registered schedule (see the `cron_schedules` filter) of at
		 * least an hour.
		 *
		 * @since 2.0.0
		 *
		 * @param string[] $names Schedule names. Default hourly, twicedaily, daily and weekly.
		 */
		$names     = apply_filters( 'sc_wpun_frequencies', array( 'hourly', 'twicedaily', 'daily', 'weekly' ) );
		$schedules = wp_get_schedules();
		$offered   = array();

		foreach ( (array) $names as $name ) {
			if ( ! is_string( $name ) || ! isset( $schedules[ $name ]['interval'], $schedules[ $name ]['display'] ) ) {
				continue;
			}

			$interval = (int) $schedules[ $name ]['interval'];
			if ( $interval >= HOUR_IN_SECONDS ) {
				$offered[ $name ] = array(
					'interval' => $interval,
					'display'  => (string) $schedules[ $name ]['display'],
				);
			}
		}

		uasort( $offered, static fn( $a, $b ): int => $a['interval'] <=> $b['interval'] );

		return $offered;
	}
}
