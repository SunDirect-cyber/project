/**
 * "Import from another plugin": preview (read-only) then confirm
 * (writes) — two separate AJAX calls per plugin row, kept apart on
 * purpose so nothing is written until the admin has seen exactly what
 * would be imported.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var config = window.wcmcsCompetitorImport || {};
		var container = document.getElementById( 'wcmcs-competitor-import' );

		if ( ! container ) {
			return;
		}

		function request( action, plugin ) {
			var body = new URLSearchParams();
			body.set( 'action', action );
			body.set( 'nonce', config.nonce );
			body.set( 'plugin', plugin );

			return fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} ).then( function ( r ) { return r.json(); } );
		}

		container.addEventListener( 'click', function ( event ) {
			var previewBtn = event.target.closest( '[data-wcmcs-competitor-preview]' );

			if ( ! previewBtn ) {
				return;
			}

			var plugin = previewBtn.getAttribute( 'data-wcmcs-competitor-preview' );
			var status = container.querySelector( '[data-wcmcs-competitor-status="' + plugin + '"]' );
			var resultBox = container.querySelector( '[data-wcmcs-competitor-preview-result="' + plugin + '"]' );

			if ( status ) {
				status.textContent = config.i18n.previewing;
			}

			request( 'wcmcs_preview_competitor_import', plugin ).then( function ( response ) {
				if ( status ) {
					status.textContent = '';
				}

				if ( ! response.success || ! response.data.currencies || ! response.data.currencies.length ) {
					if ( resultBox ) {
						resultBox.hidden = false;
						resultBox.textContent = config.i18n.noneFound;
					}
					return;
				}

				if ( resultBox ) {
					resultBox.hidden = false;
					resultBox.innerHTML = '';

					var list = document.createElement( 'p' );
					list.textContent = response.data.currencies.join( ', ' );
					resultBox.appendChild( list );

					var confirmBtn = document.createElement( 'button' );
					confirmBtn.type = 'button';
					confirmBtn.className = 'button button-primary';
					confirmBtn.textContent = 'Import ' + response.data.currencies.length + ' currencies';
					confirmBtn.addEventListener( 'click', function () {
						if ( ! window.confirm( config.i18n.confirm ) ) {
							return;
						}

						if ( status ) {
							status.textContent = config.i18n.importing;
						}

						request( 'wcmcs_confirm_competitor_import', plugin ).then( function ( importResponse ) {
							if ( status ) {
								status.textContent = importResponse.success && importResponse.data.message
									? importResponse.data.message
									: ( importResponse.data && importResponse.data.message ? importResponse.data.message : config.i18n.importFailed );
							}

							if ( importResponse.success ) {
								confirmBtn.disabled = true;
							}
						} ).catch( function () {
							if ( status ) {
								status.textContent = config.i18n.importFailed;
							}
						} );
					} );
					resultBox.appendChild( confirmBtn );
				}
			} ).catch( function () {
				if ( status ) {
					status.textContent = config.i18n.previewFailed;
				}
			} );
		} );
	} );
} )();
