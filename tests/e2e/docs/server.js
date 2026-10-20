// A static file server for the built documentation site, with no dependencies: Playwright starts it (webServer in
// playwright.config.js). Serves SITE_DIR the way GitHub Pages does: a directory is its index.html, a missing file is
// 404.html with status 404. Reads only; nothing outside SITE_DIR is reachable.
const http = require( 'http' );
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( process.env.SITE_DIR || '/site' );
const PORT = Number( process.env.PORT || 4173 );

const TYPES = {
	'.html': 'text/html; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.json': 'application/json; charset=utf-8',
	'.svg': 'image/svg+xml',
	'.png': 'image/png',
	'.xml': 'application/xml',
	'.map': 'application/json',
};

function resolve( urlPath ) {
	let file = path.join( ROOT, path.normalize( decodeURIComponent( urlPath ) ) );
	if ( ! file.startsWith( ROOT ) ) {
		return null;
	}
	if ( fs.existsSync( file ) && fs.statSync( file ).isDirectory() ) {
		file = path.join( file, 'index.html' );
	}
	return fs.existsSync( file ) ? file : null;
}

http.createServer( ( req, res ) => {
	const file = resolve( new URL( req.url, 'http://localhost' ).pathname );
	const status = file ? 200 : 404;
	const body = file || path.join( ROOT, '404.html' );
	res.writeHead( status, { 'Content-Type': TYPES[ path.extname( body ) ] || 'application/octet-stream' } );
	fs.createReadStream( body ).pipe( res );
} ).listen( PORT, '127.0.0.1', () => console.log( `serving ${ ROOT } on http://127.0.0.1:${ PORT }` ) );
