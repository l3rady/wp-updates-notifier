<?php
/**
 * The plugin's Site Health tests and debug information.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Tells admins in Tools, Site Health whether the update checks run and whether anything can be sent, and adds the plugin to the site info.
 *
 * Every test is direct and reads what is stored: none makes a request, so none runs a check. The
 * debug information is shared in public forums, so it holds no secret value (a secret field is
 * only set or not set), no value of a configuration problem, and no email address.
 *
 * @internal
 */
class Site_Health {
	const SECTION = 'wp-updates-notifier';

	/**
	 * How many intervals late the scheduled event may be before the check counts as not running.
	 */
	const OVERDUE_INTERVALS = 2;

	/**
	 * How many intervals ago the last check may be and still show that checks are running.
	 */
	const RECENT_INTERVALS = 3;

	/**
	 * What the email addresses in the debug information are replaced with.
	 */
	const REDACTED = '[email redacted]';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Run state.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Channel instances.
	 *
	 * @var Channel_Store
	 */
	private $store;

	/**
	 * Registered modules.
	 *
	 * @var Channel_Registry
	 */
	private $registry;

	/**
	 * Config, for the problems it found.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * What the status command reports, shared with it.
	 *
	 * @var Status_Report
	 */
	private $report;

	/**
	 * Where the plugin runs.
	 *
	 * @var Network_Context
	 */
	private $network_context;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings Settings.
	 * @param State                $state    Run state.
	 * @param Channel_Store        $store    Channel instances.
	 * @param Channel_Registry     $registry Registered modules.
	 * @param Config               $config   Resolver.
	 * @param Status_Report        $report   Report of settings, schedule and channels.
	 * @param Network_Context|null $network_context Where the plugin runs.
	 */
	public function __construct( Settings $settings, State $state, Channel_Store $store, Channel_Registry $registry, Config $config, Status_Report $report, ?Network_Context $network_context = null ) {
		$this->settings = $settings;
		$this->state    = $state;
		$this->store    = $store;
		$this->registry = $registry;
		$this->config   = $config;
		$this->report   = $report;

		$this->network_context = $network_context ?? new Network_Context();
	}

	/**
	 * Add the tests. The cron test only while WP-Cron is switched off.
	 *
	 * @param mixed $tests Site Health tests.
	 *
	 * @return mixed
	 */
	public function add_tests( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['sc_wpun_schedule'] = array(
			'label' => __( 'WP Updates Notifier checks for updates', 'wp-updates-notifier' ),
			'test'  => array( $this, 'schedule_result' ),
		);
		if ( $this->wp_cron_disabled() ) {
			$tests['direct']['sc_wpun_wp_cron'] = array(
				'label' => __( 'WP-Cron is switched off for WP Updates Notifier', 'wp-updates-notifier' ),
				'test'  => array( $this, 'wp_cron_result' ),
			);
		}
		$tests['direct']['sc_wpun_channels']         = array(
			'label' => __( 'WP Updates Notifier has a channel to send through', 'wp-updates-notifier' ),
			'test'  => array( $this, 'channels_result' ),
		);
		$tests['direct']['sc_wpun_channel_modules']  = array(
			'label' => __( 'The modules of the WP Updates Notifier channels are available', 'wp-updates-notifier' ),
			'test'  => array( $this, 'channel_modules_result' ),
		);
		$tests['direct']['sc_wpun_channel_delivery'] = array(
			'label' => __( 'The last notification of each WP Updates Notifier channel was delivered', 'wp-updates-notifier' ),
			'test'  => array( $this, 'channel_delivery_result' ),
		);

		return $tests;
	}

