// The Channels tab: add, edit, send a test, duplicate, disable, enable and delete an email channel, as a person does.
const { test, expect } = require( '@playwright/test' );
const { CHANNELS, resetSite, waitForMail, recipients, channelRow } = require( './helpers' );

const notice = ( page ) => page.locator( '.sc-wpun-notice' );

test.beforeEach( async ( { request } ) => resetSite( request ) );

test( 'an email channel goes through its whole life', async ( { page, request } ) => {
	// Add.
	await page.goto( CHANNELS );
	await expect( page.locator( '.sc-wpun-no-channels' ) ).toBeVisible();
	await page.getByRole( 'link', { name: 'Add channel' } ).click();
	await page.locator( '.sc-wpun-modules li[data-type="email"] a' ).click();
	await page.getByLabel( /^Label/ ).fill( 'Ops email' );
	await page.getByLabel( /^Send to/ ).fill( 'ops@example.com' );
	// A new channel starts switched off: it has to be switched on.
	await expect( page.getByLabel( 'Send notifications through this channel' ) ).not.toBeChecked();
	await page.getByLabel( 'Send notifications through this channel' ).check();
	await page.getByRole( 'button', { name: 'Add channel' } ).click();
	await expect( notice( page ) ).toContainText( 'Ops email added.' );
	await expect( channelRow( page, 'Ops email' ).locator( '.sc-wpun-state' ) ).toHaveText( 'On' );

	// Edit.
	await channelRow( page, 'Ops email' ).getByRole( 'link', { name: 'Edit Ops email' } ).click();
	await expect( page.locator( '.sc-wpun-channel-id' ) ).toHaveText( 'ops_email' );
	await page.getByLabel( /^Label/ ).fill( 'Ops mail' );
	await page.getByLabel( /^Send to/ ).fill( 'ops@example.com, oncall@example.com' );
	await page.getByLabel( 'Subject prefix' ).fill( '[Ops]' );
	await page.getByRole( 'button', { name: 'Save channel' } ).click();
	await expect( notice( page ) ).toContainText( 'Ops mail saved.' );
	await expect( channelRow( page, 'Ops mail' ) ).toBeVisible();
	await expect( channelRow( page, 'Ops email' ) ).toHaveCount( 0 );

	// Send test: the notice says so and the message is in the mailbox, with the edited settings.
	await channelRow( page, 'Ops mail' ).getByRole( 'button', { name: 'Send test to Ops mail' } ).click();
	await expect( notice( page ) ).toHaveClass( /notice-success/ );
	await expect( notice( page ) ).toContainText( 'Test message sent through Ops mail.' );
	const [ testMessage ] = await waitForMail( request, 1 );
	expect( recipients( testMessage ).sort() ).toEqual( [ 'ops@example.com', 'oncall@example.com' ].sort() );
	expect( testMessage.Subject ).toBe( '[Ops] WP Updates Notifier: test message for Test' );

	// Duplicate: the copy starts switched off.
	await channelRow( page, 'Ops mail' ).getByRole( 'button', { name: 'Duplicate Ops mail' } ).click();
	await expect( notice( page ) ).toContainText( 'Channel duplicated as Ops mail (copy).' );
	await expect( channelRow( page, 'Ops mail (copy)' ).locator( '.sc-wpun-state' ) ).toHaveText( 'Off' );

	// Enable the copy, then disable the original.
	await channelRow( page, 'Ops mail (copy)' ).getByRole( 'button', { name: 'Enable Ops mail (copy)' } ).click();
	await expect( notice( page ) ).toContainText( 'Ops mail (copy) enabled.' );
	await expect( channelRow( page, 'Ops mail (copy)' ).locator( '.sc-wpun-state' ) ).toHaveText( 'On' );
	await channelRow( page, 'Ops mail' ).getByRole( 'button', { name: 'Disable Ops mail' } ).click();
	await expect( notice( page ) ).toContainText( 'Ops mail disabled.' );
	await expect( channelRow( page, 'Ops mail' ).locator( '.sc-wpun-state' ) ).toHaveText( 'Off' );

	// Delete asks first: Cancel keeps the channel, Delete removes it and leaves the other.
	await channelRow( page, 'Ops mail (copy)' ).getByRole( 'link', { name: 'Delete Ops mail (copy)' } ).click();
	await expect( page.locator( '.sc-wpun-confirm-delete' ) ).toContainText( 'Delete the channel Ops mail (copy)?' );
	await page.getByRole( 'link', { name: 'Cancel' } ).click();
	await expect( channelRow( page, 'Ops mail (copy)' ) ).toBeVisible();
	await channelRow( page, 'Ops mail (copy)' ).getByRole( 'link', { name: 'Delete Ops mail (copy)' } ).click();
	await page.locator( '.sc-wpun-confirm-delete' ).getByRole( 'button', { name: 'Delete' } ).click();
	await expect( notice( page ) ).toContainText( 'Ops mail (copy) deleted.' );
	await expect( channelRow( page, 'Ops mail (copy)' ) ).toHaveCount( 0 );
	await expect( channelRow( page, 'Ops mail' ) ).toBeVisible();
} );

test( 'a channel with an invalid address is not saved and the error is on the field', async ( { page } ) => {
	await page.goto( `${ CHANNELS }&action=new&type=email` );
	await page.getByLabel( /^Label/ ).fill( 'Broken' );
	await page.getByLabel( /^Send to/ ).fill( 'not-an-address' );
	await page.getByRole( 'button', { name: 'Add channel' } ).click();

	await expect( page.locator( '.sc-wpun-form-errors' ) ).toContainText( 'The channel was not saved.' );
	await expect( page.locator( '#sc_wpun_channel_settings_to' ) ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( page.locator( '#sc_wpun_channel_settings_to-error' ) ).toContainText( 'invalid' );
	await expect( page.locator( '#sc_wpun_channel_settings_from' ) ).not.toHaveAttribute( 'aria-invalid', 'true' );

	await page.goto( CHANNELS );
	await expect( channelRow( page, 'Broken' ) ).toHaveCount( 0 );
} );
