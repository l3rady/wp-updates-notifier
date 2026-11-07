<?php
/**
 * Data a module keeps for one channel instance.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Per-instance storage for modules and add-ons.
 *
 * Get it with `sc_wpun_instance_data( $instance_id )`. The values are kept in options of the plugin:
 *
 * - Network-aware: site options when the plugin runs for a network, else ordinary options.
 * - Never autoloaded.
 * - Removed when the instance is deleted (after `sc_wpun_channel_deleted`, so listeners can still read it)
 *   and when the plugin is uninstalled.
 * - Kept when the instance is switched off or its module is missing.
 * - Works for instances defined in code, but their data stays until uninstall if they are removed from code.
 * - Last write wins. There is no locking.
 *
 * Not for secrets or settings: the data is not shown in Site Health, the config report or the export.
 * Prefix the keys with the name of your add-on, for example `myaddon_cursor`. For a value that expires,
 * use a transient or store a timestamp with it.
 *
 * Keys are 1 to 64 characters: lower case letters, digits and underscores. Invalid input is refused
 * silently: reads give the default, writes return false.
 *
 * @since 3.0.0
 */
final class Instance_Data {

	/**
	 * Pattern of an instance id, as in Channel_Instance.
	 */
	const ID_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/D';

	/**
	 * Pattern of a key.
	 */
	const KEY_PATTERN = '/^[a-z0-9_]{1,64}$/D';

	/**
	 * Longest instance id used as it is in an option name; longer ones are hashed.
	 */
	const MAX_ID_LENGTH = 100;

	/**
	 * Key the value is stored under, inside the option.
	 */
	const WRAP = 'value';

	/**
	 * Start of every option name.
	 */
	const PREFIX = 'sc_wpun_data_';

	/**
	 * Instance id.
	 *
	 * @var string
	 */
	private $instance_id;

	/**
	 * Where the options are stored.
	 *
	 * @var Network_Context
	 */
	private $network;

	/**
	 * The instances.
	 *
	 * @var Channel_Store
	 */
	private $channels;

	/**
	 * Constructor.
	 *
	 * @internal Use sc_wpun_instance_data().
	 *
	 * @param string          $instance_id Instance id.
	 * @param Network_Context $network     Where the options are stored.
	 * @param Channel_Store   $channels    The instances.
	 */
	public function __construct( string $instance_id, Network_Context $network, Channel_Store $channels ) {
		$this->instance_id = $instance_id;
		$this->network     = $network;
		$this->channels    = $channels;
	}

	/**
	 * The stored value.
	 *
	 * The default is returned when the id or key is invalid, there is no value, or the value is damaged.
	 * A value that cannot be read back, for example a damaged serialised string, counts as damaged.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key     Key.
	 * @param mixed  $fallback Value when there is none.
	 *
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		if ( ! $this->is_valid( $key ) ) {
			return $fallback;
		}

		$stored = $this->read( $this->value_name( $key ) );

		// Missing, or damaged: WordPress gives the raw string back when it cannot unserialise a value.
		return is_array( $stored ) && array_key_exists( self::WRAP, $stored ) ? $stored[ self::WRAP ] : $fallback;
	}

	/**
	 * Store a value.
	 *
	 * The last write wins. Nothing is locked.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value; anything WordPress can store as an option.
	 *
	 * @return bool True once the value is stored. False if the id or key is invalid or the instance is not known.
	 */
	public function set( string $key, $value ): bool {
		if ( ! $this->is_valid( $key ) || ! $this->channels->has( $this->instance_id ) ) {
			return false;
		}

		// The value is wrapped: WordPress stores false as an empty string, and does not create an option for it.
		$this->network->update_option( $this->value_name( $key ), array( self::WRAP => $value ), false );

		$keys = $this->read_keys();
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			$this->write_keys( $keys );
		}

		return true;
	}

	/**
	 * Remove a value.
	 *
	 * @since 3.0.0
	 *
	 * @param string $key Key.
	 *
	 * @return void
	 */
	public function delete( string $key ): void {
		if ( ! $this->is_valid( $key ) ) {
			return;
		}

		$this->network->delete_option( $this->value_name( $key ) );

		$this->write_keys( array_values( array_diff( $this->read_keys(), array( $key ) ) ) );
	}

	/**
	 * The keys that have a value.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return $this->is_valid() ? $this->read_keys() : array();
	}

	/**
	 * Remove every value of the instance.
	 *
	 * The plugin does this when the instance is deleted. An add-on can use it to reset its data.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function clear(): void {
		if ( ! $this->is_valid() ) {
			return;
		}

		foreach ( $this->read_keys() as $key ) {
			$this->network->delete_option( $this->value_name( $key ) );
		}

		$this->network->delete_option( $this->index_name() );
	}

	/**
	 * Whether the id is valid and, when given, the key too.
	 *
	 * Checked before any option name is built: valid ids never contain a double underscore, which keeps
	 * the separator between id and key unambiguous.
	 *
	 * @param string|null $key Key, or null to check the id only.
	 *
	 * @return bool
	 */
	private function is_valid( ?string $key = null ): bool {
		if ( 1 !== preg_match( self::ID_PATTERN, $this->instance_id ) ) {
			return false;
		}

		return null === $key || 1 === preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * Option name that holds the list of keys.
	 *
	 * @return string
	 */
	private function index_name(): string {
		$id = strlen( $this->instance_id ) <= self::MAX_ID_LENGTH ? $this->instance_id : md5( $this->instance_id );

		return self::PREFIX . $id;
	}

	/**
	 * Option name that holds one value.
	 *
	 * @param string $key Valid key.
	 *
	 * @return string
	 */
	private function value_name( string $key ): string {
		return $this->index_name() . '__' . $key;
	}

	/**
	 * Read an option.
	 *
	 * @param string $name Option name.
	 *
	 * @return mixed
	 */
	private function read( string $name ) {
		return $this->network->get_option( $name, null );
	}

	/**
	 * The keys in the index.
	 *
	 * @return string[]
	 */
	private function read_keys(): array {
		$stored = $this->read( $this->index_name() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$keys = array();
		foreach ( $stored as $key ) {
			if ( is_string( $key ) && 1 === preg_match( self::KEY_PATTERN, $key ) ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Store the list of keys; no list when it is empty.
	 *
	 * @param string[] $keys Keys.
	 *
	 * @return void
	 */
	private function write_keys( array $keys ): void {
		if ( array() === $keys ) {
			$this->network->delete_option( $this->index_name() );
			return;
		}

		$this->network->update_option( $this->index_name(), $keys, false );
	}
}