	/**
	 * Whether the site switched WP-Cron off.
	 *
	 * @return bool
	 */
	protected function wp_cron_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * The update check is scheduled and runs.
	 *
	 * Critical when the event is missing, or when it is more than twice its interval late and no check ran in the last
	 * three intervals. The second condition is there because a site that runs `wp updates-notifier check` from a server
	 * cron keeps an event that never fires, and its checks do run.
	 *
	 * @return array<string, mixed>
	 */
	public function schedule_result(): array {
		$schedule = $this->report->schedule();
		$event    = wp_get_scheduled_event( Scheduler::CRON_NAME );
		$interval = $this->interval( $event );
		$details  = $this->schedule_details( $schedule );

		if ( false === $event ) {
			$status = 'critical';
			$intro  = __( 'The update check is not scheduled, so no update will be reported.', 'wp-updates-notifier' );
		} elseif ( $event->timestamp < time() - self::OVERDUE_INTERVALS * $interval && ! $this->checked_recently( $interval ) ) {
			$status = 'critical';
			$intro  = __( 'The update checks are not running. The next one is long overdue and none ran lately. WP-Cron may not be running on this site.', 'wp-updates-notifier' );
		} else {
			$status = 'good';
			$intro  = __( 'The update check is scheduled and running.', 'wp-updates-notifier' );
		}

		return $this->result( 'sc_wpun_schedule', $status, 'good' === $status ? __( 'WP Updates Notifier checks for updates', 'wp-updates-notifier' ) : __( 'WP Updates Notifier is not checking for updates', 'wp-updates-notifier' ), '<p>' . esc_html( $intro ) . '</p>' . $details );
	}

	/**
	 * WP-Cron is switched off: recommend a server cron, unless checks demonstrably run.
	 *
	 * @return array<string, mixed>
	 */
	public function wp_cron_result(): array {
		if ( $this->checked_recently( $this->interval( wp_get_scheduled_event( Scheduler::CRON_NAME ) ) ) ) {
			return $this->result( 'sc_wpun_wp_cron', 'good', __( 'WP Updates Notifier checks run from a server cron', 'wp-updates-notifier' ), '<p>' . esc_html__( 'WP-Cron is switched off, and the update checks are running from a server cron.', 'wp-updates-notifier' ) . '</p>' );
		}

		return $this->result(
			'sc_wpun_wp_cron',
			'recommended',
			__( 'WP-Cron is switched off, so WP Updates Notifier may not check', 'wp-updates-notifier' ),
			'<p>' . esc_html__( 'DISABLE_WP_CRON is set and no update check ran lately. Make a server cron request wp-cron.php regularly, or run "wp updates-notifier check" from a server cron.', 'wp-updates-notifier' ) . '</p>'
		);
	}

	/**
	 * At least one enabled channel can send.
	 *
	 * @return array<string, mixed>
	 */
	public function channels_result(): array {
		foreach ( $this->store->all() as $instance ) {
			if ( $instance->is_enabled() && Status_Report::AVAILABLE === $this->report->availability( $instance ) ) {
				return $this->result( 'sc_wpun_channels', 'good', __( 'WP Updates Notifier has a channel to send through', 'wp-updates-notifier' ), '<p>' . esc_html__( 'At least one channel is switched on and can send.', 'wp-updates-notifier' ) . '</p>' );
			}
		}

		return $this->result( 'sc_wpun_channels', 'recommended', __( 'WP Updates Notifier has no channel to send through', 'wp-updates-notifier' ), '<p>' . esc_html__( 'No channel is switched on and able to send, so nothing will be sent. Switch a channel on if you want to be told about updates.', 'wp-updates-notifier' ) . '</p>' );
	}

