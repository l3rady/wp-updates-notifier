<?php
/**
 * What the WP-CLI status and channels commands report.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Collects the plugin's settings, schedule, channel instances and pending updates as plain arrays.
 *
 * The commands only print them, so this works without WP-CLI. Secret field values are never read
 * into the report, only whether they are set, and an instance whose module is not registered has
 * no field values at all: without the module's schema there is no telling which are secret.
 *
 * @internal
 * @phpstan-type Last_Result array{success: bool, message: string, time: string}
 * @phpstan-type Field_Row array{field: string, value: string, source: string}
 * @phpstan-type Muted_Row array{type: string, key: string, name: ?string, until: ?string, from_code: bool, time: int, user: int, offered: ?string}
 * @phpstan-type Channel_Row array{id: string, label: string, module: string, availability: string, enabled: bool, code_defined: bool, last_result: ?Last_Result, last_failure: ?Last_Result, retry_at: ?string, fields: Field_Row[]}
 */
class Status_Report {
	const AVAILABLE = 'available';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

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
	 * Dispatcher, to know whether a module is there.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Run state.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Update checker, for the pending updates.
	 *
	 * @var Update_Checker
	 */
	private $checker;

	/**
	 * Mute storage.
	 *
	 * @var Mute_Store
	 */
	private $mutes;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings   Settings.
	 * @param Channel_Store    $store      Channel instances.
	 * @param Channel_Registry $registry   Registered modules.
	 * @param Dispatcher       $dispatcher Dispatcher.
	 * @param State            $state      Run state.
	 * @param Update_Checker   $checker    Update checker.
	 * @param Mute_Store       $mutes      Mute storage.
	 */
	public function __construct( Settings $settings, Channel_Store $store, Channel_Registry $registry, Dispatcher $dispatcher, State $state, Update_Checker $checker, Mute_Store $mutes ) {
		$this->settings   = $settings;
		$this->store      = $store;
		$this->registry   = $registry;
		$this->dispatcher = $dispatcher;
		$this->state      = $state;
		$this->checker    = $checker;
		$this->mutes      = $mutes;
	}

	/**
	 * Everything the status command shows.
	 *
	 * @return array{settings: array<int, array{setting: string, value: string, source: string}>, schedule: array{frequency: string, next_run: string, last_check: string}, channels: Channel_Row[], pending: array<int, array{id: string, label: string, status: string, items: array<int, array{type: string, key: string, name: string, current_version: string, new_version: string, new: bool}>}>}
	 */
	public function all(): array {
		return array(
			'settings' => $this->settings(),
			'schedule' => $this->schedule(),
			'channels' => $this->channels(),
			'pending'  => $this->pending(),
		);
	}

	/**
	 * The muted plugins and themes, without the mutes a newer version has ended.
	 *
	 * Plain data: the screens and commands format it. A mute set in code has no time or user.
	 *
	 * @return Muted_Row[] The name is null when the item is not installed, and `offered` is null when no update is waiting.
	 */
	public function muted(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();

		$rows = array();
		foreach ( $this->mutes->all() as $mute ) {
			$type = $mute->get_type();
			$key  = $mute->get_key();
			$name = null;
			if ( 'plugin' === $type && isset( $plugins[ $key ] ) ) {
				$name = (string) $plugins[ $key ]['Name'];
			} elseif ( 'theme' === $type && wp_get_theme( $key )->exists() ) {
				$name = (string) wp_get_theme( $key )->get( 'Name' );
			}

			$rows[] = array(
				'type'      => $type,
				'key'       => $key,
				'name'      => '' === $name ? null : $name,
				'until'     => $mute->get_until(),
				'from_code' => $mute->is_from_code(),
				'time'      => $mute->get_time(),
				'user'      => $mute->get_user_id(),
				'offered'   => $this->mutes->offered_version( $type, $key ),
			);
		}

		return $rows;
	}

	/**
	 * A time in the site's timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 *
	 * @return string
	 */
	public static function format_time( int $timestamp ): string {
		return (string) wp_date( 'Y-m-d H:i:s T', $timestamp );
	}

	/**
	 * The global settings that can be stored, with where each comes from.
	 *
	 * @return array<int, array{setting: string, value: string, source: string}> The source is `database` unless a constant, environment variable or other source supplies it.
	 */
	public function settings(): array {
		$values = $this->settings->get();
		$rows   = array();

		foreach ( $this->settings->schemas() as $name => $schema ) {
			if ( false === ( $schema['storage'] ?? true ) ) {
				continue; // Code-only setting.
			}
			$rows[] = array(
				'setting' => $name,
				'value'   => $this->display( $values[ $name ] ?? null ),
				'source'  => $this->settings->external_source( $name ) ?? 'database',
			);
		}

		return $rows;
	}

