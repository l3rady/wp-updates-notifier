<?php
/**
 * Puts the `make e2e` site back to a known state, between tests (specs/helpers.js, resetSite). Run with
 * `wp eval-file reset.php '<json>'`, where the JSON is {"channels": {...}, "settings": {...}}.
 *
 * Clears the run state (what was notified, the last results) and the mutes, stores the given channels and settings, removes every
 * SC_WPUN_ constant from wp-config.php, puts the site title (the network name on a multisite) back, switches the fixture add-on off and puts the update check back on the
 * schedule. Mail is emptied by the test, in Mailpit.
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$wpun_args = isset( $args[0] ) ? json_decode( $args[0], true ) : array();
$wpun_args = is_array( $wpun_args ) ? $wpun_args : array();

require_once ABSPATH . 'wp-admin/includes/plugin.php';
// On a multisite (make e2e-ms) the plugin is network-activated and keeps its options on the network.
$wpun_network = is_multisite();

// A test that deactivated the plugin and failed before it activated it again must not break the next one.
if ( ! is_plugin_active( 'wp-updates-notifier/wp-updates-notifier.php' ) || ( $wpun_network && ! is_plugin_active_for_network( 'wp-updates-notifier/wp-updates-notifier.php' ) ) ) {
	activate_plugin( 'wp-updates-notifier/wp-updates-notifier.php', '', $wpun_network );
}

// The field showcase (a dev plugin docker/site-setup.sh activates) is on, as on the site a person sees.
if ( ! is_plugin_active( 'wpun-field-showcase/wpun-field-showcase.php' ) || ( $wpun_network && ! is_plugin_active_for_network( 'wpun-field-showcase/wpun-field-showcase.php' ) ) ) {
	activate_plugin( 'wpun-field-showcase/wpun-field-showcase.php', '', $wpun_network );
}

$wpun_channels = isset( $wpun_args['channels'] ) && is_array( $wpun_args['channels'] ) ? $wpun_args['channels'] : array();
$wpun_settings = array_merge(
	array(
		'frequency'      => 'hourly',
		'notify_core'    => 'all',
		'notify_plugins' => 'all',
		'notify_themes'  => 'all',
		'hide_updates'   => false,
	),
	isset( $wpun_args['settings'] ) && is_array( $wpun_args['settings'] ) ? $wpun_args['settings'] : array()
);

if ( $wpun_network ) {
	update_network_option( null, 'site_name', 'Test Network' );
	delete_site_option( 'sc_wpun_state' );
	delete_site_option( 'sc_wpun_muted' );
	update_site_option( 'sc_wpun_channels', $wpun_channels );
	update_site_option( 'sc_wpun_settings', $wpun_settings );
} else {
	update_option( 'blogname', 'Test' );
	delete_option( 'sc_wpun_state' );
	delete_option( 'sc_wpun_muted' );
	update_option( 'sc_wpun_channels', $wpun_channels, false );
	update_option( 'sc_wpun_settings', $wpun_settings );
}

$wpun_constants = json_decode( WP_CLI::runcommand( 'config list SC_WPUN_ --fields=name --format=json', array( 'return' => true, 'exit_error' => false ) ), true );
foreach ( is_array( $wpun_constants ) ? $wpun_constants : array() as $wpun_row ) {
	WP_CLI::runcommand( 'config delete ' . escapeshellarg( $wpun_row['name'] ) . ' --type=constant --quiet', array( 'exit_error' => false ) );
}

deactivate_plugins( 'wpun-test-addon/wpun-test-addon.php' );

// A check left on a sub-site (a test schedules one to see it clear itself) does not carry over to the next test.
if ( $wpun_network ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $wpun_blog_id ) {
		if ( get_main_site_id() === (int) $wpun_blog_id ) {
			continue;
		}
		switch_to_blog( $wpun_blog_id );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		restore_current_blog();
	}
}

if ( ! wp_next_scheduled( 'sc_wpun_update_check' ) ) {
	wp_schedule_event( time(), 'hourly', 'sc_wpun_update_check' );
}
echo 'reset';