	/**
	 * Every enabled channel has its module.
	 *
	 * Slack instances are left to the Slack test, which says what to do about them.
	 *
	 * @return array<string, mixed>
	 */
	public function channel_modules_result(): array {
		$items = '';
		foreach ( $this->store->all() as $instance ) {
			if ( ! $instance->is_enabled() || ( 'slack' === $instance->get_type() && ! $this->registry->has( 'slack' ) ) ) {
				continue;
			}
			$availability = $this->report->availability( $instance );
			if ( Status_Report::AVAILABLE !== $availability ) {
				$items .= '<li>' . esc_html( $instance->get_label() ) . ' (<code>' . esc_html( $instance->get_type() ) . '</code>): ' . esc_html( $availability ) . '</li>';
			}
		}

		if ( '' === $items ) {
			return $this->result( 'sc_wpun_channel_modules', 'good', __( 'The modules of the WP Updates Notifier channels are available', 'wp-updates-notifier' ), '<p>' . esc_html__( 'Every channel that is switched on has its module.', 'wp-updates-notifier' ) . '</p>' );
		}

		return $this->result( 'sc_wpun_channel_modules', 'recommended', __( 'Some WP Updates Notifier channels cannot send', 'wp-updates-notifier' ), '<p>' . esc_html__( 'These channels are switched on, but their module is not available, so nothing is sent through them. Activate the add-on that provides the module, or switch the channel off.', 'wp-updates-notifier' ) . '</p><ul>' . $items . '</ul>' );
	}

	/**
	 * No enabled channel failed its last delivery.
	 *
	 * @return array<string, mixed>
	 */
	public function channel_delivery_result(): array {
		$items = '';
		foreach ( $this->report->channels() as $row ) {
			if ( $row['enabled'] && null !== $row['last_failure'] ) {
				$items .= '<li>' . esc_html( $row['label'] ) . ' (' . esc_html( $row['last_failure']['time'] ) . '): ' . esc_html( $row['last_failure']['message'] ) . '</li>';
			}
		}

		if ( '' === $items ) {
			return $this->result( 'sc_wpun_channel_delivery', 'good', __( 'The last notification of each WP Updates Notifier channel was delivered', 'wp-updates-notifier' ), '<p>' . esc_html__( 'No channel that is switched on failed its last delivery.', 'wp-updates-notifier' ) . '</p>' );
		}

		return $this->result( 'sc_wpun_channel_delivery', 'recommended', __( 'Some WP Updates Notifier channels failed to deliver', 'wp-updates-notifier' ), '<p>' . esc_html__( 'The last delivery of these channels failed. The update will be tried again at the next check.', 'wp-updates-notifier' ) . '</p><ul>' . $items . '</ul>' );
	}

	/**
	 * Add the plugin's section to the site info.
	 *
	 * @param mixed $info Debug information sections.
	 *
	 * @return mixed
	 */
	public function add_debug_info( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$fields = array();
		foreach ( $this->report->settings() as $row ) {
			$fields[ 'setting_' . $row['setting'] ] = array(
				'label' => $row['setting'],
				'value' => sprintf( '%s (%s)', $row['value'], $row['source'] ),
				'debug' => sprintf( '%s (%s)', $row['value'], $row['source'] ),
			);
		}

		$schedule = $this->report->schedule();
		$cron_off = $this->wp_cron_disabled();

		$fields['schedule_frequency'] = array(
			'label' => __( 'Check frequency', 'wp-updates-notifier' ),
			'value' => $schedule['frequency'],
		);
		$fields['schedule_next']      = array(
			'label' => __( 'Next check', 'wp-updates-notifier' ),
			'value' => $schedule['next_run'],
		);
		$fields['schedule_last']      = array(
			'label' => __( 'Last check', 'wp-updates-notifier' ),
			'value' => $schedule['last_check'],
		);
		$fields['wp_cron_disabled']   = array(
			'label' => __( 'WP-Cron switched off (DISABLE_WP_CRON)', 'wp-updates-notifier' ),
			'value' => $cron_off ? __( 'Yes', 'wp-updates-notifier' ) : __( 'No', 'wp-updates-notifier' ),
			'debug' => $cron_off ? 'true' : 'false',
		);

		foreach ( $this->report->channels() as $row ) {
			$fields[ 'channel_' . $row['id'] ] = array(
				'label' => $row['label'],
				'value' => $this->describe_channel( $row, true ),
				'debug' => $this->describe_channel( $row, false ),
			);
		}

		$muted = array();
		foreach ( $this->report->muted() as $row ) {
			$muted[] = sprintf( '%s (%s, %s%s)', $row['name'] ?? $row['key'], $row['type'], Status_Report::describe_mute( $row['until'] ), $row['from_code'] ? ', ' . __( 'set in code', 'wp-updates-notifier' ) : '' );
		}
		$fields['muted'] = array(
			'label' => __( 'Muted plugins and themes', 'wp-updates-notifier' ),
			'value' => array() === $muted ? __( 'None', 'wp-updates-notifier' ) : implode( '; ', $muted ),
		);

		$blocked = ( new Auto_Update_Policy() )->blocked_reason();
		$reasons = array(
			'file_mods' => array( __( 'Disabled by DISALLOW_FILE_MODS', 'wp-updates-notifier' ), 'Disabled by DISALLOW_FILE_MODS' ),
			'disabled'  => array( __( 'Disabled by AUTOMATIC_UPDATER_DISABLED or the automatic_updater_disabled filter', 'wp-updates-notifier' ), 'Disabled by AUTOMATIC_UPDATER_DISABLED or the automatic_updater_disabled filter' ),
			'vcs'       => array( __( 'Version control checkout', 'wp-updates-notifier' ), 'Version control checkout' ),
		);
		$reason  = $reasons[ $blocked ] ?? array( __( 'Can run', 'wp-updates-notifier' ), 'Can run' );

		$fields['auto_updates'] = array(
			'label' => __( 'WordPress automatic updates', 'wp-updates-notifier' ),
			'value' => $reason[0],
			'debug' => $reason[1],
		);

		foreach ( $this->problems() as $index => $problem ) {
			$fields[ 'problem_' . $index ] = array(
				'label' => __( 'Configuration problem', 'wp-updates-notifier' ),
				'value' => sprintf( '%s (%s): %s', $problem['key'], $problem['source'], $problem['message'] ),
			);
		}

		$info[ self::SECTION ] = $this->mask_emails(
			array(
				'label'  => __( 'WP Updates Notifier', 'wp-updates-notifier' ),
				'fields' => $fields,
			)
		);

		return $info;
	}

