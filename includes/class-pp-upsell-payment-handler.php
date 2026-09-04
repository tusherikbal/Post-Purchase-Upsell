<?php
/**
 * One-click charge engine: reuses the customer's saved payment token via
 * the gateway's own process_payment(), charging only the upsell amount.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Payment_Handler
 *
 * Key trick: rather than calling process_payment() on the parent order
 * directly (which would re-charge its already-paid total), a temporary
 * throwaway WC_Order is created containing only the offer product at the
 * discounted price, charged via the resolved gateway's own process_payment()
 * (the same tokenized-charge code path core checkout uses), then deleted.
 * This satisfies the "single order, no child order" decision while still
 * letting the gateway do the actual charge math.
 */
class PP_Upsell_Payment_Handler {

	/**
	 * WooCommerce order-status-change emails that must never fire for a
	 * temp order -- it exists only for a few seconds inside charge_attempt()
	 * before being deleted, and a customer/admin notification about it would
	 * just be confusing.
	 *
	 * @var string[]
	 */
	const SUPPRESSED_EMAIL_IDS = array(
		'new_order',
		'customer_processing_order',
		'customer_completed_order',
		'customer_on_hold_order',
		'failed_order',
		'customer_invoice',
	);

	const TEMP_ORDER_META_KEY = '_pp_upsell_temp_order';

