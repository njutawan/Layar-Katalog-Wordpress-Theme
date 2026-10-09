/**
 * Back-to-top button — smooth scroll with show/hide on scroll.
 *
 * @package Layar_Katalog
 */
( function () {
  'use strict';

  var SCROLL_THRESHOLD = 400;
  var button;

  function create() {
    button = document.createElement( 'button' );
    button.className = 'lk-back-to-top';
    button.type = 'button';
    button.setAttribute( 'aria-label', 'Kembali ke atas' );
    button.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>';
    button.addEventListener( 'click', function () {
      window.scrollTo( { top: 0, behavior: 'smooth' } );
    } );
    document.body.appendChild( button );
  }

  function onScroll() {
    if ( ! button ) return;
    if ( window.scrollY > SCROLL_THRESHOLD ) {
      button.classList.add( 'is-visible' );
    } else {
      button.classList.remove( 'is-visible' );
    }
  }

  function initialize() {
    create();
    window.addEventListener( 'scroll', onScroll, { passive: true } );
    onScroll();
  }

  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', initialize );
  } else {
    initialize();
  }
} )();