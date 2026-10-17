/**
 * The round trip a browser would make, without a browser.
 *
 * tests/php/csp-strict.test.php boots the shipped plugin and theme in the
 * WebAssembly sandbox and emits two things: the Content-Security-Policy header
 * the plugin actually sends once `lkc_csp_strict` is on, and a document assembled
 * from the markup the theme actually prints (the ad slot, an administrator's ad
 * loader, plus a page-builder style attribute and an inline handler as controls).
 *
 * This file parses that document with jsdom and judges it with the CSP matcher
 * from csp-audit.mjs - the same matcher the pre-flight auditor runs against a
 * staging URL - so the header and the markup are checked against each other
 * rather than each against a test's own idea of what the other contains.
 *
 * Run with: node tests/js/csp-strict-roundtrip.test.js
 */

import nodeAssert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { auditDocument, parsePolicy } from './csp-audit.mjs';

const { equal, deepEqual, ok, match } = nodeAssert;

let passed = 0;
let failed = 0;

/**
 * Run one assertion, catching the AssertionError so the whole file still reports.
 *
 * @param {string}   label What is being asserted.
 * @param {Function} fn    Throws when the assertion does not hold.
 * @returns {void}
 */
function check( label, fn ) {
	try {
		fn();
		passed += 1;
		process.stdout.write( `PASS  ${label}\n` );
	} catch ( error ) {
		failed += 1;
		process.stdout.write( `FAIL  ${label}\n        ${String( error.message ).split( '\n' ).slice( 0, 3 ).join( ' ' )}\n` );
	}
}

/**
 * Boot the sandbox and take the emitter's payload.
 *
 * @returns {object} nonce, document_url, html and four header variants.
 */
function emitFromPhp() {
	const here = path.dirname( fileURLToPath( import.meta.url ) );
	const repo = path.resolve( here, '..', '..' );
	const stdout = execFileSync(
		process.execPath,
		[ 'tests/php/run-phpwasm.mjs', '8.2', 'tests/php/csp-strict.test.php' ],
		{ cwd: repo, encoding: 'utf8' }
	);
	const marker = 'CSP_ROUNDTRIP_JSON=';
	const line = stdout.split( '\n' ).find( ( candidate ) => candidate.startsWith( marker ) );
	ok( line, `the emitter printed ${marker}` );
	ok( /0 failed/.test( stdout ), 'the emitter own assertions all passed' );
	return JSON.parse( line.slice( marker.length ) );
}

const payload = emitFromPhp();

/**
 * Everything the given header would refuse to load.
 *
 * @param {string} header  A Content-Security-Policy value.
 * @param {object} options Extra auditDocument options.
 * @returns {Array<object>} Blocked resources.
 */
function blockedBy( header, options = {} ) {
	return auditDocument( payload.html, header, { documentUrl: payload.document_url, ...options } ).blocked;
}

/**
 * One resource of the document, by kind.
 *
 * @param {string} header A Content-Security-Policy value.
 * @param {string} kind   Resource kind from scanHtml().
 * @param {number} index  Which one, when the document has several.
 * @returns {object} The audited resource.
 */
function resource( header, kind, index = 0 ) {
	const audit = auditDocument( payload.html, header, { documentUrl: payload.document_url } );
	const found = audit.resources.filter( ( candidate ) => candidate.kind === kind );
	ok( found.length > index, `the document contains a ${kind}` );
	return found[ index ];
}

process.stdout.write( '\n--- the header the plugin emits ---\n' );

check( 'the strict header is one line with no stray whitespace', () => {
	equal( payload.header_strict.trim(), payload.header_strict );
	match( payload.header_strict, /^[^\n]+$/ );
} );

check( 'script-src is the site itself plus the request nonce, and nothing else', () => {
	deepEqual( parsePolicy( payload.header_strict ).get( 'script-src' ), [ "'self'", `'nonce-${payload.nonce}'` ] );
} );

check( 'style-src is the site itself plus the same nonce', () => {
	deepEqual( parsePolicy( payload.header_strict ).get( 'style-src' ), [ "'self'", `'nonce-${payload.nonce}'` ] );
} );

check( "the one concession is style-src-attr 'unsafe-inline'", () => {
	deepEqual( parsePolicy( payload.header_strict ).get( 'style-src-attr' ), [ "'unsafe-inline'" ] );
} );

check( 'script-src-attr is not loosened, so inline event handlers stay blocked', () => {
	equal( parsePolicy( payload.header_strict ).has( 'script-src-attr' ), false );
	equal( parsePolicy( payload.header_strict ).has( 'style-src-elem' ), false );
} );

check( 'the nonce in the header is the one the theme stamps into the markup', () => {
	ok( payload.html.includes( `nonce="${payload.nonce}"` ), 'no stamped element carries that nonce' );
} );

check( 'a site that prints no style attributes can drop the concession', () => {
	equal( parsePolicy( payload.header_locked ).has( 'style-src-attr' ), false );
	equal(
		payload.header_strict.replace( "; style-src-attr 'unsafe-inline'", '' ),
		payload.header_locked,
		'the concession is the only difference between the two headers'
	);
} );

