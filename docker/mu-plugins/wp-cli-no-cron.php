<?php
/**
 * Local development only: WP-CLI calls do not spawn WP-Cron.
 *
 * The wp-cli service runs in its own container, where nothing listens on the site URL. A spawn from there takes
 * the cron lock for a minute and its request goes nowhere, so the next page load would not run due events.
 * Run them with `wp cron event run` instead.
 *
 * @package wp-updates-notifier
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	remove_action( 'init', 'wp_cron' );
}
