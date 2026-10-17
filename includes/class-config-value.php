<?php
/**
 * A resolved configuration value.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * A value from outside the database, with where it came from.
 *
 * Immutable. The value can be a secret, so debug output, casting to string and serialising
 * never include it.
 *
 * @since 2.0.0
 */
final class Config_Value {

	/**
	 * The value.
	 *
	 * @var mixed
	 */
	private $value;

	/**
	 * Where it came from.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Constant and environment variable name.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed  $value  The value.
	 * @param string $source Where it came from, without the value.
	 * @param string $key    The constant and environment variable name.
	 */
	public function __construct( $value, string $source, string $key ) {
		$this->value  = $value;
		$this->source = $source;
		$this->key    = $key;
	}

	/**
	 * The value.
	 *
	 * @since 2.0.0
	 *
	 * @return mixed
	 */
	public function get_value() {
		return $this->value;
	}

	/**
	 * Where the value came from, in words.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_source(): string {
		return $this->source;
	}

	/**
	 * The constant and environment variable name.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_key(): string {
		return $this->key;
	}

	/**
	 * What var_dump() and print_r() show: the key and the source, never the value.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array(
			'key'    => $this->key,
			'source' => $this->source,
		);
	}

	/**
	 * What serialize() keeps: the key and the source, never the value.
	 *
	 * @return array<string, string>
	 */
	public function __serialize(): array {
		return $this->__debugInfo();
	}
}
