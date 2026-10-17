/**
 * Syntax-check every JavaScript file the project ships.
 *
 * Uses `node --check`, so it catches exactly what a browser would refuse to
 * parse. Plain Node, no dependencies.
 *
 * Usage: node tests/lint-js.mjs
 */
import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const skipDirs = new Set( [ '.git', 'vendor', 'node_modules' ] );

function collect( dir, out = [] ) {
	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		if ( skipDirs.has( entry.name ) ) {
			continue;
		}
		const abs = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			collect( abs, out );
		} else if ( entry.name.endsWith( '.js' ) || entry.name.endsWith( '.mjs' ) ) {
			out.push( abs );
		}
	}
	return out;
}

const files = collect( root ).sort();
let failed = 0;
for ( const file of files ) {
	try {
		execFileSync( process.execPath, [ '--check', file ], { stdio: 'pipe' } );
		console.log( `OK    ${path.relative( root, file )}` );
	} catch ( error ) {
		failed++;
		console.log( `FAIL  ${path.relative( root, file )}` );
		console.log( String( error.stderr || error.message ).trim().split( '\n' ).map( ( l ) => `        ${l}` ).join( '\n' ) );
	}
}
console.log( `\n${files.length - failed}/${files.length} file(s) parsed cleanly` );
process.exit( failed ? 1 : 0 );
