/**
 * Lightweight image lightbox — no dependencies.
 * Activates on featured images, catalog cards, and gallery images.
 *
 * @package Layar_Katalog
 */
( function () {
  'use strict';

  var current = 0;
  var images  = [];
  var overlay, imgEl, counterEl;

  function build() {
    overlay = document.createElement( 'div' );
    overlay.className = 'lk-lightbox';
    overlay.setAttribute( 'role', 'dialog' );
    overlay.setAttribute( 'aria-label', 'Pratinjau gambar' );
    overlay.setAttribute( 'aria-hidden', 'true' );
    overlay.innerHTML =
      '<button type="button" class="lk-lightbox__close" aria-label="Tutup">&times;</button>' +
      '<button type="button" class="lk-lightbox__nav lk-lightbox__prev" aria-label="Gambar sebelumnya">&#8249;</button>' +
      '<figure class="lk-lightbox__figure">' +
        '<img class="lk-lightbox__img" alt="">' +
        '<figcaption class="lk-lightbox__caption"></figcaption>' +
      '</figure>' +
      '<button type="button" class="lk-lightbox__nav lk-lightbox__next" aria-label="Gambar berikutnya">&#8250;</button>' +
      '<span class="lk-lightbox__counter"></span>';
    document.body.appendChild( overlay );

    imgEl     = overlay.querySelector( '.lk-lightbox__img' );
    counterEl = overlay.querySelector( '.lk-lightbox__counter' );

    overlay.addEventListener( 'click', function ( e ) {
      if ( e.target === overlay ) { close(); }
    } );
    overlay.querySelector( '.lk-lightbox__close' ).addEventListener( 'click', close );
    overlay.querySelector( '.lk-lightbox__prev' ).addEventListener( 'click', function () { navigate( -1 ); } );
    overlay.querySelector( '.lk-lightbox__next' ).addEventListener( 'click', function () { navigate( 1 ); } );
  }

  function getFullSrc( img ) {
    /* Try to find the largest available source. */
    var srcset = img.getAttribute( 'srcset' );
    if ( srcset ) {
      var parts = srcset.split( ',' );
      var best = '';
      var bestW = 0;
      parts.forEach( function ( p ) {
        var bits = p.trim().split( /\s+/ );
        var w = parseInt( bits[ 1 ], 10 );
        if ( w > bestW ) { bestW = w; best = bits[ 0 ]; }
      } );
      if ( best ) return best;
    }
    var link = img.closest( 'a' );
    if ( link && /\.(jpe?g|png|gif|webp|avif)(\?|$)/i.test( link.href ) ) {
      return link.href;
    }
    return img.currentSrc || img.src;
  }

  function getCaption( img ) {
    var figcaption = img.closest( 'figure' );
    if ( figcaption ) {
      var cap = figcaption.querySelector( 'figcaption' );
      if ( cap && cap.textContent.trim() ) return cap.textContent.trim();
    }
    return img.alt || '';
  }

  function open( index ) {
    if ( ! overlay ) { build(); }
    current = index;
    show();
    overlay.classList.add( 'is-open' );
    overlay.setAttribute( 'aria-hidden', 'false' );
    document.body.style.overflow = 'hidden';
  }

  function close() {
    if ( ! overlay ) return;
    overlay.classList.remove( 'is-open' );
    overlay.setAttribute( 'aria-hidden', 'true' );
    document.body.style.overflow = '';
  }

  function navigate( dir ) {
    current = ( current + dir + images.length ) % images.length;
    show();
  }

  function show() {
    var entry = images[ current ];
    imgEl.src = entry.src;
    imgEl.alt = entry.caption;
    overlay.querySelector( '.lk-lightbox__caption' ).textContent = entry.caption;
    counterEl.textContent = ( current + 1 ) + ' / ' + images.length;
    overlay.querySelector( '.lk-lightbox__prev' ).style.display = images.length > 1 ? '' : 'none';
    overlay.querySelector( '.lk-lightbox__next' ).style.display = images.length > 1 ? '' : 'none';
  }

  function initialize() {
    var selectors = [
      '.single-cover img',
      '.catalog-card__media img',
      '.hero-feature-image img',
      '.episode-card__image img',
      '.lk-related-card__image img',
      '.wp-block-gallery img',
      '.wp-block-image img'
    ].join( ', ' );

    var allImgs = document.querySelectorAll( selectors );
    images = [];

    allImgs.forEach( function ( img, i ) {
      var src = getFullSrc( img );
      if ( ! src ) return;
      images.push( { src: src, caption: getCaption( img ), el: img } );
      img.style.cursor = 'zoom-in';
      img.setAttribute( 'data-lk-lightbox-index', i );
      img.addEventListener( 'click', function ( e ) {
        e.preventDefault();
        e.stopPropagation();
        open( parseInt( img.getAttribute( 'data-lk-lightbox-index' ), 10 ) );
      } );
    } );

    /* Keyboard navigation */
    document.addEventListener( 'keydown', function ( e ) {
      if ( ! overlay || ! overlay.classList.contains( 'is-open' ) ) return;
      if ( e.key === 'Escape' ) close();
      if ( e.key === 'ArrowLeft' ) navigate( -1 );
      if ( e.key === 'ArrowRight' ) navigate( 1 );
    } );
  }

  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', initialize );
  } else {
    initialize();
  }
} )();