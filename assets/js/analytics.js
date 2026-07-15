/**
 * Analytics screen: date-range controls, fetches from
 * AnalyticsAjaxController, renders the totals table and both charts via
 * chart-lite.js.
 */
( function () {
	'use strict';

	var config = window.wcmcsAnalytics || {};

	function isoDate( date ) {
		return date.toISOString().slice( 0, 10 );
	}

	function daysAgo( n ) {
		var d = new Date();
		d.setDate( d.getDate() - n );
		return d;
	}

	function fetchAnalytics( from, to, compareYoy ) {
		var status = document.getElementById( 'wcmcs-analytics-status' );
		status.textContent = config.i18n.loading;

		var url = config.ajaxUrl +
			'?action=wcmcs_get_analytics' +
			'&from=' + encodeURIComponent( from ) +
			'&to=' + encodeURIComponent( to ) +
			'&compare_yoy=' + ( compareYoy ? '1' : '0' ) +
			'&nonce=' + encodeURIComponent( config.nonce );

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( response ) {
				status.textContent = '';
				if ( ! response.success ) { return; }
				render( response.data, compareYoy );
			} );
	}

	function render( data, compareYoy ) {
		renderTable( data.totals, data.previous_year ? data.previous_year.totals : null, compareYoy );
		renderTrendChart( data.trend );
		renderDistributionChart( data.totals );
	}

	function renderTable( totals, previousYearTotals, compareYoy ) {
		var tbody = document.querySelector( '#wcmcs-totals-table tbody' );
		var yoyHeader = document.getElementById( 'wcmcs-yoy-header' );
		tbody.innerHTML = '';
		yoyHeader.hidden = ! compareYoy;

		var codes = Object.keys( totals );

		if ( ! codes.length ) {
			var emptyRow = document.createElement( 'tr' );
			var emptyCell = document.createElement( 'td' );
			emptyCell.colSpan = compareYoy ? 6 : 5;
			emptyCell.textContent = config.i18n.noData;
			emptyRow.appendChild( emptyCell );
			tbody.appendChild( emptyRow );
			return;
		}

		codes.forEach( function ( code ) {
			var row = totals[ code ];
			var tr = document.createElement( 'tr' );

			[ code, row.order_count, row.revenue.toFixed( 2 ) + ' ' + code, row.average_order_value.toFixed( 2 ) + ' ' + code, row.revenue_base_currency.toFixed( 2 ) + ' ' + config.baseCurrency ].forEach( function ( val ) {
				var td = document.createElement( 'td' );
				td.textContent = String( val );
				tr.appendChild( td );
			} );

			if ( compareYoy ) {
				var td = document.createElement( 'td' );
				var prev = previousYearTotals && previousYearTotals[ code ] ? previousYearTotals[ code ].revenue : 0;
				if ( prev > 0 ) {
					var change = ( ( row.revenue - prev ) / prev ) * 100;
					td.textContent = ( change >= 0 ? '+' : '' ) + change.toFixed( 1 ) + '%';
				} else {
					td.textContent = '—';
				}
				tr.appendChild( td );
			}

			tbody.appendChild( tr );
		} );
	}

	function renderTrendChart( trend ) {
		var canvas = document.getElementById( 'wcmcs-trend-chart' );
		if ( ! canvas || ! window.WcmcsChartLite ) { return; }

		var byCurrency = {};
		var dateSet = {};

		trend.forEach( function ( row ) {
			byCurrency[ row.currency ] = byCurrency[ row.currency ] || {};
			byCurrency[ row.currency ][ row.stat_date ] = row.revenue;
			dateSet[ row.stat_date ] = true;
		} );

		var dates = Object.keys( dateSet ).sort();

		var datasets = Object.keys( byCurrency ).map( function ( code ) {
			return {
				label: code,
				points: dates.map( function ( date ) {
					return { x: date, y: byCurrency[ code ][ date ] || 0 };
				} )
			};
		} );

		if ( ! datasets.length ) {
			datasets = [ { label: '', points: [] } ];
		}

		window.WcmcsChartLite.drawLine( canvas, datasets );
	}

	function renderDistributionChart( totals ) {
		var canvas = document.getElementById( 'wcmcs-distribution-chart' );
		if ( ! canvas || ! window.WcmcsChartLite ) { return; }

		var slices = Object.keys( totals ).map( function ( code ) {
			return { label: code, value: totals[ code ].revenue_base_currency };
		} );

		window.WcmcsChartLite.drawPie( canvas, slices );
	}

	function currentRange() {
		var compareYoy = document.getElementById( 'wcmcs-compare-yoy' ).checked;
		return compareYoy;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var fromInput = document.getElementById( 'wcmcs-range-from' );
		var toInput = document.getElementById( 'wcmcs-range-to' );

		function applyPreset( days ) {
			var to = new Date();
			var from = 'today' === days ? new Date() : daysAgo( parseInt( days, 10 ) - 1 );
			fromInput.value = isoDate( from );
			toInput.value = isoDate( to );
			fetchAnalytics( isoDate( from ), isoDate( to ), currentRange() );
		}

		document.querySelectorAll( '[data-wcmcs-range]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				applyPreset( button.getAttribute( 'data-wcmcs-range' ) );
			} );
		} );

		document.getElementById( 'wcmcs-range-custom' ).addEventListener( 'click', function () {
			if ( fromInput.value && toInput.value ) {
				fetchAnalytics( fromInput.value, toInput.value, currentRange() );
			}
		} );

		document.getElementById( 'wcmcs-compare-yoy' ).addEventListener( 'change', function () {
			if ( fromInput.value && toInput.value ) {
				fetchAnalytics( fromInput.value, toInput.value, currentRange() );
			}
		} );

		applyPreset( '30' );
	} );
} )();
