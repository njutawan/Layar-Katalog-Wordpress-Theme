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

	function findNextEpisodeLink() {
		var nav = document.querySelector( '.lk-episode-navigation__next' );
		if ( nav && nav.href ) {
			return { url: nav.href, title: nav.textContent.trim() };
		}
		return null;
	}

	function createOverlay( nextInfo ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'lkc-autoplay-overlay';
		overlay.setAttribute( 'role', 'alert' );
		overlay.setAttribute( 'aria-live', 'assertive' );
		overlay.innerHTML =
			'<p class="lkc-autoplay-title">Episode berikutnya</p>' +
			'<p class="lkc-autoplay-next"><a href="' + nextInfo.url + '">' + nextInfo.title + '</a></p>' +
			'<span class="lkc-autoplay-countdown" data-lkc-countdown>Memutar dalam <strong>' + COUNTDOWN_SECONDS + '</strong> detik…</span>' +
			'<button type="button" class="lkc-autoplay-cancel" data-lkc-cancel-autoplay>Batal</button>';
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
				var countdownEl = overlay.querySelector( '[data-lkc-countdown]' );
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
						countdownEl.innerHTML = 'Memutar dalam <strong>' + secondsLeft + '</strong> detik…';
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