// Shared by the specs: the addresses, WP-CLI (through control.php), Mailpit and the reset between tests.
const { expect } = require( '@playwright/test' );

const SETTINGS = '/wp-admin/options-general.php?page=wp-updates-notifier';
const CHANNELS = `${ SETTINGS }&tab=channels`;
const MAILPIT = process.env.MAILPIT_URL || 'http://mailpit:8025';
const WPCLI = process.env.WPCLI_URL || 'http://wpcli:9000';

// The fake updates of .wordpress-org/blueprint/fake-updates.php: what an update email lists.
const FAKE_ITEMS = [ 'Example Contact Forms', 'Example Gallery', 'Example Backup' ];

// Runs WP-CLI in the site (tests/flows/control.php) and returns its output. Throws if it exits non-zero.
async function wp( request, ...args ) {
	const response = await request.post( WPCLI, { data: { args }, timeout: 60000 } );
	const result = await response.json();
	if ( result.code !== 0 ) {
		throw new Error( `wp ${ args.join( ' ' ) } exited ${ result.code }: ${ result.stderr || result.stdout }` );
	}
	return result.stdout.trim();
}

// Like wp(), but returns the exit code and both outputs instead of throwing: for a command that is expected to fail or
// whose output is what a test is about.
async function wpResult( request, ...args ) {
	const response = await request.post( WPCLI, { data: { args }, timeout: 120000 } );
	return response.json();
}

// Puts the site back to a known state and empties the mailbox. `channels` is the stored sc_wpun_channels option (an
// object by instance id); `settings` overrides the General tab defaults; `constants` is a list of [name, value] to
// define in wp-config.php (reset removes them all again, so a test never leaks one).
async function resetSite( request, { channels = {}, settings = {}, constants = [] } = {} ) {
	await wp( request, 'eval-file', '/flows/reset.php', JSON.stringify( { channels, settings } ) );
	for ( const [ name, value ] of constants ) {
		await wp( request, 'config', 'set', name, value, '--type=constant', '--quiet' );
	}
	await clearMail( request );
}

// An email channel as it is stored. `routing` and `settings` are merged over the defaults.
function emailChannel( label, { to = 'office@example.com', enabled = true, routing = {}, settings = {} } = {} ) {
	return { type: 'email', label, enabled, settings: { to, ...settings }, routing };
}

async function clearMail( request ) {
	const response = await request.delete( `${ MAILPIT }/api/v1/messages` );
	expect( response.ok() ).toBe( true );
}

// The messages in Mailpit, newest first, each with its text body and subject (the list has only a summary).
async function messages( request ) {
	const list = await ( await request.get( `${ MAILPIT }/api/v1/messages` ) ).json();
	const full = [];
	for ( const summary of list.messages ) {
		full.push( await ( await request.get( `${ MAILPIT }/api/v1/message/${ summary.ID }` ) ).json() );
	}
	return full;
}

// Waits until `count` messages are in Mailpit (mail is delivered over SMTP, so it can be a moment behind) and
// returns them. Fails with the count it saw.
async function waitForMail( request, count = 1 ) {
	let found = [];
	await expect
		.poll( async () => ( found = await messages( request ) ).length, { timeout: 15000, message: `waiting for ${ count } email(s)` } )
		.toBe( count );
	return found;
}

// That no mail arrives. Mail is sent before WP-CLI returns, so a short look is enough; there is nothing to wait for.
async function expectNoMail( request ) {
	const list = await ( await request.get( `${ MAILPIT }/api/v1/messages` ) ).json();
	expect( list.total, 'no email was sent' ).toBe( 0 );
}

// The addresses a message went to.
function recipients( message ) {
	return message.To.map( ( a ) => a.Address );
}

// Runs the scheduled update check the way cron does.
async function runCheck( request ) {
	return wp( request, 'cron', 'event', 'run', 'sc_wpun_update_check' );
}

// The state of one channel instance in the stored run state (option sc_wpun_state).
async function channelState( request, id ) {
	const state = JSON.parse( await wp( request, 'option', 'get', 'sc_wpun_state', '--format=json' ) );
	return state.channels[ id ];
}

// Collects the console errors and uncaught exceptions of a page. Call before navigating.
function watchConsole( page ) {
	const errors = [];
	page.on( 'console', ( m ) => m.type() === 'error' && errors.push( `console: ${ m.text() } (${ m.location().url })` ) );
	page.on( 'pageerror', ( e ) => errors.push( `exception: ${ e.message }` ) );
	return errors;
}

// The row of a channel in the Channels list, by its label.
function channelRow( page, label ) {
	return page.locator( 'tr.sc-wpun-channel', { has: page.locator( 'strong', { hasText: new RegExp( `^${ label.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }$` ) } ) } );
}

module.exports = { WPCLI, wpResult, SETTINGS, CHANNELS, MAILPIT, FAKE_ITEMS, wp, resetSite, emailChannel, clearMail, messages, waitForMail, expectNoMail, recipients, runCheck, channelState, watchConsole, channelRow };
