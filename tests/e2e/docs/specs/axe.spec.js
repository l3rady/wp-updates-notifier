// axe over every built page, in both colour schemes (the dark and light projects). The whole page is scanned, not just
// the content: header, navigation, footer, code blocks and diagrams. WCAG 2.2 A and AA, no rule disabled.
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { TAGS, describe } = require( '../../specs/helpers' );
const { listPages, open } = require( './site' );

const pages = listPages();

test( 'the site has pages to check', () => {
	expect( pages.length ).toBeGreaterThan( 5 );
} );

for ( const [ rel, urlPath ] of pages ) {
	test( `axe: ${ rel }`, async ( { page }, testInfo ) => {
		await open( page, urlPath, rel, testInfo.project.name );
		const { violations } = await new AxeBuilder( { page } ).withTags( TAGS ).analyze();
		expect( describe( violations ), 'axe violations' ).toBe( '' );
	} );
}
