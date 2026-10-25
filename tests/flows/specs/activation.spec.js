// Activation (#62): the update check is scheduled when the plugin is activated, and not once it is deactivated.
const { test, expect } = require( '@playwright/test' );
const { wp, resetSite } = require( './helpers' );

const PLUGIN = 'wp-updates-notifier/wp-updates-notifier.php';

// The schedule of the update check in WP-Cron, or null if it is not scheduled.
async function scheduleOfCheck( request ) {
	const events = JSON.parse( await wp( request, 'cron', 'event', 'list', '--fields=hook,schedule', '--format=json' ) );
	const event = events.find( ( e ) => e.hook === 'sc_wpun_update_check' );
	return event ? event.schedule : null;
}

test.beforeEach( async ( { request } ) => resetSite( request ) );

test( 'activating the plugin schedules the update check', async ( { page, request } ) => {
	// WordPress will not deactivate a plugin that others require, so the add-ons of the site go first.
	await wp( request, 'plugin', 'deactivate', 'wpun-field-showcase', 'wpun-test-addon' );
	await page.goto( '/wp-admin/plugins.php' );
	const row = page.locator( `tr[data-plugin="${ PLUGIN }"]` );

	await row.getByRole( 'link', { name: /^Deactivate/ } ).click();
	await expect( row.getByRole( 'link', { name: /^Activate/ } ) ).toBeVisible();
	expect( await scheduleOfCheck( request ), 'nothing is scheduled while the plugin is off' ).toBeNull();

	await row.getByRole( 'link', { name: /^Activate/ } ).click();
	await expect( row.getByRole( 'link', { name: /^Deactivate/ } ) ).toBeVisible();
	expect( await scheduleOfCheck( request ), 'the check runs at the default frequency' ).toBe( 'hourly' );
} );
