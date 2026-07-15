/**
 * Webhooks screen: the "Send Test" button per registered webhook.
 */
( function () {
	'use strict';

	var config = window.wcmcsWebhooks || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-wcmcs-test-webhook]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var id = button.getAttribute( 'data-wcmcs-test-webhook' );
				var result = document.querySelector( '[data-wcmcs-test-result="' + id + '"]' );

				result.textContent = config.i18n.testing;

				var body = new URLSearchParams();
				body.set( 'action', 'wcmcs_test_webhook' );
				body.set( 'nonce', config.nonce );
				body.set( 'webhook_id', id );

				fetch( config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( response ) {
						var message = response.data && response.data.message ? response.data.message : '';
						result.textContent = message;
						result.className = 'wcmcs-status ' + ( response.success ? 'wcmcs-status--ok' : 'wcmcs-status--error' );
					} );
			} );
		} );
	} );
} )();
