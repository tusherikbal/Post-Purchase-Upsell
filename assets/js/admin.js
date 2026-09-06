/* global jQuery */
( function ( $ ) {
	'use strict';

	function updateDiscountHint() {
		var $type = $( '#pp_discount_type' );
		var $hint = $( '#pp_discount_amount_hint' );

		if ( ! $type.length || ! $hint.length ) {
			return;
		}

		$hint.text( 'fixed' === $type.val() ? '$' : '%' );
	}

	/**
	 * Live "Offer Preview" sidebar box -- mirrors the text/discount/color
	 * fields on every keystroke, no AJAX or reload. Uses a placeholder $100
	 * "Sample Product" since the real offer product's price isn't known
	 * client-side without an extra request.
	 */
	function initPreview() {
		var $preview = $( '#pp-upsell-preview' );

		if ( ! $preview.length ) {
			return;
		}

		var SAMPLE_PRICE = 100;

		function formatMoney( amount ) {
			return '$' + amount.toFixed( 2 );
		}

		function mirrorText( fieldId, previewId ) {
			var $field = $( '#' + fieldId );
			var $target = $( '#' + previewId );

			if ( ! $field.length || ! $target.length ) {
				return;
			}

			var value = $field.val();
			$target.text( value || $field.attr( 'placeholder' ) || '' );
		}

		function updatePrice() {
			var type = $( '#pp_discount_type' ).val();
			var amount = parseFloat( $( '#pp_discount_amount' ).val() ) || 0;
			var finalPrice = SAMPLE_PRICE;

			if ( 'fixed' === type ) {
				finalPrice = SAMPLE_PRICE - amount;
			} else {
				finalPrice = SAMPLE_PRICE - ( SAMPLE_PRICE * amount / 100 );
			}

			finalPrice = Math.max( 0, finalPrice );

			var hasDiscount = finalPrice < SAMPLE_PRICE;

			$( '#pp-preview-price-regular' ).toggle( hasDiscount ).text( formatMoney( SAMPLE_PRICE ) );
			$( '#pp-preview-price-final' ).text( formatMoney( finalPrice ) );

			var acceptLabel = $( '#pp_text_accept' ).val() || $( '#pp_text_accept' ).attr( 'placeholder' ) || '';
			$( '#pp-preview-accept' ).text( acceptLabel + ' (' + formatMoney( finalPrice ) + ')' );
		}

		function updateAll() {
			mirrorText( 'pp_text_eyebrow', 'pp-preview-eyebrow' );
			mirrorText( 'pp_text_headline', 'pp-preview-headline' );
			mirrorText( 'pp_text_decline', 'pp-preview-decline' );
			mirrorText( 'pp_text_note_charge', 'pp-preview-note' );
			updatePrice();
		}

		$( document ).on(
			'input change',
			'#pp_text_eyebrow, #pp_text_headline, #pp_text_accept, #pp_text_decline, #pp_text_note_charge, #pp_discount_type, #pp_discount_amount',
			updateAll
		);

		function applyColor( cssVar, hex ) {
			$preview.css( cssVar, hex );
		}

		$( '#pp_color_accent, #pp_color_button_text' ).wpColorPicker( {
			change: function ( event, ui ) {
				var cssVar = 'pp_color_accent' === this.id ? '--pp-accent' : '--pp-accent-text';
				applyColor( cssVar, ui.color.toString() );
			},
			clear: function () {
				var $input = $( this );
				var cssVar = 'pp_color_accent' === $input.attr( 'id' ) ? '--pp-accent' : '--pp-accent-text';
				applyColor( cssVar, $input.data( 'default-color' ) );
			},
		} );

		$( document ).on( 'click', '.pp-upsell-preview-tab', function () {
			var context = $( this ).data( 'context' );

			$( '.pp-upsell-preview-tab' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );

			$( '#pp-upsell-preview-context' )
				.removeClass( 'pp-upsell-preview-context--popup pp-upsell-preview-context--page' )
				.addClass( 'pp-upsell-preview-context--' + context );
		} );

		updateAll();
	}

	$( function () {
		updateDiscountHint();
		$( document ).on( 'change', '#pp_discount_type', updateDiscountHint );
		initPreview();
	} );
} )( jQuery );
