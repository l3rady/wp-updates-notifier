// A real update check: `wp cron event run sc_wpun_update_check` against the fake pending updates of
// .wordpress-org/blueprint/fake-updates.php (three plugins, the active theme and WordPress), with the mail in Mailpit.
const { test, expect } = require( '@playwright/test' );
const { SETTINGS, CHANNELS, FAKE_ITEMS, wp, resetSite, emailChannel, clearMail, waitForMail, expectNoMail, recipients, runCheck, channelRow } = require( './helpers' );

const OFFICE = { office: emailChannel( 'Office', { to: 'office@example.com' } ) };
const CLIENT = { client: emailChannel( 'Client', { to: 'client@example.com', routing: { types: [ 'core' ] } } ) };

// A text body has no HTML entity: &amp; &#8217; &lt; and the like (#65).
const ENTITY = /&(?:[a-z][a-z0-9]*|#\d+|#x[0-9a-f]+);/i;

// Makes the reminder of a channel due, by moving the start of its interval back (ADR 0016: channels[id].last_reminder).
const moveReminderBack = ( request, id, seconds ) =>
	wp( request, 'eval', `$s = get_option( 'sc_wpun_state' ); $s['channels']['${ id }']['last_reminder'] = time() - ${ seconds }; update_option( 'sc_wpun_state', $s );` );

test.describe( 'the update check', () => {
	test( 'sends one email with the subject and every pending update', async ( { page, request } ) => {
		await resetSite( request, { channels: OFFICE } );
		await runCheck( request );

		const [ mail ] = await waitForMail( request, 1 );
		expect( recipients( mail ) ).toEqual( [ 'office@example.com' ] );
		expect( mail.Subject ).toBe( 'WP Updates Notifier: updates available for Test' );
		expect( mail.Text ).toContain( 'There are updates available for Test (http://wordpress:8080)' );
		expect( mail.Text ).toContain( 'Example Contact Forms from version 3.4.1 to 3.5.0' );
		expect( mail.Text ).toContain( 'Example Gallery from version 2.1.0 to 2.1.2' );
		expect( mail.Text ).toContain( 'Example Backup from version 1.8.3 to 1.9.0' );
		expect( mail.Text ).toMatch( /Compatibility with WordPress [\d.]+: not tested yet/ );
		expect( mail.Text ).toMatch( /WordPress from version \d\S* to \d\S*/ );
		// The active theme has an update too: the five items in all.
		expect( mail.Text.match( / from version \S+ to \S+/g ) ).toHaveLength( 5 );
		expect( mail.Text ).toContain( 'http://wordpress:8080/wp-admin/update-core.php' );

		// The Channels list shows the delivery.
		await page.goto( CHANNELS );
		await expect( channelRow( page, 'Office' ).locator( '.sc-wpun-channel-last' ) ).toContainText( /^Sent / );
	} );

	test( 'a title with an ampersand and an apostrophe has no HTML entity in the subject or the text (#65)', async ( { request } ) => {
		await resetSite( request, { channels: OFFICE } );
		// What WordPress stores for the title Tom & Jerry's: it escapes the title when it is saved.
		await wp( request, 'option', 'update', 'blogname', 'Tom &amp; Jerry&#039;s Shop' );
		await runCheck( request );

		const [ mail ] = await waitForMail( request, 1 );
		expect( mail.Subject ).toBe( "WP Updates Notifier: updates available for Tom & Jerry's Shop" );
		expect( mail.Text ).toContain( "There are updates available for Tom & Jerry's Shop (" );
		expect( mail.Subject ).not.toMatch( ENTITY );
		expect( mail.Text ).not.toMatch( ENTITY );
	} );

	test( 'a channel for WordPress updates only gets the WordPress item (#95)', async ( { request } ) => {
		await resetSite( request, { channels: { ...OFFICE, ...CLIENT } } );
		await runCheck( request );

		const mails = await waitForMail( request, 2 );
		const office = mails.find( ( m ) => recipients( m ).includes( 'office@example.com' ) );
		const client = mails.find( ( m ) => recipients( m ).includes( 'client@example.com' ) );
		expect( office.Text.match( / from version \S+ to \S+/g ) ).toHaveLength( 5 );
		expect( client.Text.match( / from version \S+ to \S+/g ) ).toHaveLength( 1 );
		expect( client.Text ).toMatch( /WordPress from version \S+ to \S+/ );
		for ( const name of FAKE_ITEMS ) {
			expect( client.Text, `${ name } is not for the client` ).not.toContain( name );
		}
	} );

	test( 'a second run sends nothing (dedupe)', async ( { request } ) => {
		await resetSite( request, { channels: OFFICE } );
		await runCheck( request );
		await waitForMail( request, 1 );
		await clearMail( request );

		await runCheck( request );
		await expectNoMail( request );
	} );

	test( 'Check for updates and notify now says what it did (#188)', async ( { page, request } ) => {
		await resetSite( request, { channels: OFFICE } );
		await page.goto( SETTINGS );
		await page.getByRole( 'button', { name: 'Check for updates and notify now' } ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveClass( /notice-success/ );
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveText( 'Checked just now. Sent to 1 channel.' );
		await waitForMail( request, 1 );

		// Nothing is new now.
		await page.getByRole( 'button', { name: 'Check for updates and notify now' } ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveText( 'Checked just now. Nothing new to send.' );
		await expect( page.locator( '.sc-wpun-last-check' ) ).toContainText( 'Last checked:' );
		expect( ( await waitForMail( request, 1 ) ).length, 'the second click sent nothing' ).toBe( 1 );
	} );

	test( 'Check for updates and notify now with every channel off says nothing was sent', async ( { page, request } ) => {
		await resetSite( request, { channels: { office: emailChannel( 'Office', { enabled: false } ) } } );
		await page.goto( SETTINGS );
		await page.getByRole( 'button', { name: 'Check for updates and notify now' } ).click();
		await expect( page.locator( '.sc-wpun-notice' ) ).toHaveText( 'Checked just now. Nothing was sent because no channel is enabled.' );
		await expectNoMail( request );
	} );

	test( 'a due daily reminder repeats what is still waiting (#81)', async ( { request } ) => {
		await resetSite( request, { channels: { office: emailChannel( 'Office', { routing: { remind: 'daily' } } ) } } );

		// The first run sends the new updates and starts the reminder interval.
		await runCheck( request );
		const [ first ] = await waitForMail( request, 1 );
		expect( first.Subject ).toBe( 'WP Updates Notifier: updates available for Test' );
		await clearMail( request );

		// Not due yet: the interval has just begun.
		await runCheck( request );
		await expectNoMail( request );

		// Due: a day and an hour have passed since the interval began.
		await moveReminderBack( request, 'office', 25 * 3600 );
		await runCheck( request );
		const [ reminder ] = await waitForMail( request, 1 );
		expect( reminder.Subject ).toBe( 'WP Updates Notifier: reminder of updates waiting for Test' );
		expect( reminder.Text ).toContain( 'Reminder: these updates are still waiting for Test' );
		for ( const name of FAKE_ITEMS ) {
			expect( reminder.Text ).toContain( name );
		}
		expect( reminder.Text.match( / from version \S+ to \S+/g ) ).toHaveLength( 5 );
		await clearMail( request );

		// The interval began again with the reminder.
		await runCheck( request );
		await expectNoMail( request );
	} );
} );
