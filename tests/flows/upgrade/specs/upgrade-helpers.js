// Shared by the update specs: a fresh 1.6.0 site, and what must be true after the update and the second activation.
const { expect } = require( '@playwright/test' );
const h = require( '../../specs/helpers' );

const OLD = 'wp-updates-notifier/class-sc-wp-updates-notifier.php';
const NEW = 'wp-updates-notifier/wp-updates-notifier.php';
// What reset.php stores, as 1.6.0 keeps it.
const OWNER = 'owner@example.com';

// The site as a real 1.6.0 user has it: 1.6.0 from WordPress.org, active and configured (email to OWNER, Slack on, a
// daily check), the update check scheduled, an empty mailbox, and the 2.0.0 package offered as an update.
async function freshSite( request ) {
	await h.wp( request, '--skip-plugins', 'eval-file', '/upgrade/reset.php' );
	await h.wp( request, 'plugin', 'activate', 'wp-updates-notifier' );
	// Activating 1.6.0 from WP-CLI does not schedule its check (it registers its activation hook when it initialises,
	// after the file was included). A real site has the event, so schedule it as 1.6.0 does on a settings save.
	await h.wp( request, 'cron', 'event', 'schedule', 'sc_wpun_update_check', 'now', 'daily' );
	expect( await h.wp( request, 'plugin', 'get', 'wp-updates-notifier', '--field=version' ) ).toBe( '1.6.0' );
	expect( await h.wp( request, 'plugin', 'get', 'wp-updates-notifier', '--field=status' ) ).toBe( 'active' );
	await h.clearMail( request );
}

// The text of the PHP error log (empty if there is none).
function errorLog( request ) {
	return h.wp( request, 'eval', 'echo file_exists( WP_CONTENT_DIR . "/debug.log" ) ? file_get_contents( WP_CONTENT_DIR . "/debug.log" ) : "";' );
}

// Every scheduled run of the update check, as {time, schedule, interval, args}, from the cron array, plus the server time.
async function checkEvents( request ) {
	return JSON.parse(
		await h.wp(
			request,
			'eval',
			'$found = array(); foreach ( _get_cron_array() as $time => $hooks ) { foreach ( $hooks[ "sc_wpun_update_check" ] ?? array() as $event ) { $found[] = array( "time" => $time, "schedule" => $event["schedule"], "interval" => $event["interval"] ?? null, "args" => $event["args"] ); } } echo wp_json_encode( array( "now" => time(), "events" => $found ) );'
		)
	);
}

// Updating must not cause a PHP fatal error, in the update or in the request after it.
async function expectNoFatal( request ) {
	expect( await errorLog( request ), 'PHP fatal error in the log' ).not.toMatch( /PHP (Fatal|Parse) error|Uncaught/ );
}

// Right after the update: the files are 2.0.0 under the new main file, 1.6.0's main file is gone, and the plugin is not
// active (WordPress cannot activate a file that no longer exists).
async function expectUpdatedAndInactive( request ) {
	const files = await h.wp( request, 'eval', 'echo implode( ",", array_keys( get_plugins() ) );' );
	expect( files.split( ',' ) ).toContain( NEW );
	expect( files.split( ',' ), '1.6.0 main file is gone' ).not.toContain( OLD );
	expect( await h.wp( request, 'plugin', 'get', 'wp-updates-notifier', '--field=version' ) ).toBe( '2.0.0' );
	expect( await h.wp( request, 'plugin', 'get', 'wp-updates-notifier', '--field=status' ) ).toBe( 'inactive' );
	// 1.6.0's scheduled check is still in the cron table with nobody to run it: one event, daily, no arguments.
	const left = await checkEvents( request );
	expect( left.events, 'the 1.x event is left behind' ).toHaveLength( 1 );
	expect( left.events[ 0 ] ).toMatchObject( { schedule: 'daily', interval: 86400, args: [] } );
	// The 1.x settings are still stored, untouched: nothing ran 2.0.0 yet.
	const stored = JSON.parse( await h.wp( request, 'option', 'get', 'sc_wpun_settings', '--format=json' ) );
	expect( stored.notify_to ).toBe( OWNER );
	await expectNoFatal( request );
}

