// Playwright settings for `make e2e-upgrade` (tests/flows/upgrade/run.sh). Runs inside the Playwright image, on the
// compose network. The packages and the login are the ones of `make a11y` (tests/e2e).
const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './specs',
	testMatch: '*.spec.js',
	outputDir: '/tmp/wpun-upgrade-results',
	globalSetup: path.resolve( __dirname, '../../e2e/specs/global-setup.js' ),
	// One site, put back to a fresh 1.6.0 by every test, so one at a time.
	workers: 1,
	fullyParallel: false,
	retries: 0,
	timeout: 120000,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.E2E_BASE_URL || 'http://wordpress:8080',
		storageState: '/tmp/wpun-upgrade-auth.json',
		viewport: { width: 1280, height: 900 },
		trace: 'off',
	},
} );
