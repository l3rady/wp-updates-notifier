// Playwright settings for `make blueprint-smoke` (tests/blueprint-smoke/run.sh). Runs inside the Playwright image, in the
// network of the Playground container. The packages are the ones of `make a11y` (tests/e2e).
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: '.',
	testMatch: 'smoke.spec.js',
	outputDir: '/tmp/wpun-playground-results',
	workers: 1,
	retries: 0,
	timeout: 60000,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:9400',
		viewport: { width: 1280, height: 800 },
		trace: 'off',
	},
} );
