// The General tab: saving, an invalid value (#74) and the console of every settings page.
const { test, expect } = require( '@playwright/test' );
const { SETTINGS, CHANNELS, wp, resetSite, emailChannel, watchConsole } = require( './helpers' );

const radio = ( page, name, value ) => page.locator( `input[name="sc_wpun_settings[${ name }]"][value="${ value }"]` );

test.beforeEach( async ( { request } ) => resetSite( request, { channels: { office: emailChannel( 'Office' ) } } ) );

test( 'saving the General tab stores the settings and reschedules the check', async ( { page, request } ) => {
	await page.goto( SETTINGS );
	await radio( page, 'frequency', 'daily' ).check();
	await radio( page, 'notify_plugins', 'active' ).check();
	await radio( page, 'notify_core', 'major' ).check();
	await page.getByRole( 'button', { name: 'Save settings' } ).click();

	await expect( page.locator( '#setting-error-settings_updated' ) ).toContainText( 'Settings saved' );
	await expect( radio( page, 'frequency', 'daily' ) ).toBeChecked();
	await expect( radio( page, 'notify_plugins', 'active' ) ).toBeChecked();
	await expect( radio( page, 'notify_core', 'major' ) ).toBeChecked();

	const stored = JSON.parse( await wp( request, 'option', 'get', 'sc_wpun_settings', '--format=json' ) );
	expect( stored ).toMatchObject( { frequency: 'daily', notify_plugins: 'active', notify_core: 'major', notify_themes: 'all' } );
	const events = JSON.parse( await wp( request, 'cron', 'event', 'list', '--fields=hook,schedule', '--format=json' ) );
	expect( events.find( ( e ) => e.hook === 'sc_wpun_update_check' ).schedule ).toBe( 'daily' );
} );

test( 'an invalid value shows its error on its own field and is not saved', async ( { page, request } ) => {
	await page.goto( SETTINGS );
	// The browser cannot choose a value the form does not offer, so a stale or hand-made request is sent instead.
	await radio( page, 'frequency', 'weekly' ).evaluate( ( input ) => {
		input.value = 'fortnightly';
		input.checked = true;
	} );
	await radio( page, 'notify_plugins', 'active' ).check();
	await page.getByRole( 'button', { name: 'Save settings' } ).click();

	const frequency = page.locator( '#sc_wpun_settings_main_frequency' );
	await expect( frequency ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( page.locator( '#sc_wpun_settings_main_frequency-error' ) ).toContainText( 'Error:' );
	// The message sits in the row of the field that is wrong, and in no other row.
	await expect( page.locator( 'tr', { has: frequency } ).locator( '.sc-wpun-field-error' ) ).toHaveCount( 1 );
	await expect( page.locator( '.sc-wpun-field-error' ) ).toHaveCount( 1 );
	await expect( page.locator( '#sc_wpun_settings_main_notify_core' ) ).not.toHaveAttribute( 'aria-invalid', 'true' );

	const stored = JSON.parse( await wp( request, 'option', 'get', 'sc_wpun_settings', '--format=json' ) );
	expect( stored.frequency, 'the invalid frequency was not stored' ).toBe( 'hourly' );
} );

test( 'no settings page logs a console error', async ( { page } ) => {
	const errors = watchConsole( page );
	const urls = [
		SETTINGS,
		CHANNELS,
		`${ CHANNELS }&action=add`,
		`${ CHANNELS }&action=new&type=email`,
		`${ CHANNELS }&action=edit&id=office`,
		`${ CHANNELS }&action=delete&id=office`,
		`${ SETTINGS }&tab=about`,
	];
	for ( const url of urls ) {
		await page.goto( url );
		await expect( page.locator( '.wrap h1' ) ).toBeVisible();
		await page.waitForLoadState( 'networkidle' );
	}
	expect( errors ).toEqual( [] );
} );
