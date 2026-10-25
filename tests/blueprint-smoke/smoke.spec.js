// The demo as a visitor of the WordPress.org Live Preview sees it (`make blueprint-smoke`). Playground logs the
// browser in as admin (the --login option of the CLI, which the Live Preview does with the blueprint's login step).
const { test, expect } = require( '@playwright/test' );

const SETTINGS = '/wp-admin/options-general.php?page=wp-updates-notifier';

test.describe.configure( { mode: 'serial' } );

test( 'the settings screen shows the demo notice', async ( { page } ) => {
	await page.goto( SETTINGS );
	await expect( page.locator( 'h1' ).first() ).toContainText( 'Updates Notifier' );
	const notice = page.locator( '.notice-info', { hasText: 'This is a WordPress Playground demo' } );
	await expect( notice ).toContainText( 'Emails are caught, not sent' );
	await expect( notice.getByRole( 'link', { name: /Demo emails/ } ) ).toBeVisible();
} );

test( 'the channels list shows both deliveries as sent', async ( { page } ) => {
	await page.goto( `${ SETTINGS }&tab=channels` );
	await expect( page.locator( '.wp-list-table' ).getByText( 'Office email' ) ).toBeVisible();
	await expect( page.locator( '.wp-list-table' ).getByText( 'Client: WordPress updates' ) ).toBeVisible();
	await expect( page.locator( '.wp-list-table' ).getByText( /^Sent / ) ).toHaveCount( 2 );
} );

test( 'Tools, Demo emails lists the update emails and shows one with the made-up updates', async ( { page } ) => {
	await page.goto( '/wp-admin/tools.php?page=wpun-demo-emails' );
	await expect( page.locator( 'h1' ) ).toHaveText( 'Demo emails' );
	const rows = page.locator( 'table.widefat tbody tr' );
	await expect( rows ).toHaveCount( 2 );
	await expect( page.locator( 'table.widefat' ) ).toContainText( 'office@example.com' );
	await expect( page.locator( 'table.widefat' ) ).toContainText( 'client@example.com' );

	// The office email is HTML: in a sandboxed iframe, with the fake plugins in it.
	await rows.filter( { hasText: 'office@example.com' } ).getByRole( 'link' ).click();
	const frame = page.locator( 'iframe[sandbox]' );
	await expect( frame ).toBeVisible();
	expect( await frame.getAttribute( 'sandbox' ) ).toBe( '' );
	const body = page.frameLocator( 'iframe[sandbox]' );
	await expect( body.getByText( 'Example Contact Forms' ).first() ).toBeVisible();
	await expect( body.getByText( 'Example Gallery' ).first() ).toBeVisible();
	await expect( body.getByText( 'Example Backup' ).first() ).toBeVisible();
} );