	public function __construct() {
		foreach ( self::SUPPRESSED_EMAIL_IDS as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, array( __CLASS__, 'maybe_suppress_email' ), 10, 2 );
		}
	}

	/**
	 * @param bool  $enabled Whether WooCommerce would send this email.
	 * @param mixed $object  Usually the WC_Order the email is about.
	 * @return bool
	 */
	public static function maybe_suppress_email( $enabled, $object ) {
		if ( $enabled && $object instanceof WC_Order && 'yes' === $object->get_meta( self::TEMP_ORDER_META_KEY ) ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Accept an offer attempt. Dispatches to the Cash on Delivery path (no
	 * online charge, just adds a line item the customer pays on delivery)
	 * or the tokenized-gateway charge path, depending on how the attempt
	 * was created. Safe to call more than once for the same attempt -- the
	 * pending -> processing compare-and-swap is the actual duplicate-request
	 * guard, since generic process_payment() reuse can't inject a raw
	 * gateway-level idempotency header.
	 *
	 * @param int    $attempt_id Attempt id.
	 * @param string $token      Attempt token.
	 * @return array { redirect: string }
	 */
	public static function process_accept( $attempt_id, $token ) {
		$attempt = PP_Upsell_Attempts_Repository::get_by_token( $attempt_id, $token );

		if ( ! $attempt ) {
			return array( 'redirect' => home_url( '/' ) );
		}

		if ( PP_Upsell_Attempts_Repository::maybe_expire( $attempt_id ) ) {
			return array( 'redirect' => $attempt->original_thankyou_url );
		}

		$moved = PP_Upsell_Attempts_Repository::try_transition( $attempt_id, $token, 'pending', 'processing' );

		if ( ! $moved ) {
			// Not pending anymore -- either a concurrent request already
			// claimed it, or it was already charged/declined/failed
			// earlier. Either way, no charge is attempted here.
			return array( 'redirect' => $attempt->original_thankyou_url );
		}

		$order = wc_get_order( $attempt->order_id );

		if ( ! $order ) {
			self::fail( $attempt_id, $token, 'Order not found.' );
			return array( 'redirect' => $attempt->original_thankyou_url );
		}

		$offer   = get_post( $attempt->offer_id );
		$product = $offer ? PP_Upsell_Offer_Repository::get_offer_product( $offer->ID ) : null;

		if ( ! $offer || ! $product ) {
			self::fail( $attempt_id, $token, 'Offer or offer product no longer available.' );
			return array( 'redirect' => $attempt->original_thankyou_url );
		}

		$price = PP_Upsell_Offer_Repository::get_discounted_price( $offer->ID, $product );

		if ( 'cod' === $attempt->gateway_id && empty( $attempt->payment_token_id ) ) {
			return self::apply_cod_upsell( $attempt, $order, $offer, $product, $price );
		}

		return self::charge_via_gateway( $attempt, $order, $offer, $product, $price );
	}

	/**
	 * Cash on Delivery path -- no online charge is made at all. The upsell
	 * product is simply added as another line item on the same order, and
	 * the customer pays for it in cash along with everything else on
	 * delivery. Only reached when the "allow_cod_upsell" setting is on
	 * (enforced when the attempt was created).
	 *
	 * @param object     $attempt Attempt row.
	 * @param WC_Order   $order   Parent order.
	 * @param WP_Post    $offer   Offer post.
	 * @param WC_Product $product Offer product.
	 * @param float      $price   Price to add.
	 * @return array { redirect: string }
	 */
	private static function apply_cod_upsell( $attempt, WC_Order $order, $offer, WC_Product $product, $price ) {
		PP_Upsell_Order_Manager::add_upsell_line_item( $order, $offer, $product, $price, array( 'transaction_id' => '' ) );

		PP_Upsell_Attempts_Repository::try_transition(
			$attempt->id,
			$attempt->token,
			'processing',
			'charged',
			array(
				'amount'       => $price,
				'responded_at' => current_time( 'mysql', true ),
			)
		);

		return array( 'redirect' => $attempt->original_thankyou_url );
	}

	/**
	 * Tokenized-gateway path (e.g. Stripe): charges the offer amount by
	 * reusing the customer's saved payment method.
	 *
	 * @param object     $attempt Attempt row.
	 * @param WC_Order   $order   Parent order.
	 * @param WP_Post    $offer   Offer post.
	 * @param WC_Product $product Offer product.
	 * @param float      $price   Price to charge.
	 * @return array { redirect: string }
	 */
	private static function charge_via_gateway( $attempt, WC_Order $order, $offer, WC_Product $product, $price ) {
		$attempt_id = $attempt->id;
		$token      = $attempt->token;

		$gateway = wc_get_payment_gateway_by_order( $order );

		if ( ! $gateway || ! $gateway->supports( 'tokenization' ) ) {
			self::fail( $attempt_id, $token, 'Gateway unavailable or does not support tokenization.' );
			return array( 'redirect' => $attempt->original_thankyou_url );
		}

		$payment_token = $attempt->payment_token_id ? WC_Payment_Tokens::get( $attempt->payment_token_id ) : null;

		// Ownership re-validated server-side -- the client never chooses
		// which token gets charged.
		if ( ! $payment_token || (int) $payment_token->get_user_id() !== (int) $order->get_customer_id() ) {
			self::fail( $attempt_id, $token, 'Payment token invalid or does not belong to this customer.' );
			return array( 'redirect' => $attempt->original_thankyou_url );
		}

		$temp_order = self::build_temp_order( $order, $product, $price, $gateway );

		if ( is_wp_error( $temp_order ) ) {
			self::fail( $attempt_id, $token, $temp_order->get_error_message() );
			return array( 'redirect' => $attempt->original_thankyou_url );
		}

		$transaction_id = '';
		$success        = false;

		try {
			$_POST['payment_method'] = $gateway->id;
			$_POST[ 'wc-' . $gateway->id . '-payment-token' ] = $payment_token->get_id();

			/**
			 * Extension seam for gateway-specific adapters (e.g. a future
			 * PayPal integration) that need to populate additional fields
			 * before process_payment() runs.
			 */
			do_action( 'pp_upsell_before_charge', $gateway, $payment_token, $temp_order );

			$result = $gateway->process_payment( $temp_order->get_id() );

			$success = is_array( $result ) && isset( $result['result'] ) && 'success' === $result['result'];

			if ( $success ) {
				$refreshed      = wc_get_order( $temp_order->get_id() );
				$transaction_id = $refreshed ? $refreshed->get_transaction_id() : '';
			}
		} catch ( Exception $e ) {
			self::fail( $attempt_id, $token, $e->getMessage() );
			$success = false;
		} finally {
			unset( $_POST['payment_method'], $_POST[ 'wc-' . $gateway->id . '-payment-token' ] );
			wp_delete_post( $temp_order->get_id(), true );
		}

		if ( ! $success ) {
			// Covers gateway-declined charges and cards that require
			// SCA/3-DS re-authentication (which can't be completed in this
			// background flow) -- either way, the customer's original,
			// already-successful order is untouched, and they still land
			// on the normal Thank You page.
			self::fail( $attempt_id, $token, 'Gateway did not report success.' );
			return array( 'redirect' => $attempt->original_thankyou_url );
		}

		PP_Upsell_Attempts_Repository::try_transition(
			$attempt_id,
			$token,
			'processing',
			'charged',
			array(
				'amount'         => $price,
				'transaction_id' => $transaction_id,
				'responded_at'   => current_time( 'mysql', true ),
			)
		);

		PP_Upsell_Order_Manager::add_upsell_line_item(
			$order,
			$offer,
			$product,
			$price,
			array( 'transaction_id' => $transaction_id )
		);

		return array( 'redirect' => $attempt->original_thankyou_url );
	}

	/**
	 * @param int    $attempt_id Attempt id.
	 * @param string $token      Attempt token.
	 * @param string $message    Error message to store.
	 */
	private static function fail( $attempt_id, $token, $message ) {
		PP_Upsell_Attempts_Repository::try_transition(
			$attempt_id,
			$token,
			'processing',
			'failed',
			array(
				'error_message' => $message,
				'responded_at'  => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Build the throwaway order that the gateway will actually charge.
	 *
	 * @param WC_Order            $parent_order Original order (for customer/billing).
	 * @param WC_Product          $product      Offer product.
	 * @param float               $price        Discounted price.
	 * @param WC_Payment_Gateway  $gateway      Resolved gateway.
	 * @return WC_Order|WP_Error
	 */
	private static function build_temp_order( WC_Order $parent_order, WC_Product $product, $price, $gateway ) {
		$temp_order = wc_create_order( array( 'customer_id' => $parent_order->get_customer_id() ) );

		if ( is_wp_error( $temp_order ) ) {
			return $temp_order;
		}

		$temp_order->set_address( $parent_order->get_address( 'billing' ), 'billing' );
		$temp_order->add_product(
			$product,
			1,
			array(
				'subtotal' => $price,
				'total'    => $price,
			)
		);
		$temp_order->set_payment_method( $gateway );
		$temp_order->update_meta_data( self::TEMP_ORDER_META_KEY, 'yes' );
		$temp_order->calculate_totals();
		$temp_order->save();

		return $temp_order;
	}
}
