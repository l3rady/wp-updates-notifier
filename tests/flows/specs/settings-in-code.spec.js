// Settings in code (#107): a value set by a constant is read-only on the form and is the one used when sending.
const { test, expect } = require( '@playwright/test' );
const { SETTINGS, CHANNELS, wp, resetSite, emailChannel, waitForMail, recipients, runCheck } = require( './helpers' );

test( 'a channel option set by a constant is read-only and is what the email is sent with', async ( { page, request } ) => {
	// The database says one thing and wp-config.php another. resetSite removes the constants again.
	await resetSite( request, {
		channels: { office: emailChannel( 'Office', { to: 'database@example.com', settings: { subject_prefix: '[Database]' } } ) },
		constants: [
			[ 'SC_WPUN_CHANNEL_OFFICE__TO', 'constant@example.com' ],
			[ 'SC_WPUN_CHANNEL_OFFICE__SUBJECT_PREFIX', '[Constant]' ],
		],
	} );

	await page.goto( `${ CHANNELS }&action=edit&id=office` );
	for ( const [ field, constant, value ] of [
		[ 'to', 'SC_WPUN_CHANNEL_OFFICE__TO', 'constant@example.com' ],
		[ 'subject_prefix', 'SC_WPUN_CHANNEL_OFFICE__SUBJECT_PREFIX', '[Constant]' ],
	] ) {
		const input = page.locator( `#sc_wpun_channel_settings_${ field }` );
		await expect( input ).toBeDisabled();
		await expect( input ).toHaveValue( value );
		await expect( page.locator( `#sc_wpun_channel_settings_${ field }-external` ) ).toHaveText( `Set by constant ${ constant }` );
	}
	// An option that nothing sets stays editable.
	await expect( page.locator( '#sc_wpun_channel_settings_reply_to' ) ).toBeEnabled();

	// Saving the form does not write the constant's value, or the form's, over the stored one.
	await page.getByRole( 'button', { name: 'Save channel' } ).click();
	await expect( page.locator( '.sc-wpun-notice' ) ).toContainText( 'Office saved.' );
	const stored = JSON.parse( await wp( request, 'option', 'get', 'sc_wpun_channels', '--format=json' ) );
	expect( stored.office.settings.to ).toBe( 'database@example.com' );

	// The email goes where the constant says, with the prefix it sets.
	await runCheck( request );
	const [ mail ] = await waitForMail( request, 1 );
	expect( recipients( mail ) ).toEqual( [ 'constant@example.com' ] );
	expect( mail.Subject ).toBe( '[Constant] WP Updates Notifier: updates available for Test' );
} );

test( 'a General setting set by a constant is read-only', async ( { page, request } ) => {
	await resetSite( request, { constants: [ [ 'SC_WPUN_SETTING_FREQUENCY', 'weekly' ] ] } );

	await page.goto( SETTINGS );
	await expect( page.locator( 'input[name="sc_wpun_settings[frequency]"][value="weekly"]' ) ).toBeChecked();
	for ( const radio of await page.locator( 'input[name="sc_wpun_settings[frequency]"]' ).all() ) {
		await expect( radio ).toBeDisabled();
	}
	await expect( page.locator( '.sc-wpun-external' ).first() ).toHaveText( 'Set by constant SC_WPUN_SETTING_FREQUENCY' );
	// The other settings are still editable.
	await expect( page.locator( 'input[name="sc_wpun_settings[notify_core]"][value="none"]' ) ).toBeEnabled();
} );
