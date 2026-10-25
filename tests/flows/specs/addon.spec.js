// The fixture add-on (#105, tests/fixtures/plugins/wpun-test-addon): a separate plugin that extends this one through
// its public API only. Its module appears in the module picker and its own field type validates in the form.
const { test, expect } = require( '@playwright/test' );
const { CHANNELS, wp, resetSite, watchConsole, channelRow } = require( './helpers' );

test.beforeEach( async ( { request } ) => resetSite( request ) );

test( 'the add-on module registers, is offered in the picker and can be added', async ( { page, request } ) => {
	const picker = `${ CHANNELS }&action=add`;
	const module = page.locator( '.sc-wpun-modules li[data-type="test_addon"]' );

	// Without the add-on only the built-in modules are offered.
	await page.goto( picker );
	await expect( page.locator( '.sc-wpun-modules li[data-type="email"]' ) ).toBeVisible();
	await expect( module ).toHaveCount( 0 );

	await wp( request, 'plugin', 'activate', 'wpun-test-addon' );
	const errors = watchConsole( page );
	await page.goto( picker );
	await expect( module.getByRole( 'link', { name: 'Test add-on' } ) ).toBeVisible();
	await expect( module ).toContainText( 'A module supplied by the test add-on.' );

	// Its form is drawn by the add-on (render_settings); a wrong code is refused, a right one adds the channel.
	await module.getByRole( 'link', { name: 'Test add-on' } ).click();
	await page.getByLabel( /^Label/ ).fill( 'Add-on channel' );
	await page.locator( 'input[name="settings[code]"]' ).fill( 'nope' );
	await page.getByRole( 'button', { name: 'Add channel' } ).click();
	await expect( page.locator( '.sc-wpun-form-errors' ) ).toContainText( 'Enter a code like ABC-123.' );

	await page.getByLabel( /^Label/ ).fill( 'Add-on channel' );
	await page.locator( 'input[name="settings[code]"]' ).fill( 'ABC-123' );
	await page.getByRole( 'button', { name: 'Add channel' } ).click();
	await expect( page.locator( '.sc-wpun-form-errors' ) ).toContainText( 'Name is required.' );

	await page.getByLabel( /^Label/ ).fill( 'Add-on channel' );
	await page.locator( 'input[name="settings[code]"]' ).fill( 'ABC-123' );
	await page.locator( 'input[name="settings[name]"]' ).fill( 'Fixture' );
	await page.getByRole( 'button', { name: 'Add channel' } ).click();
	await expect( page.locator( '.sc-wpun-notice' ) ).toContainText( 'Add-on channel added.' );
	await expect( channelRow( page, 'Add-on channel' ).locator( '.sc-wpun-channel-module' ) ).toHaveText( 'Test add-on' );
	expect( errors ).toEqual( [] );
} );
