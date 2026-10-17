/**
 * Run the PHP security tests without a local PHP install.
 *
 * Boots a real PHP build compiled to WebAssembly, copies the files the tests
 * need into its filesystem, and runs tests/php/security.test.php unchanged.
 *
 * Usage:  node tests/php/run-phpwasm.mjs [phpVersion]
 *         (phpVersion defaults to 8.2, the plugin's minimum)
 *
 * Requires `npm install` inside tests/ first. If you do have PHP 8.2+, prefer
 * the faster path: `php tests/php/security.test.php`.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const root = path.resolve( here, '..', '..' );
const mirror = '/tmp/repo';

const needed = [
	'tests/php/bootstrap.php',
	'tests/php/security.test.php',
	'plugins/layar-katalog-core/includes/security.php',
	'plugins/layar-katalog-core/includes/audit.php',
	'plugins/layar-katalog-core/includes/web-push.php',
	'plugins/layar-katalog-core/layar-katalog-core.php',
	'themes/layar-katalog/functions.php',
];

const version = process.argv[2] || '8.2';
const php = new PHP(
	await loadNodeRuntime( version, { emscriptenOptions: { processId: 4242 } } )
);

const prelude = [ '<?php', `define( 'LKC_TEST_ROOT', '${mirror}' );` ];
for ( const rel of needed ) {
	const abs = path.join( root, rel );
	if ( ! fs.existsSync( abs ) ) {
		console.error( `missing required file: ${rel}` );
		process.exit( 1 );
	}
	const b64 = fs.readFileSync( abs ).toString( 'base64' );
	prelude.push(
		`@mkdir( dirname( '${mirror}/${rel}' ), 0777, true );`,
		`file_put_contents( '${mirror}/${rel}', base64_decode( ${JSON.stringify( b64 )} ) );`
	);
}
prelude.push( `require '${mirror}/tests/php/security.test.php';` );

const out = await php.runStream( { code: prelude.join( '\n' ) } );
const stdout = await out.stdoutText;
const stderr = await out.stderrText;

process.stdout.write( stdout );
if ( stderr.trim() ) {
	process.stdout.write( '\n--- stderr ---\n' + stderr );
}

const failed = /(\d+) passed, (\d+) failed/.exec( stdout );
const ok = failed ? Number( failed[2] ) === 0 && stdout.includes( 'passed' ) : false;
process.exit( ok ? 0 : 1 );
