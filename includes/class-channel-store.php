<?php
/**
 * Storage of the configured channels.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Stores the channel instances of the site, in the order they were added.
 *
 * The store does not check settings against channel modules and does not need the module of
 * an instance to be registered: instances of a type that is not available right now (an add-on
 * that is deactivated) are kept untouched. Part of the extension API.
 *
 * @since 2.0.0
 */
class Channel_Store {
	const OPT_FIELD = 'sc_wpun_channels';

	/**
	 * Every instance that can be read, keyed by id, in stored order.
	 *
	 * Stored entries that cannot be turned into an instance are skipped.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, Channel_Instance>
	 */
	public function all(): array {
		$instances = array();

		foreach ( $this->read() as $id => $entry ) {
			$instance = $this->hydrate( (string) $id, $entry );
			if ( null !== $instance ) {
				$instances[ $instance->get_id() ] = $instance;
			}
		}

		return $instances;
	}

	/**
	 * One instance.
	 *
	 * @since 2.0.0
	 *
	 * @param string $id Identifier.
	 *
	 * @return Channel_Instance|null Null if there is no usable instance with this id.
	 */
	public function get( string $id ): ?Channel_Instance {
		$stored = $this->read();

		return array_key_exists( $id, $stored ) ? $this->hydrate( $id, $stored[ $id ] ) : null;
	}

	/**
	 * Whether there is a usable instance with this id.
	 *
	 * @since 2.0.0
	 *
	 * @param string $id Identifier.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		return null !== $this->get( $id );
	}

	/**
	 * Add an instance, or replace the one with the same id (which keeps its position).
	 *
	 * @since 2.0.0
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return void
	 */
	public function save( Channel_Instance $instance ): void {
		$stored = $this->read();

		$stored[ $instance->get_id() ] = array(
			'type'     => $instance->get_type(),
			'label'    => $instance->get_label(),
			'enabled'  => $instance->is_enabled(),
			'settings' => $instance->get_settings(),
			'routing'  => $instance->get_routing(),
		);

		$this->write( $stored );
	}

	/**
	 * Remove an instance.
	 *
	 * @since 2.0.0
	 *
	 * @param string $id Identifier.
	 *
	 * @return bool False if there was no instance with this id.
	 */
	public function delete( string $id ): bool {
		$stored = $this->read();

		if ( ! array_key_exists( $id, $stored ) ) {
			return false;
		}

		$instance = $this->get( $id );
		unset( $stored[ $id ] );
		$this->write( $stored );

		if ( null !== $instance ) {
			/**
			 * Fires after a channel instance was deleted.
			 *
			 * @since 2.0.0
			 *
			 * @param string           $id       Identifier of the deleted instance.
			 * @param Channel_Instance $instance The deleted instance.
			 */
			do_action( 'sc_wpun_channel_deleted', $id, $instance );
		}

		return true;
	}

	/**
	 * The stored entries as they are, after the get filter.
	 *
	 * @return array<int|string, mixed>
	 */
	private function read(): array {
		/** This filter is documented in includes/class-settings.php */
		$stored = apply_filters( 'sc_wpun_get_options_filter', get_option( self::OPT_FIELD ), self::OPT_FIELD );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Store the entries, creating the option without autoload if it is missing.
	 *
	 * @param array<int|string, mixed> $stored Entries.
	 *
	 * @return void
	 */
	private function write( array $stored ): void {
		/** This filter is documented in includes/class-settings.php */
		$stored = apply_filters( 'sc_wpun_put_options_filter', $stored, self::OPT_FIELD );

		if ( false === get_option( self::OPT_FIELD ) ) {
			add_option( self::OPT_FIELD, $stored, '', false );
		} else {
			update_option( self::OPT_FIELD, $stored );
		}
	}

	/**
	 * Turn a stored entry into an instance.
	 *
	 * @param string $id    Identifier.
	 * @param mixed  $entry Stored entry.
	 *
	 * @return Channel_Instance|null Null if the entry is not usable.
	 */
	private function hydrate( string $id, $entry ): ?Channel_Instance {
		if ( ! is_array( $entry ) || ! isset( $entry['type'] ) || ! is_string( $entry['type'] ) ) {
			return null;
		}

		try {
			return new Channel_Instance(
				$id,
				$entry['type'],
				isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? (string) $entry['label'] : '',
				! empty( $entry['enabled'] ),
				isset( $entry['settings'] ) && is_array( $entry['settings'] ) ? $entry['settings'] : array(),
				isset( $entry['routing'] ) && is_array( $entry['routing'] ) ? $entry['routing'] : array()
			);
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}
}
