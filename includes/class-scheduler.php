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
	 * Puts the update check back on the schedule when it is missing, or when it runs at another frequency than the effective one.
	 *
	 * Repairs sites where activation never scheduled it, and events lost for any
	 * other reason. Cheap: WordPress keeps the cron array in an autoloaded option.
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		$event = wp_get_scheduled_event( self::CRON_NAME );
		if ( false === $event ) {
			$this->enable_cron();
			return;
		}

		// The frequency can change without a save: a constant, an environment variable or a source supplies it.
		if ( $event->schedule !== $this->settings->get()['frequency'] ) {
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
	 * @return array<string, array{interval: int, display: string}> Schedules keyed by name.
	 */
	public function get_frequencies(): array {
		return Frequencies::all();
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
			// A frequency supplied from outside the database wins over the saved one.
			$this->enable_cron( null === $this->settings->external_source( 'frequency' ) ? $new_frequency : false );
		}
	}
}