check( 'an allowlist snippet adds its host without weakening anything else', () => {
	const policy = parsePolicy( payload.header_allowlisted );
	ok( policy.get( 'script-src' ).includes( 'https://pagead2.googlesyndication.com' ) );
	ok( policy.get( 'script-src' ).includes( `'nonce-${payload.nonce}'` ) );
	equal( policy.get( 'script-src' ).includes( "'unsafe-inline'" ), false );
	deepEqual( policy.get( 'style-src-attr' ), [ "'unsafe-inline'" ], 'pasting a snippet did not disturb the concession' );
} );

process.stdout.write( '\n--- the document, judged by that header ---\n' );

check( 'everything the theme and plugin print is allowed by the strict header', () => {
	const blocked = blockedBy( payload.header_strict );
	deepEqual(
		blocked.filter( ( item ) => !item.url || !item.url.includes( 'pagead2' ) ).map( ( item ) => item.kind ),
		[ 'inline-handler' ],
		'the controls are the only things left, plus the ad host the operator has not allowlisted yet'
	);
} );

check( "the ad slot's nonce'd <style> element is allowed", () => {
	const style = resource( payload.header_strict, 'inline-style' );
	equal( style.allowed, true );
	match( style.reason, /nonce matches/ );
} );

check( "the administrator's inline ad script is allowed by the same nonce", () => {
	const script = resource( payload.header_strict, 'inline-script' );
	equal( script.allowed, true );
	match( script.reason, /nonce matches/ );
} );

check( 'the same-origin stylesheet, player script, poster image and episode video are all allowed', () => {
	const blocked = blockedBy( payload.header_strict ).map( ( item ) => item.url || item.kind );
	for ( const url of [
		'https://example.test/wp-content/themes/layar-katalog/style.css',
		'https://example.test/wp-content/plugins/layar-katalog-core/player.js',
		'https://img.example.test/poster.jpg',
		'https://example.test/wp-content/uploads/episode-1.mp4',
		'https://img.example.test/still.jpg',
	] ) {
		equal( blocked.includes( url ), false, `${url} was blocked` );
	}
} );

check( 'an external ad loader is blocked until the operator allowlists it, which is what the report-only week is for', () => {
	const blocked = blockedBy( payload.header_strict );
	equal( blocked.length, 2 );
	equal( blocked[ 0 ].kind, 'script' );
	match( blocked[ 0 ].url, /pagead2\.googlesyndication\.com/ );
} );

check( 'and allowed once the generated snippet has been pasted', () => {
	deepEqual( blockedBy( payload.header_allowlisted ).map( ( item ) => item.kind ), [ 'inline-handler' ] );
} );

check( 'a page-builder style attribute survives, because of the concession', () => {
	const attribute = resource( payload.header_strict, 'style-attribute' );
	equal( attribute.allowed, true );
	match( attribute.reason, /'unsafe-inline' is present in style-src-attr/ );
} );

check( 'and is blocked the moment the concession is switched off', () => {
	const attribute = resource( payload.header_locked, 'style-attribute' );
	equal( attribute.allowed, false );
	match( attribute.reason, /a nonce cannot cover an attribute/ );
} );

check( 'an inline event handler is blocked in strict mode whatever the concession says', () => {
	for ( const header of [ payload.header_strict, payload.header_locked, payload.header_allowlisted ] ) {
		const handler = resource( header, 'inline-handler' );
		equal( handler.allowed, false );
		match( handler.reason, /a nonce cannot cover an attribute/ );
	}
} );

check( "which is a change from today's report-only header, where the handler still runs", () => {
	equal( resource( payload.header_default, 'inline-handler' ).allowed, true );
	equal( resource( payload.header_default, 'style-attribute' ).allowed, true );
	match( resource( payload.header_default, 'inline-script' ).reason, /'unsafe-inline' is present in script-src/ );
} );

check( 'the auditor own strict lens is a different question from the plugin strict mode', () => {
	/* --strict asks what a maximally strict policy would cost, so it also flags
	   the inline markup the nonce already covers. The plugin's strict mode does
	   not: with its real header, only the two controls and the ad host fail. */
	ok( blockedBy( payload.header_default, { strict: true } ).length > blockedBy( payload.header_strict ).length );
} );

check( 'the document is what the theme printed, not a fixture written for this test', () => {
	match( payload.html, /<style nonce="[^"]+">#lk-ad-slot-content-\d+\{min-height:250px\}<\/style>/ );
	match( payload.html, /<div class="lk-ad-slot__content" id="lk-ad-slot-content-\d+">/ );
	match( payload.html, /<nav class="wp-block-navigation layarkatalog-header-nav">/ );
	equal( /style="/.test( payload.html.replace( /<style[^>]*>/g, '' ) ), true, 'the page-builder control attribute is still there' );
} );

process.stdout.write( `\n=================================\n${passed} passed, ${failed} failed\n` );
process.exit( failed > 0 ? 1 : 0 );
