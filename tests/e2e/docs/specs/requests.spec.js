// The site loads nothing from a third party: every request goes to the local server. Material fetches Mermaid from
// unpkg.com unless it is already on the page, and a theme can pull in Google Fonts, so the pages with diagrams matter.
const { test, expect } = require( '@playwright/test' );
const { listPages, open } = require( './site' );

for ( const [ rel, urlPath ] of listPages() ) {
	test( `no third-party requests: ${ rel }`, async ( { page, baseURL }, testInfo ) => {
		const local = new URL( baseURL ).host;
		const foreign = [];
		// Anything that is not the local server is recorded and refused, so the check does not depend on the network.
		await page.route( '**/*', ( route ) => {
			const url = new URL( route.request().url() );
			if ( url.host === local || url.protocol === 'data:' || url.protocol === 'blob:' ) {
				return route.continue();
			}
			foreign.push( route.request().url() );
			return route.abort();
		} );
		await open( page, urlPath, rel, testInfo.project.name );
		// Let late requests (search index, lazy scripts) happen.
		await page.waitForLoadState( 'networkidle' );
		expect( foreign, 'requests to other hosts' ).toEqual( [] );
	} );
}
