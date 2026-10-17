/**
 * Behavioural test for the service worker's cache rules.
 *
 * Loads service-worker.js into a stubbed ServiceWorkerGlobalScope, dispatches
 * synthetic fetch/activate/notificationclick events, and asserts what actually
 * ends up in Cache Storage.
 *
 * No dependencies: plain Node.
 *
 * Usage:
 *   node tests/js/service-worker.test.js                 # the shipped file
 *   node tests/js/service-worker.test.js --previous      # the version in git HEAD
 *   node tests/js/service-worker.test.js path/to/sw.js   # any file
 *
 * --previous is what makes this a regression test: the revision before the
 * caching fix fails 10 of these assertions, including opening a cross-origin URL
 * from a notification click.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );
const { execFileSync } = require( 'child_process' );

const ROOT = path.resolve( __dirname, '..', '..' );
const SW_REL = 'plugins/layar-katalog-core/service-worker.js';
const ORIGIN = 'https://example.test';

const arg = process.argv[2];
let source;
let label;

if ( '--previous' === arg ) {
	source = execFileSync( 'git', [ 'show', `HEAD:${SW_REL}` ], { cwd: ROOT, encoding: 'utf8' } );
	label = `${SW_REL} (git HEAD)`;
} else if ( arg ) {
	source = fs.readFileSync( arg, 'utf8' );
	label = arg;
} else {
	source = fs.readFileSync( path.join( ROOT, SW_REL ), 'utf8' );
	label = SW_REL;
}

function makeResponse( opts ) {
	const o = Object.assign( { status: 200, type: 'basic', headers: {} }, opts || {} );
	const lower = {};
	Object.keys( o.headers ).forEach( ( k ) => {
		lower[ k.toLowerCase() ] = o.headers[ k ];
	} );
	return {
		ok: o.status >= 200 && o.status < 300,
		status: o.status,
		type: o.type,
		headers: { get: ( n ) => ( String( n ).toLowerCase() in lower ? lower[ String( n ).toLowerCase() ] : null ) },
		clone() {
			return this;
		},
	};
}

function makeEnv( responseForUrl, existingCaches ) {
	const listeners = {};
	const cacheStore = new Map();
	const openedWindows = [];
	const deletedCaches = [];

	const cache = {
		put: async ( req, res ) => {
			cacheStore.set( typeof req === 'string' ? req : req.url, res );
		},
		match: async ( req ) => cacheStore.get( typeof req === 'string' ? req : req.url ),
		addAll: async () => {
			throw new Error( 'offline page missing' );
		},
	};

	const self = {
		location: new URL( `${ORIGIN}/service-worker.js` ),
		addEventListener: ( type, fn ) => {
			( listeners[ type ] = listeners[ type ] || [] ).push( fn );
		},
		skipWaiting() {},
		registration: { scope: `${ORIGIN}/`, showNotification: async () => {} },
		clients: {
			claim() {},
			matchAll: async () => [],
			openWindow: async ( url ) => {
				openedWindows.push( url );
				return null;
			},
		},
	};

	const sandbox = {
		self,
		caches: {
			open: async () => cache,
			keys: async () => existingCaches.slice(),
			delete: async ( name ) => {
				deletedCaches.push( name );
				return true;
			},
			match: async () => undefined,
		},
		fetch: async ( req ) => responseForUrl( typeof req === 'string' ? req : req.url ),
		URL,
		Promise,
		setTimeout,
		clearTimeout,
		console,
		RegExp,
		Object,
		Array,
		String,
		Number,
		Boolean,
		Error,
		Math,
		JSON,
	};
	sandbox.globalThis = sandbox;
	vm.createContext( sandbox );
	vm.runInContext( source, sandbox, { filename: label } );

	return { listeners, cacheStore, openedWindows, deletedCaches, sandbox };
}

const flush = async () => {
	for ( let i = 0; i < 6; i++ ) {
		// Let the fire-and-forget cache writes settle.
		// eslint-disable-next-line no-await-in-loop
		await new Promise( ( r ) => setTimeout( r, 0 ) );
	}
};

async function runFetch( env, url, mode ) {
	const event = {
		request: { method: 'GET', url, mode: mode || 'navigate', headers: { get: () => null } },
		responded: null,
		respondWith( p ) {
			this.responded = p;
		},
		waitUntil( p ) {
			this.wait = p;
		},
	};
	env.listeners.fetch[ 0 ]( event );
	const response = event.responded ? await event.responded : null;
	await flush();
	return response;
}

let failures = 0;
let passes = 0;
function assert( label, cond, detail ) {
	if ( cond ) {
		passes++;
	} else {
		failures++;
	}
	console.log( `${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? ` — ${detail}` : ''}` );
}

/** Assert a handler ignored the event entirely (never called respondWith). */
function assertIgnored( env, label, request ) {
	const event = {
		request,
		used: false,
		respondWith() {
			this.used = true;
		},
	};
	env.listeners.fetch[ 0 ]( event );
	assert( label, ! event.used );
}

