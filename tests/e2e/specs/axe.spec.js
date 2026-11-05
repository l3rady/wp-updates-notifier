// axe scans of the plugin's settings screen for WCAG 2.2 A and AA (#225). A violation fails the test and is listed with
// its rule, impact, elements and the help text. The console of each page must also be free of errors.
const { test, expect } = require( '@playwright/test' );
const { CHANNELS, VIEWS, scan, describe, watchConsole } = require( './helpers' );

for ( const [ name, url ] of VIEWS ) {
	test( `axe: ${ name }`, async ( { page } ) => {
		const errors = watchConsole( page );
		await page.goto( url );
		await expect( page.locator( '.wrap' ) ).toBeVisible();

		const violations = await scan( page );
		expect( violations, `${ violations.length } axe violation(s) on ${ url }\n\n${ describe( violations ) }\n` ).toEqual( [] );
		expect( errors, `console errors on ${ url }` ).toEqual( [] );
	} );
}

test( 'axe: form after a failed save', async ( { page } ) => {
	const errors = watchConsole( page );
	await page.goto( `${ CHANNELS }&action=new&type=email` );
	await page.fill( '#sc_wpun_channel_label', '' );
	// Validation is the server's (the form has no HTML `required`, #205). Switch the browser's own checks off anyway, so a
	// field type that gains one (an email or url input) can't stop the submit before the server answers.
	await page.locator( 'form.sc-wpun-channel-form' ).evaluate( ( form ) => ( form.noValidate = true ) );
	await Promise.all( [ page.waitForURL( /action=new/ ), page.locator( 'form.sc-wpun-channel-form button[type=submit]' ).click() ] );
	await expect( page.locator( '.sc-wpun-form-errors' ) ).toBeVisible();

	const violations = await scan( page );
	expect( violations, `${ violations.length } axe violation(s) on the form after a failed save\n\n${ describe( violations ) }\n` ).toEqual( [] );
	expect( errors, 'console errors after a failed save' ).toEqual( [] );
} );

test( 'axe: field showcase with its collapsed group open', async ( { page } ) => {
	const errors = watchConsole( page );
	await page.goto( `${ CHANNELS }&action=edit&id=e2e_showcase` );

	// The group is closed until its summary is activated. Its summary is the control, so a click is what a user does.
	const details = page.locator( 'details.sc-wpun-field-group-details:not(.sc-wpun-form-panel-details)' );
	await expect( details ).toBeVisible();
	await expect( details ).not.toHaveAttribute( 'open', /.*/ );
	await details.locator( 'summary' ).click();
	await expect( details ).toHaveAttribute( 'open', /.*/ );
	await expect( details.locator( 'input[name="settings[advanced_required]"]' ) ).toBeVisible();

	// The collapsed panel, too: its content is out of sight until its summary is activated.
	const panel = page.locator( 'details.sc-wpun-form-panel-details' );
	await expect( panel ).not.toHaveAttribute( 'open', /.*/ );
	await panel.locator( 'summary' ).click();
	await expect( panel ).toHaveAttribute( 'open', /.*/ );
	await expect( panel.locator( '.sc-wpun-form-panel-content' ) ).toBeVisible();

	const violations = await scan( page );
	expect( violations, `${ violations.length } axe violation(s) on the showcase with the collapsed group open\n\n${ describe( violations ) }\n` ).toEqual( [] );
	expect( errors, 'console errors on the showcase with the collapsed group open' ).toEqual( [] );
} );

test( 'axe: webhook form with the Advanced group open', async ( { page } ) => {
	const errors = watchConsole( page );
	await page.goto( `${ CHANNELS }&action=edit&id=e2e_webhook` );

	const details = page.locator( 'details.sc-wpun-field-group-details:not(.sc-wpun-form-panel-details)' );
	await expect( details ).not.toHaveAttribute( 'open', /.*/ );
	await details.locator( 'summary' ).click();
	await expect( details ).toHaveAttribute( 'open', /.*/ );

	// The extra headers are a secret textarea: empty, with the saved note and the Clear checkbox.
	const headers = details.locator( 'textarea[name="settings[headers]"]' );
	await expect( headers ).toBeVisible();
	await expect( headers ).toHaveValue( '' );
	await expect( details.locator( 'input[name="settings[headers__clear]"]' ) ).toBeVisible();

	// The preview panel, too: open it so its headers and body are scanned.
	const preview = page.locator( 'details.sc-wpun-form-panel-details' );
	await preview.locator( 'summary' ).click();
	await expect( preview.locator( '.sc-wpun-preview pre' ).first() ).toBeVisible();

	const violations = await scan( page );
	expect( violations, `${ violations.length } axe violation(s) on the webhook form with the group open\n\n${ describe( violations ) }\n` ).toEqual( [] );

	expect( errors, 'console errors on the webhook form' ).toEqual( [] );
} );

// Submit the webhook form with one field changed, to see the form after a failed save.
async function failWebhookSave( page, field, value ) {
	await page.goto( `${ CHANNELS }&action=edit&id=e2e_webhook` );
	// A control in the collapsed group can only be filled once the group is open.
	if ( field.startsWith( 'textarea' ) ) {
		await page.locator( 'details.sc-wpun-field-group-details:not(.sc-wpun-form-panel-details) summary' ).click();
	}
	await page.fill( field, value );
	await page.locator( 'form.sc-wpun-channel-form' ).evaluate( ( form ) => ( form.noValidate = true ) );
	await page.locator( 'form.sc-wpun-channel-form button[type=submit]' ).click();
	await expect( page.locator( '.sc-wpun-form-errors' ) ).toBeVisible();
}

test( 'axe: webhook form after a bad signing secret', async ( { page } ) => {
	const errors = watchConsole( page );
	await failWebhookSave( page, 'input[name="settings[signing_secret]"]', 'not-a-whsec-secret' );

	await expect( page.locator( 'input[name="settings[signing_secret]"]' ) ).toHaveAttribute( 'aria-invalid', 'true' );
	// The Advanced group has no error, so it stays closed.
	await expect( page.locator( 'details.sc-wpun-field-group-details:not(.sc-wpun-form-panel-details)' ) ).not.toHaveAttribute( 'open', /.*/ );

	const violations = await scan( page );
	expect( violations, `${ violations.length } axe violation(s) on the webhook form after a bad signing secret\n\n${ describe( violations ) }\n` ).toEqual( [] );
	expect( errors, 'console errors on the webhook form' ).toEqual( [] );
} );

test( 'axe: webhook form after a bad header line, with the Advanced group open by itself', async ( { page } ) => {
	const errors = watchConsole( page );
	await failWebhookSave( page, 'textarea[name="settings[headers]"]', 'no colon here' );

	const details = page.locator( 'details.sc-wpun-field-group-details:not(.sc-wpun-form-panel-details)' );
	await expect( details ).toHaveAttribute( 'open', /.*/ );
	const area = details.locator( 'textarea[name="settings[headers]"]' );
	await expect( area ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( page.locator( '.sc-wpun-field-error' ) ).toContainText( 'Line 1 must be written Name: value.' );

	const violations = await scan( page );
	expect( violations, `${ violations.length } axe violation(s) on the webhook form after a bad header line\n\n${ describe( violations ) }\n` ).toEqual( [] );
	expect( errors, 'console errors on the webhook form' ).toEqual( [] );
} );
