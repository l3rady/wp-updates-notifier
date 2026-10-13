<?php
/**
 * Settings options as saved by past releases of the plugin.
 *
 * Shapes taken from the plugin's git history (the OPT_VERSION and the `$defaults` array of each
 * release). Each fixture is `[ 'version' => settings version string, 'options' => saved array ]`.
 * A change that stops these upgrading cleanly breaks sites that update from those releases.
 *
 * @package wp-updates-notifier
 */

return array(
	// 1.4: settings version 4.0. Had a cron method and an external-cron key, no automatic update flag.
	'1.4'                 => array(
		'version' => '4.0',
		'options' => array(
			'cron_method'     => 'wordpress',
			'frequency'       => 'twicedaily',
			'notify_to'       => 'old@example.org,second@example.org',
			'notify_from'     => 'sender@example.org',
			'notify_plugins'  => 2,
			'notify_themes'   => 0,
			'hide_updates'    => 0,
			'notified'        => array(
				'core'   => '3.9.1',
				'plugin' => array( 'hello.php' => '1.8' ),
				'theme'  => array(),
			),
			'security_key'    => 'abc123',
			'last_check_time' => 1400000000,
		),
	),

	// Later 1.4.x: settings version 5.0 added notify_automatic.
	'1.4.x'               => array(
		'version' => '5.0',
		'options' => array(
			'cron_method'      => 'wordpress',
			'frequency'        => 'daily',
			'notify_to'        => 'old@example.org',
			'notify_from'      => 'old@example.org',
			'notify_plugins'   => 1,
			'notify_themes'    => 1,
			'notify_automatic' => 0,
			'hide_updates'     => 1,
			'notified'         => array(
				'core'   => '',
				'plugin' => array(),
				'theme'  => array(),
			),
			'security_key'     => 'abc123',
			'last_check_time'  => 1500000000,
		),
	),

	// 1.6.0 (current): settings version 7.0, email and Slack as separate notifications.
	'1.6.0'               => array(
		'version' => '7.0',
		'options' => array(
			'frequency'              => 'hourly',
			'email_notifications'    => 1,
			'notify_to'              => 'ops@example.org',
			'notify_from'            => 'ops@example.org',
			'slack_notifications'    => 1,
			'slack_webhook_url'      => 'https://hooks.slack.com/services/T000/B000/XXXX',
			'slack_channel_override' => '#ops',
			'notify_plugins'         => 2,
			'notify_themes'          => 1,
			'notify_automatic'       => 1,
			'hide_updates'           => 1,
			'notified'               => array(
				'core'   => '6.4',
				'plugin' => array( 'hello.php' => '1.8' ),
				'theme'  => array( 'twentytwentyfour' => '1.1' ),
			),
			'last_check_time'        => 1700000000,
		),
	),

	// The same release with every number saved as a string, as `wp option update`, imports or filters can leave them.
	'1.6.0-string-values' => array(
		'version' => '7.0',
		'options' => array(
			'frequency'              => 'hourly',
			'email_notifications'    => '1',
			'notify_to'              => 'ops@example.org',
			'notify_from'            => 'ops@example.org',
			'slack_notifications'    => '0',
			'slack_webhook_url'      => '',
			'slack_channel_override' => '',
			'notify_plugins'         => '1',
			'notify_themes'          => '1',
			'notify_automatic'       => '1',
			'hide_updates'           => '1',
			'notified'               => array(
				'core'   => '',
				'plugin' => array(),
				'theme'  => array(),
			),
			'last_check_time'        => '1700000000',
		),
	),
);
