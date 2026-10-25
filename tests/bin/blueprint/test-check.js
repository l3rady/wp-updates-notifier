// Tests check.js: it must accept the generated blueprint and reject each way of breaking it, or `make check-blueprint`
// could pass while validating nothing. Run by `make test-blueprint`, after `make blueprint` wrote build/blueprint.json.
//
//   node test-check.js <blueprint.json>
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const good = JSON.parse( fs.readFileSync( process.argv[ 2 ], 'utf8' ) );
const work = fs.mkdtempSync( path.join( os.tmpdir(), 'wpun-blueprint-' ) );
let failures = 0;

// The blueprint changed by `edit`, run through check.js. `expected` is a message that must be in the output, or null
// when the blueprint must pass.
function run( name, edit, expected ) {
	const copy = JSON.parse( JSON.stringify( good ) );
	edit( copy );
	const file = path.join( work, 'blueprint.json' );
	fs.writeFileSync( file, JSON.stringify( copy ) );
	const result = spawnSync( 'node', [ path.join( __dirname, 'check.js' ), file ], { encoding: 'utf8' } );
	const output = result.stdout + result.stderr;
	const ok = null === expected ? 0 === result.status : 1 === result.status && output.includes( expected );
	console.log( `${ ok ? 'ok' : 'FAIL' }: ${ name }` );
	if ( ! ok ) {
		failures++;
		console.log( output );
	}
}

const step = ( b, name ) => b.steps.find( ( s ) => s.step === name );

run( 'the generated blueprint', () => {}, null );
run( 'a misspelt step', ( b ) => { step( b, 'writeFile' ).step = 'writefile'; }, 'schema:' );
run( 'an unknown property of a step', ( b ) => { step( b, 'login' ).colour = 'blue'; }, 'schema:' );
run( 'a step without its required property', ( b ) => { delete step( b, 'writeFile' ).path; }, 'schema:' );
run( 'a property of the wrong type', ( b ) => { step( b, 'mkdir' ).path = 5; }, 'schema:' );
run( 'an unknown top-level property', ( b ) => { b.colour = 'blue'; }, 'schema:' );
run( 'another landing page', ( b ) => { b.landingPage = '/wp-admin/'; }, 'landing page' );
run( 'a placeholder left in', ( b ) => { step( b, 'writeFile' ).data = '@file:fake-updates.php'; }, '@file:' );
run( 'no update check', ( b ) => { step( b, 'runPHP' ).code = '<?php'; }, 'update check' );

fs.rmSync( work, { recursive: true, force: true } );
if ( failures ) {
	process.exit( 1 );
}
console.log( 'OK' );
