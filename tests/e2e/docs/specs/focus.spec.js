// Keyboard focus is visible: Tab through the first controls of the page (skip link, header, search, palette toggle,
// navigation, a link in the content) and check each shows an indicator. Material draws focus with the browser's own
// outline in the accent colour (outline-color on :focus-visible), so that is what is measured. Run in both colour
// schemes and, for each, in forced colours mode, where the system draws it.
const { test, expect } = require( '@playwright/test' );
const { open } = require( './site' );

// Pages to try: the home page and a long page with a table of contents, diagrams and code.
const PAGES = [
	[ 'index.html', '/' ],
	[ 'developers/architecture/index.html', '/developers/architecture/' ],
];

// How many controls to Tab through.
const TABS = 12;

// Run in the page. The focused element, its focus indicator and the colour it sits on. Some controls are drawn by
// another element: Material hides the palette toggle's radio inputs and outlines the label next to them, and the
// search field sits in a form that shows the outline. So the element, its label and its form are all candidates.
function inspectFocus() {
	const el = document.activeElement;
	const rgb = ( c ) => ( c.match( /[\d.]+/g ) || [] ).map( Number );
	const candidates = [ el ];
	if ( el.nextElementSibling && el.nextElementSibling.tagName === 'LABEL' ) {
		candidates.push( el.nextElementSibling );
	}
	if ( el.closest( 'form' ) ) {
		candidates.push( el.closest( 'form' ) );
	}
	const measured = candidates.map( ( node ) => {
		const s = getComputedStyle( node );
		const outline = s.outlineStyle !== 'none' && parseFloat( s.outlineWidth ) > 0 && ( rgb( s.outlineColor )[ 3 ] ?? 1 ) > 0;
		// The outline is drawn outside the box, over what is behind it: the first opaque background up the tree.
		let bg = [ 255, 255, 255 ];
		for ( let n = node.parentElement; n; n = n.parentElement ) {
			const c = rgb( getComputedStyle( n ).backgroundColor );
			if ( c.length >= 3 && ( c[ 3 ] ?? 1 ) === 1 ) {
				bg = c;
				break;
			}
		}
		return {
			outline,
			shadow: s.boxShadow !== 'none',
			text: `${ node.tagName.toLowerCase() }: outline ${ s.outlineStyle } ${ s.outlineWidth } ${ s.outlineColor }; box-shadow ${ s.boxShadow }`,
			outlineColor: rgb( s.outlineColor ).slice( 0, 3 ),
			bg,
		};
	} );
	const found = measured.find( ( m ) => m.outline || m.shadow ) || measured[ 0 ];
	const label = el.getAttribute( 'aria-label' ) || el.getAttribute( 'title' ) || ( el.textContent || '' ).trim().slice( 0, 30 ) || el.id;
	return {
		name: `<${ el.tagName.toLowerCase() }${ el.className ? ' class="' + el.className + '"' : '' }> ${ label }`,
		isBody: el === document.body,
		indicator: found.outline || found.shadow,
		outline: found.outline,
		detail: measured.map( ( m ) => m.text ).join( ' | ' ),
		outlineColor: found.outlineColor,
		bg: found.bg,
	};
}

function luminance( [ r, g, b ] ) {
	const f = ( v ) => ( ( v /= 255 ) <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 ) );
	return 0.2126 * f( r ) + 0.7152 * f( g ) + 0.0722 * f( b );
}

function contrast( a, b ) {
	const [ hi, lo ] = [ luminance( a ), luminance( b ) ].sort( ( x, y ) => y - x );
	return ( hi + 0.05 ) / ( lo + 0.05 );
}

for ( const forced of [ false, true ] ) {
	test.describe( forced ? 'forced colours' : 'normal colours', () => {
		test.use( { forcedColors: forced ? 'active' : 'none' } );

		for ( const [ rel, urlPath ] of PAGES ) {
			test( `focus is visible: ${ rel }`, async ( { page }, testInfo ) => {
				await open( page, urlPath, rel, testInfo.project.name );
				const problems = [];
				const reached = [];
				for ( let i = 0; i < TABS; i++ ) {
					await page.keyboard.press( 'Tab' );
					const f = await page.evaluate( inspectFocus );
					reached.push( f.name );
					if ( f.isBody ) {
						problems.push( `Tab ${ i + 1 }: focus left the page content` );
						continue;
					}
					if ( ! f.indicator ) {
						problems.push( `Tab ${ i + 1 }: no visible focus indicator: ${ f.name } (${ f.detail })` );
					} else if ( ! forced && f.outline && f.outlineColor.length === 3 ) {
						// WCAG 1.4.11: the indicator needs 3:1 against what it sits on. (Forced colours: the system picks it.)
						const ratio = contrast( f.outlineColor, f.bg );
						if ( ratio < 3 ) {
							problems.push( `Tab ${ i + 1 }: focus ring contrast ${ ratio.toFixed( 2 ) }:1 is under 3:1: ${ f.name }` );
						}
					}
				}
				// Guard: the walk really covered the header controls and the content, not just the first link.
				expect( reached.join( '\n' ), 'controls reached' ).toMatch( /Search/i );
				expect( reached.join( '\n' ), 'controls reached' ).toMatch( /Switch to/ );
				expect( problems, `focus problems\n${ reached.join( '\n' ) }` ).toEqual( [] );
			} );
		}
	} );
}
