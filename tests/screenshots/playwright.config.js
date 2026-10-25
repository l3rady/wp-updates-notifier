// Playwright settings for `make screenshots` (tests/screenshots/run.sh). Runs inside the Playwright image, on the
// compose network. The packages and the login are the ones of `make a11y` (tests/e2e).
const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: '.',
	testMatch: 'screenshots.spec.js',
	outputDir: '/tmp/wpun-shots-results',
	globalSetup: path.resolve( __dirname, '../e2e/specs/global-setup.js' ),
	// The order of the files matters (the screenshots are numbered), so one worker, in the order of the file.
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.E2E_BASE_URL || 'http://example.com:8080',
		storageState: '/tmp/wpun-shots-auth.json',
		// A fixed size and scale, so the files do not depend on the machine. 1280 is a desktop width.
		viewport: { width: 1280, height: 800 },
		deviceScaleFactor: 1,
		trace: 'off',
	},
} );
