/**
 * Layar Katalog Service Worker — push notifications + offline caching.
 *
 * @package Layar_Katalog_Core
 */

/*
 * v2: the caching rules below were tightened, so the version is bumped to make
 * the activate handler drop v1 caches that may already hold personalised HTML.
 */
var CACHE_NAME = 'lk-shell-v2';
var OFFLINE_URL = '/offline/';

/* App shell assets to pre-cache on install. */
var SHELL_ASSETS = [
  '/',
  OFFLINE_URL
];

/* Query arguments that mark a preview or a customizer session. */
var UNCACHEABLE_QUERY = /[?&](preview|customize_changeset_uuid|customize_autosave|lk_no_cache)=/i;

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

/* ---------- Cache eligibility ---------- */

/**
 * Decide whether a response may be written to Cache Storage.
 *
 * Cache Storage is shared by everyone using the browser profile, so storing a
 * signed-in page would show the previous visitor's admin bar, account state, and
 * nonces to the next person on a shared device. The server marks personalised
 * responses with X-LK-Personalized; anything private, non-basic, unsuccessful, or
 * belonging to a preview is left out as well.
 */
function isCacheable( request, response ) {
  if ( ! response || ! response.ok || response.type !== 'basic' ) {
    return false;
  }
  if ( response.headers.get( 'X-LK-Personalized' ) ) {
    return false;
  }
  var cacheControl = ( response.headers.get( 'Cache-Control' ) || '' ).toLowerCase();
  if ( cacheControl.indexOf( 'no-store' ) !== -1 || cacheControl.indexOf( 'private' ) !== -1 ) {
    return false;
  }
  try {
    if ( UNCACHEABLE_QUERY.test( new URL( request.url ).search ) ) {
      return false;
    }
  } catch ( error ) {
    return false;
  }
  return true;
}

/** Store a clone of the response when, and only when, it is eligible. */
function cacheIfEligible( request, response ) {
  if ( ! isCacheable( request, response ) ) {
    return;
  }
  var clone = response.clone();
  caches.open( CACHE_NAME ).then( function ( cache ) {
    cache.put( request, clone ).catch( function () {
      /* Cache Storage can be full or evicted; never fail the request over it. */
    } );
  } );
}

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
  if ( pathname.indexOf( '/wp-admin' ) === 0 || pathname.indexOf( '/wp-json' ) === 0 || pathname.indexOf( '/wp-login.php' ) === 0 ) {
    return;
  }

  var isNavigation = request.mode === 'navigate';
  var isAsset = /\.(?:css|js|woff2?|ttf|eot|svg|png|jpe?g|gif|webp|ico|mp4|webm)(\?|$)/i.test( pathname );

  if ( isNavigation ) {
    /* Network-first for HTML pages: fast online, offline fallback. */
    event.respondWith(
      fetch( request )
        .then( function ( response ) {
          cacheIfEligible( request, response );
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
          cacheIfEligible( request, response );
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

  /* Never open a URL outside this origin, whatever a payload claims. */
  try {
    if ( new URL( targetUrl, self.location.origin ).origin !== self.location.origin ) {
      targetUrl = '/';
    }
  } catch ( error ) {
    targetUrl = '/';
  }

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
