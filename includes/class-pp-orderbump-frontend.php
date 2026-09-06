<?php
/**
 * Classic/Elementor checkout rendering + shared cart reconciliation.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_OrderBump_Frontend
 *
 * Every bump now has a required offer product -- accepting one adds that
 * real product to the cart as a stock-tracked line item at the bump's
 * discounted price, and a bump only appears at all when one of its trigger
 * products is already in the cart (same trigger/offer pairing as Offers).
 * An optional per-bump "free shipping" flag zeroes the order's shipping
 * cost when a granting bump is in the cart, regardless of which shipping
 * method the customer picks.
 *
 * reconcile_cart() is the single place that adds/removes bump products from
 * the cart, called from three trigger points that both checkout renderers
 * ultimately funnel through:
 *   - Classic: `woocommerce_checkout_update_order_review` (the live AJAX
 *     totals-refresh WooCommerce's own checkout.js sends on every change).
 *   - Classic: `woocommerce_checkout_process` (a safety net right before
 *     order creation, in case the final submit raced ahead of a review call).
 *   - Blocks: PP_Upsell_OrderBump_Blocks::handle_update(), which receives
 *     the Store API's cart/extensions payload.
 * All three re-validate the checked ids against find_matching_bumps() for
 * the CURRENT cart contents server-side -- a tampered request can't check a
 * bump that isn't actually eligible.
 */
class PP_Upsell_OrderBump_Frontend {

	const SESSION_KEY   = 'pp_order_bumps';
	const CART_ITEM_KEY = 'pp_order_bump_id';

	public function __construct() {
		add_action( 'woocommerce_checkout_after_customer_details', array( $this, 'render_bump_checkboxes' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'reconcile_from_post_data' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'reconcile_from_raw_post' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'override_bump_prices' ), 20 );
		add_filter( 'woocommerce_package_rates', array( $this, 'maybe_apply_free_shipping' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_styles' ) );
	}

	/**
	 * Loaded only on the checkout page.
	 */
	public function maybe_enqueue_styles() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'pp-order-bump',
			PP_UPSELL_URL . 'assets/css/order-bump.css',
			array(),
			PP_Upsell_Main::VERSION
		);
	}

