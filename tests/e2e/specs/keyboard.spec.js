// Keyboard checks (#225): on the Channels list and on a channel form, Tab from the start of `.wrap` must reach every
// visible control, in the visual order (top to bottom, left to right within a row), each with a visible focus indicator.
const { test, expect } = require( '@playwright/test' );
const { CHANNELS, ABOUT } = require( './helpers' );

const PAGES = [
	[ 'About tab', ABOUT ],
	[ 'Channels list', CHANNELS ],
	[ 'Email edit form', `${ CHANNELS }&action=edit&id=e2e_email` ],
];

// How far, in pixels, an element may sit above or to the left of the one before it and still count as in order.
const TOLERANCE = 8;

// Run in the page. Describes the focusable controls in `.wrap` that are displayed. Tab reaches a radio group once, so
// a group is one control.
function listControls() {
	const selector = 'a[href], button, input, select, textarea, summary, [tabindex]';
	const seen = new Set();
	const out = [];
	for ( const el of document.querySelectorAll( '.wrap ' + selector.split( ', ' ).join( ', .wrap ' ) ) ) {
		const style = getComputedStyle( el );
		const tabindex = el.getAttribute( 'tabindex' );
		if ( el.disabled || el.type === 'hidden' || ( tabindex !== null && Number( tabindex ) < 0 ) ) {
			continue;
		}
		// display: none (core's .toggle-row below 782px, a closed <details>) leaves no box. Off-screen row actions
		// do have one, and appear when focused.
		if ( style.visibility === 'hidden' || el.getClientRects().length === 0 ) {
			continue;
		}
		if ( el.type === 'radio' ) {
			const key = ( el.form ? el.form.getAttribute( 'class' ) : '' ) + ':' + el.name;
			if ( seen.has( key ) ) {
				continue;
			}
			seen.add( key );
		}
		out.push( name( el ) );
	}
	return out;

	function name( e ) {
		return e.localName + ( e.id ? '#' + e.id : '' ) + ( e.name ? '[name="' + e.name + '"]' : '' ) + ( e.className && typeof e.className === 'string' ? '.' + e.className.trim().split( /\s+/ ).join( '.' ) : '' ) + ( e.localName === 'a' || e.localName === 'button' ? ' "' + ( e.getAttribute( 'aria-label' ) || e.textContent ).trim().slice( 0, 40 ) + '"' : '' ) + ( e.type === 'radio' ? ' (' + e.value + ')' : '' );
	}
}

// Run in the page. What the focused element is, where it is and whether it shows that it has focus.
function describeFocus() {
	const e = document.activeElement;
	const wrap = document.querySelector( '.wrap' );
	if ( ! e || ! wrap || ! wrap.contains( e ) ) {
		return null;
	}
	const rect = e.getBoundingClientRect();
	const s = getComputedStyle( e );
	// An outline of style none or width 0 draws nothing. Core sets a transparent outline next to its box-shadow (it
	// shows in forced-colours mode only), so a transparent one does not count either.
	const alpha = ( c ) => ( c.startsWith( 'rgba' ) ? Number( c.split( ',' )[ 3 ].replace( ')', '' ) ) : 1 );
	const outline = s.outlineStyle !== 'none' && parseFloat( s.outlineWidth ) > 0 && alpha( s.outlineColor ) > 0;
	const shadow = s.boxShadow !== 'none';
	return {
		name: e.localName + ( e.id ? '#' + e.id : '' ) + ( e.name ? '[name="' + e.name + '"]' : '' ) + ( e.className && typeof e.className === 'string' ? '.' + e.className.trim().split( /\s+/ ).join( '.' ) : '' ) + ( e.localName === 'a' || e.localName === 'button' ? ' "' + ( e.getAttribute( 'aria-label' ) || e.textContent ).trim().slice( 0, 40 ) + '"' : '' ) + ( e.type === 'radio' ? ' (' + e.value + ')' : '' ),
		group: e.type === 'radio' ? ( e.form ? e.form.getAttribute( 'class' ) : '' ) + ':' + e.name : null,
		top: Math.round( rect.top + window.scrollY ),
		left: Math.round( rect.left + window.scrollX ),
		indicator: outline || shadow,
		style: `outline: ${ s.outlineStyle } ${ s.outlineWidth } ${ s.outlineColor }; box-shadow: ${ s.boxShadow }`,
	};
}

for ( const [ label, url ] of PAGES ) {
	test( `keyboard: ${ label }`, async ( { page } ) => {
		await page.goto( url );
		await expect( page.locator( '.wrap' ) ).toBeVisible();

		const expected = await page.evaluate( listControls );
		expect( expected.length, 'no focusable controls found in .wrap' ).toBeGreaterThan( 0 );

		// Start Tab at the top of .wrap: focus it (it is not focusable, so give it a tabindex for a moment). Chromium
		// continues from the last focused place, so the next Tab goes to the first control inside.
		await page.evaluate( () => {
			const wrap = document.querySelector( '.wrap' );
			wrap.setAttribute( 'tabindex', '-1' );
			wrap.focus();
			wrap.removeAttribute( 'tabindex' );
		} );

		const reached = [];
		for ( let i = 0; i < expected.length + 5; i++ ) {
			await page.keyboard.press( 'Tab' );
			const focus = await page.evaluate( describeFocus );
			if ( null === focus ) {
				if ( reached.length ) {
					break; // Focus left .wrap at the end.
				}
				continue; // Not in .wrap yet.
			}
			reached.push( focus );
		}

		const problems = [];

		// (a) Every visible control is reached.
		const names = reached.map( ( f ) => f.name );
		for ( const name of expected ) {
			if ( ! names.includes( name ) ) {
				problems.push( `not reached by Tab: ${ name }` );
			}
		}

		// (b) The order follows the page: never above the previous control, and to its right when on the same row.
		for ( let i = 1; i < reached.length; i++ ) {
			const a = reached[ i - 1 ];
			const b = reached[ i ];
			const sameRow = Math.abs( b.top - a.top ) <= TOLERANCE;
			if ( b.top < a.top - TOLERANCE || ( sameRow && b.left < a.left - TOLERANCE ) ) {
				problems.push( `out of visual order: ${ b.name } (${ b.left },${ b.top }) comes after ${ a.name } (${ a.left },${ a.top })` );
			}
		}

		// (c) Every focused control shows its focus.
		for ( const f of reached ) {
			if ( ! f.indicator ) {
				problems.push( `no visible focus indicator: ${ f.name } (${ f.style })` );
			}
		}

		expect( problems, `${ problems.length } keyboard problem(s) on ${ url }\nTab order: ${ names.join( ' > ' ) }\n` ).toEqual( [] );
	} );
}
