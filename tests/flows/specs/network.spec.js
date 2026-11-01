// The core path on a multisite with the plugin network-activated (make e2e-ms; docs/users/multisite.md, ADR 0021): the
// settings in the Network Admin, one check on the main site, one email that names the network. Run only there.
const { test, expect } = require( '@playwright/test' );
const { SETTINGS, NETWORK_SETTINGS, MAILPIT, wp, resetSite, emailChannel, waitForMail, expectNoMail, recipients, runCheck } = require( './helpers' );

const OFFICE = { office: emailChannel( 'Office', { to: 'office@example.com' } ) };
const ALPHA = 'wordpress:8080/alpha/';

const events = async ( request, ...url ) =>
	JSON.parse( await wp( request, 'cron', 'event', 'list', '--fields=hook', '--format=json', ...url ) ).filter( ( e ) => e.hook === 'sc_wpun_update_check' );

test.beforeEach( async ( { request } ) => resetSite( request, { channels: OFFICE } ) );

test( 'the settings are in the Network Admin and are saved as network options', async ( { page, request } ) => {
	await page.goto( NETWORK_SETTINGS );
	await expect( page.locator( 'input[name="sc_wpun_settings[frequency]"][value="hourly"]' ) ).toBeChecked();
	await page.locator( 'input[name="sc_wpun_settings[frequency]"][value="daily"]' ).check();
	await page.getByRole( 'button', { name: 'Save settings' } ).click();

	await expect( page.locator( '.notice-success, #setting-error-settings_updated' ).filter( { hasText: 'Settings saved' } ).first() ).toBeVisible();
	await expect( page.locator( 'input[name="sc_wpun_settings[frequency]"][value="daily"]' ) ).toBeChecked();

	const stored = JSON.parse( await wp( request, 'eval', 'echo wp_json_encode( get_site_option( "sc_wpun_settings" ) );' ) );
	expect( stored ).toMatchObject( { frequency: 'daily' } );
	expect( await wp( request, 'cron', 'event', 'list', '--hook=sc_wpun_update_check', '--field=schedule' ) ).toBe( 'daily' );
} );

test( 'a site has no settings screen of its own', async ( { page } ) => {
	await page.goto( SETTINGS );
	await expect( page.locator( 'input[name^="sc_wpun_settings"]' ) ).toHaveCount( 0 );
} );

test( 'one check sends one email that names the network', async ( { request } ) => {
	await runCheck( request );

	const [ mail ] = await waitForMail( request, 1 );
	expect( recipients( mail ) ).toEqual( [ 'office@example.com' ] );
	expect( mail.Subject ).toBe( 'WP Updates Notifier: updates available for Test Network' );
	expect( mail.Text ).toContain( 'There are updates available for Test Network (http://wordpress:8080)' );
	expect( mail.Text ).toContain( 'http://wordpress:8080/wp-admin/network/update-core.php' );
	expect( mail.Text ).toContain( 'Example Contact Forms from version 3.4.1 to 3.5.0' );

	// Nothing has changed since: the next check says nothing.
	await runCheck( request );
	const list = await ( await request.get( `${ MAILPIT }/api/v1/messages` ) ).json();
	expect( list.total, 'no second email' ).toBe( 1 );
} );

test( '"active only" counts a plugin active on a sub-site, not one active nowhere', async ( { request } ) => {
	await resetSite( request, { channels: OFFICE, settings: { notify_plugins: 'active' } } );
	await runCheck( request );

	const [ mail ] = await waitForMail( request, 1 );
	expect( mail.Text ).toContain( 'Example Contact Forms from version' );
	expect( mail.Text ).toContain( 'Example Gallery from version' );
	expect( mail.Text ).not.toContain( 'Example Backup' );
} );

test( 'a check left on a sub-site sends nothing and removes itself', async ( { request } ) => {
	await wp( request, 'cron', 'event', 'schedule', 'sc_wpun_update_check', 'now', 'hourly', `--url=${ ALPHA }` );
	expect( await events( request, `--url=${ ALPHA }` ) ).toHaveLength( 1 );

	await wp( request, 'cron', 'event', 'run', 'sc_wpun_update_check', `--url=${ ALPHA }` );

	await expectNoMail( request );
	expect( await events( request, `--url=${ ALPHA }` ) ).toHaveLength( 0 );
	expect( await events( request ), 'the main site still has its check' ).toHaveLength( 1 );
} );
