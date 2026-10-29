// Shared by the specs: the settings screen's addresses and the reading of the pages.
const AxeBuilder = require( '@axe-core/playwright' ).default;

const SETTINGS = '/wp-admin/options-general.php?page=wp-updates-notifier';
const CHANNELS = `${ SETTINGS }&tab=channels`;
const ABOUT = `${ SETTINGS }&tab=about`;
const MUTED = `${ SETTINGS }&tab=muted`;

// Every view the axe scan covers. The ids are the channels tests/e2e/seed.sh creates.
const VIEWS = [
	[ 'General tab', SETTINGS ],
	[ 'About tab', ABOUT ],
	[ 'Channels list', CHANNELS ],
	[ 'Muted tab', MUTED ],
	[ 'Module picker', `${ CHANNELS }&action=add` ],
	[ 'New Email form', `${ CHANNELS }&action=new&type=email` ],
	[ 'Edit Email form', `${ CHANNELS }&action=edit&id=e2e_email` ],
	[ 'Delete confirmation', `${ CHANNELS }&action=delete&id=e2e_email` ],
	[ 'Field showcase edit form', `${ CHANNELS }&action=edit&id=e2e_showcase` ],
];

// WCAG 2.2 A and AA. No rule is disabled: a rule that has to be would be listed here with the reason.
const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

// Scan the plugin's `.wrap` only, so core's admin bar and menu (and debugging plugins) cannot fail the build.
async function scan( page ) {
	return ( await new AxeBuilder( { page } ).include( '.wrap' ).withTags( TAGS ).analyze() ).violations;
}

// The violations as readable text: one block per rule, one line per element.
function describe( violations ) {
	return violations
		.map( ( v ) => {
			const nodes = v.nodes.map( ( n ) => `    - ${ n.target.join( ' ' ) }\n      ${ ( n.failureSummary || '' ).replace( /\n/g, '\n      ' ) }` );
			return `[${ v.impact }] ${ v.id }: ${ v.help }\n  ${ v.helpUrl }\n${ nodes.join( '\n' ) }`;
		} )
		.join( '\n\n' );
}

// Collects the console errors and uncaught exceptions of a page. Call before navigating.
function watchConsole( page ) {
	const errors = [];
	page.on( 'console', ( m ) => m.type() === 'error' && errors.push( `console: ${ m.text() } (${ m.location().url })` ) );
	page.on( 'pageerror', ( e ) => errors.push( `exception: ${ e.message }` ) );
	return errors;
}

module.exports = { SETTINGS, CHANNELS, ABOUT, MUTED, VIEWS, TAGS, scan, describe, watchConsole };
