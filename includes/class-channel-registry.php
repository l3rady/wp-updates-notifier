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
	 * @return bool False if its type is invalid or already registered.
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

		$this->channels[ $type ] = $channel;
		return true;
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
		 * Fires when the channels are first needed: register yours here.
		 *
		 * @since 2.0.0
		 *
		 * @param Channel_Registry $registry The registry; call its register() method.
		 */
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
