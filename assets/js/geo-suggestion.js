/**
 * The optional geo-detected currency confirmation modal. Only present
 * in the page at all when GeoSuggestionController decided there's
 * something worth asking about — this script just wires up its two
 * buttons.
 */
( function () {
	'use strict';

	var config = window.wcmcsGeoSuggestion || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		var modal = document.querySelector( '[data-wcmcs-geo-modal]' );

		if ( ! modal ) {
			return;
		}

		var confirmBtn = modal.querySelector( '[data-wcmcs-geo-confirm]' );
		var dismissBtn = modal.querySelector( '[data-wcmcs-geo-dismiss]' );

		function close() {
			modal.remove();
		}

		if ( confirmBtn ) {
			confirmBtn.addEventListener( 'click', function () {
				var body = new URLSearchParams();
				body.set( 'action', 'wcmcs_switch_currency' );
				body.set( 'nonce', config.switchNonce );
				body.set( 'currency', config.currency );

				fetch( config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} ).then( function () {
					window.location.reload();
				} );
			} );
		}

		if ( dismissBtn ) {
			dismissBtn.addEventListener( 'click', function () {
				var body = new URLSearchParams();
				body.set( 'action', 'wcmcs_dismiss_geo_suggestion' );
				body.set( 'nonce', config.dismissNonce );

				fetch( config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} );

				close();
			} );
		}
	} );
} )();