// Activating again, as the 2.0.0 Upgrade Notice says: the settings come with it, the check is scheduled and a real run
// sends to the address the user had.
async function reactivateAndExpectSettingsKept( request, page ) {
	// What the offer of the update left behind must not reach the 2.0.0 checks.
	await h.wp( request, 'option', 'update', 'wpun_upgrade_stage', '{"fake":true}', '--format=json' );
	// 1.6.0's event is still in the cron table, as on a real site. It is kept, not removed, so what 2.0.0 does with it
	// is what is tested.
	const before = await checkEvents( request );

	await h.wp( request, 'plugin', 'activate', 'wp-updates-notifier' );
	expect( await h.wp( request, 'plugin', 'get', 'wp-updates-notifier', '--field=status' ) ).toBe( 'active' );
	await expectNoFatal( request );

	// Email became a channel instance with the 1.x address; Slack was kept in its own instance, switched on.
	const channels = JSON.parse( await h.wp( request, 'option', 'get', 'sc_wpun_channels', '--format=json' ) );
	expect( channels.email ).toMatchObject( { type: 'email', enabled: true, settings: { to: OWNER, from: 'from@example.com' } } );
	expect( channels.slack ).toMatchObject( {
		type: 'slack',
		enabled: true,
		settings: { webhook_url: 'https://hooks.slack.com/services/T000/B000/XXXX', channel: '#updates' },
	} );

	// The check is scheduled at the frequency the user had: exactly one event (the 1.x one is taken over, not doubled),
	// daily with no arguments, and not stuck in the past (a run that is due fires at the next cron run, so it may be a
	// little behind, never days).
	const after = await checkEvents( request );
	expect( after.events, 'exactly one update check is scheduled' ).toHaveLength( 1 );
	expect( after.events[ 0 ] ).toMatchObject( { schedule: 'daily', interval: 86400, args: [] } );
	expect( after.events[ 0 ].time ).toBeGreaterThan( after.now - 3600 );
	expect( after.events[ 0 ].time ).toBeLessThanOrEqual( after.now + 86400 );
	expect( after.events[ 0 ].time, 'the existing event is kept' ).toBe( before.events[ 0 ].time );

	// The settings screen shows the Slack notice, and the channel list the migrated email channel.
	await page.goto( h.SETTINGS );
	await expect( page.locator( '.sc-wpun-slack-notice' ) ).toContainText( 'Slack notifications are no longer supported' );
	await page.goto( h.CHANNELS );
	await expect( h.channelRow( page, 'Email' ).locator( '.sc-wpun-state' ) ).toHaveText( 'On' );

	// A real run: the update email goes to the migrated address, and so does the Slack notice, once.
	await h.runCheck( request );
	const mails = await h.waitForMail( request, 2 );
	const update = mails.find( ( m ) => /updates available/.test( m.Subject ) );
	const slack = mails.find( ( m ) => /Slack notifications are no longer supported/.test( m.Subject ) );
	expect( update, 'the update email' ).toBeTruthy();
	expect( slack, 'the one-time Slack email' ).toBeTruthy();
	expect( h.recipients( update ) ).toEqual( [ OWNER ] );
	expect( h.recipients( slack ) ).toEqual( [ OWNER ] );
	expect( update.Text ).toContain( 'Example Contact Forms from version 3.4.1 to 3.5.0' );

	// The next run sends neither again.
	await h.clearMail( request );
	await h.runCheck( request );
	await h.expectNoMail( request );
	const state = JSON.parse( await h.wp( request, 'option', 'get', 'sc_wpun_state', '--format=json' ) );
	expect( state.slack_notice_sent ).toBe( true );
	await expectNoFatal( request );
}

module.exports = { OLD, NEW, OWNER, freshSite, errorLog, expectNoFatal, expectUpdatedAndInactive, reactivateAndExpectSettingsKept };
