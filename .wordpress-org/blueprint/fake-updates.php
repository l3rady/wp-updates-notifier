<?php
/**
 * Fake, offline update data for the demo site: the WordPress Playground blueprint (`make blueprint`) and the throwaway
 * site of `make screenshots` both load this file as a must-use plugin.
 *
 * Reports pending updates for the three made-up plugins and the active theme, adds a WordPress update to the update
 * check's items, and stops every request to WordPress.org, so nothing replaces the fake data. The core update is added
 * to the items and not to WordPress's own update data, because that would put an update notice at the top of every
 * admin screen. Playground installs the plugin before this file exists, and fetches it outside PHP, so blocking
 * WordPress.org here does not get in the way of that.
 *
 * @package wp-updates-notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

// The made-up plugins: file => slug, installed version, new version, and whether the new version is tested with this
// WordPress (the last one is not yet). tests/screenshots/seed.sh and the blueprint template (blueprint.json) make the
// same plugins.
const WPUN_DEMO_PLUGINS = array(
	'example-contact-forms/example-contact-forms.php' => array( 'example-contact-forms', '3.4.1', '3.5.0', true ),
	'example-gallery/example-gallery.php'             => array( 'example-gallery', '2.1.0', '2.1.2', true ),
	'example-backup/example-backup.php'               => array( 'example-backup', '1.8.3', '1.9.0', false ),
);

/**
 * The next version after a version number: the last part plus one.
 *
 * @param string $version A version such as 1.5.
 *
 * @return string
 */
function wpun_demo_next_version( $version ) {
	$parts = explode( '.', (string) $version );
	$last  = count( $parts ) - 1;

	$parts[ $last ] = (string) ( (int) $parts[ $last ] + 1 );
	return implode( '.', $parts );
}

/**
 * The major.minor of the WordPress before this one, for a plugin that is not tested with it yet.
 *
 * @return string
 */
function wpun_demo_previous_minor() {
	list( $major, $minor ) = array_map( 'intval', explode( '.', wp_get_wp_version() ) + array( 0, 0 ) );

	return $minor > 0 ? $major . '.' . ( $minor - 1 ) : ( $major - 1 ) . '.9';
}

add_filter(
	'site_transient_update_plugins',
	static function ( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$transient->last_checked = time();
		$transient->response     = array();
		foreach ( WPUN_DEMO_PLUGINS as $file => $data ) {
			$transient->checked[ $file ]  = $data[1];
			$transient->response[ $file ] = (object) array(
				'id'           => 'example.com/' . $data[0],
				'slug'         => $data[0],
				'plugin'       => $file,
				'new_version'  => $data[2],
				'url'          => 'https://example.com/plugins/' . $data[0] . '/',
				'package'      => '',
				'tested'       => $data[3] ? implode( '.', array_slice( explode( '.', wp_get_wp_version() ), 0, 2 ) ) : wpun_demo_previous_minor(),
				'requires'     => '6.0',
				'requires_php' => '7.4',
			);
		}
		return $transient;
	},
	100
);

add_filter(
	'site_transient_update_themes',
	static function ( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$theme                   = wp_get_theme();
		$transient->last_checked = time();
		$transient->response     = array(
			$theme->get_stylesheet() => array(
				'theme'       => $theme->get_stylesheet(),
				'new_version' => wpun_demo_next_version( $theme->get( 'Version' ) ),
				'url'         => 'https://example.com/themes/' . $theme->get_stylesheet() . '/',
				'package'     => '',
			),
		);
		return $transient;
	},
	100
);

add_filter(
	'sc_wpun_update_items',
	static function ( $items ) {
		$items[] = new WPUN\Notification_Item(
			array(
				'type'            => 'core',
				'key'             => 'core',
				'name'            => 'WordPress',
				'current_version' => wp_get_wp_version(),
				'new_version'     => wpun_demo_next_version( wp_get_wp_version() ),
			)
		);
		return $items;
	}
);

// No request to WordPress.org, whose answer would replace the data above.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( 'wordpress.org' === $host || '.wordpress.org' === substr( $host, -14 ) ) {
			return new WP_Error( 'wpun_demo_offline', 'Blocked: this site is offline.' );
		}
		return $pre;
	},
	10,
	3
);