	/**
	 * Replace every email address in the strings of a value.
	 *
	 * @param mixed $value A string, or an array of values.
	 *
	 * @return mixed The same shape.
	 */
	private function mask_emails( $value ) {
		if ( is_string( $value ) ) {
			return (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*/', self::REDACTED, $value );
		}
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'mask_emails' ), $value );
		}

		return $value;
	}

	/**
	 * One channel instance as a line of text.
	 *
	 * Site Health shows `value` in the site's language and copies `debug`, so the copy is in English for a support forum.
	 * The availability and the last result come from the report, already translated.
	 *
	 * @param array{id: string, label: string, module: string, availability: string, enabled: bool, code_defined: bool, last_result: ?array{success: bool, message: string, time: string}, last_failure: ?array{success: bool, message: string, time: string}, retry_at: ?string, held_until: ?string, fields: array<int, array{field: string, value: string, source: string}>} $row The instance from the report.
	 * @param bool                                                                                                                                                                                                                                                                                                                                                              $translate Whether to use the site's language.
	 *
	 * @return string
	 */
	private function describe_channel( array $row, bool $translate ): string {
		$yes  = $translate ? __( 'Yes', 'wp-updates-notifier' ) : 'yes';
		$no   = $translate ? __( 'No', 'wp-updates-notifier' ) : 'no';
		$last = Status_Report::describe_last( $row['last_result'] );

		$labels = array(
			'module'       => $translate ? __( 'Module', 'wp-updates-notifier' ) : 'module',
			'availability' => $translate ? __( 'Availability', 'wp-updates-notifier' ) : 'availability',
			'enabled'      => $translate ? __( 'Enabled', 'wp-updates-notifier' ) : 'enabled',
			'code_defined' => $translate ? __( 'Defined in code', 'wp-updates-notifier' ) : 'code-defined',
			'last_result'  => $translate ? __( 'Last delivery', 'wp-updates-notifier' ) : 'last result',
			'retry_at'     => $translate ? __( 'Next try after', 'wp-updates-notifier' ) : 'retry at',
			'held_until'   => $translate ? __( 'Held until', 'wp-updates-notifier' ) : 'held until',
		);
		$values = array(
			'module'       => $row['module'],
			'availability' => $row['availability'],
			'enabled'      => $row['enabled'] ? $yes : $no,
			'code_defined' => $row['code_defined'] ? $yes : $no,
			'last_result'  => '' === $last ? '-' : $last,
			'retry_at'     => $row['retry_at'] ?? '-',
			'held_until'   => $row['held_until'] ?? '-',
		);

		$parts = array();
		foreach ( $labels as $key => $label ) {
			$parts[] = sprintf( '%s: %s', $label, $values[ $key ] );
		}
		foreach ( $row['fields'] as $field ) {
			$parts[] = sprintf( '%s: %s (%s)', $field['field'], $field['value'], $field['source'] );
		}

		return implode( '; ', $parts );
	}

	/**
	 * The configuration problems of this request, after making sure the settings were resolved.
	 *
	 * @return array<int, array{key: string, source: string, message: string}>
	 */
	private function problems(): array {
		// Resolving is lazy and problems are found while doing it.
		$this->settings->get();
		$this->settings->show_about();
		$this->store->all();

		return $this->config->problems();
	}

	/**
	 * The interval between checks, in seconds.
	 *
	 * For a frequency that is no longer offered it is what the scheduler does: an event keeps the interval WordPress stored
	 * with it, and without one the default frequency is used.
	 *
	 * @param object|false $event The scheduled event.
	 *
	 * @return int
	 */
	private function interval( $event ): int {
		$offered   = Frequencies::all();
		$frequency = $this->settings->get()['frequency'];

		if ( isset( $offered[ $frequency ] ) ) {
			return $offered[ $frequency ]['interval'];
		}
		if ( is_object( $event ) && isset( $event->interval ) && (int) $event->interval > 0 ) {
			return (int) $event->interval;
		}

		return $offered[ Scheduler::DEFAULT_FREQUENCY ]['interval'] ?? HOUR_IN_SECONDS;
	}

	/**
	 * Whether a check ran within the last few intervals.
	 *
	 * @param int $interval Seconds between checks.
	 *
	 * @return bool
	 */
	private function checked_recently( int $interval ): bool {
		$last = $this->state->get()['last_check_time'];

		return false !== $last && $last >= time() - self::RECENT_INTERVALS * $interval;
	}

	/**
	 * The frequency, next check and last check as a list.
	 *
	 * @param array{frequency: string, next_run: string, last_check: string} $schedule The schedule from the report.
	 *
	 * @return string HTML.
	 */
	private function schedule_details( array $schedule ): string {
		return '<ul><li>' . esc_html( sprintf( /* translators: %s: Name of the check frequency, for example hourly. */ __( 'Frequency: %s', 'wp-updates-notifier' ), $schedule['frequency'] ) )
			. '</li><li>' . esc_html( sprintf( /* translators: %s: Date and time of the next check. */ __( 'Next check: %s', 'wp-updates-notifier' ), $schedule['next_run'] ) )
			. '</li><li>' . esc_html( sprintf( /* translators: %s: A date and time, or "never". */ __( 'Last check: %s', 'wp-updates-notifier' ), $schedule['last_check'] ) )
			. '</li></ul>';
	}

	/**
	 * A Site Health test result.
	 *
	 * @param string $test        Test name.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Headline.
	 * @param string $description HTML, already escaped.
	 *
	 * @return array<string, mixed>
	 */
	private function result( string $test, string $status, string $label, string $description ): array {
		$colors = array(
			'good'        => 'blue',
			'recommended' => 'orange',
			'critical'    => 'red',
		);

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Updates Notifier', 'wp-updates-notifier' ),
				'color' => $colors[ $status ],
			),
			'description' => $description,
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $this->network_context->settings_url() ),
				esc_html__( 'Review the notification settings', 'wp-updates-notifier' )
			),
			'test'        => $test,
		);
	}
}
