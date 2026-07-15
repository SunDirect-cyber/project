/**
 * Exchange Rates admin page: fetches rate history via admin-ajax, draws
 * it as a simple line chart on <canvas> with no external charting
 * library, and wires up the "Refresh Rates Now" and per-row rollback
 * actions. Vanilla JS/DOM only — no build step, no dependencies.
 */
( function () {
	'use strict';

	var config = window.wcmcsRateHistory || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		var select = document.getElementById( 'wcmcs-target-currency' );
		var canvas = document.getElementById( 'wcmcs-rate-chart' );
		var refreshBtn = document.getElementById( 'wcmcs-refresh-now' );

		if ( ! select || ! canvas ) {
			return;
		}

		function currentTarget() {
			return select.value;
		}

		function loadHistory() {
			var status = document.getElementById( 'wcmcs-refresh-status' );
			if ( status ) {
				status.textContent = config.i18n.loading;
			}

			var url = config.ajaxUrl +
				'?action=wcmcs_get_rate_history' +
				'&base=' + encodeURIComponent( config.baseCurrency ) +
				'&target=' + encodeURIComponent( currentTarget() ) +
				'&limit=60' +
				'&nonce=' + encodeURIComponent( config.historyNonce );

			fetch( url, { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( response ) {
					if ( status ) {
						status.textContent = '';
					}

					if ( ! response.success ) {
						renderEmpty();
						return;
					}

					var history = response.data.history || [];
					drawChart( history );
					renderTable( history );
				} )
				.catch( function () {
					if ( status ) {
						status.textContent = config.i18n.refreshFailed;
					}
				} );
		}

		function renderEmpty() {
			var ctx = canvas.getContext( '2d' );
			ctx.clearRect( 0, 0, canvas.width, canvas.height );
			ctx.fillStyle = '#666';
			ctx.font = '14px sans-serif';
			ctx.fillText( config.i18n.noData, 20, 30 );

			var tbody = document.querySelector( '#wcmcs-history-table tbody' );
			if ( tbody ) {
				tbody.innerHTML = '';
			}
		}

		/**
		 * Minimal line chart: plots rate over time, oldest to newest,
		 * with axis labels. No smoothing, no external dependency — just
		 * enough to visualise a trend.
		 */
		function drawChart( history ) {
			var ctx = canvas.getContext( '2d' );
			var w = canvas.width;
			var h = canvas.height;
			var padding = { top: 20, right: 20, bottom: 30, left: 60 };

			ctx.clearRect( 0, 0, w, h );

			if ( ! history.length ) {
				renderEmpty();
				return;
			}

			// History comes back newest-first; plot oldest-first, left to right.
			var points = history.slice().reverse();
			var rates = points.map( function ( p ) { return parseFloat( p.rate ); } );
			var min = Math.min.apply( null, rates );
			var max = Math.max.apply( null, rates );

			// Avoid a zero-height range (e.g. every rate identical) collapsing the chart.
			if ( min === max ) {
				min -= Math.abs( min ) * 0.05 || 0.5;
				max += Math.abs( max ) * 0.05 || 0.5;
			}

			var plotW = w - padding.left - padding.right;
			var plotH = h - padding.top - padding.bottom;

			function xAt( i ) {
				return padding.left + ( points.length === 1 ? plotW / 2 : ( i / ( points.length - 1 ) ) * plotW );
			}

			function yAt( rate ) {
				return padding.top + plotH - ( ( rate - min ) / ( max - min ) ) * plotH;
			}

			// Axes.
			ctx.strokeStyle = '#ccc';
			ctx.lineWidth = 1;
			ctx.beginPath();
			ctx.moveTo( padding.left, padding.top );
			ctx.lineTo( padding.left, padding.top + plotH );
			ctx.lineTo( padding.left + plotW, padding.top + plotH );
			ctx.stroke();

			// Y-axis min/max labels.
			ctx.fillStyle = '#555';
			ctx.font = '11px sans-serif';
			ctx.fillText( max.toFixed( 4 ), 4, padding.top + 4 );
			ctx.fillText( min.toFixed( 4 ), 4, padding.top + plotH );

			// Line.
			ctx.strokeStyle = '#2271b1';
			ctx.lineWidth = 2;
			ctx.beginPath();
			points.forEach( function ( p, i ) {
				var x = xAt( i );
				var y = yAt( parseFloat( p.rate ) );
				if ( 0 === i ) {
					ctx.moveTo( x, y );
				} else {
					ctx.lineTo( x, y );
				}
			} );
			ctx.stroke();

			// Points.
			ctx.fillStyle = '#2271b1';
			points.forEach( function ( p, i ) {
				ctx.beginPath();
				ctx.arc( xAt( i ), yAt( parseFloat( p.rate ) ), 3, 0, Math.PI * 2 );
				ctx.fill();
			} );
		}

		function renderTable( history ) {
			var tbody = document.querySelector( '#wcmcs-history-table tbody' );
			if ( ! tbody ) {
				return;
			}

			tbody.innerHTML = '';

			history.forEach( function ( row ) {
				var tr = document.createElement( 'tr' );

				var tdDate = document.createElement( 'td' );
				tdDate.textContent = row.created_at;
				tr.appendChild( tdDate );

				var tdRate = document.createElement( 'td' );
				tdRate.textContent = row.rate;
				tr.appendChild( tdRate );

				var tdSource = document.createElement( 'td' );
				tdSource.textContent = row.source;
				tr.appendChild( tdSource );

				var tdAction = document.createElement( 'td' );
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'button';
				btn.textContent = 'Rollback';
				btn.addEventListener( 'click', function () {
					rollback( row.id );
				} );
				tdAction.appendChild( btn );
				tr.appendChild( tdAction );

				tbody.appendChild( tr );
			} );
		}

		function rollback( historyId ) {
			if ( ! window.confirm( config.i18n.confirmRollback ) ) {
				return;
			}

			var body = new URLSearchParams();
			body.set( 'action', 'wcmcs_rollback_rate' );
			body.set( 'nonce', config.historyNonce );
			body.set( 'history_id', historyId );

			fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( response ) {
					var status = document.getElementById( 'wcmcs-refresh-status' );
					if ( status ) {
						status.textContent = response.success ? config.i18n.rollbackDone : config.i18n.rollbackFailed;
					}
					if ( response.success ) {
						loadHistory();
					}
				} );
		}

		if ( refreshBtn ) {
			refreshBtn.addEventListener( 'click', function () {
				var status = document.getElementById( 'wcmcs-refresh-status' );
				if ( status ) {
					status.textContent = config.i18n.refreshing;
				}

				var body = new URLSearchParams();
				body.set( 'action', 'wcmcs_refresh_rates' );
				body.set( config.refreshNonceField, config.refreshNonce );

				fetch( config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( response ) {
						if ( status ) {
							// Prefer the server's own message when present —
							// this is what carries the rate-limit "please
							// wait Ns" text back to the admin instead of a
							// generic failure string.
							status.textContent = response.success
								? config.i18n.refreshDone
								: ( response.data && response.data.message ? response.data.message : config.i18n.refreshFailed );
						}
						if ( response.success ) {
							loadHistory();
						} else if ( response.data && response.data.retry_after ) {
							refreshBtn.disabled = true;
							setTimeout( function () {
								refreshBtn.disabled = false;
							}, response.data.retry_after * 1000 );
						}
					} )
					.catch( function () {
						if ( status ) {
							status.textContent = config.i18n.refreshFailed;
						}
					} );
			} );
		}

		select.addEventListener( 'change', loadHistory );

		loadHistory();
	} );
} )();
