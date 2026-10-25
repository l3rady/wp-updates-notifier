<?php
/**
 * Seeds the demo site (a runPHP step of the blueprint, after the plugin and the demo files are in place): the site title,
 * two email channels, and one run of the update check, so that the channels show as sent and the caught emails exist.
 *
 * @package wp-updates-notifier
 */

// runPHP does not load WordPress.
require '/wordpress/wp-load.php';

update_option( 'blogname', 'Example Site' );
update_option( 'blogdescription', 'Just another example' );

update_option(
	'sc_wpun_channels',
	array(
		'office' => array(
			'type'     => 'email',
			'label'    => 'Office email',
			'enabled'  => true,
			'settings' => array(
				'to'             => 'office@example.com',
				'format'         => 'html',
				'subject_prefix' => '[Example Site]',
			),
			'routing'  => array( 'remind' => 'daily' ),
		),
		'client' => array(
			'type'     => 'email',
			'label'    => 'Client: WordPress updates',
			'enabled'  => true,
			'settings' => array( 'to' => 'client@example.com' ),
			'routing'  => array( 'types' => array( 'core' ) ),
		),
	),
	false
);

do_action( 'sc_wpun_run_check' );

// Fail the blueprint rather than leave a demo without emails.
$wpun_demo_emails = get_option( 'wpun_demo_emails', array() );
if ( count( $wpun_demo_emails ) < 2 ) {
	throw new RuntimeException( 'The update check did not send the demo emails.' );
}
