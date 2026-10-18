<?php
/**
 * One update in a notification.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * One thing that has an update: WordPress itself, a plugin, a theme, or a type an add-on adds.
 *
 * Immutable. Part of the extension API: channels receive these inside a Notification.
 *
 * Values are single-line: in the name, versions, notes and link labels every run of control characters (line breaks,
 * tabs and the like) becomes one space and the value is trimmed, and control characters are removed from link URLs.
 * `key`, `type` and flags are kept as given.
 *
 * @since 2.0.0
 */
final class Notification_Item {
	/**
	 * Kind of item: core, plugin, theme or a custom type (lower case letters, digits and underscores).
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Identifier within the type: plugin file, theme stylesheet or "core".
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Installed version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Version available.
	 *
	 * @var string
	 */
	private $new_version;

	/**
	 * Links: label (for example "details" or "changelog") => URL.
	 *
	 * @var array<string, string>
	 */
	private $links;

	/**
	 * Lines of extra information, such as compatibility notes and warnings.
	 *
	 * @var string[]
	 */
	private $notes;

	/**
	 * Machine readable facts, such as `tested`, `requires_php` and `requires_wp`.
	 *
	 * @var array<string, scalar>
	 */
	private $flags;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $data {
	 *     The item.
	 *
	 *     @type string   $type            Kind of item; must match /^[a-z0-9_]+$/.
	 *     @type string   $key             Plugin file, theme stylesheet or "core".
	 *     @type string   $name            Display name.
	 *     @type string   $current_version Installed version.
	 *     @type string   $new_version     Version available.
	 *     @type array    $links           Label => URL.
	 *     @type string[] $notes           Extra lines.
	 *     @type array    $flags           Name => scalar value. Plugin and theme items from the update check carry a bool `active` flag, and plugins also `tested`, `requires_php` and `requires_wp`.
	 * }
	 *
	 * @throws InvalidArgumentException If the type is not valid.
	 */
	public function __construct( array $data ) {
		$type = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : '';
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/D', $type ) ) {
			throw new InvalidArgumentException( 'A notification item type may only contain a-z, 0-9 and underscores.' );
		}

		$this->type            = $type;
		$this->key             = self::string_value( $data, 'key' );
		$this->name            = self::single_line( self::string_value( $data, 'name' ) );
		$this->current_version = self::single_line( self::string_value( $data, 'current_version' ) );
		$this->new_version     = self::single_line( self::string_value( $data, 'new_version' ) );

		$this->links = array();
		foreach ( isset( $data['links'] ) && is_array( $data['links'] ) ? $data['links'] : array() as $label => $url ) {
			if ( is_scalar( $url ) ) {
				$this->links[ self::single_line( (string) $label ) ] = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', (string) $url );
			}
		}

		$this->notes = array();
		foreach ( isset( $data['notes'] ) && is_array( $data['notes'] ) ? $data['notes'] : array() as $note ) {
			if ( is_scalar( $note ) ) {
				$this->notes[] = self::single_line( (string) $note );
			}
		}

		$this->flags = array();
		foreach ( isset( $data['flags'] ) && is_array( $data['flags'] ) ? $data['flags'] : array() as $flag => $value ) {
			if ( is_scalar( $value ) ) {
				$this->flags[ (string) $flag ] = $value;
			}
		}
	}

	/**
	 * Collapse control characters (line breaks, tabs and the like) to one space and trim.
	 *
	 * @param string $value Value.
	 *
	 * @return string
	 */
	private static function single_line( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) );
	}

	/**
	 * A string entry of the constructor data, or an empty string.
	 *
	 * @param array<string, mixed> $data Constructor data.
	 * @param string               $key  Entry.
	 *
	 * @return string
	 */
	private static function string_value( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
	}

	/**
	 * Kind of item.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Plugin file, theme stylesheet or "core".
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_key(): string {
		return $this->key;
	}

	/**
	 * Display name.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Installed version.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_current_version(): string {
		return $this->current_version;
	}

	/**
	 * Version available.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_new_version(): string {
		return $this->new_version;
	}

	/**
	 * Links: label => URL.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, string>
	 */
	public function get_links(): array {
		return $this->links;
	}

	/**
	 * Extra lines of information.
	 *
	 * @since 2.0.0
	 *
	 * @return string[]
	 */
	public function get_notes(): array {
		return $this->notes;
	}

	/**
	 * Machine readable facts: name => scalar value.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, scalar>
	 */
	public function get_flags(): array {
		return $this->flags;
	}
}