async function main() {
	console.log( `\n=== ${label} ===` );
	const CURRENT = /CACHE_NAME\s*=\s*'([^']+)'/.exec( source );
	const cacheName = CURRENT ? CURRENT[1] : 'unknown';
	console.log( `cache name: ${cacheName}\n` );

	let env = makeEnv( () => makeResponse( { headers: { 'Cache-Control': 'public, max-age=60' } } ) );
	await runFetch( env, `${ORIGIN}/katalog/`, 'navigate' );
	assert( 'anonymous public HTML is cached', env.cacheStore.has( `${ORIGIN}/katalog/` ) );

	env = makeEnv( () => makeResponse( { headers: { 'X-LK-Personalized': '1' } } ) );
	await runFetch( env, `${ORIGIN}/katalog/`, 'navigate' );
	assert( 'X-LK-Personalized response is NOT cached', ! env.cacheStore.has( `${ORIGIN}/katalog/` ) );

	env = makeEnv( () => makeResponse( { headers: { 'Cache-Control': 'private, no-store, max-age=0' } } ) );
	await runFetch( env, `${ORIGIN}/katalog/`, 'navigate' );
	assert( 'private/no-store response is NOT cached', ! env.cacheStore.has( `${ORIGIN}/katalog/` ) );

	env = makeEnv( () => makeResponse( { status: 404 } ) );
	await runFetch( env, `${ORIGIN}/missing/`, 'navigate' );
	assert( '404 response is NOT cached', ! env.cacheStore.has( `${ORIGIN}/missing/` ) );

	env = makeEnv( () => makeResponse( { status: 500 } ) );
	await runFetch( env, `${ORIGIN}/broken/`, 'navigate' );
	assert( '500 response is NOT cached', ! env.cacheStore.has( `${ORIGIN}/broken/` ) );

	env = makeEnv( () => makeResponse( { type: 'opaque' } ) );
	await runFetch( env, `${ORIGIN}/asset.js`, 'no-cors' );
	assert( 'opaque (cross-origin) response is NOT cached', ! env.cacheStore.has( `${ORIGIN}/asset.js` ) );

	env = makeEnv( () => makeResponse( {} ) );
	await runFetch( env, `${ORIGIN}/episode/ep-1/?preview=true`, 'navigate' );
	assert( 'preview URL is NOT cached', ! env.cacheStore.has( `${ORIGIN}/episode/ep-1/?preview=true` ) );

	env = makeEnv( () => makeResponse( {} ) );
	await runFetch( env, `${ORIGIN}/?customize_changeset_uuid=abc`, 'navigate' );
	assert( 'customizer session URL is NOT cached', ! env.cacheStore.has( `${ORIGIN}/?customize_changeset_uuid=abc` ) );

	env = makeEnv( () => makeResponse( {} ) );
	await runFetch( env, `${ORIGIN}/wp-content/themes/layar-katalog/style.css`, 'no-cors' );
	assert( 'static CSS asset is cached', env.cacheStore.has( `${ORIGIN}/wp-content/themes/layar-katalog/style.css` ) );

	env = makeEnv( () => makeResponse( {} ) );
	assertIgnored( env, 'POST requests are ignored', { method: 'POST', url: `${ORIGIN}/wp-json/lkc/v1/push/subscribe`, mode: 'cors' } );
	assertIgnored( env, 'REST API requests are ignored', { method: 'GET', url: `${ORIGIN}/wp-json/lkc/v1/catalog/search`, mode: 'cors' } );
	assertIgnored( env, 'wp-admin requests are ignored', { method: 'GET', url: `${ORIGIN}/wp-admin/edit.php`, mode: 'navigate' } );
	assertIgnored( env, 'cross-origin requests are ignored', { method: 'GET', url: 'https://cdn.example.com/a.js', mode: 'no-cors' } );

	env = makeEnv( () => makeResponse( {} ) );
	const evilClick = {
		notification: { close() {}, data: { url: 'https://evil.test/phish' } },
		waitUntil( p ) {
			this.wait = p;
		},
	};
	env.listeners.notificationclick[ 0 ]( evilClick );
	await evilClick.wait;
	await flush();
	assert(
		'cross-origin notification URL is not opened',
		-1 === env.openedWindows.indexOf( 'https://evil.test/phish' ),
		JSON.stringify( env.openedWindows )
	);

	env = makeEnv( () => makeResponse( {} ) );
	const okClick = {
		notification: { close() {}, data: { url: `${ORIGIN}/episode/ep-2/` } },
		waitUntil( p ) {
			this.wait = p;
		},
	};
	env.listeners.notificationclick[ 0 ]( okClick );
	await okClick.wait;
	await flush();
	assert(
		'same-origin notification URL is kept',
		0 === env.openedWindows.length || `${ORIGIN}/episode/ep-2/` === env.openedWindows[ 0 ],
		JSON.stringify( env.openedWindows )
	);

	env = makeEnv( () => makeResponse( {} ), [ 'lk-shell-v1', cacheName, 'other-plugin-cache' ] );
	const activateEvent = {
		waitUntil( p ) {
			this.wait = p;
		},
	};
	env.listeners.activate[ 0 ]( activateEvent );
	await activateEvent.wait;
	assert( 'activate purges stale lk-shell-v1 cache', -1 !== env.deletedCaches.indexOf( 'lk-shell-v1' ), JSON.stringify( env.deletedCaches ) );
	assert( 'activate keeps the current cache', -1 === env.deletedCaches.indexOf( cacheName ), JSON.stringify( env.deletedCaches ) );
	assert( "activate leaves other plugins' caches alone", -1 === env.deletedCaches.indexOf( 'other-plugin-cache' ), JSON.stringify( env.deletedCaches ) );

	console.log( `\n${passes} passed, ${failures} failed` );
	process.exitCode = failures ? 1 : 0;
}

main().catch( ( e ) => {
	console.error( e );
	process.exitCode = 1;
} );
