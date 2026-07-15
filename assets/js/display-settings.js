/**
 * Display & Behavior screen: swaps the pre-rendered style preview
 * panels as the admin picks a radio option — no AJAX round trip, since
 * all three variants were already rendered server-side and are just
 * hidden/shown.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var radios = document.querySelectorAll( '[data-wcmcs-style-radio]' );

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				if ( ! radio.checked ) { return; }

				document.querySelectorAll( '[data-wcmcs-style-preview]' ).forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-wcmcs-style-preview' ) !== radio.value;
				} );
			} );
		} );
	} );
} )();
