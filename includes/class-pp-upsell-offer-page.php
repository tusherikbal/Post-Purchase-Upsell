<?php
/**
 * Renders the standalone post-purchase offer page.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Offer_Page
 *
 * Prints a fully standalone HTML document (own <head>, no get_header()/
 * get_footer()) so the offer is immune to whatever the active theme or page
 * builder (Elementor, The7, etc.) does with normal page templates -- this is
 * a deliberate response to the "checkout/page-builder override" risk.
 */
class PP_Upsell_Offer_Page {

	/**
	 * @param object     $attempt Row from pp_upsell_attempts.
	 * @param WP_Post    $offer   The matched offer post.
	 * @param WC_Product $product The offer product.
	 * @param float      $price   Discounted price.
	 */
	public static function render( $attempt, $offer, $product, $price ) {
		$regular_price = (float) $product->get_price();
		$has_discount  = $price < $regular_price;

		$data = array(
			'attemptId'  => (int) $attempt->id,
			'token'      => $attempt->token,
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'pp_upsell_offer' ),
		);
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php esc_html_e( 'Special One-Time Offer', 'post-purchase-upsell' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( PP_UPSELL_URL . 'assets/css/offer-page.css?v=' . PP_Upsell_Main::VERSION ); ?>" />
</head>
<body class="pp-upsell-offer-page">
	<div class="pp-upsell-offer" id="pp-upsell-offer">
		<div class="pp-upsell-offer__card">
			<p class="pp-upsell-offer__eyebrow"><?php esc_html_e( 'Wait! Before you go...', 'post-purchase-upsell' ); ?></p>
			<h1 class="pp-upsell-offer__title"><?php esc_html_e( 'Add this to your order?', 'post-purchase-upsell' ); ?></h1>

			<div class="pp-upsell-offer__product">
				<div class="pp-upsell-offer__image"><?php echo wp_kses_post( $product->get_image( 'medium' ) ); ?></div>
				<div class="pp-upsell-offer__details">
					<h2 class="pp-upsell-offer__name"><?php echo esc_html( $product->get_name() ); ?></h2>
					<p class="pp-upsell-offer__price">
						<?php if ( $has_discount ) : ?>
							<span class="pp-upsell-offer__price-regular"><?php echo wp_kses_post( wc_price( $regular_price ) ); ?></span>
						<?php endif; ?>
						<span class="pp-upsell-offer__price-final"><?php echo wp_kses_post( wc_price( $price ) ); ?></span>
					</p>
					<?php
					$short_description = $product->get_short_description();
					if ( $short_description ) {
						echo '<div class="pp-upsell-offer__excerpt">' . wp_kses_post( wpautop( $short_description ) ) . '</div>';
					}
					?>
				</div>
			</div>

			<div class="pp-upsell-offer__actions">
				<button type="button" class="pp-upsell-offer__accept" id="pp-upsell-accept">
					<?php
					/* translators: %s: formatted price */
					echo esc_html( sprintf( __( 'Yes, Add This (%s)', 'post-purchase-upsell' ), wp_strip_all_tags( wc_price( $price ) ) ) );
					?>
				</button>
				<button type="button" class="pp-upsell-offer__decline" id="pp-upsell-decline">
					<?php esc_html_e( 'No, thanks', 'post-purchase-upsell' ); ?>
				</button>
			</div>

			<p class="pp-upsell-offer__note"><?php esc_html_e( "You'll be charged using the payment method you just used -- no need to re-enter your card.", 'post-purchase-upsell' ); ?></p>
			<p class="pp-upsell-offer__status" id="pp-upsell-status" role="status" aria-live="polite"></p>
		</div>
	</div>

	<script>
		window.ppUpsellOffer = <?php echo wp_json_encode( $data ); ?>;
	</script>
	<script src="<?php echo esc_url( PP_UPSELL_URL . 'assets/js/offer-page.js?v=' . PP_Upsell_Main::VERSION ); ?>"></script>
</body>
</html>
		<?php
	}
}
