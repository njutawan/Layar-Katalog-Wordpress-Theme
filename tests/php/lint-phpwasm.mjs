/**
 * Parse-check every project PHP file without a local PHP install.
 *
 * Uses token_get_all( $src, TOKEN_PARSE ), which throws ParseError on a syntax
 * error, inside a real PHP build compiled to WebAssembly. This is the check that
 * caught a fatal parse error in the plugin's main file (finding F-15 in
 * docs/SECURITY-AUDIT.md): the plugin could not be activated at all, and nothing
 * else in the repository would have noticed.
 *
 * Usage:  node tests/php/lint-phpwasm.mjs [phpVersion] [file...]
 *         (no files = every .php under the project, excluding vendor/)
 *
 * If you have PHP 8.2+ installed, `php -l` is faster and CI uses that instead.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const root = path.resolve( here, '..', '..' );

function collectPhpFiles( dir, out = [] ) {
	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		if ( entry.name === '.git' || entry.name === 'vendor' || entry.name === 'node_modules' ) {
			continue;
		}
		const abs = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			collectPhpFiles( abs, out );
		} else if ( entry.name.endsWith( '.php' ) ) {
			out.push( abs );
		}
	}
	return out;
}

const args = process.argv.slice( 2 );
const version = args[0] && /^\d/.test( args[0] ) ? args.shift() : '8.2';
let files = args.length ? args.map( ( f ) => path.resolve( f ) ) : collectPhpFiles( root );
files = files.filter( ( f ) => ! f.includes( `${path.sep}vendor${path.sep}` ) ).sort();

const php = new PHP(
	await loadNodeRuntime( version, { emscriptenOptions: { processId: 4321 } } )
);
const boot = await php.runStream( { code: '<?php echo PHP_VERSION;' } );
console.log( `PHP runtime: ${await boot.stdoutText}` );
console.log( `Checking ${files.length} file(s)\n` );

let failed = 0;
for ( const file of files ) {
	const b64 = fs.readFileSync( file ).toString( 'base64' );
	const code = `<?php
define( 'LKC_TEST_NO_EXIT', true );
$src = base64_decode( ${JSON.stringify( b64 )} );
try {
    $tokens = token_get_all( $src, TOKEN_PARSE );
    echo 'SYNTAX_OK tokens=' . count( $tokens );
} catch ( Throwable $e ) {
    echo 'PARSE_ERROR line ' . $e->getLine() . ': ' . $e->getMessage();
}
`;
	const out = await php.runStream( { code } );
	const text = ( ( await out.stdoutText ) + ( await out.stderrText ) ).trim();
	const ok = text.startsWith( 'SYNTAX_OK' );
	if ( ! ok ) {
		failed++;
	}
	console.log( `${ok ? 'OK  ' : 'FAIL'}  ${path.relative( root, file )}  ${text}` );
}

console.log( failed ? `\n${failed} file(s) failed to parse` : '\nAll files parsed cleanly' );
process.exit( failed ? 1 : 0 );
