/**
 * AJAX catalog filter — replaces the GET form with a fetch-based filter
 * that swaps the catalog grid without a full page reload.
 *
 * @package Layar_Katalog_Core
 */
( function () {
  'use strict';

  var FILTER_SELECTOR = '.lk-catalog-filter';
  var GRID_SELECTOR   = '.catalog-query';
  var DEBOUNCE_MS     = 300;
  var debounceTimer   = null;

  /**
   * Build a clean URL from the current filter values.
   * If the catalog archive base is not known, fall back to the form action.
   */
  function buildFilterUrl( form ) {
    var base = form.getAttribute( 'action' ) || window.location.pathname;
    var params = new URLSearchParams();
    var fields = form.querySelectorAll( 'input[name], select[name]' );
    for ( var i = 0; i < fields.length; i++ ) {
      var field = fields[ i ];
      var value = ( field.value || '' ).trim();
      if ( value && !( field.type === 'submit' ) ) {
        params.set( field.name, value );
      }
    }
    var qs = params.toString();
    return base + ( qs ? '?' + qs : '' );
  }

  /**
   * Update the browser URL without triggering a reload, so back/forward
   * buttons and shareable links reflect the current filter state.
   */
  function pushFilterState( url ) {
    if ( window.history && window.history.replaceState ) {
      window.history.replaceState( null, '', url );
    }
  }

  /**
   * Restore filter fields from the current URL query string.
   */
  function restoreFiltersFromUrl( form ) {
    var params = new URLSearchParams( window.location.search );
    var fields = form.querySelectorAll( 'input[name], select[name]' );
    for ( var i = 0; i < fields.length; i++ ) {
      var field = fields[ i ];
      if ( params.has( field.name ) ) {
        field.value = params.get( field.name );
      }
    }
  }

  /**
   * Show a loading state on the grid while the fetch is in progress.
   */
  function setGridLoading( grid, loading ) {
    if ( loading ) {
      grid.style.opacity = '0.45';
      grid.style.pointerEvents = 'none';
      grid.setAttribute( 'aria-busy', 'true' );
    } else {
      grid.style.opacity = '';
      grid.style.pointerEvents = '';
      grid.removeAttribute( 'aria-busy' );
    }
  }

  /**
   * Extract the catalog grid HTML from a full HTML response.
   */
  function extractGridHtml( html ) {
    var parser = new DOMParser();
    var doc = parser.parseFromString( html, 'text/html' );
    var grid = doc.querySelector( GRID_SELECTOR );
    return grid ? grid.innerHTML : null;
  }

  /**
   * Count the number of catalog cards currently shown.
   */
  function countCards( grid ) {
    return grid.querySelectorAll( '.catalog-card' ).length;
  }

  /**
   * Update the "no results" message visibility.
   */
  function updateNoResults( grid, hasCards ) {
    var noResults = grid.querySelector( '.wp-block-query-no-results' );
    if ( noResults ) {
      noResults.hidden = hasCards;
    }
  }

  /**
   * Fetch filtered catalog HTML and swap the grid content.
   */
  function applyFilter( form ) {
    var grid = document.querySelector( GRID_SELECTOR );
    if ( ! grid ) {
      form.submit();
      return;
    }

    var url = buildFilterUrl( form );
    pushFilterState( url );
    setGridLoading( grid, true );

    window.fetch( url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html' }
    } )
    .then( function ( response ) {
      if ( ! response.ok ) {
        throw new Error( 'HTTP ' + response.status );
      }
      return response.text();
    } )
    .then( function ( html ) {
      var newContent = extractGridHtml( html );
      if ( newContent !== null ) {
        grid.innerHTML = newContent;
        updateNoResults( grid, countCards( grid ) > 0 );
        setGridLoading( grid, false );

        /* Scroll to the catalog section. */
        var section = grid.closest( '.catalog-section' ) || grid.closest( 'section' );
        if ( section ) {
          section.scrollIntoView( { behavior: 'smooth', block: 'start' } );
        }
      } else {
        /* Fallback: navigate to the URL if we cannot parse the response. */
        window.location.href = url;
      }
    } )
    .catch( function () {
      setGridLoading( grid, false );
      window.location.href = url;
    } );
  }

  function initialize() {
    var forms = document.querySelectorAll( FILTER_SELECTOR );
    if ( ! forms.length ) {
      return;
    }

    forms.forEach( function ( form ) {
      /* Restore filter values from URL on page load. */
      restoreFiltersFromUrl( form );

      form.addEventListener( 'submit', function ( event ) {
        event.preventDefault();
        applyFilter( form );
      } );

      /* Auto-apply on select change with a short debounce. */
      form.querySelectorAll( 'select[name]' ).forEach( function ( select ) {
        select.addEventListener( 'change', function () {
          clearTimeout( debounceTimer );
          debounceTimer = setTimeout( function () {
            applyFilter( form );
          }, DEBOUNCE_MS );
        } );
      } );

      /* Allow Enter key in the search input to submit. */
      var searchInput = form.querySelector( 'input[type="search"]' );
      if ( searchInput ) {
        searchInput.addEventListener( 'keydown', function ( event ) {
          if ( event.key === 'Enter' ) {
            event.preventDefault();
            clearTimeout( debounceTimer );
            applyFilter( form );
          }
        } );
      }
    } );

    /* Handle browser back/forward buttons. */
    window.addEventListener( 'popstate', function () {
      var form = document.querySelector( FILTER_SELECTOR );
      var grid = document.querySelector( GRID_SELECTOR );
      if ( ! form || ! grid ) {
        return;
      }
      restoreFiltersFromUrl( form );
      applyFilter( form );
    } );
  }

  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', initialize );
  } else {
    initialize();
  }
} )();