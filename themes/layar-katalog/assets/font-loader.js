/**
 * Async Google Fonts loader with font-display: swap.
 *
 * @package Layar_Katalog
 */
( function () {
  'use strict';

  var fonts = window.LKFonts;
  if ( ! fonts || ! fonts.urls || ! fonts.urls.length ) return;

  /* Preconnect to Google Fonts CDN. */
  var link = document.createElement( 'link' );
  link.rel = 'preconnect';
  link.href = 'https://fonts.gstatic.com';
  link.crossOrigin = 'anonymous';
  document.head.appendChild( link );

  /* Load each font family with font-display: swap. */
  fonts.urls.forEach( function ( url ) {
    var el = document.createElement( 'link' );
    el.rel = 'stylesheet';
    el.href = url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'display=swap';
    document.head.appendChild( el );
  } );
} )();