#!/bin/sh
#
# Breaks the `make screenshots` site on purpose, for the Site Health screenshot (5). run.sh runs it after screenshots 1
# to 4, which need the update check to have delivered: the update check is overdue by five days, the Office email
# channel failed its last delivery, an enabled channel has no module, and WP-Cron is off (so nothing can run the
# overdue check while the page is photographed). Made-up data only, no email address in the error.
# Runs in the wp-cli container, in /var/www/html.

set -eu

wp config set DISABLE_WP_CRON true --raw --type=constant --quiet

wp eval '
$frequency = get_option( "sc_wpun_settings" )["frequency"] ?? "hourly";
$schedules = wp_get_schedules();
if ( ! isset( $schedules[ $frequency ] ) ) {
	$frequency = "hourly";
}
$past = time() - 5 * DAY_IN_SECONDS;
wp_clear_scheduled_hook( "sc_wpun_update_check" );
wp_schedule_event( $past, $frequency, "sc_wpun_update_check" );

$state                    = get_option( "sc_wpun_state" );
$state["last_check_time"] = $past;
$state["channels"]["office"]["last_result"] = array(
	"success" => false,
	"message" => "SMTP Error: Could not authenticate.",
	"time"    => $past,
);
update_option( "sc_wpun_state", $state );

$channels = (array) get_option( "sc_wpun_channels" );
$channels["sms"] = array(
	"type"     => "sms",
	"label"    => "SMS alerts",
	"enabled"  => true,
	"settings" => array(),
	"routing"  => array(),
);
update_option( "sc_wpun_channels", $channels, false );
'
echo 'Screenshots site broken for Site Health: overdue check, failed Office email delivery, SMS alerts without a module'
