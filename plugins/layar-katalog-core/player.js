( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-lk-player] .lk-player__source' );
		if ( ! button ) {
			return;
		}

		var player = button.closest( '[data-lk-player]' );
		var video = player ? player.querySelector( 'video' ) : null;
		if ( ! video ) {
			return;
		}

		player.querySelectorAll( '.lk-player__source' ).forEach( function ( sourceButton ) {
			sourceButton.classList.remove( 'is-current' );
			sourceButton.setAttribute( 'aria-pressed', 'false' );
		} );
		button.classList.add( 'is-current' );
		button.setAttribute( 'aria-pressed', 'true' );

		var currentTime = video.currentTime || 0;
		var wasPaused = video.paused;

		/* Preserve subtitle tracks when switching video source. */
		var tracks = video.querySelectorAll( 'track' );
		var trackData = [];
		tracks.forEach( function ( track ) {
			trackData.push( {
				kind: track.kind,
				src: track.getAttribute( 'src' ) || '',
				srclang: track.srclang || '',
				label: track.label || '',
				defaultTrack: track.default
			} );
		} );

		video.src = button.getAttribute( 'data-video-src' );

		/*
		 * Assigning src does not remove <track> children, so drop the originals
		 * before re-adding them. Without this every source switch appends another
		 * copy and the subtitle menu grows one "Indonesia" entry at a time.
		 */
		tracks.forEach( function ( track ) {
			track.remove();
		} );

		/* Re-add subtitle tracks after source change. */
		trackData.forEach( function ( data ) {
			if ( data.src ) {
				var trackEl = document.createElement( 'track' );
				trackEl.kind = data.kind;
				trackEl.src = data.src;
				trackEl.srclang = data.srclang;
				trackEl.label = data.label;
				if ( data.defaultTrack ) {
					trackEl.default = true;
				}
				video.appendChild( trackEl );
			}
		} );

		video.load();
		if ( currentTime > 0 ) {
			video.currentTime = currentTime;
		}
		if ( ! wasPaused ) {
			video.play().catch( function () {} );
		}
		var status = player.querySelector( '[data-player-status]' );
		if ( status ) {
			status.textContent = button.textContent.trim() + ' dipilih. Tekan putar untuk melanjutkan.';
		}
	} );
} )();

/**
 * Autoplay next-episode overlay.
 * When a video finishes, show a countdown overlay linking to the next episode.
 */
( function () {
	'use strict';

	var COUNTDOWN_SECONDS = 8;

	/**
	 * Only http(s) targets may be navigated to, so a tampered href attribute can
	 * never trigger a javascript: or data: scheme.
	 */
	function isSafeUrl( url ) {
		try {
			var parsed = new URL( url, window.location.href );
			return parsed.protocol === 'http:' || parsed.protocol === 'https:';
		} catch ( error ) {
			return false;
		}
	}

	/** Create an element whose text is assigned as text, never parsed as HTML. */
	function el( tagName, className, text ) {
		var node = document.createElement( tagName );
		if ( className ) {
			node.className = className;
		}
		if ( typeof text === 'string' ) {
			node.textContent = text;
		}
		return node;
	}

	function findNextEpisodeLink() {
		var nav = document.querySelector( '.lk-episode-navigation__next' );
		if ( ! nav || ! nav.href || ! isSafeUrl( nav.href ) ) {
			return null;
		}
		/*
		 * The link holds a <span> label plus a <strong> title, so nav.textContent
		 * would concatenate both. Read the title element on its own.
		 */
		var titleEl = nav.querySelector( 'strong' );
		var title   = titleEl ? titleEl.textContent.trim() : nav.textContent.trim();
		return { url: nav.href, title: title };
	}

	/*
	 * The episode title is author-supplied content. Build the overlay with DOM
	 * APIs and textContent so a title containing markup can never be executed.
	 */
	function createOverlay( nextInfo ) {
		var overlay = el( 'div', 'lkc-autoplay-overlay' );
		overlay.setAttribute( 'role', 'alert' );
		overlay.setAttribute( 'aria-live', 'assertive' );

		overlay.appendChild( el( 'p', 'lkc-autoplay-title', 'Episode berikutnya' ) );

		var link = el( 'a', '', nextInfo.title );
		link.href = nextInfo.url;
		var nextWrap = el( 'p', 'lkc-autoplay-next' );
		nextWrap.appendChild( link );
		overlay.appendChild( nextWrap );

		var countdown = el( 'span', 'lkc-autoplay-countdown' );
		countdown.setAttribute( 'data-lkc-countdown', '' );
		countdown.appendChild( document.createTextNode( 'Memutar dalam ' ) );
		countdown.appendChild( el( 'strong', '', String( COUNTDOWN_SECONDS ) ) );
		countdown.appendChild( document.createTextNode( ' detik…' ) );
		overlay.appendChild( countdown );

		var cancel = el( 'button', 'lkc-autoplay-cancel', 'Batal' );
		cancel.type = 'button';
		cancel.setAttribute( 'data-lkc-cancel-autoplay', '' );
		overlay.appendChild( cancel );

		return overlay;
	}

	function initialize() {
		document.querySelectorAll( '[data-lk-player] video' ).forEach( function ( video ) {
			video.addEventListener( 'ended', function () {
				var nextInfo = findNextEpisodeLink();
				if ( ! nextInfo ) {
					return;
				}

				var player = video.closest( '[data-lk-player]' );
				if ( ! player ) {
					return;
				}

				/* Remove any existing overlay. */
				var existing = player.querySelector( '.lkc-autoplay-overlay' );
				if ( existing ) {
					existing.remove();
				}

				player.style.position = 'relative';
				var overlay = createOverlay( nextInfo );
				player.appendChild( overlay );

				var secondsLeft = COUNTDOWN_SECONDS;
				var countdownEl = overlay.querySelector( '[data-lkc-countdown] strong' );
				var cancelled = false;

				var interval = setInterval( function () {
					secondsLeft--;
					if ( cancelled ) {
						clearInterval( interval );
						return;
					}
					if ( secondsLeft <= 0 ) {
						clearInterval( interval );
						window.location.href = nextInfo.url;
						return;
					}
					if ( countdownEl ) {
						countdownEl.textContent = String( secondsLeft );
					}
				}, 1000 );

				overlay.addEventListener( 'click', function ( event ) {
					if ( event.target.closest( '[data-lkc-cancel-autoplay]' ) ) {
						cancelled = true;
						clearInterval( interval );
						overlay.hidden = true;
						overlay.remove();
					}
				} );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initialize );
	} else {
		initialize();
	}
} )();