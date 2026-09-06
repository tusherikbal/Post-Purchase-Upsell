( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.blocksCheckout || ! window.wp || ! window.wp.data || ! window.wp.element || ! window.wp.plugins ) {
		return;
	}

	var ExperimentalOrderMeta = window.wc.blocksCheckout.ExperimentalOrderMeta;
	var registerPlugin = window.wp.plugins.registerPlugin;
	var createElement = window.wp.element.createElement;
	var useSelect = window.wp.data.useSelect;
	var dispatch = window.wp.data.dispatch;

	var settings = ( window.wc.wcSettings && window.wc.wcSettings.getSetting( 'pp-orderbump_data' ) ) || {};
	var NAMESPACE = settings.namespace || 'post-purchase-upsell/order-bumps';

	function OrderBumps() {
		var extensionData = useSelect( function ( select ) {
			var cartData = select( 'wc/store/cart' ).getCartData();
			return ( cartData && cartData.extensions && cartData.extensions[ NAMESPACE ] ) || { checked: [], bumps: [] };
		}, [] );

		var bumps = extensionData.bumps || [];
		var checked = extensionData.checked || [];

		if ( ! bumps.length ) {
			return null;
		}

		function toggle( id, isChecked ) {
			var next = checked.slice();
			var idx = next.indexOf( id );

			if ( isChecked && -1 === idx ) {
				next.push( id );
			} else if ( ! isChecked && -1 !== idx ) {
				next.splice( idx, 1 );
			}

			dispatch( 'wc/store/cart' ).applyExtensionCartUpdate( {
				namespace: NAMESPACE,
				data: { checked: next },
			} );
		}

		return createElement(
			'div',
			{ className: 'pp-orderbump-blocks' },
			bumps.map( function ( bump ) {
				var checkbox = createElement( 'input', {
					type: 'checkbox',
					checked: -1 !== checked.indexOf( bump.id ),
					onChange: function ( e ) {
						toggle( bump.id, e.target.checked );
					},
				} );

				var priceChildren = [];
				if ( bump.hasDiscount ) {
					priceChildren.push(
						createElement( 'span', { key: 'reg', className: 'pp-orderbump-blocks__price-regular' }, bump.regularPriceText )
					);
				}
				priceChildren.push(
					createElement( 'span', { key: 'final', className: 'pp-orderbump-blocks__price-final' }, bump.priceText )
				);
				if ( bump.freeShipping ) {
					priceChildren.push(
						createElement( 'span', { key: 'ship', className: 'pp-orderbump-blocks__free-shipping' }, '+ Free Shipping' )
					);
				}

				return createElement(
					'label',
					{ key: bump.id, className: 'pp-orderbump-blocks__item pp-orderbump-blocks__item--product' },
					checkbox,
					createElement( 'img', { className: 'pp-orderbump-blocks__thumb', src: bump.imageUrl, alt: '' } ),
					createElement(
						'span',
						{ className: 'pp-orderbump-blocks__text' },
						createElement( 'span', { className: 'pp-orderbump-blocks__name' }, bump.name ),
						createElement( 'span', { className: 'pp-orderbump-blocks__price' }, priceChildren )
					)
				);
			} )
		);
	}

	registerPlugin( 'pp-upsell-order-bumps', {
		render: function () {
			return createElement( ExperimentalOrderMeta, null, createElement( OrderBumps, null ) );
		},
		scope: 'woocommerce-checkout',
	} );
} )();
