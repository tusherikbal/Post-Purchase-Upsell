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

	$( function () {
		updateDiscountHint();
		$( document ).on( 'change', '#pp_discount_type', updateDiscountHint );
	} );
} )( jQuery );
