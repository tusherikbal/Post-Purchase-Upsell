<?php
/**
 * Data access for matching post-purchase offers.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Offer_Repository
 *
 * Pure data access, no hooks. Offer counts are small (a store-owner
 * configuration screen, not a catalog), so matching is done by loading all
 * published offers and filtering in PHP rather than via a meta_query against
 * the serialized _pp_trigger_products array -- that avoids the classic
 * LIKE-on-serialized-data partial-match pitfall (e.g. "12" matching "123").
 */
class PP_Upsell_Offer_Repository {

	/**
	 * Find the highest-priority published offer triggered by any of the
	 * given product ids.
	 *
	 * @param int[] $product_ids Product ids from the order that was just paid.
	 * @return WP_Post|null
	 */
	public static function find_matching_offer( array $product_ids ) {
		if ( empty( $product_ids ) ) {
			return null;
		}

		$offers = get_posts(
			array(
				'post_type'      => PP_Upsell_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$matches = array();

		foreach ( $offers as $offer ) {
			$offer_product = (int) get_post_meta( $offer->ID, '_pp_offer_product', true );

			if ( ! $offer_product ) {
				continue;
			}

			$trigger_products = get_post_meta( $offer->ID, '_pp_trigger_products', true );
			$trigger_products = is_array( $trigger_products ) ? $trigger_products : array();

			if ( ! array_intersect( $trigger_products, $product_ids ) ) {
				continue;
			}

			$matches[] = array(
				'post'     => $offer,
				'priority' => (int) get_post_meta( $offer->ID, '_pp_priority', true ),
			);
		}

		if ( empty( $matches ) ) {
			return null;
		}

		usort(
			$matches,
			static function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		return $matches[0]['post'];
	}

	/**
	 * @param int $offer_id Offer post id.
	 * @return WC_Product|null
	 */
	public static function get_offer_product( $offer_id ) {
		$product_id = (int) get_post_meta( $offer_id, '_pp_offer_product', true );

		if ( ! $product_id ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return $product ? $product : null;
	}

	/**
	 * @param int        $offer_id Offer post id.
	 * @param WC_Product $product  The offer product.
	 * @return float Discounted price, never negative.
	 */
	public static function get_discounted_price( $offer_id, WC_Product $product ) {
		$type    = get_post_meta( $offer_id, '_pp_discount_type', true );
		$amount  = (float) get_post_meta( $offer_id, '_pp_discount_amount', true );
		$regular = (float) $product->get_price();

		if ( 'fixed' === $type ) {
			$price = $regular - $amount;
		} else {
			$price = $regular - ( $regular * ( $amount / 100 ) );
		}

		return (float) wc_format_decimal( max( 0, $price ) );
	}
}