	/**
	 * The check frequency, when the next check is due and when the last one ran.
	 *
	 * @return array{frequency: string, next_run: string, last_check: string}
	 */
	public function schedule(): array {
		$frequency = $this->settings->get()['frequency'];
		$event     = wp_get_scheduled_event( Scheduler::CRON_NAME );
		$last      = $this->state->get()['last_check_time'];

		if ( false !== $event ) {
			$next = self::format_time( $event->timestamp );
		} else {
			$next = __( 'not scheduled', 'wp-updates-notifier' );
		}

		return array(
			'frequency'  => $frequency,
			'next_run'   => $next,
			'last_check' => false === $last ? __( 'never', 'wp-updates-notifier' ) : self::format_time( $last ),
		);
	}

	/**
	 * Every channel instance, with its module, availability, last delivery and field values.
	 *
	 * @return Channel_Row[]
	 */
	public function channels(): array {
		$channels = $this->state->get()['channels'];
		$rows     = array();

		foreach ( $this->store->all() as $id => $instance ) {
			$entry  = $channels[ $id ] ?? array();
			$last   = isset( $entry['last_result'] ) ? array(
				'success' => $entry['last_result']['success'],
				'message' => $entry['last_result']['message'],
				'time'    => self::format_time( $entry['last_result']['time'] ),
			) : null;
			$rows[] = array(
				'id'           => $id,
				'label'        => $instance->get_label(),
				'module'       => $instance->get_type(),
				'availability' => $this->availability( $instance ),
				'enabled'      => $instance->is_enabled(),
				'code_defined' => $this->store->is_code_defined( $id ),
				'last_result'  => $last,
				'last_failure' => null !== $last && ! $last['success'] ? $last : null,
				'retry_at'     => isset( $entry['retry_at'] ) ? self::format_time( $entry['retry_at'] ) : null,
				'fields'       => $this->fields( $instance ),
			);
		}

		return $rows;
	}

	/**
	 * Whether the module of an instance is registered, and if not, why.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return string `available`, or "rejected: <reason>" or "unknown module".
	 */
	public function availability( Channel_Instance $instance ): string {
		if ( $this->dispatcher->is_available( $instance ) ) {
			return self::AVAILABLE;
		}

		$rejected = $this->registry->get_rejected()[ $instance->get_type() ] ?? null;
		if ( null !== $rejected ) {
			// translators: %s: Why the module was refused, for example the API version it needs.
			return sprintf( __( 'rejected: %s', 'wp-updates-notifier' ), $rejected['reason'] );
		}

		return __( 'unknown module', 'wp-updates-notifier' );
	}

	/**
	 * What is waiting for each instance and which of it is new to it, as a dry run sees it.
	 *
	 * Runs the check without sending or saving anything, which does refresh WordPress's update data.
	 *
	 * @return array<int, array{id: string, label: string, status: string, items: array<int, array{type: string, key: string, name: string, current_version: string, new_version: string, new: bool}>}>
	 */
	public function pending(): array {
		$channels  = $this->state->get()['channels'];
		$instances = $this->store->all();
		$rows      = array();

		foreach ( $this->checker->run_check( 'cli', true, false ) as $id => $plan ) {
			$items = array();
			foreach ( $plan['pending'] as $item ) {
				$items[] = array(
					'type'            => $item->get_type(),
					'key'             => $item->get_key(),
					'name'            => $item->get_name(),
					'current_version' => $item->get_current_version(),
					'new_version'     => $item->get_new_version(),
					'new'             => ( $channels[ $id ]['notified'][ $item->get_type() ][ $item->get_key() ] ?? null ) !== $item->get_new_version(),
				);
			}
			$rows[] = array(
				'id'     => $id,
				'label'  => isset( $instances[ $id ] ) ? $instances[ $id ]->get_label() : $id,
				'status' => $plan['status'],
				'items'  => $items,
			);
		}

		return $rows;
	}

	/**
	 * The last delivery result as one line.
	 *
	 * @param array{success: bool, message: string, time: string}|null $last The result from the report.
	 *
	 * @return string Empty if there is none.
	 */
	public static function describe_last( ?array $last ): string {
		if ( null === $last ) {
			return '';
		}

		return sprintf( '%s, %s: %s', $last['success'] ? __( 'success', 'wp-updates-notifier' ) : __( 'failure', 'wp-updates-notifier' ), $last['time'], $last['message'] );
	}

	/**
	 * The settings of an instance by field of its module, with their sources.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return Field_Row[] A secret shows `set` or `not set`. Empty if the module is not registered or its fields cannot be read.
	 */
	private function fields( Channel_Instance $instance ): array {
		$channel = $this->registry->get( $instance->get_type() );
		if ( null === $channel ) {
			return array();
		}

		try {
			$schema = $channel->get_fields();
		} catch ( \Throwable $e ) {
			return array();
		}

		$rows = array();
		foreach ( $schema as $name => $field ) {
			$value  = $this->display( $instance->get_setting( (string) $name ) );
			$rows[] = array(
				'field'  => (string) $name,
				'value'  => Field_Types::is_secret( $field ) ? ( '' === $value ? __( 'not set', 'wp-updates-notifier' ) : __( 'set', 'wp-updates-notifier' ) ) : $value,
				'source' => $this->store->external_source( $instance->get_id(), (string) $name ) ?? 'database',
			);
		}

		return $rows;
	}

	/**
	 * A setting value as text.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function display( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}
}
