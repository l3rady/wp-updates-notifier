<?php
/**
 * `make e2e-upgrade` only (tests/flows/upgrade): the update of the plugin, and the fake pending updates, on request.
 *
 * Nothing happens until a test sets the option `wpun_upgrade_stage` (reset.php does):
 *
 * - `fake`: load the fake pending updates of .wordpress-org/blueprint/fake-updates.php, which also stops every request
 *   to WordPress.org. They are off while 1.6.0 is installed from WordPress.org (seed.sh) and on afterwards, so the
 *   update check finds updates to send without the network.
 * - `package`: the address of the 2.0.0 zip. WordPress is told the 1.x plugin has an update, at that address, as if
 *   WordPress.org offered it. Everything after that is WordPress's own updater.
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

$wpun_stage = get_option( 'wpun_upgrade_stage' );
$wpun_stage = is_array( $wpun_stage ) ? $wpun_stage : array();

if ( ! empty( $wpun_stage['fake'] ) ) {
	require_once WP_CONTENT_DIR . '/wpun-fake-updates.php';
}

if ( ! empty( $wpun_stage['package'] ) ) {
	add_filter(
		'site_transient_update_plugins',
		static function ( $transient ) use ( $wpun_stage ) {
			if ( ! is_object( $transient ) ) {
				$transient = new stdClass();
			}
			$file                         = 'wp-updates-notifier/class-sc-wp-updates-notifier.php';
			$transient->response          = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
			$transient->checked           = isset( $transient->checked ) && is_array( $transient->checked ) ? $transient->checked : array();
			$transient->last_checked      = time();
			$transient->response[ $file ] = (object) array(
				'id'           => 'w.org/plugins/wp-updates-notifier',
				'slug'         => 'wp-updates-notifier',
				'plugin'       => $file,
				'new_version'  => '2.0.0',
				'url'          => 'https://wordpress.org/plugins/wp-updates-notifier/',
				'package'      => $wpun_stage['package'],
				'tested'       => implode( '.', array_slice( explode( '.', wp_get_wp_version() ), 0, 2 ) ),
				'requires'     => '6.7',
				'requires_php' => '7.4',
			);
			return $transient;
		},
		110
	);
}
