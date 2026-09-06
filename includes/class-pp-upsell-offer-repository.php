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
		$type   = get_post_meta( $offer_id, '_pp_discount_type', true );
		$amount = (float) get_post_meta( $offer_id, '_pp_discount_amount', true );

		// get_regular_price(), not get_price() -- if the product also has its
		// own WooCommerce sale price active, get_price() would return that
		// already-discounted value and our discount would stack on top of it.
		$regular = (float) $product->get_regular_price();

		if ( 'fixed' === $type ) {
			$price = $regular - $amount;
		} else {
			$price = $regular - ( $regular * ( $amount / 100 ) );
		}

		return (float) wc_format_decimal( max( 0, $price ) );
	}

	/**
	 * Default copy for offer keys not overridden by the store owner.
	 *
	 * @return array<string,string>
	 */
	public static function get_default_copy() {
		return array(
			'eyebrow'     => __( 'Wait! Before you go...', 'post-purchase-upsell' ),
			'headline'    => __( 'Add this to your order?', 'post-purchase-upsell' ),
			'accept'      => __( 'Yes, Add This', 'post-purchase-upsell' ),
			'decline'     => __( 'No, thanks', 'post-purchase-upsell' ),
			'note_charge' => __( "You'll be charged using the payment method you just used -- no need to re-enter your card.", 'post-purchase-upsell' ),
			'note_cod'    => __( "No payment is taken now -- if you say yes, it's simply added to your order and you pay for it on delivery.", 'post-purchase-upsell' ),
		);
	}

	/**
	 * Offer copy with the store owner's overrides (if any) merged over the
	 * defaults -- an offer with every field left blank behaves exactly as
	 * before this was made editable.
	 *
	 * @param int $offer_id Offer post id.
	 * @return array<string,string>
	 */
	public static function get_offer_copy( $offer_id ) {
		$defaults = self::get_default_copy();
		$copy     = $defaults;

		foreach ( array_keys( $defaults ) as $key ) {
			$value = get_post_meta( $offer_id, '_pp_text_' . $key, true );

			if ( '' !== trim( (string) $value ) ) {
				$copy[ $key ] = $value;
			}
		}

		return $copy;
	}

	/**
	 * @return array<string,string> accent, button_text -- both 6-digit hex.
	 */
	public static function get_default_colors() {
		return array(
			'accent'      => '#1a7f37',
			'button_text' => '#ffffff',
		);
	}

	/**
	 * @param int $offer_id Offer post id.
	 * @return array<string,string>
	 */
	public static function get_offer_colors( $offer_id ) {
		$defaults = self::get_default_colors();
		$colors   = $defaults;

		foreach ( array_keys( $defaults ) as $key ) {
			$value = get_post_meta( $offer_id, '_pp_color_' . $key, true );

			if ( $value && preg_match( '/^#[0-9a-f]{6}$/i', $value ) ) {
				$colors[ $key ] = $value;
			}
		}

		return $colors;
	}
}
