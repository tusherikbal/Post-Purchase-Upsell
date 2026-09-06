<?php
/**
 * WooCommerce Checkout block compatibility for order bumps.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_OrderBump_Blocks
 *
 * The Checkout block is a REST-driven (Store API) experience with no
 * classic $_POST fields at all, so getting a checkbox's state from the
 * customer's browser into PP_Upsell_OrderBump_Frontend::reconcile_cart()
 * needs two separate pieces of WooCommerce's block-extension API, both
 * registered here:
 *
 * - `ExtendSchema` (via the `woocommerce_store_api_register_*` helpers,
 *   registered on `woocommerce_blocks_loaded` -- WC core's own documented
 *   safe hook for this) exposes the currently-matching bumps + which are
 *   checked to the client, and receives updates from it. A checkbox toggle
 *   calls the Store API's POST /wc/store/v1/cart/extensions endpoint, which
 *   invokes handle_update() below -- routed straight into the exact same
 *   reconcile_cart() the classic checkout path uses (see
 *   PP_Upsell_OrderBump_Frontend), so the add/remove/price/shipping logic
 *   is written once and shared.
 * - `IntegrationInterface` (registered on
 *   `woocommerce_blocks_checkout_block_registration`) enqueues the JS that
 *   renders the checkbox into the block's `ExperimentalOrderMeta` slot and
 *   dispatches that Store API call.
 *
 * This class implements IntegrationInterface directly since both concerns
 * are tightly coupled to the same client/server contract (the
 * NAMESPACE_KEY constant). The whole file is only require_once'd from the
 * bootstrap after confirming the interface exists, since WooCommerce Blocks
 * classes (bundled in core since WC ~8.x) aren't guaranteed on very old WC.
 */
class PP_Upsell_OrderBump_Blocks implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

	const NAMESPACE_KEY   = 'post-purchase-upsell/order-bumps';
	const SCRIPT_HANDLE    = 'pp-orderbump-blocks';
	const SESSION_KEY      = 'pp_order_bumps';

	public function __construct() {
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_extend_schema' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_integration' ) );
	}

	/**
	 * @param \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry $registry Block integration registry.
	 */
	public function register_integration( $registry ) {
		$registry->register( $this );
	}

	/**
	 * Registers the Store API cart-extension data + update callback.
	 */
	public function register_extend_schema() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE_KEY,
				'data_callback'   => array( __CLASS__, 'get_extension_data' ),
				'schema_callback' => array( __CLASS__, 'get_extension_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::NAMESPACE_KEY,
				'callback'  => array( __CLASS__, 'handle_update' ),
			)
		);
	}

	/**
	 * @return array
	 */
	public static function get_extension_schema() {
		return array(
			'checked' => array(
				'description' => __( 'Currently checked order bump ids.', 'post-purchase-upsell' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'bumps'   => array(
				'description' => __( 'Available order bumps.', 'post-purchase-upsell' ),
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Data pushed to the client on every cart read -- only bumps whose
	 * trigger products are actually in the current cart (same condition the
	 * classic checkout applies), with the offer product's name/image/price
	 * so the client can render the same product-card look, plus which ones
	 * are currently checked so the checkbox UI can restore its own state
	 * after a page refresh.
	 *
	 * @return array
	 */
	public static function get_extension_data() {
		$bumps            = array();
		$cart_product_ids = array();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$cart_product_ids[] = (int) $item['product_id'];
			}
		}

		foreach ( PP_Upsell_OrderBump_Repository::find_matching_bumps( $cart_product_ids ) as $bump ) {
			$product = PP_Upsell_OrderBump_Repository::get_offer_product( $bump->ID );

			if ( ! $product ) {
				continue;
			}

			$price    = PP_Upsell_OrderBump_Repository::get_discounted_price( $bump->ID, $product );
			$regular  = (float) $product->get_regular_price();
			$image_id = $product->get_image_id();

			$bumps[] = array(
				'id'               => $bump->ID,
				'name'             => $product->get_name(),
				'imageUrl'         => $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' ),
				'price'            => $price,
				// Plain text, not HTML -- already stripped, so it's safe to
				// concatenate directly into a JS string on the client.
				'priceText'        => wp_strip_all_tags( wc_price( $price ) ),
				'hasDiscount'      => $regular > $price,
				'regularPriceText' => wp_strip_all_tags( wc_price( $regular ) ),
				'freeShipping'     => PP_Upsell_OrderBump_Repository::grants_free_shipping( $bump->ID ),
			);
		}

		return array(
			'checked' => self::get_session_checked_ids(),
			'bumps'   => $bumps,
		);
	}

	/**
	 * @return int[]
	 */
	private static function get_session_checked_ids() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}

		$ids = WC()->session->get( self::SESSION_KEY, array() );

		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	/**
	 * Invoked by the Store API's cart/extensions route when the client
	 * toggles a checkbox. Routes through the exact same reconciliation the
	 * classic checkout uses (id validation, adding/removing product-linked
	 * bumps as real cart items, writing the shared session key) so there is
	 * one source of truth regardless of which checkout renderer is active.
	 *
	 * @param array $data Raw payload the client sent, e.g. ['checked' => [3, 7]].
	 */
	public static function handle_update( $data ) {
		$ids = isset( $data['checked'] ) && is_array( $data['checked'] ) ? array_map( 'absint', $data['checked'] ) : array();

		PP_Upsell_OrderBump_Frontend::reconcile_cart( $ids );
	}

	/**
	 * @return string
	 */
	public function get_name() {
		return 'pp-orderbump';
	}

	/**
	 * Registers the frontend script -- called by the block framework before
	 * get_script_handles()/get_script_data() are read.
	 */
	public function initialize() {
		wp_register_script(
			self::SCRIPT_HANDLE,
			PP_UPSELL_URL . 'assets/js/order-bump-blocks.js',
			array( 'wc-blocks-checkout', 'wp-element', 'wp-data', 'wp-plugins' ),
			PP_Upsell_Main::VERSION,
			true
		);
	}

	/**
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * @return array
	 */
	public function get_script_data() {
		return array(
			'namespace' => self::NAMESPACE_KEY,
		);
	}
}
