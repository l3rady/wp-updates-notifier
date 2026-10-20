// Shared by the documentation specs: the pages of the built site and the checks every page gets.
const fs = require( 'fs' );
const path = require( 'path' );
const { expect } = require( '@playwright/test' );

const SITE_DIR = process.env.SITE_DIR || '/site';

// Every built HTML page as [ name, url path ]. 404.html is included: the server returns it for a missing page.
function listPages() {
	const pages = [];
	const walk = ( dir ) => {
		for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
			const full = path.join( dir, entry.name );
			if ( entry.isDirectory() ) {
				walk( full );
			} else if ( entry.name.endsWith( '.html' ) ) {
				const rel = path.relative( SITE_DIR, full ).split( path.sep ).join( '/' );
				pages.push( [ rel, '/' + rel.replace( /(^|\/)index\.html$/, '$1' ) ] );
			}
		}
	};
	walk( SITE_DIR );
	if ( pages.length === 0 ) {
		throw new Error( `No built pages in ${ SITE_DIR }: run make docs first.` );
	}
	return pages.sort();
}

// Whether the page has a Mermaid diagram (a fence the build turned into <pre class="mermaid">).
function hasDiagram( rel ) {
	return fs.readFileSync( path.join( SITE_DIR, rel ), 'utf8' ).includes( 'class="mermaid"' );
}

// The scheme each Playwright project must end up with: the automatic toggle state follows the browser's colour scheme.
const SCHEMES = { dark: 'solarized-dark', light: 'solarized-light' };

// Open a page and wait until it is finished: the palette applied and, if it has diagrams, every diagram drawn.
// Asserts the scheme, so a check can never pass while looking at the wrong one.
async function open( page, urlPath, rel, project ) {
	await page.goto( urlPath, { waitUntil: 'load' } );
	await expect( page.locator( 'body' ) ).toHaveAttribute( 'data-md-color-scheme', SCHEMES[ project ] );
	if ( hasDiagram( rel ) ) {
		// Material swaps each <pre class="mermaid"> for a <div class="mermaid"> holding the drawn SVG in a closed shadow
		// root (so axe cannot look inside it). The swap only happens once Mermaid has loaded and run.
		await expect( page.locator( 'pre.mermaid' ) ).toHaveCount( 0 );
		await expect( page.locator( 'div.mermaid' ).first() ).toBeVisible();
	}
}

module.exports = { SITE_DIR, listPages, hasDiagram, open, SCHEMES };
