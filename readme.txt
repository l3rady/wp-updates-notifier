=== Plugin Name ===
Contributors: l3rady, eherman24
Donate link: http://l3rady.com/donate
Tags: admin, theme, monitor, plugin, notification, upgrade, security
Requires at least: 3.1
Tested up to: 4.3
Stable tag: 1.4.3
License: GPLv3 or later

Sends email to notify you if there are any updates for your WordPress site. Can notify about core, plugin and theme updates.

== Description ==

Monitors your WordPress installation for core, plugin and theme updates and emails you when they are available. This plugin is ideal if you don't login to your WordPress admin regularly or you support a client's website.

*Features*

- Set the interval of how often to check for updates; hourly, twice daily or daily.
- Sets WordPress to check for updates more often meaning you get to know about updates sooner.
- Get emailed about core, plugin and theme updates.
- Chose if you want to be notified about active only themes and plugins updates.
- Remove upgrade nag message to non-admin users.
- For advanced users there are a number of filters and actions you can use. More coming soon.

This plugin is a fork of [Update Notifier](http://wordpress.org/extend/plugins/update-notifier/). This plugin was forked because there seemed to be no further development on the existing plugin and there was no way to contact the original author to ask about taking ownership. WP Updates Notifier has the following improvements over Updates Notifier:

- Completely rewritten from the ground up using best practises for writing WordPress plugins
- Code wrapped in a class so better namespace.
- You can set the cron interval, allowing for more frequent checks.
- Update checks trigger WordPress internal update check before notification.
- Allows you to set the 'from address'.
- Allows you to set multiple 'to addresses'.
- Makes use of the Settings API.
- A number of available hooks and filters for advanced users.
- Active support and development.

*Languages*

- French by [Christophe Catarina](http://www.ordilibre.com/) - *Added 03 July 2013*
- German by [Alexander Pfabel](http://alexander.pfabel.de/) - *Added 02 October 2012*

== Installation ==

* Unzip and upload contents to your plugins directory (usually wp-content/plugins/).
* Activate plugin
* Visit Settings page under Settings -> WP Updates Monitor in your WordPress Administration Area
* Configure plugin settings
* Wait for email notifications of updates

== Screenshots ==

1. Settings page
2. Email alert

== Changelog ==

= 1.4.3 =
* Repaired all PHP errors being thrown
* Two new filters added to allow you to alter the email content (sc_wpun_email_subject, sc_wpun_email_content - see readme.txt for examples)

= 1.4.2 =
* Added an option that allows the plugin to notify the provided email about WordPress core automatic updates.

= 1.4.1 =
* Switch from using site_url() to home_url() in email subject line so not to link to a 404 page.

= 1.4 =
* Added external cron method allowing users check for updates as often or as little as they want
* Added sc_wpun_get_options_filter and sc_wpun_put_options_filter filters to allow filtering of this plugins settings
* Now using wp_get_schedules() rather than statically assigned schedules. This allows admins to set their own schedules such as a weekly one
* Added French translations
* Added date and time of when this plugin last did an update check on the settings screen

= 1.3.2 =
* Added $wp_version globals ( Explains why WordPress Core Updates notifications haven't been working )
* Added missed variable $cur_wp_version

= 1.3.1 =
* Fixed PHP Fatal error on line 175.

= 1.3 =
* Added send test email functionality in settings page.
* Fixed `Call-time pass-by-reference has been deprecated` PHP errors.

= 1.2 =
* Added the ability to allow multiple email address to be added to the `notify to` setting. Multiple email addresses to be comma separated.
* Removed code from last version that was left in from dev. Caused WP to check for update on every admin page load.

= 1.1 =
* Plugin update notification email now includes links to new plugin description and changelog page.
* Plugin update notification email now shows compatibility of a new plugin. This is same functionality that appears in the WP update area.
* On plugin activation the first update check is scheduled to run an hour after rather than straight away. This stops current awaiting updates being sent to admin email before you've had chance to change the email settings.

= 1.0.4 =
* Fixed code to not report multiple times of core upgrades. Plugin now only notifies you once of core upgrade until upgrade is done.

= 1.0.3 =
* When plugin was deactivated then reactivated the cron was not rescheduled unless the settings were saved. This has now been fixed.

= 1.0.2 =
* Fixed plugin version

= 1.0.1 =
* Fixed spelling mistake in deactivate hook that stopped deactivate running properly.

= 1.0 =
* Initial release

== Filters ==

Two filters have been provided to allow you to alter the email subject and email content being sent by WP Updates Notifier.

<h4>sc_wpun_email_subject</h4>

@parameters:<br /> 
$email_subject - the email subject to be filtered.

<strong>Example:</strong>
`
/* 
*	Alter the email subject being sent by WP Updates Notifier 
*/
function alter_wp_updates_notifier_email_subject( $email_subject ) {
	$email_subject = 'This is the new email subject for updates notifier';
	return $email_subject;
}
add_filter( 'sc_wpun_email_subject', 'alter_wp_updates_notifier_email_subject' );
`

<h4>sc_wpun_email_content</h4>

@parameters:<br />
$message - the content of the email to be filtered

<strong>Example:</strong>
`
/* 
*	Alter the email content being sent by WP Updates Notifier 
*/
function alter_wp_updates_notifier_email_content( $message ) {
	$message = 'This is our new email content that will override the default.';
	return $message;
}
add_filter( 'sc_wpun_email_content', 'alter_wp_updates_notifier_email_content' );
`