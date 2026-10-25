// Update 1.6.0 to 2.0.0 from the Plugins screen (#89, #90): "update now" in the browser, which runs WordPress's updater
// through admin-ajax on the package that a faked update offer points at.
const { test, expect } = require( '@playwright/test' );
const h = require( '../../specs/helpers' );
const u = require( './upgrade-helpers' );

test( 'update now leaves 2.0.0 deactivated with the core notice, and activating it again keeps the settings', async ( { page, request } ) => {
	await u.freshSite( request );

	await page.goto( '/wp-admin/plugins.php' );
	const offer = page.locator( `tr.plugin-update-tr[data-plugin="${ u.OLD }"]` );
	await expect( offer ).toContainText( 'There is a new version of WP Updates Notifier available' );
	await offer.locator( 'a.update-link' ).click();
	// The page does not reload: wait for the updater's own result, and that 2.0.0 is on disk.
	// (While WordPress swaps the files the plugin is briefly missing, so a failed lookup is just not yet.)
	await expect
		.poll( async () => ( await h.wpResult( request, 'plugin', 'get', 'wp-updates-notifier', '--field=version' ) ).stdout.trim(), { timeout: 60000 } )
		.toBe( '2.0.0' );
	// ... and the updater has finished: maintenance mode is over.
	await expect
		.poll( () => h.wp( request, 'eval', 'echo file_exists( ABSPATH . ".maintenance" ) ? "yes" : "no";' ), { timeout: 30000 } )
		.toBe( 'no' );

	// The next Plugins screen load: WordPress finds the old file gone, deactivates the plugin and says so.
	await page.goto( '/wp-admin/plugins.php' );
	await expect( page.locator( '.notice-error, .error' ).filter( { hasText: 'has been deactivated due to an error' } ) ).toContainText(
		`The plugin ${ u.OLD } has been deactivated due to an error: Plugin file does not exist.`
	);
	await expect( page.locator( `tr[data-plugin="${ u.NEW }"]` ).first() ).toHaveClass( /inactive/ );
	expect( JSON.parse( await h.wp( request, 'option', 'get', 'active_plugins', '--format=json' ) ) ).not.toContain( u.OLD );

	await u.expectUpdatedAndInactive( request );
	await u.reactivateAndExpectSettingsKept( request, page );
} );
