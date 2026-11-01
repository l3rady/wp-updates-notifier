// Muting (#82): mute a plugin from the Plugins screen, run the update check and see that no message names it, then
// unmute it. Also skipping a version from the update notice, and the Muted tab.
const { test, expect } = require( '@playwright/test' );
const { SETTINGS, emailChannel, resetSite, runCheck, waitForMail, clearMail } = require( './helpers' );

const PLUGINS = '/wp-admin/plugins.php';
const MUTED = `${ SETTINGS }&tab=muted`;
const OFFICE = { office: emailChannel( 'Office', { to: 'office@example.com' } ) };

// The row of a plugin on the Plugins screen, by its file.
const row = ( page, file ) => page.locator( `tr[data-plugin="${ file }"]` );

test.describe( 'muting', () => {
	test( 'a plugin muted from the Plugins screen is left out of the messages until it is unmuted', async ( { page, request } ) => {
		await resetSite( request, { channels: OFFICE } );

		await page.goto( PLUGINS );
		await row( page, 'example-gallery/example-gallery.php' ).locator( '.row-actions a.sc-wpun-mute' ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveClass( /notice-success/ );
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveText( 'Update notifications for Example Gallery are muted.' );
		await expect( row( page, 'example-gallery/example-gallery.php' ).locator( '.sc-wpun-muted-note' ) ).toHaveText( 'Update notifications muted' );
		await expect( row( page, 'example-gallery/example-gallery.php' ).locator( '.row-actions a.sc-wpun-mute' ) ).toHaveCount( 0 );

		// The check leaves it out, and the other items are sent.
		await runCheck( request );
		const [ mail ] = await waitForMail( request, 1 );
		expect( mail.Text ).toContain( 'Example Contact Forms' );
		expect( mail.Text ).toContain( 'Example Backup' );
		expect( mail.Text, 'a muted plugin is not named' ).not.toContain( 'Example Gallery' );

		// The Muted tab lists it.
		await page.goto( MUTED );
		const item = page.locator( 'tr.sc-wpun-muted-item', { hasText: 'Example Gallery' } );
		await expect( item ).toContainText( 'Always' );
		await expect( item ).toContainText( 'Update to 2.1.2 waiting' );

		// Unmuting sends what was held back, and only that: the rest was sent before.
		await page.goto( PLUGINS );
		await row( page, 'example-gallery/example-gallery.php' ).locator( '.row-actions a.sc-wpun-unmute' ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveText( 'Update notifications for Example Gallery are on again.' );
		await clearMail( request );
		await runCheck( request );
		const [ later ] = await waitForMail( request, 1 );
		expect( later.Text ).toContain( 'Example Gallery from version 2.1.0 to 2.1.2' );
		expect( later.Text ).not.toContain( 'Example Contact Forms' );
	} );

	test( 'skipping the waiting version from the update notice, then undoing it', async ( { page, request } ) => {
		await resetSite( request, { channels: OFFICE } );

		await page.goto( PLUGINS );
		await page.locator( 'tr.plugin-update-tr[data-plugin="example-backup/example-backup.php"] a.sc-wpun-skip' ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveText( 'Update notifications for Example Backup version 1.9.0 are skipped.' );
		await expect( page.locator( 'tr.plugin-update-tr[data-plugin="example-backup/example-backup.php"]' ) ).toContainText( 'Notifications for version 1.9.0 are skipped.' );

		await runCheck( request );
		const [ mail ] = await waitForMail( request, 1 );
		expect( mail.Text ).not.toContain( 'Example Backup' );

		await page.goto( MUTED );
		await expect( page.locator( 'tr.sc-wpun-muted-item', { hasText: 'Example Backup' } ) ).toContainText( 'Version 1.9.0' );

		await page.goto( PLUGINS );
		await page.locator( 'tr.plugin-update-tr[data-plugin="example-backup/example-backup.php"] a.sc-wpun-unmute' ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveText( 'Update notifications for Example Backup are on again.' );
	} );

	test( 'snoozing from the update notice, then undoing it', async ( { page, request } ) => {
		await resetSite( request, { channels: OFFICE } );

		const notice = page.locator( 'tr.plugin-update-tr[data-plugin="example-backup/example-backup.php"]' );
		await page.goto( PLUGINS );
		await notice.getByRole( 'link', { name: '1 week' } ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveClass( /notice-success/ );
		await expect( page.locator( '.sc-wpun-notice' ) ).toContainText( 'Update notifications for Example Backup are snoozed until' );
		await expect( notice ).toContainText( 'Notifications snoozed until' );
		await expect( notice.locator( 'a.sc-wpun-snooze' ) ).toHaveCount( 0 );

		await runCheck( request );
		const [ mail ] = await waitForMail( request, 1 );
		expect( mail.Text, 'a snoozed plugin is not named' ).not.toContain( 'Example Backup' );

		await page.goto( MUTED );
		const item = page.locator( 'tr.sc-wpun-muted-item', { hasText: 'Example Backup' } );
		await expect( item ).toContainText( 'Snoozed until' );

		await page.goto( PLUGINS );
		await notice.locator( 'a.sc-wpun-unmute' ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveText( 'Update notifications for Example Backup are on again.' );
		await expect( notice.getByRole( 'link', { name: '1 week' } ) ).toBeVisible();
	} );

	test( 'the Muted tab mutes and unmutes a plugin', async ( { page, request } ) => {
		await resetSite( request, { channels: OFFICE } );

		await page.goto( MUTED );
		await expect( page.locator( '.sc-wpun-no-muted' ) ).toBeVisible();
		await page.getByLabel( 'Plugin or theme' ).selectOption( { label: 'Example Contact Forms' } );
		await page.getByRole( 'button', { name: 'Mute', exact: true } ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveText( 'Update notifications for Example Contact Forms are muted.' );

		await runCheck( request );
		const [ mail ] = await waitForMail( request, 1 );
		expect( mail.Text ).not.toContain( 'Example Contact Forms' );

		await page.getByRole( 'button', { name: 'Unmute Example Contact Forms' } ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveText( 'Update notifications for Example Contact Forms are on again.' );
		await expect( page.locator( '.sc-wpun-no-muted' ) ).toBeVisible();

		await clearMail( request );
		await runCheck( request );
		const [ later ] = await waitForMail( request, 1 );
		expect( later.Text ).toContain( 'Example Contact Forms' );
	} );
} );
