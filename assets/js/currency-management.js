/**
 * Currency Management screen. Plain HTML5 drag-and-drop (draggable +
 * dragstart/dragover/drop) instead of jQuery UI's sortable(), and plain
 * fetch() calls instead of any admin framework — consistent with the
 * rest of this plugin's admin JS.
 */
( function () {
	'use strict';

	var config = window.wcmcsCurrencyManagement || {};

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
		initConfigureToggle();
		initSaveSettings();
		initRemove();
		initSaveOrder();
		initSearch();
		initAddSelected();
		initPreview();
	} );

	function currentOrder() {
		return Array.prototype.map.call(
			document.querySelectorAll( '#wcmcs-enabled-list .wcmcs-currency-row' ),
			function ( row ) { return row.getAttribute( 'data-code' ); }
		);
	}

	function initDragDrop() {
		var list = document.getElementById( 'wcmcs-enabled-list' );
		if ( ! list ) { return; }

		var dragged = null;

		list.addEventListener( 'dragstart', function ( e ) {
			if ( ! e.target.classList.contains( 'wcmcs-currency-row' ) ) { return; }
			dragged = e.target;
			e.dataTransfer.effectAllowed = 'move';
		} );

		list.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			var target = e.target.closest( '.wcmcs-currency-row' );
			if ( ! target || target === dragged ) { return; }

			var rect = target.getBoundingClientRect();
			var before = ( e.clientY - rect.top ) < rect.height / 2;
			list.insertBefore( dragged, before ? target : target.nextSibling );
		} );
	}

	function initSaveOrder() {
		var button = document.getElementById( 'wcmcs-save-order' );
		var status = document.getElementById( 'wcmcs-order-status' );
		if ( ! button ) { return; }

		button.addEventListener( 'click', function () {
			post( 'wcmcs_save_enabled_currencies', { currencies: currentOrder() } ).then( function ( response ) {
				status.textContent = response.success ? config.i18n.saved : config.i18n.saveFailed;
			} );
		} );
	}

	function initConfigureToggle() {
		document.querySelectorAll( '[data-wcmcs-configure]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var code = button.getAttribute( 'data-wcmcs-configure' );
				var panel = document.querySelector( '[data-wcmcs-settings-panel="' + code + '"]' );
				if ( panel ) { panel.hidden = ! panel.hidden; }
			} );
		} );
	}

	function initSaveSettings() {
		document.querySelectorAll( '[data-wcmcs-save-settings]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var code = button.getAttribute( 'data-wcmcs-save-settings' );
				var panel = document.querySelector( '[data-wcmcs-settings-panel="' + code + '"]' );
				var status = panel.querySelector( '[data-wcmcs-settings-status]' );
				var fields = { code: code };

				panel.querySelectorAll( '[data-field]' ).forEach( function ( input ) {
					fields[ input.getAttribute( 'data-field' ) ] = input.value;
				} );

				post( 'wcmcs_save_currency_settings', fields ).then( function ( response ) {
					status.textContent = response.success ? config.i18n.saved : config.i18n.saveFailed;
				} );
			} );
		} );
	}

	function initRemove() {
		document.querySelectorAll( '[data-wcmcs-remove]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! window.confirm( config.i18n.confirmRemove ) ) { return; }

				var code = button.getAttribute( 'data-wcmcs-remove' );
				var updated = currentOrder().filter( function ( c ) { return c !== code; } );

				post( 'wcmcs_save_enabled_currencies', { currencies: updated } ).then( function ( response ) {
					if ( response.success ) { window.location.reload(); }
				} );
			} );
		} );
	}

	function initSearch() {
		var input = document.getElementById( 'wcmcs-currency-search' );
		if ( ! input ) { return; }

		input.addEventListener( 'input', function () {
			var term = input.value.trim().toLowerCase();

			document.querySelectorAll( '.wcmcs-available-item' ).forEach( function ( item ) {
				var haystack = item.getAttribute( 'data-search' ) || '';
				item.style.display = ( '' === term || haystack.indexOf( term ) !== -1 ) ? '' : 'none';
			} );
		} );
	}

	function initAddSelected() {
		var button = document.getElementById( 'wcmcs-add-selected' );
		if ( ! button ) { return; }

		button.addEventListener( 'click', function () {
			var checked = Array.prototype.map.call(
				document.querySelectorAll( '#wcmcs-available-list input[type="checkbox"]:checked' ),
				function ( cb ) { return cb.value; }
			);

			if ( ! checked.length ) {
				window.alert( config.i18n.noneSelected );
				return;
			}

			var updated = currentOrder().concat( checked );

			post( 'wcmcs_save_enabled_currencies', { currencies: updated } ).then( function ( response ) {
				if ( response.success ) { window.location.reload(); }
			} );
		} );
	}

	function initPreview() {
		var button = document.getElementById( 'wcmcs-preview-button' );
		var select = document.getElementById( 'wcmcs-preview-product' );
		var results = document.getElementById( 'wcmcs-preview-results' );
		if ( ! button ) { return; }

		button.addEventListener( 'click', function () {
			if ( ! select.value ) { return; }

			results.textContent = config.i18n.previewing;

			post( 'wcmcs_preview_prices', { product_id: select.value } ).then( function ( response ) {
				if ( ! response.success ) {
					results.textContent = config.i18n.saveFailed;
					return;
				}

				var data = response.data;
				var table = document.createElement( 'table' );
				table.className = 'widefat striped';

				var thead = document.createElement( 'thead' );
				thead.innerHTML = '<tr><th>Currency</th><th>Price</th></tr>';
				table.appendChild( thead );

				var tbody = document.createElement( 'tbody' );
				data.results.forEach( function ( row ) {
					var tr = document.createElement( 'tr' );
					var tdCode = document.createElement( 'td' );
					tdCode.textContent = row.code + ( row.code === config.baseCurrency ? ' (base)' : '' );
					var tdPrice = document.createElement( 'td' );
					tdPrice.textContent = row.formatted || '—';
					tr.appendChild( tdCode );
					tr.appendChild( tdPrice );
					tbody.appendChild( tr );
				} );
				table.appendChild( tbody );

				results.innerHTML = '';
				var heading = document.createElement( 'p' );
				heading.innerHTML = '<strong>' + data.product_name + '</strong> — base price: ' + data.base_price;
				results.appendChild( heading );
				results.appendChild( table );
			} );
		} );
	}
} )();
