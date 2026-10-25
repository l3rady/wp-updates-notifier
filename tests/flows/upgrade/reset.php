<?php
/**
 * `make e2e-upgrade`: puts the site back to a fresh, configured 1.6.0 before each test. Run with
 * `wp --skip-plugins eval-file reset.php`, so no plugin code is loaded while its files are replaced.
 *
 * Restores the pristine copy of 1.6.0 that setup.sh installed from WordPress.org, forgets everything an earlier test
 * left (options, cron, update leftovers, the Slack notice), sets the 1.x settings a real site has (an address to notify, a
 * From address, Slack on with a webhook, a daily check) and tells the stage mu-plugin to offer
 * the 2.0.0 package as an update and to use the fake pending updates. The test then activates 1.6.0 with `wp plugin activate`, which schedules its cron event as on a
 * real site (with --skip-plugins here, its hooks are not loaded).
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound, WordPress.WP.GlobalVariablesOverride
if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;

$wpun_plugins = WP_PLUGIN_DIR . '/wp-updates-notifier';
foreach ( array( $wpun_plugins, WP_CONTENT_DIR . '/upgrade', WP_CONTENT_DIR . '/upgrade-temp-backup' ) as $wpun_path ) {
	exec( 'rm -rf ' . escapeshellarg( $wpun_path ) );
}
exec( 'cp -a ' . escapeshellarg( WP_CONTENT_DIR . '/wpun-1.6.0' ) . ' ' . escapeshellarg( $wpun_plugins ), $wpun_out, $wpun_code );
if ( 0 !== $wpun_code ) {
	WP_CLI::error( 'Could not restore 1.6.0.' );
}
if ( file_exists( WP_CONTENT_DIR . '/debug.log' ) ) {
	unlink( WP_CONTENT_DIR . '/debug.log' );
}

// Everything the plugin (either version) and the updater store.
update_option( 'active_plugins', array( 'example-contact-forms/example-contact-forms.php', 'example-gallery/example-gallery.php' ) );
foreach ( array( 'sc_wpun_settings', 'sc_wpun_settings_ver', 'sc_wpun_channels', 'sc_wpun_state', 'auto_update_plugins', 'recently_activated', 'wpun_upgrade_stage', 'cron' ) as $wpun_option ) {
	delete_option( $wpun_option );
}
delete_site_transient( 'update_plugins' );
delete_transient( 'sc_wpun_settings_errors' );
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'sc\\_wpun\\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%sc\\_wpun%' OR option_name LIKE '\\_transient\\_settings\\_errors%'" );

// The settings of a real 1.6.0 site (its option names and values).
update_option(
	'sc_wpun_settings',
	array(
		'frequency'              => 'daily',
		'email_notifications'    => 1,
		'notify_to'              => 'owner@example.com',
		'notify_from'            => 'from@example.com',
		'slack_notifications'    => 1,
		'slack_webhook_url'      => 'https://hooks.slack.com/services/T000/B000/XXXX',
		'slack_channel_override' => '#updates',
		'notify_plugins'         => 1,
		'notify_themes'          => 1,
		'notify_automatic'       => 1,
		'hide_updates'           => 1,
	)
);
update_option(
	'wpun_upgrade_stage',
	array(
		'fake'    => true,
		'package' => home_url( '/wp-content/uploads/wpun-upgrade/wp-updates-notifier.zip' ),
	)
);
echo 'reset';
