/**
 * Customizer live-preview for Layar Katalog.
 *
 * @package Layar_Katalog
 */
( function( $ ) {
	'use strict';

	wp.customize( 'layarkatalog_primary_color', function( value ) {
		value.bind( function( newval ) {
			document.documentElement.style.setProperty( '--lk-lime', newval );
		} );
	} );

	wp.customize( 'layarkatalog_accent_color', function( value ) {
		value.bind( function( newval ) {
			document.documentElement.style.setProperty( '--lk-coral', newval );
		} );
	} );

} )( jQuery );