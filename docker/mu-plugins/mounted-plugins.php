<?php
/**
 * Local development only: keep WordPress from updating or deleting the plugins mounted from the repo.
 *
 * The working tree is mounted as wp-content/plugins/wp-updates-notifier, a real WordPress.org slug. Once
 * WordPress.org has a newer version than the checkout, the Updates screen would offer it, and updating (or deleting)
 * the plugin removes its directory: the working tree, .git included. The example and dev plugins are mounted too.
 *
 * @package wp-updates-notifier
 */

/**
 * The mounted plugins, by plugin file.
 *
 * @return string[]
 */
function wpun_dev_mounted_plugins() {
	return array(
		'wp-updates-notifier/wp-updates-notifier.php',
		'wpun-example-channel/wpun-example-channel.php',
		'wpun-field-showcase/wpun-field-showcase.php',
	);
}

add_filter(
	'site_transient_update_plugins',
	static function ( $transient ) {
		if ( is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ) {
			foreach ( wpun_dev_mounted_plugins() as $file ) {
				unset( $transient->response[ $file ] );
			}
		}
		return $transient;
	},
	99
);

add_filter(
	'auto_update_plugin',
	static function ( $update, $item ) {
		return isset( $item->plugin ) && in_array( $item->plugin, wpun_dev_mounted_plugins(), true ) ? false : $update;
	},
	99,
	2
);

add_filter(
	'plugin_action_links',
	static function ( $actions, $file ) {
		if ( in_array( $file, wpun_dev_mounted_plugins(), true ) ) {
			unset( $actions['delete'] );
		}
		return $actions;
	},
	99,
	2
);
