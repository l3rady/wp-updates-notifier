<?php
/**
 * The registered channels.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Knows which channel types exist.
 *
 * The first read fires the `sc_wpun_register_channels` action, so that add-ons
 * and the built-in channels register themselves through it. register() works at
 * any time.
 *
 * @since 2.0.0
 */
class Channel_Registry {
	/**
	 * Channels by type.
	 *
	 * @var array<string, Channel>
	 */
	private $channels = array();

	/**
	 * Channels refused because the API version of the site does not satisfy what they need.
	 *
	 * @var array<string, array{label: string, constraint: string, reason: string}>
	 */
	private $rejected = array();

	/**
	 * Whether the registration action has run.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Register a channel.
	 *
	 * @since 2.0.0
	 *
	 * @param Channel $channel The channel.
	 *
	 * @return bool False if its type is invalid or already registered, or its API constraint is not met.
	 */
	public function register( Channel $channel ): bool {
		$type = $channel->get_type();

		if ( 1 !== preg_match( '/^[a-z0-9_]+$/D', $type ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html__( 'A channel type may only contain lower case letters, digits and underscores.', 'wp-updates-notifier' ),
				'2.0.0'
			);
			return false;
		}

		if ( isset( $this->channels[ $type ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					// translators: %s: Channel type.
					sprintf( __( 'A channel of type "%s" is already registered.', 'wp-updates-notifier' ), $type )
				),
				'2.0.0'
			);
			return false;
		}

		$constraint = $channel->get_api_constraint();
		if ( '' !== $constraint && ! Version_Constraint::satisfies( sc_wpun_api_version(), $constraint ) ) {
			$this->reject( $channel, $constraint );
			return false;
		}

		unset( $this->rejected[ $type ] );
		$this->channels[ $type ] = $channel;
		return true;
	}

	/**
	 * Refuse a channel: record why, and tell listeners.
	 *
	 * @param Channel $channel    The channel.
	 * @param string  $constraint The API constraint it declared.
	 *
	 * @return void
	 */
	private function reject( Channel $channel, string $constraint ): void {
		$type = $channel->get_type();

		if ( Version_Constraint::is_valid( $constraint ) ) {
			$reason = sprintf(
				// translators: 1: The API version constraint the module needs, 2: The API version of this site.
				__( 'Needs WP Updates Notifier API %1$s; this site has %2$s. Update WP Updates Notifier.', 'wp-updates-notifier' ),
				$constraint,
				sc_wpun_api_version()
			);
		} else {
			$reason = sprintf(
				// translators: %s: The API version constraint the module declared.
				__( 'Its API requirement "%s" could not be read.', 'wp-updates-notifier' ),
				$constraint
			);
		}

		$this->rejected[ $type ] = array(
			'label'      => $channel->get_label(),
			'constraint' => $constraint,
			'reason'     => $reason,
		);

		/**
		 * Fires when a channel is refused because the API version of the site does not satisfy what it needs.
		 *
		 * Example:
		 *
		 *     add_action(
		 *         'sc_wpun_channel_rejected',
		 *         function ( $type, $reason, $channel ) {
		 *             error_log( sprintf( 'Channel "%s" was refused: %s', $type, $reason ) );
		 *         },
		 *         10,
		 *         3
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param string  $type    The channel type.
		 * @param string  $reason  Why it was refused, as plain text.
		 * @param Channel $channel The channel that was refused.
		 */
		do_action( 'sc_wpun_channel_rejected', $type, $reason, $channel );
	}

	/**
	 * Remove a channel.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Channel type.
	 *
	 * @return bool Whether there was such a channel.
	 */
	public function unregister( string $type ): bool {
		// Load first, so a channel registered through the hook can be removed before anything reads the registry.
		$this->load();

		if ( ! isset( $this->channels[ $type ] ) ) {
			return false;
		}

		unset( $this->channels[ $type ] );
		return true;
	}

	/**
	 * A channel.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Channel type.
	 *
	 * @return Channel|null
	 */
	public function get( string $type ): ?Channel {
		$this->load();
		return $this->channels[ $type ] ?? null;
	}

	/**
	 * Whether a channel is registered.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Channel type.
	 *
	 * @return bool
	 */
	public function has( string $type ): bool {
		$this->load();
		return isset( $this->channels[ $type ] );
	}

	/**
	 * Every channel.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, Channel> Channels by type.
	 */
	public function all(): array {
		$this->load();
		return $this->channels;
	}

	/**
	 * The channels that were refused because the site's API version does not satisfy what they need.
	 *
	 * A later successful registration of the same type, for example by an updated add-on, removes it.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array{label: string, constraint: string, reason: string}> By channel type.
	 */
	public function get_rejected(): array {
		$this->load();
		return $this->rejected;
	}

	/**
	 * Let add-ons register their channels, once.
	 *
	 * @return void
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		/**
		 * Fires when the notification channels are first needed, for modules and add-ons to register theirs.
		 *
		 * Example:
		 *
		 *     add_action(
		 *         'sc_wpun_register_channels',
		 *         function ( \WPUN\Channel_Registry $registry ) {
		 *             $registry->register( new My_Channel() );
		 *         }
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param Channel_Registry $registry The registry to register channels with.
		 */
		do_action( 'sc_wpun_register_channels', $this );
	}
}