	/**
	 * @return int[] Product ids currently in the cart.
	 */
	private static function get_cart_product_ids() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$ids = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$ids[] = (int) $item['product_id'];
		}

		return $ids;
	}

	/**
	 * Renders inside the static part of the classic checkout form (after
	 * billing/shipping fields, before the #order_review block) -- that
	 * placement matters: #order_review is replaced wholesale on every
	 * `update_checkout` AJAX refresh, which would wipe the checkbox's
	 * checked state on its own change event if it lived inside that block.
	 * The `update_totals_on_change` wrapper class is WooCommerce core's own
	 * convention (assets/js/frontend/checkout.js) for "recalculate totals
	 * when this checkbox changes".
	 *
	 * Only bumps whose trigger products are actually in the cart are shown.
	 */
	public function render_bump_checkboxes() {
		$bumps = PP_Upsell_OrderBump_Repository::find_matching_bumps( self::get_cart_product_ids() );

		if ( empty( $bumps ) ) {
			return;
		}

		$checked_ids = self::get_checked_ids();
		?>
		<div class="pp-order-bump-list">
			<?php foreach ( $bumps as $bump ) :
				$product = PP_Upsell_OrderBump_Repository::get_offer_product( $bump->ID );

				if ( ! $product ) {
					continue;
				}

				$price         = PP_Upsell_OrderBump_Repository::get_discounted_price( $bump->ID, $product );
				$regular       = (float) $product->get_regular_price();
				$free_shipping = PP_Upsell_OrderBump_Repository::grants_free_shipping( $bump->ID );
				?>
				<div class="pp-order-bump update_totals_on_change">
					<label>
						<input type="checkbox" class="pp-order-bump__checkbox" name="pp_order_bump[<?php echo esc_attr( $bump->ID ); ?>]" value="1" <?php checked( in_array( $bump->ID, $checked_ids, true ) ); ?> />
						<span class="pp-order-bump__thumb"><?php echo wp_kses_post( $product->get_image( 'thumbnail' ) ); ?></span>
						<span class="pp-order-bump__text">
							<span class="pp-order-bump__name"><?php echo esc_html( $product->get_name() ); ?></span>
							<?php if ( $regular > $price ) : ?>
								<span class="pp-order-bump__price-regular"><?php echo wp_kses_post( wc_price( $regular ) ); ?></span>
							<?php endif; ?>
							<span class="pp-order-bump__price"><?php echo wp_kses_post( wc_price( $price ) ); ?></span>
							<?php if ( $free_shipping ) : ?>
								<span class="pp-order-bump__free-shipping"><?php esc_html_e( '+ Free Shipping', 'post-purchase-upsell' ); ?></span>
							<?php endif; ?>
						</span>
					</label>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param string $post_data Serialized checkout form data.
	 */
	public function reconcile_from_post_data( $post_data ) {
		$parsed = array();
		parse_str( $post_data, $parsed );
		self::reconcile_cart( self::extract_ids( $parsed ) );
	}

	/**
	 * Safety net for the final submit, in case it raced ahead of a review call.
	 */
	public function reconcile_from_raw_post() {
		self::reconcile_cart( self::extract_ids( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC's own checkout nonce already gates this hook.
	}

	/**
	 * @param array $source $_POST-shaped array (raw or parsed from post_data).
	 * @return int[]
	 */
	private static function extract_ids( array $source ) {
		if ( ! isset( $source['pp_order_bump'] ) || ! is_array( $source['pp_order_bump'] ) ) {
			return array();
		}

		return array_map( 'absint', array_keys( $source['pp_order_bump'] ) );
	}

	/**
	 * Single place that adds/removes bump products from the cart and
	 * records the checked-id list to session (used only to restore checkbox
	 * state on re-render -- the cart itself, tagged with CART_ITEM_KEY, is
	 * the actual source of truth for pricing/shipping).
	 *
	 * Safe to call from a plain action (not from inside
	 * woocommerce_before_calculate_totals): WC_Cart::add_to_cart() triggers
	 * its own calculate_totals() internally, and calling it here -- before
	 * any calculate_totals() already in progress -- avoids the re-entrancy
	 * that would happen if this ran inside that hook instead.
	 *
	 * @param int[] $checked_ids Raw ids from the client; re-validated below.
	 */
	public static function reconcile_cart( array $checked_ids ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		// Re-validated against bumps that actually match the CURRENT cart --
		// a tampered request can't check a bump whose trigger product isn't
		// even in the cart.
		$matching_ids = wp_list_pluck( PP_Upsell_OrderBump_Repository::find_matching_bumps( self::get_cart_product_ids() ), 'ID' );
		$checked_ids  = array_values( array_intersect( $checked_ids, $matching_ids ) );

		$in_cart = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( isset( $item[ self::CART_ITEM_KEY ] ) ) {
				$in_cart[ (int) $item[ self::CART_ITEM_KEY ] ] = $key;
			}
		}

		foreach ( $in_cart as $bump_id => $key ) {
			if ( ! in_array( $bump_id, $checked_ids, true ) ) {
				WC()->cart->remove_cart_item( $key );
			}
		}

		foreach ( $checked_ids as $bump_id ) {
			if ( isset( $in_cart[ $bump_id ] ) ) {
				continue;
			}

			$product_id = PP_Upsell_OrderBump_Repository::get_offer_product_id( $bump_id );

			// add_to_cart() runs WC's normal stock/purchasability validation
			// itself and simply returns false (with its own notice) if the
			// product can't be added -- nothing extra needed here.
			WC()->cart->add_to_cart( $product_id, 1, 0, array(), array( self::CART_ITEM_KEY => $bump_id ) );
		}

		WC()->session->set( self::SESSION_KEY, $checked_ids );
	}

	/**
	 * @return int[]
	 */
	private static function get_checked_ids() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}

		$ids = WC()->session->get( self::SESSION_KEY, array() );

		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * Sets each bump cart item's price to its configured discounted price.
	 * Idempotent -- safe to run on every calculate_totals() pass, including
	 * the extra passes triggered by reconcile_cart()'s own add_to_cart()
	 * calls.
	 *
	 * @param WC_Cart $cart Cart being totaled.
	 */
	public function override_bump_prices( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( ! isset( $item[ self::CART_ITEM_KEY ] ) || ! isset( $item['data'] ) ) {
				continue;
			}

			$price = PP_Upsell_OrderBump_Repository::get_discounted_price( (int) $item[ self::CART_ITEM_KEY ], $item['data'] );
			$item['data']->set_price( $price );
		}
	}

	/**
	 * Zeroes every shipping rate's cost (and taxes) for the order when a
	 * free-shipping-granting bump is in the cart -- guaranteed free
	 * delivery regardless of which shipping methods/zones the store has
	 * configured, rather than relying on WC's own "Free Shipping" method
	 * (which only exists, and only unlocks, if the store already set one up).
	 *
	 * @param WC_Shipping_Rate[] $rates   Rates for one shipping package.
	 * @param array              $package The shipping package.
	 * @return WC_Shipping_Rate[]
	 */
	public function maybe_apply_free_shipping( $rates, $package ) {
		if ( ! self::cart_has_free_shipping_bump() ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			$rate->cost = 0;

			if ( ! empty( $rate->taxes ) && is_array( $rate->taxes ) ) {
				$rate->taxes = array_map( '__return_zero', $rate->taxes );
			}
		}

		return $rates;
	}

	/**
	 * @return bool
	 */
	private static function cart_has_free_shipping_bump() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item[ self::CART_ITEM_KEY ] ) && PP_Upsell_OrderBump_Repository::grants_free_shipping( (int) $item[ self::CART_ITEM_KEY ] ) ) {
				return true;
			}
		}

		return false;
	}
}
