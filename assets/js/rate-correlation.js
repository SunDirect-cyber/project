/**
 * Rate Correlation screen: fetches the merged rate+sales series and
 * draws them as two stacked line charts sharing the same x-axis dates,
 * so a sales dip lining up with a rate spike is visible at a glance.
 */
( function () {
	'use strict';

	var config = window.wcmcsRateCorrelation || {};

	function fetchSeries( currency, from, to ) {
		var url = config.ajaxUrl +
			'?action=wcmcs_get_rate_correlation' +
			'&currency=' + encodeURIComponent( currency ) +
			'&from=' + encodeURIComponent( from ) +
			'&to=' + encodeURIComponent( to ) +
			'&nonce=' + encodeURIComponent( config.nonce );

		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( response ) {
				if ( ! response.success || ! window.WcmcsChartLite ) { return; }
				render( response.data.series, currency );
			} );
	}

	function render( series, currency ) {
		var rateCanvas = document.getElementById( 'wcmcs-correlation-rate-chart' );
		var salesCanvas = document.getElementById( 'wcmcs-correlation-sales-chart' );

		var ratePoints = series
			.filter( function ( row ) { return null !== row.rate; } )
			.map( function ( row ) { return { x: row.date, y: row.rate }; } );

		var salesPoints = series.map( function ( row ) { return { x: row.date, y: row.revenue }; } );

		window.WcmcsChartLite.drawLine( rateCanvas, [ { label: currency, points: ratePoints, color: '#d63638' } ] );
		window.WcmcsChartLite.drawLine( salesCanvas, [ { label: 'Revenue', points: salesPoints, color: '#2271b1' } ] );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var currencySelect = document.getElementById( 'wcmcs-correlation-currency' );
		var fromInput = document.getElementById( 'wcmcs-correlation-from' );
		var toInput = document.getElementById( 'wcmcs-correlation-to' );
		var button = document.getElementById( 'wcmcs-correlation-update' );

		if ( ! currencySelect ) { return; }

		function update() {
			fetchSeries( currencySelect.value, fromInput.value, toInput.value );
		}

		button.addEventListener( 'click', update );
		update();
	} );
} )();
