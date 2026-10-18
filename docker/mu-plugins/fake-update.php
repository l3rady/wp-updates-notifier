<?php
/**
 * Local development only: an offline, predictable pending update.
 *
 * Off by default. `make wp ARGS="config set WPUN_FAKE_UPDATE true --raw --type=constant"` makes WordPress report
 * an update to 9.9.9 for the dummy plugin (docker/dev-plugins/wpun-dummy), with no request to WordPress.org.
 * The update itself fails if you click it: there is no package. `config delete WPUN_FAKE_UPDATE` switches it off.
 *
 * @package wp-updates-notifier
 */

if ( ! defined( 'WPUN_FAKE_UPDATE' ) || ! WPUN_FAKE_UPDATE ) {
	return;
}

add_filter(
	'site_transient_update_plugins',
	static function ( $transient ) {
		$file = 'wpun-dummy/wpun-dummy.php';
		if ( ! is_object( $transient ) ) {
			$transient = (object) array(
				'last_checked' => time(),
				'checked'      => array(),
				'response'     => array(),
			);
		}
		$transient->checked[ $file ]  = '1.0.0';
		$transient->response[ $file ] = (object) array(
			'id'          => 'wpun-dummy',
			'slug'        => 'wpun-dummy',
			'plugin'      => $file,
			'new_version' => '9.9.9',
			'url'         => 'https://example.test/wpun-dummy/',
			'package'     => '',
		);
		return $transient;
	},
	100
);
