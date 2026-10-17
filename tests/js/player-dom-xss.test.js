/**
 * DOM XSS regression test for the player's autoplay overlay.
 *
 * The next episode's title is author-supplied content. It used to be read with
 * textContent and then interpolated into innerHTML, which let an author-level
 * account run script in every visitor's browser (finding F-01 in
 * docs/SECURITY-AUDIT.md). These assertions feed a hostile title through the
 * real player.js and check what ends up in the DOM.
 *
 * Requires jsdom: run `npm install` inside tests/ first.
 *
 * Usage:
 *   node tests/js/player-dom-xss.test.js                # the shipped player.js
 *   node tests/js/player-dom-xss.test.js --previous     # the version in git HEAD
 *   node tests/js/player-dom-xss.test.js path/to/file   # any file
 *
 * Run both to see the differential: the pre-fix revision turns the payload into
 * a live element and accepts a javascript: target.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const ROOT = path.resolve( __dirname, '..', '..' );
const PLAYER_REL = 'plugins/layar-katalog-core/player.js';

const arg = process.argv[2];
let source;
let label;
if ( '--previous' === arg ) {
	source = execFileSync( 'git', [ 'show', `HEAD:${PLAYER_REL}` ], { cwd: ROOT, encoding: 'utf8' } );
	label = `${PLAYER_REL} (git HEAD)`;
} else if ( arg ) {
	source = fs.readFileSync( arg, 'utf8' );
	label = arg;
} else {
	source = fs.readFileSync( path.join( ROOT, PLAYER_REL ), 'utf8' );
	label = PLAYER_REL;
}

const PAYLOAD = 'Ep 2 <img src=x data-pwned="1">';

/*
 * WordPress prints an escaped title, so the markup arrives in the DOM as a text
 * node holding those exact characters. Escaping it here reproduces that: the
 * vulnerable code path re-parses the text as HTML, the fixed one does not.
 */
const PAYLOAD_HTML = PAYLOAD.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );

/** jsdom has no media playback; swallow only that complaint. */
function quietConsole() {
	const vc = new VirtualConsole();
	vc.on( 'jsdomError', ( error ) => {
		if ( /Not implemented/.test( String( error && error.message ) ) ) {
			return;
		}
		console.error( error );
	} );
	return vc;
}

function pageHtml( nextHref, nextTitle ) {
	return `<!DOCTYPE html><html><body>
	<div data-lk-player>
		<video controls src="https://cdn.example.test/ep-1.mp4">
			<track kind="subtitles" src="https://cdn.example.test/ep-1.vtt" srclang="id" label="Indonesia">
		</video>
		<button type="button" class="lk-player__source" data-video-src="https://cdn.example.test/ep-1-b.mp4">Sumber 2</button>
		<span data-player-status></span>
	</div>
	<a class="lk-episode-navigation__next" href="${nextHref}">
		<span>Episode berikutnya</span>
		<strong>${'ESCAPED_TITLE' === nextTitle ? PAYLOAD_HTML : nextTitle}</strong>
	</a>
	</body></html>`;
}

/**
 * Build a page, let it finish loading, then evaluate player.js.
 *
 * Evaluating after `load` means the script's readyState branch takes the
 * immediate-initialize path, so listeners are attached synchronously and the
 * test never races DOMContentLoaded.
 */
async function loadPlayer( nextHref, nextTitle ) {
	const dom = new JSDOM( pageHtml( nextHref, nextTitle ), {
		url: 'https://example.test/episode/ep-1/',
		runScripts: 'outside-only',
		pretendToBeVisual: true,
		virtualConsole: quietConsole(),
	} );
	await new Promise( ( resolve ) => {
		if ( 'complete' === dom.window.document.readyState ) {
			resolve();
			return;
		}
		dom.window.addEventListener( 'load', resolve );
	} );
	dom.window.eval( source );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	return dom;
}

function fireEnded( dom ) {
	const video = dom.window.document.querySelector( 'video' );
	video.dispatchEvent( new dom.window.Event( 'ended', { bubbles: true } ) );
}

let passes = 0;
let failures = 0;
function assert( text, cond, detail ) {
	if ( cond ) {
		passes++;
	} else {
		failures++;
	}
	console.log( `${cond ? 'PASS' : 'FAIL'}  ${text}${detail ? ` — ${detail}` : ''}` );
}

