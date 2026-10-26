<?php
/**
 * A configured channel.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * One configured use of a channel type, for example one Slack webhook.
 *
 * A site can have several instances of the same type. Immutable. Part of the extension API.
 *
 * The settings of an effective instance are resolved, secrets included. The values are hidden from var_dump() and
 * print_r(), but don't log or dump them yourself.
 *
 * @since 2.0.0
 */
final class Channel_Instance {
	/**
	 * Identifier, unique among the instances.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Channel type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Label shown to the site owner.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Whether the instance is switched on.
	 *
	 * @var bool
	 */
	private $enabled;

	/**
	 * Settings, as the channel's fields describe them.
	 *
	 * @var array<string, mixed>
	 */
	private $settings;

	/**
	 * Routing rules.
	 *
	 * @var array<string, mixed>
	 */
	private $routing;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $id       Identifier; must match /^[a-z0-9]+(?:_[a-z0-9]+)*$/, so it can be part of a constant name.
	 * @param string               $type     Channel type; must match /^[a-z0-9_]+$/.
	 * @param string               $label    Label.
	 * @param bool                 $enabled  Whether the instance is switched on.
	 * @param array<string, mixed> $settings Settings.
	 * @param array<string, mixed> $routing  Routing rules.
	 *
	 * @throws InvalidArgumentException If the id or the type is not valid.
	 */
	public function __construct( string $id, string $type, string $label, bool $enabled, array $settings = array(), array $routing = array() ) {
		if ( 1 !== preg_match( '/^[a-z0-9]+(?:_[a-z0-9]+)*$/D', $id ) ) {
			throw new InvalidArgumentException( 'A channel instance id may only contain a-z, 0-9 and single underscores between them.' );
		}
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/D', $type ) ) {
			throw new InvalidArgumentException( 'A channel type may only contain a-z, 0-9 and underscores.' );
		}

		$this->id       = $id;
		$this->type     = $type;
		$this->label    = $label;
		$this->enabled  = $enabled;
		$this->settings = $settings;
		$this->routing  = $routing;
	}

	/**
	 * What var_dump() and print_r() show: the settings keys, never the values, which can be resolved secrets.
	 *
	 * @return array<string, mixed>
	 */
	public function __debugInfo(): array {
		return array(
			'id'       => $this->id,
			'type'     => $this->type,
			'label'    => $this->label,
			'enabled'  => $this->enabled,
			'routing'  => $this->routing,
			'settings' => array_fill_keys( array_keys( $this->settings ), '[redacted]' ),
		);
	}

	/**
	 * What serialize() keeps: the settings keys, never the values, which can be resolved secrets.
	 *
	 * @return array<string, mixed>
	 */
	public function __serialize(): array {
		return $this->__debugInfo();
	}

	/**
	 * Identifier.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Channel type.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Label.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Whether the instance is switched on.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Settings.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->settings;
	}

	/**
	 * One setting.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Returned if the setting does not exist.
	 *
	 * @return mixed
	 */
	public function get_setting( string $key, $fallback = null ) {
		return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $fallback;
	}

	/**
	 * Routing rules.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_routing(): array {
		return $this->routing;
	}

	/**
	 * A copy with another label.
	 *
	 * @since 2.0.0
	 *
	 * @param string $label Label.
	 *
	 * @return self
	 */
	public function with_label( string $label ): self {
		return new self( $this->id, $this->type, $label, $this->enabled, $this->settings, $this->routing );
	}

	/**
	 * A copy that is switched on or off.
	 *
	 * @since 2.0.0
	 *
	 * @param bool $enabled Whether the instance is switched on.
	 *
	 * @return self
	 */
	public function with_enabled( bool $enabled ): self {
		return new self( $this->id, $this->type, $this->label, $enabled, $this->settings, $this->routing );
	}

	/**
	 * A copy with other settings (replacing all of them).
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $settings Settings.
	 *
	 * @return self
	 */
	public function with_settings( array $settings ): self {
		return new self( $this->id, $this->type, $this->label, $this->enabled, $settings, $this->routing );
	}

	/**
	 * A copy with other routing rules (replacing all of them).
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $routing Routing rules.
	 *
	 * @return self
	 */
	public function with_routing( array $routing ): self {
		return new self( $this->id, $this->type, $this->label, $this->enabled, $this->settings, $routing );
	}
}
