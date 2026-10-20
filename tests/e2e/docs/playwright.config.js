// Playwright settings for `make docs-a11y` (tests/e2e/docs/run.sh): checks of the built documentation site, with no
// WordPress. Runs inside the Playwright image, with build/docs mounted at /site. The site is checked in both colour
// schemes: the palette toggle starts in its automatic state, which follows the browser's colour scheme.
const { defineConfig } = require( '@playwright/test' );

const PORT = 4173;

module.exports = defineConfig( {
	testDir: './specs',
	outputDir: '../reports/docs/results',
	workers: 2,
	retries: 0,
	reporter: [ [ 'list' ], [ 'html', { outputFolder: '../reports/docs/html', open: 'never' } ] ],
	webServer: {
		command: 'node server.js',
		cwd: __dirname,
		url: `http://127.0.0.1:${ PORT }/`,
		reuseExistingServer: false,
		env: { PORT: String( PORT ), SITE_DIR: process.env.SITE_DIR || '/site' },
	},
	use: {
		baseURL: `http://127.0.0.1:${ PORT }`,
		viewport: { width: 1280, height: 900 },
		trace: 'off',
	},
	projects: [
		{ name: 'dark', use: { colorScheme: 'dark' } },
		{ name: 'light', use: { colorScheme: 'light' } },
	],
} );
