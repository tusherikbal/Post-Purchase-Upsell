( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var canvas = document.getElementById( 'pp-upsell-trend-chart' );

		if ( ! canvas || 'undefined' === typeof window.ppUpsellTrend || ! window.ppUpsellTrend.length ) {
			return;
		}

		var data = window.ppUpsellTrend;
		var dpr = window.devicePixelRatio || 1;
		var cssWidth = canvas.parentNode.clientWidth || 600;
		var cssHeight = 220;

		canvas.style.width = cssWidth + 'px';
		canvas.style.height = cssHeight + 'px';
		canvas.width = cssWidth * dpr;
		canvas.height = cssHeight * dpr;

		var ctx = canvas.getContext( '2d' );
		ctx.scale( dpr, dpr );

		var padding = { top: 24, right: 16, bottom: 24, left: 32 };
		var plotW = cssWidth - padding.left - padding.right;
		var plotH = cssHeight - padding.top - padding.bottom;

		var maxViews = 1;
		data.forEach( function ( d ) {
			maxViews = Math.max( maxViews, d.views );
		} );

		function x( i ) {
			return padding.left + ( data.length > 1 ? ( plotW * i ) / ( data.length - 1 ) : plotW / 2 );
		}

		function y( value ) {
			return padding.top + plotH - ( plotH * value ) / maxViews;
		}

		function drawLine( key, color ) {
			ctx.strokeStyle = color;
			ctx.lineWidth = 2;
			ctx.beginPath();
			data.forEach( function ( d, i ) {
				var px = x( i );
				var py = y( d[ key ] );
				if ( 0 === i ) {
					ctx.moveTo( px, py );
				} else {
					ctx.lineTo( px, py );
				}
			} );
			ctx.stroke();

			ctx.fillStyle = color;
			data.forEach( function ( d, i ) {
				ctx.beginPath();
				ctx.arc( x( i ), y( d[ key ] ), 2.5, 0, 2 * Math.PI );
				ctx.fill();
			} );
		}

		// Axes.
		ctx.strokeStyle = '#dcdcde';
		ctx.lineWidth = 1;
		ctx.beginPath();
		ctx.moveTo( padding.left, padding.top );
		ctx.lineTo( padding.left, padding.top + plotH );
		ctx.lineTo( padding.left + plotW, padding.top + plotH );
		ctx.stroke();

		ctx.fillStyle = '#787c82';
		ctx.font = '11px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
		ctx.textAlign = 'right';
		ctx.fillText( String( maxViews ), padding.left - 6, padding.top + 4 );
		ctx.fillText( '0', padding.left - 6, padding.top + plotH + 4 );

		// X-axis labels: first, middle, last date (MM-DD).
		ctx.textAlign = 'center';
		[ 0, Math.floor( ( data.length - 1 ) / 2 ), data.length - 1 ].forEach( function ( i ) {
			if ( data[ i ] ) {
				ctx.fillText( data[ i ].date.slice( 5 ), x( i ), padding.top + plotH + 16 );
			}
		} );

		drawLine( 'views', '#8a8f98' );
		drawLine( 'accepted', '#1a7f37' );

		// Legend.
		ctx.textAlign = 'left';
		ctx.fillStyle = '#8a8f98';
		ctx.fillText( '● Views', padding.left, 12 );
		ctx.fillStyle = '#1a7f37';
		ctx.fillText( '● Accepted', padding.left + 70, 12 );
	} );
} )();
