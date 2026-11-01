// Playwright settings for `make e2e` (tests/flows/run.sh). Runs inside the Playwright image, on the compose network.
// The packages and the login are the ones of `make a11y` (tests/e2e).
const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );

// `make e2e-ms` (FLOWS_MULTISITE=1) runs only the network spec, on a multisite; `make e2e` runs all the others.
const multisite = !! process.env.FLOWS_MULTISITE;

module.exports = defineConfig( {
	testDir: './specs',
	testMatch: '*.spec.js',
	testIgnore: multisite ? [ '**/!(network).spec.js' ] : [ '**/network.spec.js' ],
	outputDir: '/tmp/wpun-flows-results',
	globalSetup: path.resolve( __dirname, '../e2e/specs/global-setup.js' ),
	// The tests share one site and one mailbox, and each starts by putting both back (helpers.resetSite), so they
	// run one at a time. Each file can run alone and in any order.
	workers: 1,
	fullyParallel: false,
	retries: 0,
	timeout: 30000,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.E2E_BASE_URL || 'http://wordpress:8080',
		storageState: '/tmp/wpun-flows-auth.json',
		viewport: { width: 1280, height: 900 },
		trace: 'off',
	},
} );