async function main() {
	console.log( `\n=== ${label} ===\n` );

	/* --- hostile title --- */
	const dom = await loadPlayer( '/episode/ep-2/', 'ESCAPED_TITLE' );
	const doc = dom.window.document;
	fireEnded( dom );

	const overlay = doc.querySelector( '.lkc-autoplay-overlay' );
	assert( 'overlay is created when the video ends', !! overlay );

	if ( overlay ) {
		assert( 'the payload never becomes a live element', 0 === overlay.querySelectorAll( 'img' ).length, `img count=${overlay.querySelectorAll( 'img' ).length}` );
		assert( 'no injected element exists anywhere in the document', 0 === doc.querySelectorAll( '[data-pwned]' ).length, `found=${doc.querySelectorAll( '[data-pwned]' ).length}` );
		assert( 'no script element was created', 0 === overlay.querySelectorAll( 'script' ).length );

		const link = overlay.querySelector( 'a' );
		assert( 'overlay link exists', !! link );
		if ( link ) {
			assert( 'the hostile title survives as literal text', -1 !== link.textContent.indexOf( '<img src=x data-pwned="1">' ), JSON.stringify( link.textContent ) );
			assert( 'the link title excludes the span label', -1 === link.textContent.indexOf( 'Episode berikutnya' ), JSON.stringify( link.textContent ) );
			assert( 'the link points at the next episode', '/episode/ep-2/' === new dom.window.URL( link.href ).pathname, String( link.href ) );
		}

		const countdown = overlay.querySelector( '[data-lkc-countdown] strong' );
		assert( 'countdown starts at 8', !! countdown && '8' === countdown.textContent, countdown ? countdown.textContent : 'missing' );
		assert( 'overlay is announced to assistive tech', 'alert' === overlay.getAttribute( 'role' ) && 'assertive' === overlay.getAttribute( 'aria-live' ) );

		const cancel = overlay.querySelector( '[data-lkc-cancel-autoplay]' );
		assert( 'cancel button exists and is a real button', !! cancel && 'button' === cancel.type, cancel ? cancel.type : 'missing' );
		if ( cancel ) {
			cancel.dispatchEvent( new dom.window.MouseEvent( 'click', { bubbles: true } ) );
			assert( 'clicking cancel removes the overlay', ! doc.querySelector( '.lkc-autoplay-overlay' ) );
		}

		fireEnded( dom );
		assert( 'a second ended event does not stack overlays', doc.querySelectorAll( '.lkc-autoplay-overlay' ).length <= 1, `count=${doc.querySelectorAll( '.lkc-autoplay-overlay' ).length}` );
	}
	dom.window.close();

	/* --- javascript: target must be refused --- */
	const evil = await loadPlayer( 'javascript:alert(1)//example.test', 'ESCAPED_TITLE' );
	fireEnded( evil );
	assert( 'a javascript: next-episode target produces no overlay', ! evil.window.document.querySelector( '.lkc-autoplay-overlay' ) );
	evil.window.close();

	/* --- data: target must be refused --- */
	const dataUrl = await loadPlayer( 'data:text/html,<script>alert(1)</script>', 'ESCAPED_TITLE' );
	fireEnded( dataUrl );
	assert( 'a data: next-episode target produces no overlay', ! dataUrl.window.document.querySelector( '.lkc-autoplay-overlay' ) );
	dataUrl.window.close();

	/* --- no next episode --- */
	const noNav = new JSDOM( '<!DOCTYPE html><html><body><div data-lk-player><video></video></div></body></html>', {
		url: 'https://example.test/episode/ep-1/',
		runScripts: 'outside-only',
		virtualConsole: quietConsole(),
	} );
	await new Promise( ( resolve ) => {
		if ( 'complete' === noNav.window.document.readyState ) {
			resolve();
			return;
		}
		noNav.window.addEventListener( 'load', resolve );
	} );
	noNav.window.eval( source );
	const noNavVideo = noNav.window.document.querySelector( 'video' );
	noNavVideo.dispatchEvent( new noNav.window.Event( 'ended', { bubbles: true } ) );
	assert( 'no overlay when there is no next episode link', ! noNav.window.document.querySelector( '.lkc-autoplay-overlay' ) );
	noNav.window.close();

	/* --- source switcher keeps subtitles and position --- */
	const switcher = await loadPlayer( '/episode/ep-2/', 'Episode 2' );
	const sdoc = switcher.window.document;
	const video = sdoc.querySelector( 'video' );
	video.currentTime = 123;
	const button = sdoc.querySelector( '.lk-player__source' );
	button.dispatchEvent( new switcher.window.MouseEvent( 'click', { bubbles: true } ) );
	assert( 'source button is marked as pressed', 'true' === button.getAttribute( 'aria-pressed' ), String( button.getAttribute( 'aria-pressed' ) ) );
	assert( 'the new source is applied', 'https://cdn.example.test/ep-1-b.mp4' === video.getAttribute( 'src' ) || -1 !== String( video.src ).indexOf( 'ep-1-b.mp4' ), String( video.src ) );
	assert( 'subtitle track is preserved across the switch', 1 === video.querySelectorAll( 'track' ).length, `tracks=${video.querySelectorAll( 'track' ).length}` );
	assert( 'the restored track keeps its language and label', 'id' === video.querySelector( 'track' ).srclang && 'Indonesia' === video.querySelector( 'track' ).label, `${video.querySelector( 'track' ).srclang}/${video.querySelector( 'track' ).label}` );
	button.dispatchEvent( new switcher.window.MouseEvent( 'click', { bubbles: true } ) );
	assert( 'switching source twice does not duplicate tracks', 1 === video.querySelectorAll( 'track' ).length, `tracks=${video.querySelectorAll( 'track' ).length}` );
	const status = sdoc.querySelector( '[data-player-status]' );
	assert( 'status text is updated', !! status && -1 !== status.textContent.indexOf( 'Sumber 2' ), status ? status.textContent : 'missing' );
	switcher.window.close();

	console.log( `\n${passes} passed, ${failures} failed` );
	process.exitCode = failures ? 1 : 0;
}

main().catch( ( e ) => {
	console.error( e );
	process.exitCode = 1;
} );
