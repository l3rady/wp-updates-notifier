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
 * any time. replace() lets an add-on take over a type, whatever the load order.
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
	 * Replaced types: the original channel, or null while none has registered.
	 *
	 * @var array<string, Channel|null>
	 */
	private $replaced = array();

	/**
	 * Types whose replace() was refused because its API constraint is not met.
	 *
	 * @var array<string, true>
	 */
	private $refused_replacements = array();

	/**
	 * Whether the registration action has run.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Register a channel.
	 *
	 * If another add-on has already replaced this type with replace(), the channel becomes the original that
	 * get_replaced() returns, and this returns false without a warning: the replacement stays active.
	 *
	 * @since 2.0.0
	 *
	 * @param Channel $channel The channel.
	 *
	 * @return bool False if its type is invalid or already registered, or its API constraint is not met, or the type is replaced.
	 */
	public function register( Channel $channel ): bool {
		$type = $channel->get_type();

		if ( ! $this->has_valid_type( $channel, __METHOD__ ) ) {
			return false;
		}

		if ( $this->keep_as_original( $channel ) ) {
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

		if ( ! $this->meets_constraint( $channel ) ) {
			$this->reject( $channel, $channel->get_api_constraint() );
			return false;
		}

		// A refused replacement stays reported, whichever order the two ran in.
		if ( ! isset( $this->refused_replacements[ $type ] ) ) {
			unset( $this->rejected[ $type ] );
		}
		$this->channels[ $type ] = $channel;
		return true;
	}

	/**
	 * Take over a channel type.
	 *
	 * The replacement serves every instance of the type, whether the original registers before or after this call.
	 * Instances are keyed by type, so they carry over untouched. The first successful replace() of a type wins; a
	 * second one warns and returns false. A replacement whose API constraint is not met is refused like a registered
	 * channel is (see get_rejected()). A refused replacement does not claim the type: the original stays, and a later
	 * valid replace() may still succeed and clears the rejection. Replacing a type nothing registers works like
	 * register(). unregister() removes the replacement and the original.
	 *
	 * @since 3.0.0
	 *
	 * @param Channel $channel The replacement.
	 *
	 * @return bool False if its type is invalid, the type is already replaced, or its API constraint is not met.
	 */
	public function replace( Channel $channel ): bool {
		$type = $channel->get_type();

		if ( ! $this->has_valid_type( $channel, __METHOD__ ) ) {
			return false;
		}

		if ( array_key_exists( $type, $this->replaced ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					// translators: %s: Channel type.
					sprintf( __( 'A channel of type "%s" is already replaced.', 'wp-updates-notifier' ), $type )
				),
				'3.0.0'
			);
			return false;
		}

		if ( ! $this->meets_constraint( $channel ) ) {
			$this->reject( $channel, $channel->get_api_constraint() );
			$this->refused_replacements[ $type ] = true;
			return false;
		}

		$this->replaced[ $type ] = $this->channels[ $type ] ?? null;
		$this->channels[ $type ] = $channel;
		unset( $this->rejected[ $type ], $this->refused_replacements[ $type ] );
		return true;
	}

	/**
	 * Whether the type of a channel is well formed; warns if not.
	 *
	 * @param Channel $channel The channel.
	 * @param string  $method  The public method that was called, for the warning.
	 *
	 * @return bool
	 */
	private function has_valid_type( Channel $channel, string $method ): bool {
		if ( 1 === preg_match( '/^[a-z0-9_]+$/D', $channel->get_type() ) ) {
			return true;
		}

		_doing_it_wrong(
			$method, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A method name from this class.
			esc_html__( 'A channel type may only contain lower case letters, digits and underscores.', 'wp-updates-notifier' ),
			'2.0.0'
		);
		return false;
	}

	/**
	 * Whether the site's API version satisfies what a channel needs.
	 *
	 * @param Channel $channel The channel.
	 *
	 * @return bool
	 */
	private function meets_constraint( Channel $channel ): bool {
		$constraint = $channel->get_api_constraint();
		return '' === $constraint || Version_Constraint::satisfies( sc_wpun_api_version(), $constraint );
	}

	/**
	 * Keep a channel as the original of a type that was replaced before the original registered.
	 *
	 * @param Channel $channel The channel being registered.
	 *
	 * @return bool Whether the type is waiting for its original, so the channel is not registered.
	 */
	private function keep_as_original( Channel $channel ): bool {
		$type = $channel->get_type();

		if ( ! array_key_exists( $type, $this->replaced ) || null !== $this->replaced[ $type ] ) {
			return false;
		}

		// An original that cannot run is dropped quietly: the type works through its replacement.
		if ( $this->meets_constraint( $channel ) ) {
			$this->replaced[ $type ] = $channel;
		}
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
	 * A replaced type is removed entirely, the replacement and the original.
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

		unset( $this->replaced[ $type ], $this->refused_replacements[ $type ] );

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
	 * The original channel of a type that replace() took over.
	 *
	 * Lets a replacement delegate to the channel it replaced, or a diagnostic tell that a type is replaced.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Channel type.
	 *
	 * @return Channel|null The original, or null if the type is not replaced or the original has not registered.
	 */
	public function get_replaced( string $type ): ?Channel {
		$this->load();
		return $this->replaced[ $type ] ?? null;
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
