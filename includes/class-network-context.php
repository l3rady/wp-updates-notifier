<?php
/**
 * Where the plugin stores its options: per site, or once for the whole network.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Answers whether the plugin runs for a whole multisite network and stores its options accordingly.
 *
 * Network-activated, the plugin keeps its options as network (site) options under the same names.
 * Otherwise they are ordinary options. Nothing else in the plugin asks WordPress where an option lives.
 *
 * @internal
 */
class Network_Context {

	/**
	 * Whether the plugin is network-activated on a multisite.
	 *
	 * Reads the network's active plugins directly: is_plugin_active_for_network() is only loaded in the
	 * admin. Not remembered, as WordPress caches the site option already.
	 *
	 * @return bool
	 */
	public function is_network(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$active = get_site_option( 'active_sitewide_plugins', array() );

		return is_array( $active ) && isset( $active[ plugin_basename( SC_WPUN_FILE ) ] );
	}

	/**
	 * Read an option of the plugin.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $fallback Value when the option does not exist.
	 *
	 * @return mixed
	 */
	public function get_option( string $name, $fallback = false ) {
		return $this->is_network() ? get_site_option( $name, $fallback ) : get_option( $name, $fallback );
	}

	/**
	 * Create an option of the plugin.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Whether to load it on every request. Network options have no autoload, so it is ignored there.
	 *
	 * @return bool True if the option was added.
	 */
	public function add_option( string $name, $value, bool $autoload = false ): bool {
		return $this->is_network() ? add_site_option( $name, $value ) : add_option( $name, $value, '', $autoload );
	}

	/**
	 * Update an option of the plugin.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 *
	 * @return bool True if the value was changed.
	 */
	public function update_option( string $name, $value ): bool {
		return $this->is_network() ? update_site_option( $name, $value ) : update_option( $name, $value );
	}

	/**
	 * Delete an option of the plugin.
	 *
	 * @param string $name Option name.
	 *
	 * @return bool True if the option was deleted.
	 */
	public function delete_option( string $name ): bool {
		return $this->is_network() ? delete_site_option( $name ) : delete_option( $name );
	}

	/**
	 * Call a function when an option of the plugin changes value.
	 *
	 * The callback gets the old and the new value, whichever kind of option it is. The kind is decided
	 * now, so register this once the plugin is booted.
	 *
	 * @param string   $name     Option name.
	 * @param callable $callback Receives ( $old_value, $value ).
	 *
	 * @return void
	 */
	public function on_option_updated( string $name, callable $callback ): void {
		if ( ! $this->is_network() ) {
			add_action( 'update_option_' . $name, $callback, 10, 2 );
			return;
		}

		// WordPress passes ( option, value, old value, network id ) for network options.
		add_action(
			'update_site_option_' . $name,
			static function ( $option, $value, $old_value ) use ( $callback ) {
				$callback( $old_value, $value );
			},
			10,
			3
		);
	}

	/**
	 * The site that holds the network's single schedule and screens.
	 *
	 * @return int
	 */
	public function main_site_id(): int {
		return is_multisite() ? (int) get_main_site_id() : (int) get_current_blog_id();
	}

	/**
	 * The name to call the site in notifications: the network's name in network mode.
	 *
	 * @return string
	 */
	public function site_name(): string {
		if ( $this->is_network() ) {
			$network = get_network();
			return $network instanceof \WP_Network ? (string) $network->site_name : get_bloginfo();
		}

		return get_bloginfo();
	}

	/**
	 * The address notifications link to as the site's home.
	 *
	 * @return string
	 */
	public function home_url(): string {
		return $this->is_network() ? network_home_url() : home_url();
	}

	/**
	 * The screen where the updates are listed, as notifications link to it.
	 *
	 * @return string
	 */
	public function update_core_url(): string {
		return $this->is_network() ? network_admin_url( 'update-core.php' ) : admin_url( 'update-core.php' );
	}

	/**
	 * The address of the person to notify by default: the network admin's in network mode.
	 *
	 * @return string
	 */
	public function admin_email(): string {
		return (string) ( $this->is_network() ? get_site_option( 'admin_email' ) : get_option( 'admin_email' ) );
	}

	/**
	 * The ids of the sites of the current network that are not deleted.
	 *
	 * Only for network mode, where "active" means active on any site. `number => 0` lifts the default limit of 100.
	 *
	 * @return int[]
	 */
	public function site_ids(): array {
		$ids = get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => get_current_network_id(),
				'deleted'    => 0,
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The site id the plugin's settings and schedule belong to: the main site in network mode, else the current site.
	 *
	 * @return int
	 */
	public function owner_site_id(): int {
		return $this->is_network() ? $this->main_site_id() : (int) get_current_blog_id();
	}
}
