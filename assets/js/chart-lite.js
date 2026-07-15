/**
 * Small dependency-free canvas chart renderer, shared across the
 * analytics admin screens. Same reasoning as the Exchange Rates page's
 * hand-written line chart: no way to fetch and verify a real Chart.js
 * bundle in this environment, and no external CDN dependency wanted
 * anyway — this covers exactly the two chart types the analytics
 * screens need (multi-series line, pie) in well under 200 lines.
 */
( function ( global ) {
	'use strict';

	var PALETTE = [ '#2271b1', '#d63638', '#00a32a', '#dba617', '#8c8f94', '#9b51e0', '#f56e28', '#00b0c7' ];

	function colorFor( index ) {
		return PALETTE[ index % PALETTE.length ];
	}

	/**
	 * @param {HTMLCanvasElement} canvas
	 * @param {{label: string, points: {x: string, y: number}[]}[]} datasets
	 */
	function drawLine( canvas, datasets ) {
		var ctx = canvas.getContext( '2d' );
		var w = canvas.width;
		var h = canvas.height;
		var padding = { top: 20, right: 20, bottom: 30, left: 60 };

		ctx.clearRect( 0, 0, w, h );

		var allPoints = datasets.reduce( function ( acc, d ) { return acc.concat( d.points ); }, [] );

		if ( ! allPoints.length ) {
			ctx.fillStyle = '#666';
			ctx.font = '13px sans-serif';
			ctx.fillText( 'No data for this period.', 20, 30 );
			return;
		}

		var values = allPoints.map( function ( p ) { return p.y; } );
		var min = Math.min( 0, Math.min.apply( null, values ) );
		var max = Math.max.apply( null, values ) || 1;

		var plotW = w - padding.left - padding.right;
		var plotH = h - padding.top - padding.bottom;
		var labelCount = datasets[ 0 ].points.length;

		function xAt( i ) {
			return padding.left + ( labelCount <= 1 ? plotW / 2 : ( i / ( labelCount - 1 ) ) * plotW );
		}
		function yAt( value ) {
			return padding.top + plotH - ( ( value - min ) / ( max - min || 1 ) ) * plotH;
		}

		ctx.strokeStyle = '#ccc';
		ctx.beginPath();
		ctx.moveTo( padding.left, padding.top );
		ctx.lineTo( padding.left, padding.top + plotH );
		ctx.lineTo( padding.left + plotW, padding.top + plotH );
		ctx.stroke();

		ctx.fillStyle = '#555';
		ctx.font = '11px sans-serif';
		ctx.fillText( max.toFixed( 2 ), 4, padding.top + 4 );
		ctx.fillText( min.toFixed( 2 ), 4, padding.top + plotH );

		datasets.forEach( function ( dataset, di ) {
			var color = dataset.color || colorFor( di );

			ctx.strokeStyle = color;
			ctx.lineWidth = 2;
			ctx.beginPath();
			dataset.points.forEach( function ( p, i ) {
				var x = xAt( i );
				var y = yAt( p.y );
				if ( 0 === i ) { ctx.moveTo( x, y ); } else { ctx.lineTo( x, y ); }
			} );
			ctx.stroke();
		} );

		// Legend.
		var legendX = padding.left;
		var legendY = 10;
		datasets.forEach( function ( dataset, di ) {
			var color = dataset.color || colorFor( di );
			ctx.fillStyle = color;
			ctx.fillRect( legendX, legendY, 10, 10 );
			ctx.fillStyle = '#333';
			ctx.font = '11px sans-serif';
			ctx.fillText( dataset.label, legendX + 14, legendY + 9 );
			legendX += 14 + ctx.measureText( dataset.label ).width + 16;
		} );
	}

	/**
	 * @param {HTMLCanvasElement} canvas
	 * @param {{label: string, value: number}[]} slices
	 */
	function drawPie( canvas, slices ) {
		var ctx = canvas.getContext( '2d' );
		var w = canvas.width;
		var h = canvas.height;

		ctx.clearRect( 0, 0, w, h );

		var total = slices.reduce( function ( sum, s ) { return sum + s.value; }, 0 );

		if ( ! total ) {
			ctx.fillStyle = '#666';
			ctx.font = '13px sans-serif';
			ctx.fillText( 'No data for this period.', 20, 30 );
			return;
		}

		var cx = h / 2;
		var cy = h / 2;
		var radius = h / 2 - 10;
		var start = -Math.PI / 2;

		slices.forEach( function ( slice, i ) {
			var angle = ( slice.value / total ) * Math.PI * 2;
			ctx.beginPath();
			ctx.moveTo( cx, cy );
			ctx.arc( cx, cy, radius, start, start + angle );
			ctx.closePath();
			ctx.fillStyle = slice.color || colorFor( i );
			ctx.fill();
			start += angle;
		} );

		var legendX = h + 10;
		var legendY = 10;
		slices.forEach( function ( slice, i ) {
			var pct = ( ( slice.value / total ) * 100 ).toFixed( 1 );
			ctx.fillStyle = slice.color || colorFor( i );
			ctx.fillRect( legendX, legendY, 10, 10 );
			ctx.fillStyle = '#333';
			ctx.font = '11px sans-serif';
			ctx.fillText( slice.label + ' (' + pct + '%)', legendX + 14, legendY + 9 );
			legendY += 18;
		} );
	}

	global.WcmcsChartLite = { drawLine: drawLine, drawPie: drawPie };
} )( window );
