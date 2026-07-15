/**
 * Rate Providers screen: drag-and-drop priority reorder (plain HTML5,
 * matching the pattern used on the Currencies screen), API key save,
 * and the live "Test Connection" button.
 */
( function () {
	'use strict';

	var config = window.wcmcsRateProviderConfig || {};

	function post( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', config.nonce );

		Object.keys( extra || {} ).forEach( function ( key ) {
			var value = extra[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( v ) { body.append( key + '[]', v ); } );
			} else {
				body.set( key, value );
			}
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initDragDrop();
		initTestConnection();
		initSave();
	} );

	function currentPriority() {
		return Array.prototype.map.call(
			document.querySelectorAll( '#wcmcs-provider-list .wcmcs-provider-row' ),
			function ( row ) { return row.getAttribute( 'data-slug' ); }
		);
	}

	function initDragDrop() {
		var list = document.getElementById( 'wcmcs-provider-list' );
		if ( ! list ) { return; }

		var dragged = null;

		list.addEventListener( 'dragstart', function ( e ) {
			if ( ! e.target.classList.contains( 'wcmcs-provider-row' ) ) { return; }
			dragged = e.target;
			e.dataTransfer.effectAllowed = 'move';
		} );

		list.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			var target = e.target.closest( '.wcmcs-provider-row' );
			if ( ! target || target === dragged ) { return; }

			var rect = target.getBoundingClientRect();
			var before = ( e.clientY - rect.top ) < rect.height / 2;
			list.insertBefore( dragged, before ? target : target.nextSibling );
		} );
	}

	function initTestConnection() {
		document.querySelectorAll( '[data-wcmcs-test]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var slug = button.getAttribute( 'data-wcmcs-test' );
				var result = document.querySelector( '[data-wcmcs-test-result="' + slug + '"]' );

				result.textContent = config.i18n.testing;

				post( 'wcmcs_test_provider', { provider: slug } ).then( function ( response ) {
					var message = response.data && response.data.message ? response.data.message : ( response.success ? config.i18n.saved : config.i18n.saveFailed );
					result.textContent = message;
					result.className = 'wcmcs-status ' + ( response.success ? 'wcmcs-status--ok' : 'wcmcs-status--error' );
				} );
			} );
		} );
	}

	function initSave() {
		var button = document.getElementById( 'wcmcs-save-providers' );
		var status = document.getElementById( 'wcmcs-providers-status' );
		if ( ! button ) { return; }

		button.addEventListener( 'click', function () {
			var fields = { priority: currentPriority() };

			var interval = document.getElementById( 'wcmcs-sync-interval' );
			if ( interval ) { fields.sync_interval = interval.value; }

			document.querySelectorAll( '[data-wcmcs-key]' ).forEach( function ( input ) {
				fields[ 'key_' + input.getAttribute( 'data-wcmcs-key' ) ] = input.value;
			} );

			post( 'wcmcs_save_provider_settings', fields ).then( function ( response ) {
				status.textContent = response.success ? config.i18n.saved : config.i18n.saveFailed;
				if ( response.success ) {
					window.setTimeout( function () { window.location.reload(); }, 600 );
				}
			} );
		} );
	}
} )();
