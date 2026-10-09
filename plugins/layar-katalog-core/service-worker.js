/**
 * Layar Katalog Service Worker — push notifications + offline caching.
 *
 * @package Layar_Katalog_Core
 */

var CACHE_NAME = 'lk-shell-v1';
var OFFLINE_URL = '/offline/';

/* App shell assets to pre-cache on install. */
var SHELL_ASSETS = [
  '/',
  OFFLINE_URL
];

/* ---------- Install ---------- */

self.addEventListener( 'install', function ( event ) {
  event.waitUntil(
    caches.open( CACHE_NAME ).then( function ( cache ) {
      return cache.addAll( SHELL_ASSETS ).catch( function () {
        /* Non-critical: offline page may not exist yet. */
      } );
    } )
  );
  self.skipWaiting();
} );

/* ---------- Activate ---------- */

self.addEventListener( 'activate', function ( event ) {
  event.waitUntil(
    caches.keys().then( function ( names ) {
      return Promise.all(
        names.filter( function ( name ) {
          return name !== CACHE_NAME && name.indexOf( 'lk-' ) === 0;
        } ).map( function ( name ) {
          return caches.delete( name );
        } )
      );
    } )
  );
  self.clients.claim();
} );

/* ---------- Fetch (network-first for HTML, cache-first for assets) ---------- */

self.addEventListener( 'fetch', function ( event ) {
  var request = event.request;

  /* Only handle GET requests. */
  if ( request.method !== 'GET' ) {
    return;
  }

  /* Skip non-same-origin requests (CDN, push services, etc.). */
  if ( new URL( request.url ).origin !== self.location.origin ) {
    return;
  }

  /* Skip WP admin and REST API. */
  var pathname = new URL( request.url ).pathname;
  if ( pathname.indexOf( '/wp-admin' ) === 0 || pathname.indexOf( '/wp-json' ) === 0 ) {
    return;
  }

  var isNavigation = request.mode === 'navigate';
  var isAsset = /\.(?:css|js|woff2?|ttf|eot|svg|png|jpe?g|gif|webp|ico|mp4|webm)(\?|$)/i.test( pathname );

  if ( isNavigation ) {
    /* Network-first for HTML pages: fast online, offline fallback. */
    event.respondWith(
      fetch( request )
        .then( function ( response ) {
          var clone = response.clone();
          caches.open( CACHE_NAME ).then( function ( cache ) {
            cache.put( request, clone );
          } );
          return response;
        } )
        .catch( function () {
          return caches.match( request ).then( function ( cached ) {
            return cached || caches.match( OFFLINE_URL );
          } );
        } )
    );
  } else if ( isAsset ) {
    /* Cache-first for static assets: fast repeat visits. */
    event.respondWith(
      caches.match( request ).then( function ( cached ) {
        if ( cached ) {
          return cached;
        }
        return fetch( request ).then( function ( response ) {
          var clone = response.clone();
          caches.open( CACHE_NAME ).then( function ( cache ) {
            cache.put( request, clone );
          } );
          return response;
        } );
      } )
    );
  }
} );

/* ---------- Push notifications ---------- */

self.addEventListener( 'push', function ( event ) {
  var data = { title: 'Episode baru', body: '', url: '/' };
  try {
    var payload = event.data ? event.data.json() : {};
    if ( payload.title ) data.title = payload.title;
    if ( payload.body )  data.body  = payload.body;
    if ( payload.url )   data.url   = payload.url;
  } catch ( error ) {
    data.body = event.data ? event.data.text() : '';
  }

  var options = {
    body: data.body,
    icon: data.icon || undefined,
    tag: data.tag || 'lkc-episode',
    data: { url: data.url },
    renotify: true
  };

  event.waitUntil(
    self.registration.showNotification( data.title, options )
  );
} );

self.addEventListener( 'notificationclick', function ( event ) {
  event.notification.close();
  var targetUrl = ( event.notification.data && event.notification.data.url ) || '/';
  event.waitUntil(
    self.clients.matchAll( { type: 'window', includeUncontrolled: true } ).then( function ( clients ) {
      for ( var i = 0; i < clients.length; i++ ) {
        var client = clients[ i ];
        if ( 'focus' in client ) {
          return client.focus();
        }
      }
      return self.clients.openWindow( targetUrl );
    } )
  );
} );