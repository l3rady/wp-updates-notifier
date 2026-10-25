// Update 1.6.0 to 2.0.0 with WP-CLI (#89, #90): `wp plugin update`, which runs WordPress's Plugin_Upgrader (download,
// unpack, replace the files) on the package that a faked update offer points at, as WP-CLI does for a real offer.
const { test, expect } = require( '@playwright/test' );
const h = require( '../../specs/helpers' );
const u = require( './upgrade-helpers' );

test( 'wp plugin update leaves 2.0.0 deactivated, and activating it again keeps the settings', async ( { page, request } ) => {
	await u.freshSite( request );

	const update = await h.wpResult( request, 'plugin', 'update', 'wp-updates-notifier' );
	expect( update.code ).toBe( 0 );
	expect( update.stdout ).toContain( 'wp-updates-notifier\t1.6.0\t2.0.0\tUpdated' );

	await u.expectUpdatedAndInactive( request );
	// WP-CLI leaves the old basename in active_plugins; the first admin page load drops it. Either way the plugin is
	// not running, and the Plugins screen loads.
	await page.goto( '/wp-admin/plugins.php' );
	await expect( page.locator( '#wpbody-content h1' ) ).toContainText( 'Plugins' );
	expect( JSON.parse( await h.wp( request, 'option', 'get', 'active_plugins', '--format=json' ) ) ).not.toContain( u.OLD );

	await u.reactivateAndExpectSettingsKept( request, page );
} );
