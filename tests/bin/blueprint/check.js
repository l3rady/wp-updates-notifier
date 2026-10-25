// Validates a generated blueprint (`make check-blueprint`): against the Playground blueprint JSON schema in this
// directory, and against the rules of this plugin's demo.
//
//   node check.js <blueprint.json> [--schema <schema file>]
//
// The schema is vendored (see README.md) and the run needs no network. Exits 1 and lists every problem it finds.
const fs = require( 'fs' );
const path = require( 'path' );
const Ajv = require( 'ajv' );

const LANDING_PAGE = '/wp-admin/options-general.php?page=wp-updates-notifier';

const args = process.argv.slice( 2 );
const schemaAt = args.indexOf( '--schema' );
const schemaFile = schemaAt >= 0 ? args.splice( schemaAt, 2 )[ 1 ] : path.join( __dirname, 'blueprint-schema.json' );
if ( 1 !== args.length ) {
	console.error( 'Usage: node check.js <blueprint.json> [--schema <schema file>]' );
	process.exit( 2 );
}

const text = fs.readFileSync( args[ 0 ], 'utf8' );
const problems = [];
let blueprint;
try {
	blueprint = JSON.parse( text );
} catch ( error ) {
	console.error( `FAIL: ${ args[ 0 ] } is not JSON: ${ error.message }` );
	process.exit( 1 );
}

// The schema's $schema URL is not a meta-schema ajv knows, and discriminator and deprecated are OpenAPI-style
// annotations that ajv must be told to accept.
const schema = JSON.parse( fs.readFileSync( schemaFile, 'utf8' ) );
delete schema.$schema;
const ajv = new Ajv( { allErrors: true, strict: false, discriminator: true } );
const validate = ajv.compile( schema );
if ( ! validate( blueprint ) ) {
	for ( const error of validate.errors.slice( 0, 30 ) ) {
		problems.push( `schema: ${ error.instancePath || '/' } ${ error.message }` );
	}
}

if ( blueprint.landingPage !== LANDING_PAGE ) {
	problems.push( `the landing page is ${ JSON.stringify( blueprint.landingPage ) }, not the settings page ${ LANDING_PAGE }` );
}
if ( text.includes( '@file:' ) ) {
	problems.push( 'a @file: placeholder is left in the blueprint' );
}
const steps = Array.isArray( blueprint.steps ) ? blueprint.steps : [];
if ( ! steps.some( ( s ) => s && 'installPlugin' === s.step ) ) {
	problems.push( 'no installPlugin step' );
}
if ( ! steps.some( ( s ) => s && 'runPHP' === s.step && /sc_wpun_run_check/.test( s.code ) ) ) {
	problems.push( 'no runPHP step runs the update check (sc_wpun_run_check)' );
}
if ( /\bpro\b/i.test( text ) ) {
	problems.push( 'the blueprint mentions "pro"' );
}

if ( problems.length ) {
	console.error( `FAIL: ${ args[ 0 ] }` );
	for ( const problem of problems ) {
		console.error( `  - ${ problem }` );
	}
	process.exit( 1 );
}
console.log( `ok: ${ args[ 0 ] } is a valid Playground blueprint (${ steps.length } steps)` );
