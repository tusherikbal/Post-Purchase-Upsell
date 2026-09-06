<?php
/**
 * Renders the offer as a popup on the real Thank You page.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Popup_Renderer
 *
 * Alternative to the standalone offer page: when the "display_mode" setting
 * is "popup", the customer is never redirected away from the real Thank You
 * page at all (PP_Upsell_Redirect_Controller leaves the URL untouched in
 * that mode) -- instead this hooks `woocommerce_thankyou`, which WooCommerce
 * fires with the order id while rendering that page's content, looks up the
 * pending attempt already created for that order, and injects a self-
 * contained modal (inline CSS/JS, no separate enqueue) on top of it.
 */
class PP_Upsell_Popup_Renderer {

	public function __construct() {
		add_action( 'woocommerce_thankyou', array( $this, 'maybe_render_popup' ), 5 );
	}

	/**
	 * @param int $order_id Order id WooCommerce is rendering the thank-you
	 *                       page for (0/empty on an invalid order key).
	 */
	public function maybe_render_popup( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		$settings = get_option( PP_Upsell_Main::OPTION_KEY, array() );

		if ( 'popup' !== PP_Upsell_Redirect_Controller::get_display_mode( $settings ) ) {
			return;
		}

		$attempt = PP_Upsell_Attempts_Repository::get_pending_by_order( $order_id );

		if ( ! $attempt ) {
			return;
		}

		if ( PP_Upsell_Attempts_Repository::maybe_expire( $attempt->id ) ) {
			return;
		}

		$offer   = get_post( $attempt->offer_id );
		$product = $offer ? PP_Upsell_Offer_Repository::get_offer_product( $offer->ID ) : null;

		if ( ! $offer || 'publish' !== $offer->post_status || ! $product ) {
			return;
		}

		PP_Upsell_Attempts_Repository::mark_viewed( $attempt->id );

		$price = PP_Upsell_Offer_Repository::get_discounted_price( $offer->ID, $product );

		PP_Upsell_Offer_Page::render_popup( $attempt, $offer, $product, $price );
	}
}
