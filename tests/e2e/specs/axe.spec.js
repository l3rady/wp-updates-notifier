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
