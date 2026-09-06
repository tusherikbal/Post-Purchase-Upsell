<?php
/**
 * Data access for order bumps.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_OrderBump_Repository
 *
 * Pure data access, no hooks. Matching mirrors
 * PP_Upsell_Offer_Repository::find_matching_offer() -- load all published
 * bumps and filter in PHP (bump counts are small, a config screen not a
 * catalog), avoiding the LIKE-on-serialized-meta pitfall.
 */
class PP_Upsell_OrderBump_Repository {

	/**
	 * @return WP_Post[] Published bumps, lowest priority number first.
	 */
	public static function get_active_bumps() {
		return get_posts(
			array(
				'post_type'      => PP_Upsell_OrderBump_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_pp_bump_priority',
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * @return int[]
	 */
	public static function get_active_bump_ids() {
		return wp_list_pluck( self::get_active_bumps(), 'ID' );
	}

	/**
	 * Bumps whose trigger products intersect with the given cart product ids.
	 *
	 * @param int[] $cart_product_ids Product ids currently in the cart.
	 * @return WP_Post[]
	 */
	public static function find_matching_bumps( array $cart_product_ids ) {
		if ( empty( $cart_product_ids ) ) {
			return array();
		}

		$matches = array();

		foreach ( self::get_active_bumps() as $bump ) {
			if ( ! self::get_offer_product_id( $bump->ID ) ) {
				continue;
			}

			$trigger_products = get_post_meta( $bump->ID, '_pp_bump_trigger_products', true );
			$trigger_products = is_array( $trigger_products ) ? $trigger_products : array();

			if ( ! array_intersect( $trigger_products, $cart_product_ids ) ) {
				continue;
			}

			$matches[] = $bump;
		}

		return $matches;
	}

	/**
	 * @param int $bump_id Bump post id.
	 * @return int
	 */
	public static function get_offer_product_id( $bump_id ) {
		return (int) get_post_meta( $bump_id, '_pp_bump_offer_product', true );
	}

	/**
	 * @param int $bump_id Bump post id.
	 * @return WC_Product|null
	 */
	public static function get_offer_product( $bump_id ) {
		$product_id = self::get_offer_product_id( $bump_id );

		if ( ! $product_id ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return $product ? $product : null;
	}

	/**
	 * @param int        $bump_id Bump post id.
	 * @param WC_Product $product The offer product.
	 * @return float Discounted price, never negative.
	 */
	public static function get_discounted_price( $bump_id, WC_Product $product ) {
		$type   = get_post_meta( $bump_id, '_pp_bump_discount_type', true );
		$amount = (float) get_post_meta( $bump_id, '_pp_bump_discount_amount', true );

		// get_regular_price(), not get_price(): this is called from
		// override_bump_prices() on every calculate_totals() pass against
		// the SAME cart-item product object we just called set_price() on,
		// so get_price() would reflect our own prior override and compound
		// the discount on every subsequent pass. get_regular_price() reads
		// the product's stable _regular_price meta, untouched by set_price().
		$regular = (float) $product->get_regular_price();

		if ( 'fixed' === $type ) {
			$price = $regular - $amount;
		} else {
			$price = $regular - ( $regular * ( $amount / 100 ) );
		}

		return (float) wc_format_decimal( max( 0, $price ) );
	}

	/**
	 * @param int $bump_id Bump post id.
	 * @return bool
	 */
	public static function grants_free_shipping( $bump_id ) {
		return 'yes' === get_post_meta( $bump_id, '_pp_bump_free_shipping', true );
	}
}
