<?php
/**
 * Applies a successful upsell charge onto the parent order.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Order_Manager
 *
 * Per the PRD's own v1 decision, an accepted upsell becomes a new line item
 * on the SAME parent order rather than a separate child order -- simpler,
 * and avoids split-refund complexity.
 */
class PP_Upsell_Order_Manager {

	/**
	 * @param WC_Order   $order            The customer's original order.
	 * @param WP_Post    $offer            The matched offer post.
	 * @param WC_Product $product          The offer product.
	 * @param float      $price            The price actually charged.
	 * @param array      $transaction_meta ['transaction_id' => string].
	 * @return WC_Order_Item_Product
	 */
	public static function add_upsell_line_item( WC_Order $order, $offer, WC_Product $product, $price, array $transaction_meta = array() ) {

		// add_product() returns the new item's id (int), not the item object.
		$item_id = $order->add_product(
			$product,
			1,
			array(
				'subtotal' => $price,
				'total'    => $price,
			)
		);

		$item = $order->get_item( $item_id );
		$item->add_meta_data( '_pp_upsell_offer_id', $offer->ID, true );
		$item->save();

		// Not wc_reduce_stock_levels( $order ) -- that walks every line item
		// on the order and would double-decrement the items that were
		// already reduced when the original order was paid.
		if ( $product->managing_stock() ) {
			wc_update_product_stock( $product, 1, 'decrease' );
		}

		$transaction_id = isset( $transaction_meta['transaction_id'] ) ? $transaction_meta['transaction_id'] : '';

		// Distinct meta key -- never overwrites the order's own
		// _transaction_id, so refund/lookup tooling that assumes one
		// transaction id per order-payment isn't corrupted.
		$order->update_meta_data( '_pp_upsell_transaction_id', $transaction_id );
		$order->update_meta_data( '_pp_upsell_offer_id', $offer->ID );

		if ( $transaction_id ) {
			$note = sprintf(
				/* translators: 1: product name, 2: price, 3: transaction id */
				__( 'Post-purchase upsell accepted: %1$s added for %2$s (charged, transaction: %3$s).', 'post-purchase-upsell' ),
				$product->get_name(),
				wp_strip_all_tags( wc_price( $price ) ),
				$transaction_id
			);
		} else {
			$note = sprintf(
				/* translators: 1: product name, 2: price, 3: payment method title */
				__( 'Post-purchase upsell accepted: %1$s added for %2$s (to be collected via %3$s -- no online charge made).', 'post-purchase-upsell' ),
				$product->get_name(),
				wp_strip_all_tags( wc_price( $price ) ),
				$order->get_payment_method_title()
			);
		}

		$order->add_order_note( $note );

		$order->calculate_totals( true );
		$order->save();

		do_action( 'pp_upsell_line_item_added', $order, $offer, $item );

		return $item;
	}
}
