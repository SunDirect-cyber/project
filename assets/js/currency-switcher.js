/**
 * Powers every rendered switcher instance (widget, shortcode, block,
 * floating widget, menu item) — they all share the same
 * [data-wcmcs-switcher] markup from CurrencySwitcherRenderer, so one
 * script handles all of them.
 */
( function () {
	'use strict';

	var config = window.wcmcsSwitcher || {};

	function switchCurrency( code, root ) {
		root.setAttribute( 'aria-busy', 'true' );

		var body = new URLSearchParams();
		body.set( 'action', 'wcmcs_switch_currency' );
		body.set( 'nonce', config.nonce );
		body.set( 'currency', code );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( response ) {
				if ( response && response.success ) {
					// A full reload is deliberate here: prices across the
					// page (and in the mini-cart, header totals, etc.) need
					// to reflect the new currency, and re-fetching/patching
					// all of that in place is out of scope for the switcher
					// itself.
					window.location.reload();
				} else {
					root.removeAttribute( 'aria-busy' );
				}
			} )
			.catch( function () {
				root.removeAttribute( 'aria-busy' );
			} );
	}

	function init( root ) {
		var select = root.querySelector( '[data-wcmcs-switcher-select]' );

		if ( select ) {
			select.addEventListener( 'change', function () {
				switchCurrency( select.value, root );
			} );
		}

		var buttons = root.querySelectorAll( '[data-wcmcs-switcher-option]' );

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				switchCurrency( button.getAttribute( 'data-currency' ), root );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-wcmcs-switcher]' ).forEach( init );
	} );
} )();
