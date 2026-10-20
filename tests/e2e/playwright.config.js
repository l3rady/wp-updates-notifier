// Playwright settings for `make a11y` (tests/e2e/run.sh). Runs inside the Playwright image, on the compose network.
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './specs',
	outputDir: './reports/results',
	globalSetup: require.resolve( './specs/global-setup.js' ),
	// The checks only read, apart from the failed save, which stores one transient for the user. One worker keeps the
	// output in a fixed order.
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ], [ 'html', { outputFolder: './reports/html', open: 'never' } ] ],
	use: {
		baseURL: process.env.E2E_BASE_URL || 'http://wordpress:8080',
		storageState: './reports/auth.json',
		// A desktop width: core's list tables hide their secondary columns and show a toggle button below 782px.
		viewport: { width: 1280, height: 900 },
		trace: 'off',
	},
} );
