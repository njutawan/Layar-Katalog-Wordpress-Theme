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

		video.src = button.getAttribute( 'data-video-src' );
		video.load();
		var status = player.querySelector( '[data-player-status]' );
		if ( status ) {
			status.textContent = button.textContent.trim() + ' dipilih. Tekan putar untuk melanjutkan.';
		}
	} );
} )();
