// A broken Mermaid diagram must fail the build, with the parse error. The other specs would fail too (their open() in
// site.js waits for every diagram to be drawn, which a broken one never is), but only with a timeout. This spec does
// not use open(): it needs the Mermaid the page loaded, not the drawn diagrams. mermaid.parse() throws on a syntax
// error, which is the message that says what to fix. The sources come from the built HTML, as the build wrote them, and are parsed
// by the Mermaid that the page itself loaded, so this checks exactly what visitors get. One scheme is enough: the
// source does not depend on it.
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const { SITE_DIR, listPages, hasDiagram } = require( './site' );

// The text of each <pre class="mermaid"><code>...</code></pre> in the file, still HTML-escaped.
function diagramSources( rel ) {
	const html = fs.readFileSync( path.join( SITE_DIR, rel ), 'utf8' );
	return [ ...html.matchAll( /<pre class="mermaid"><code>([\s\S]*?)<\/code><\/pre>/g ) ].map( ( m ) => m[ 1 ] );
}

for ( const [ rel, urlPath ] of listPages() ) {
	if ( ! hasDiagram( rel ) ) {
		continue;
	}
	test( `diagrams parse: ${ rel }`, async ( { page }, testInfo ) => {
		test.skip( testInfo.project.name !== 'dark', 'The diagram source is the same in both schemes.' );
		const sources = diagramSources( rel );
		// Fails if the build changes the markup, so the check cannot silently stop looking.
		expect( sources.length, 'diagrams found in the built HTML' ).toBeGreaterThan( 0 );

		await page.goto( urlPath, { waitUntil: 'load' } );
		await page.waitForFunction( () => typeof window.mermaid !== 'undefined' );
		const errors = await page.evaluate( async ( escaped ) => {
			const found = [];
			for ( const [ index, raw ] of escaped.entries() ) {
				const box = document.createElement( 'textarea' );
				box.innerHTML = raw;
				try {
					await window.mermaid.parse( box.value );
				} catch ( error ) {
					found.push( `diagram ${ index + 1 }: ${ error && error.message ? error.message : error }` );
				}
			}
			return found;
		}, sources );
		expect( errors, `Mermaid syntax errors on ${ rel }` ).toEqual( [] );
	} );
}
