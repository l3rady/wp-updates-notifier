// The WordPress.org screenshots: .wordpress-org/screenshot-1.png to screenshot-4.png, in the order of SHOTS. The
// captions are the `== Screenshots ==` list of .wordpress-org/readme/template.txt, in the same order: change both
// together.
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );

const OUT = process.env.SHOTS_OUT || '/out';
const MAILPIT = process.env.MAILPIT_URL || 'http://mailpit:8025';
const SETTINGS = '/wp-admin/options-general.php?page=wp-updates-notifier';
const CHANNELS = `${ SETTINGS }&tab=channels`;

// The id of the newest message sent to an address, from Mailpit's API.
async function messageTo( request, address ) {
	const found = await ( await request.get( `${ MAILPIT }/api/v1/search`, { params: { query: `to:${ address }` } } ) ).json();
	return found.messages.length ? found.messages[ 0 ].ID : null;
}

// The screenshots, in order: name, page and what to photograph. `mail` is the address whose message is shown.
const SHOTS = [
	{ name: 'General tab', url: SETTINGS, ready: 'h1' },
	{ name: 'Channels list', url: CHANNELS, ready: '.wp-list-table' },
	{ name: 'Edit channel form', url: `${ CHANNELS }&action=edit&id=office`, ready: 'form' },
	{ name: 'Update email in Mailpit', mail: 'office@example.com' },
];

test.describe.configure( { mode: 'serial' } );

test( 'the update check sent one email to each channel, and nothing else did', async ( { request } ) => {
	// run.sh ran the check before this starts, on an empty Mailpit: a background check would have sent more.
	const all = await ( await request.get( `${ MAILPIT }/api/v1/messages` ) ).json();
	expect( all.total, 'the two enabled channels got one email each' ).toBe( 2 );
	expect( await messageTo( request, 'office@example.com' ) ).not.toBeNull();
	expect( await messageTo( request, 'client@example.com' ) ).not.toBeNull();
} );

test( 'the channels list shows both deliveries as sent', async ( { page } ) => {
	await page.goto( CHANNELS );
	await expect( page.locator( '.wp-list-table' ).getByText( /^Sent / ) ).toHaveCount( 2 );
} );

SHOTS.forEach( ( shot, index ) => {
	test( `screenshot ${ index + 1 }: ${ shot.name }`, async ( { page, request } ) => {
		const file = path.join( OUT, `screenshot-${ index + 1 }.png` );
		if ( shot.mail ) {
			// The message as Mailpit shows it, without its inbox list or toolbar.
			await page.goto( `${ MAILPIT }/view/${ await messageTo( request, shot.mail ) }` );
			// Hide the tab row (and its device buttons) and the tags row, which are Mailpit's controls, not the message.
			await page.addStyleTag( { content: '#nav-tab, .messageHeaders tr:last-child { display: none !important; }' } );
			const message = page.locator( '#message-view' );
			await expect( message ).toBeVisible();
			// The body is in an iframe: wait until the update list is in it.
			await expect( page.frameLocator( '#preview-html' ).locator( 'li' ).first() ).toBeVisible();
			await message.screenshot( { path: file } );
			return;
		}
		await page.goto( shot.url );
		await expect( page.locator( shot.ready ).first() ).toBeVisible();
		// The viewport as tall as the page (at least 800): a full-page shot would stop the fixed admin menu's background
		// at the height of the window.
		const height = await page.evaluate( () => document.documentElement.scrollHeight );
		await page.setViewportSize( { width: 1280, height: Math.max( 800, height ) } );
		await page.screenshot( { path: file } );
	} );
} );
