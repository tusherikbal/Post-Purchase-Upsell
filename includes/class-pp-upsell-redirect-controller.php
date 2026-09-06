<?php
/**
 * Intercepts the post-checkout redirect and serves the offer page.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Redirect_Controller
 *
 * Hooks `woocommerce_get_checkout_order_received_url`, which fires inside
 * WC_Order::get_checkout_order_received_url() -- and therefore inside every
 * WC_Payment_Gateway::get_return_url( $order ) call too, since that method
 * always calls get_checkout_order_received_url() first when an order is
 * present. That makes it the single most reliable interception point,
 * independent of which gateway or checkout renderer (classic/Elementor/
 * Blocks) is in use -- no separate `woocommerce_get_return_url` hook is
 * needed, since it is always reached via this one for real orders.
 */
class PP_Upsell_Redirect_Controller {

	const QV_ID    = 'pp_upsell_id';
	const QV_TOKEN = 'pp_upsell_token';

	/**
	 * Per-request guard so an order already handled once (e.g. if some
	 * other code also calls get_checkout_order_received_url() again for
	 * logging/reference) is never processed twice in the same request.
	 *
	 * @var int[]
	 */
	private static $handled_order_ids = array();

	public function __construct() {
		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'maybe_redirect_to_offer' ), 10, 2 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_offer_page' ), 0 );
	}

	/**
	 * @param array $vars Existing public query vars.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = self::QV_ID;
		$vars[] = self::QV_TOKEN;
		return $vars;
	}

	/**
	 * Filters the thank-you URL. Returns it unchanged unless every
	 * eligibility condition is met, in which case it returns our own
	 * offer-page URL instead.
	 *
	 * @param string        $url   Real thank-you URL.
	 * @param WC_Order|null $order Order object.
	 * @return string
	 */
	public function maybe_redirect_to_offer( $url, $order ) {

		if ( ! $order instanceof WC_Order ) {
			return $url;
		}

		$order_id = $order->get_id();

		if ( isset( self::$handled_order_ids[ $order_id ] ) ) {
			return $url;
		}

		self::$handled_order_ids[ $order_id ] = true;

		if ( (float) $order->get_total() <= 0 ) {
			return $url;
		}

		if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			return $url;
		}

		$customer_id = $order->get_customer_id();
		$gateway_id  = $order->get_payment_method();

		if ( ! $gateway_id ) {
			return $url;
		}

		$settings    = get_option( PP_Upsell_Main::OPTION_KEY, array() );
		$cod_enabled = ! empty( $settings['allow_cod_upsell'] );

		$payment_token = null;

		if ( 'cod' === $gateway_id && $cod_enabled ) {
			// Cash on Delivery has no online charge to make -- an accepted
			// COD upsell is just added as another line item that the
			// customer pays for on delivery, so no saved payment token
			// (and no customer account) is required for this path.
		} else {
			if ( ! $customer_id ) {
				// Tokenized reuse requires a real customer account -- tokens
				// are keyed by user id, so guest checkouts can't have one.
				return $url;
			}

			$tokens = WC_Payment_Tokens::get_customer_tokens( $customer_id, $gateway_id );

			if ( empty( $tokens ) ) {
				// No reusable saved payment token for this customer+gateway --
				// covers non-tokenizing gateways (BACS/Cheque/legacy PayPal
				// Standard, and COD when allow_cod_upsell is off).
				return $url;
			}

			$payment_token = reset( $tokens );
		}

		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$product_ids[] = $item->get_product_id();
			}
		}

		$offer = PP_Upsell_Offer_Repository::find_matching_offer( $product_ids );

		if ( ! $offer ) {
			return $url;
		}

		$token           = wp_generate_password( 32, false );
		$idempotency_key = wp_generate_uuid4();

		$attempt_id = PP_Upsell_Attempts_Repository::create(
			array(
				'order_id'              => $order_id,
				'offer_id'               => $offer->ID,
				'customer_id'            => $customer_id,
				'token'                  => $token,
				'idempotency_key'        => $idempotency_key,
				'gateway_id'             => $gateway_id,
				'payment_token_id'       => $payment_token ? $payment_token->get_id() : null,
				'status'                 => 'pending',
				'original_thankyou_url'  => $url,
			)
		);

		if ( ! $attempt_id ) {
			return $url;
		}

		if ( 'popup' === self::get_display_mode( $settings ) ) {
			// Popup mode doesn't redirect at all -- the customer goes to the
			// real thank-you page as normal, and PP_Upsell_Popup_Renderer
			// looks up this same pending attempt by order id and renders a
			// modal on top of that page instead.
			return $url;
		}

		return add_query_arg(
			array(
				self::QV_ID    => $attempt_id,
				self::QV_TOKEN => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * @param array $settings Plugin settings option.
	 * @return string 'page' or 'popup'.
	 */
	public static function get_display_mode( array $settings ) {
		return isset( $settings['display_mode'] ) && 'popup' === $settings['display_mode'] ? 'popup' : 'page';
	}

	/**
	 * Serves the standalone offer page when the pp_upsell_id/pp_upsell_token
	 * query vars are present, bypassing theme/page-builder template
	 * rendering entirely. Runs at template_redirect priority 0 so it fires
	 * before any theme code.
	 */
	public function maybe_render_offer_page() {
		$attempt_id = (int) get_query_var( self::QV_ID );

		if ( ! $attempt_id ) {
			return;
		}

		$token = (string) get_query_var( self::QV_TOKEN );

		$attempt = PP_Upsell_Attempts_Repository::get_by_token( $attempt_id, $token );

		if ( ! $attempt ) {
			// Unknown id/token combination -- send to the normal home page
			// rather than exposing anything about attempt existence.
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( PP_Upsell_Attempts_Repository::maybe_expire( $attempt_id ) ) {
			wp_safe_redirect( $attempt->original_thankyou_url );
			exit;
		}

		if ( 'pending' !== $attempt->status ) {
			// Already responded to (Accept/Decline) -- e.g. browser back
			// button. Send them on to the real thank-you page again rather
			// than re-showing a stale offer.
			wp_safe_redirect( $attempt->original_thankyou_url );
			exit;
		}

		$offer   = get_post( $attempt->offer_id );
		$product = $offer ? PP_Upsell_Offer_Repository::get_offer_product( $offer->ID ) : null;

		if ( ! $offer || 'publish' !== $offer->post_status || ! $product ) {
			// Offer was deleted/unpublished, or its product disappeared,
			// after the attempt row was created.
			wp_safe_redirect( $attempt->original_thankyou_url );
			exit;
		}

		PP_Upsell_Attempts_Repository::mark_viewed( $attempt_id );

		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$price = PP_Upsell_Offer_Repository::get_discounted_price( $offer->ID, $product );

		PP_Upsell_Offer_Page::render( $attempt, $offer, $product, $price );
		exit;
	}
}
