// Update 1.6.0 to 2.0.0 as a background auto-update (#89, #90): auto-updates on for the plugin, then WordPress's own
// auto-updater (wp_maybe_auto_update(), what WP-Cron's wp_version_check runs) on the package that a faked update offer
// points at. WordPress 6.6 and later rolls an auto-updated plugin back when the site breaks; this checks that the
// renamed main file does not trigger that.
const { test, expect } = require( '@playwright/test' );
const h = require( '../../specs/helpers' );
const u = require( './upgrade-helpers' );

test( 'the auto-update leaves 2.0.0 deactivated, not rolled back, and activating it again keeps the settings', async ( { page, request } ) => {
	await u.freshSite( request );
	await h.wp( request, 'plugin', 'auto-updates', 'enable', 'wp-updates-notifier' );

	await h.wp( request, 'eval', 'wp_maybe_auto_update();' );

	// Not rolled back: 2.0.0 stays, and the updater wrote nothing to its backup folder that it kept.
	await u.expectUpdatedAndInactive( request );
	expect( JSON.parse( await h.wp( request, 'option', 'get', 'active_plugins', '--format=json' ) ) ).not.toContain( u.OLD );
	const log = await u.errorLog( request );
	expect( log ).toContain( "Plugin 'wp-updates-notifier' has been upgraded." );
	expect( log ).not.toMatch( /rolled back|rollback/i );
	// WordPress tells the site admin, as it does for any auto-updated plugin.
	const [ notice ] = await h.waitForMail( request, 1 );
	expect( notice.Subject ).toContain( 'Some plugins were automatically updated' );
	expect( notice.Text ).toContain( 'WP Updates Notifier (from version 1.6.0 to 2.0.0)' );
	await h.clearMail( request );

	await u.reactivateAndExpectSettingsKept( request, page );
} );
