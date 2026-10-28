// The WP-CLI commands (#79): the real `wp updates-notifier ...` in the real site, with the real exit codes and the
// mail in Mailpit.
const { test, expect } = require( '@playwright/test' );
const { wp, wpResult, resetSite, emailChannel, clearMail, waitForMail, expectNoMail, recipients } = require( './helpers' );

const OFFICE = { office: emailChannel( 'Office', { to: 'office@example.com' } ) };
const SECRET = 'KNOWN-SECRET-VALUE-4711';

// The run state as stored, as text, so that two reads can be compared. `false` before the first check.
const storedState = ( request ) => wp( request, 'eval', 'echo wp_json_encode( get_option( "sc_wpun_state" ) );' );

test.beforeEach( async ( { request } ) => resetSite( request, { channels: OFFICE } ) );

test.describe( 'wp updates-notifier', () => {
	test( 'channels list --format=json lists the instances', async ( { request } ) => {
		const result = await wpResult( request, 'updates-notifier', 'channels', 'list', '--format=json' );

		expect( result.code ).toBe( 0 );
		const rows = JSON.parse( result.stdout );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toMatchObject( { id: 'office', label: 'Office', module: 'email', availability: 'available', enabled: 'yes' } );
	} );

	test( 'status --format=json has the sections and never prints a secret', async ( { request } ) => {
		await resetSite( request, {
			channels: {
				...OFFICE,
				showcase: { type: 'field_showcase', label: 'Showcase', enabled: false, settings: { secret_plain: SECRET, secret_required: SECRET, text_secret: SECRET }, routing: {} },
			},
		} );

		const result = await wpResult( request, 'updates-notifier', 'status', '--format=json' );

		expect( result.code ).toBe( 0 );
		expect( result.stdout ).not.toContain( SECRET );
		expect( result.stderr ).not.toContain( SECRET );
		const data = JSON.parse( result.stdout );
		expect( Object.keys( data ) ).toEqual( [ 'settings', 'schedule', 'channels', 'pending' ] );
		const showcase = data.channels.find( ( c ) => c.id === 'showcase' );
		expect( showcase.fields.find( ( f ) => f.field === 'secret_plain' ).value ).toBe( 'set' );

		const table = await wpResult( request, 'updates-notifier', 'status' );
		expect( table.code ).toBe( 0 );
		expect( table.stdout ).not.toContain( SECRET );
	} );

	test( 'check --dry-run sends no mail and changes no state', async ( { request } ) => {
		const before = await storedState( request );

		const result = await wpResult( request, 'updates-notifier', 'check', '--dry-run' );

		expect( result.code, result.stderr ).toBe( 0 );
		expect( result.stdout ).toContain( 'Office (office): would send 5 items' );
		expect( result.stdout ).toContain( 'Example Contact Forms' );
		await expectNoMail( request );
		expect( await storedState( request ) ).toBe( before );
	} );

	test( 'check sends the mail once, then has nothing new', async ( { request } ) => {
		const first = await wpResult( request, 'updates-notifier', 'check' );

		expect( first.code, first.stderr ).toBe( 0 );
		expect( first.stdout ).toContain( 'Office (office): sent 5 items' );
		const [ mail ] = await waitForMail( request, 1 );
		expect( recipients( mail ) ).toEqual( [ 'office@example.com' ] );
		await clearMail( request );

		const second = await wpResult( request, 'updates-notifier', 'check' );

		expect( second.code, second.stderr ).toBe( 0 );
		expect( second.stdout ).toContain( 'Office (office): nothing new' );
		await expectNoMail( request );
	} );

	test( 'test --channel sends a test message, and an unknown channel exits non-zero', async ( { request } ) => {
		const sent = await wpResult( request, 'updates-notifier', 'test', '--channel=office' );

		expect( sent.code, sent.stderr ).toBe( 0 );
		expect( sent.stdout ).toContain( 'Test message sent through Office.' );
		const [ mail ] = await waitForMail( request, 1 );
		expect( recipients( mail ) ).toEqual( [ 'office@example.com' ] );
		await clearMail( request );

		const unknown = await wpResult( request, 'updates-notifier', 'test', '--channel=nope' );

		expect( unknown.code ).not.toBe( 0 );
		expect( unknown.stderr ).toContain( 'There is no channel instance "nope".' );
		await expectNoMail( request );
	} );
} );
