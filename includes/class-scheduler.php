<?php
/**
 * Scheduling of the update check.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Schedules the update check with WordPress cron.
 *
 * @internal
 */
class Scheduler {
	const CRON_NAME         = 'sc_wpun_update_check';
	const DEFAULT_FREQUENCY = 'hourly';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Enable cron for this plugin. Check if a cron should be scheduled.
	 *
	 * @param bool|string $manual_interval For setting a manual cron interval.
	 *
	 * @return void
	 */
	public function enable_cron( $manual_interval = false ) {
		$options          = $this->settings->get(); // Get settings.
		$current_schedule = wp_get_schedule( self::CRON_NAME ); // find if a schedule already exists.

		// `do_action()` without arguments passes an empty string: use the saved frequency.
		if ( ! is_string( $manual_interval ) || '' === $manual_interval ) {
			$manual_interval = false;
		}

		// if a manual cron interval is set, use this.
		if ( false !== $manual_interval ) {
			$options['frequency'] = $manual_interval;
		}

		$frequency = $options['frequency'];

		// Kept for code that calls do_action( 'sc_wpun_enable_cron', 'manual' ) to stop the checks.
		if ( 'manual' === $frequency ) {
			/** This action is documented in includes/class-plugin.php */
			do_action( 'sc_wpun_disable_cron' ); // Make sure no cron is setup as we are manual.
			return;
		}

		if ( ! array_key_exists( $frequency, $this->get_frequencies() ) ) {
			// A schedule that no longer exists (for example one a deactivated plugin
			// registered): an event that is already running keeps its interval, which
			// WordPress stored with it. Without one, check at the default frequency
			// rather than not at all.
			if ( false !== $current_schedule ) {
				return;
			}
			$frequency = self::DEFAULT_FREQUENCY;
		}

		// check if the current schedule matches the one set in settings.
		if ( $current_schedule === $frequency ) {
			return;
		}

		// Remove any cron's for this plugin first so we don't end up with multiple cron's doing the same thing.
		/** This action is documented in includes/class-plugin.php */
		do_action( 'sc_wpun_disable_cron' );

		// Schedule cron for this plugin.
		wp_schedule_event( time(), $frequency, self::CRON_NAME );
	}


	/**
	 * Puts the update check back on the schedule when it is missing.
	 *
	 * Repairs sites where activation never scheduled it, and events lost for any
	 * other reason. Cheap: WordPress keeps the cron array in an autoloaded option.
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		if ( false === wp_next_scheduled( self::CRON_NAME ) ) {
			$this->enable_cron();
		}
	}


	/**
	 * Removes cron for this plugin.
	 *
	 * @return void
	 */
	public function disable_cron() {
		wp_clear_scheduled_hook( self::CRON_NAME ); // clear cron.
	}

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
	public function get_frequencies(): array {
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

		uasort( $offered, array( $this, 'sort_by_interval' ) );

		return $offered;
	}

	/**
	 * Sort schedules by interval, shortest first.
	 *
	 * @param array{interval: int} $a A schedule.
	 * @param array{interval: int} $b A schedule.
	 *
	 * @return int
	 */
	public function sort_by_interval( $a, $b ): int {
		return $a['interval'] <=> $b['interval'];
	}

	/**
	 * Reschedule the update check when a saved frequency changes.
	 *
	 * Runs on update_option_{option}, so only when the settings really change,
	 * whoever saves them.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 *
	 * @return void
	 */
	public function settings_updated( $old_value, $value ) {
		$old_frequency = is_array( $old_value ) ? ( $old_value['frequency'] ?? null ) : null;
		$new_frequency = is_array( $value ) ? ( $value['frequency'] ?? null ) : null;

		if ( is_string( $new_frequency ) && $old_frequency !== $new_frequency ) {
			$this->enable_cron( $new_frequency );
		}
	}
}
