/**
 * Saves the headless storefront API's allowed-origins list.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var config = window.wcmcsApiAccess || {};
		var button = document.getElementById( 'wcmcs-save-headless-origins' );
		var textarea = document.getElementById( 'wcmcs-headless-origins' );
		var status = document.getElementById( 'wcmcs-headless-origins-status' );

		if ( ! button || ! textarea ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var body = new URLSearchParams();
			body.set( 'action', 'wcmcs_save_headless_origins' );
			body.set( 'nonce', config.nonce );
			body.set( 'origins', textarea.value );

			if ( status ) {
				status.textContent = '';
			}

			fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( response ) {
					if ( status ) {
						status.textContent = response.success
							? config.i18n.saved
							: ( response.data && response.data.message ? response.data.message : config.i18n.saveFailed );
					}
				} )
				.catch( function () {
					if ( status ) {
						status.textContent = config.i18n.saveFailed;
					}
				} );
		} );
	} );
} )();
