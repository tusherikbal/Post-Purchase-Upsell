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
	 * Resolves the store owner's per-offer text overrides (falling back to
	 * defaults), and picks the right reassurance note for how this attempt
	 * will actually be fulfilled: a tokenized gateway attempt really does
	 * charge the saved payment method, but a COD attempt never makes an
	 * online charge at all -- showing the "you'll be charged" note there
	 * would be misleading, so each offer gets a separate note for each case.
	 *
	 * @param object $attempt Row from pp_upsell_attempts.
	 * @return array<string,string> eyebrow, headline, accept, decline, note.
	 */
	private static function get_copy( $attempt ) {
		$copy = PP_Upsell_Offer_Repository::get_offer_copy( $attempt->offer_id );
		$cod  = 'cod' === $attempt->gateway_id && empty( $attempt->payment_token_id );

		return array(
			'eyebrow'  => $copy['eyebrow'],
			'headline' => $copy['headline'],
			'accept'   => $copy['accept'],
			'decline'  => $copy['decline'],
			'note'     => $cod ? $copy['note_cod'] : $copy['note_charge'],
		);
	}

	/**
	 * @param object     $attempt Row from pp_upsell_attempts.
	 * @param WP_Post    $offer   The matched offer post.
	 * @param WC_Product $product The offer product.
	 * @param float      $price   Discounted price.
	 */
	public static function render( $attempt, $offer, $product, $price ) {
		$regular_price = (float) $product->get_regular_price();
		$has_discount  = $price < $regular_price;
		$copy          = self::get_copy( $attempt );
		$colors        = PP_Upsell_Offer_Repository::get_offer_colors( $offer->ID );

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
	<div class="pp-upsell-offer" id="pp-upsell-offer" style="--pp-accent: <?php echo esc_attr( $colors['accent'] ); ?>; --pp-accent-text: <?php echo esc_attr( $colors['button_text'] ); ?>;">
		<div class="pp-upsell-offer__card">
			<p class="pp-upsell-offer__eyebrow"><?php echo esc_html( $copy['eyebrow'] ); ?></p>
			<h1 class="pp-upsell-offer__title"><?php echo esc_html( $copy['headline'] ); ?></h1>

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
					<?php echo esc_html( $copy['accept'] . ' (' . wp_strip_all_tags( wc_price( $price ) ) . ')' ); ?>
				</button>
				<button type="button" class="pp-upsell-offer__decline" id="pp-upsell-decline">
					<?php echo esc_html( $copy['decline'] ); ?>
				</button>
			</div>

			<p class="pp-upsell-offer__note"><?php echo esc_html( $copy['note'] ); ?></p>
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

	/**
	 * Popup variant: echoed directly into the real Thank You page's content
	 * (from woocommerce_thankyou), so it prints only a modal + its own
	 * scoped <style>/<script> -- no <html>/<head>/<body> of its own, and no
	 * separate enqueued assets (the hook fires mid-page-render, after the
	 * normal wp_enqueue_scripts window).
	 *
	 * @param object     $attempt Row from pp_upsell_attempts.
	 * @param WP_Post    $offer   The matched offer post.
	 * @param WC_Product $product The offer product.
	 * @param float      $price   Discounted price.
	 */
	public static function render_popup( $attempt, $offer, $product, $price ) {
		$regular_price = (float) $product->get_regular_price();
		$has_discount  = $price < $regular_price;
		$copy          = self::get_copy( $attempt );
		$colors        = PP_Upsell_Offer_Repository::get_offer_colors( $offer->ID );

		$data = array(
			'attemptId' => (int) $attempt->id,
			'token'     => $attempt->token,
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'pp_upsell_offer' ),
		);
		?>
		<div class="pp-upsell-popup" id="pp-upsell-popup" role="dialog" aria-modal="true" aria-labelledby="pp-upsell-popup-title" style="--pp-accent: <?php echo esc_attr( $colors['accent'] ); ?>; --pp-accent-text: <?php echo esc_attr( $colors['button_text'] ); ?>;">
			<div class="pp-upsell-popup__backdrop" id="pp-upsell-popup-backdrop"></div>
			<div class="pp-upsell-popup__card">
				<p class="pp-upsell-popup__eyebrow"><?php echo esc_html( $copy['eyebrow'] ); ?></p>
				<h2 class="pp-upsell-popup__title" id="pp-upsell-popup-title"><?php echo esc_html( $copy['headline'] ); ?></h2>

				<div class="pp-upsell-popup__product">
					<div class="pp-upsell-popup__image"><?php echo wp_kses_post( $product->get_image( 'thumbnail' ) ); ?></div>
					<div class="pp-upsell-popup__details">
						<h3 class="pp-upsell-popup__name"><?php echo esc_html( $product->get_name() ); ?></h3>
						<p class="pp-upsell-popup__price">
							<?php if ( $has_discount ) : ?>
								<span class="pp-upsell-popup__price-regular"><?php echo wp_kses_post( wc_price( $regular_price ) ); ?></span>
							<?php endif; ?>
							<span class="pp-upsell-popup__price-final"><?php echo wp_kses_post( wc_price( $price ) ); ?></span>
						</p>
					</div>
				</div>

				<div class="pp-upsell-popup__actions">
					<button type="button" class="pp-upsell-popup__accept" id="pp-upsell-popup-accept">
						<?php echo esc_html( $copy['accept'] . ' (' . wp_strip_all_tags( wc_price( $price ) ) . ')' ); ?>
					</button>
					<button type="button" class="pp-upsell-popup__decline" id="pp-upsell-popup-decline">
						<?php echo esc_html( $copy['decline'] ); ?>
					</button>
				</div>

				<p class="pp-upsell-popup__note"><?php echo esc_html( $copy['note'] ); ?></p>
				<p class="pp-upsell-popup__status" id="pp-upsell-popup-status" role="status" aria-live="polite"></p>
			</div>
		</div>

		<style>
			.pp-upsell-popup { position: fixed; inset: 0; z-index: 999999; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; }
			.pp-upsell-popup[hidden] { display: none; }
			.pp-upsell-popup__backdrop { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.55); }
			.pp-upsell-popup__card { position: relative; background: #fff; color: #1f2328; width: 100%; max-width: 440px; border-radius: 12px; padding: 28px; box-sizing: border-box; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; text-align: center; }
			.pp-upsell-popup__eyebrow { text-transform: uppercase; letter-spacing: 0.08em; font-size: 11px; font-weight: 700; color: #d9480f; margin: 0 0 6px; }
			.pp-upsell-popup__title { font-size: 20px; margin: 0 0 18px; }
			.pp-upsell-popup__product { display: flex; align-items: center; gap: 14px; text-align: left; background: #f9fafb; border-radius: 8px; padding: 12px; margin-bottom: 18px; }
			.pp-upsell-popup__image img { width: 64px; height: 64px; object-fit: cover; border-radius: 6px; display: block; }
			.pp-upsell-popup__name { font-size: 15px; margin: 0 0 6px; }
			.pp-upsell-popup__price-regular { text-decoration: line-through; color: #8a8f98; margin-right: 6px; font-size: 13px; }
			.pp-upsell-popup__price-final { font-size: 16px; font-weight: 700; color: var( --pp-accent, #1a7f37 ) !important; }
			.pp-upsell-popup__actions { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
			.pp-upsell-popup__accept, .pp-upsell-popup__decline { font-size: 15px; padding: 12px 18px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; }
			/* !important guards against theme/page-builder button styles (e.g. ".woocommerce button.button")
			   that can otherwise out-specificity this single class selector and repaint the button. */
			.pp-upsell-popup__accept { background: var( --pp-accent, #1a7f37 ) !important; color: var( --pp-accent-text, #fff ) !important; }
			.pp-upsell-popup__accept:disabled, .pp-upsell-popup__decline:disabled { opacity: 0.6; cursor: default; }
			.pp-upsell-popup__decline { background: transparent; color: #57606a; text-decoration: underline; }
			.pp-upsell-popup__note { font-size: 11px; color: #8a8f98; margin: 0; }
			.pp-upsell-popup__status { font-size: 12px; min-height: 16px; color: #d9480f; margin: 6px 0 0; }
		</style>

		<script>
		( function () {
			'use strict';

			var config = <?php echo wp_json_encode( $data ); ?>;
			var popup = document.getElementById( 'pp-upsell-popup' );
			var acceptBtn = document.getElementById( 'pp-upsell-popup-accept' );
			var declineBtn = document.getElementById( 'pp-upsell-popup-decline' );
			var statusEl = document.getElementById( 'pp-upsell-popup-status' );

			function setBusy( busy ) {
				if ( acceptBtn ) { acceptBtn.disabled = busy; }
				if ( declineBtn ) { declineBtn.disabled = busy; }
			}

			function setStatus( message ) {
				if ( statusEl ) { statusEl.textContent = message || ''; }
			}

			function close() {
				if ( popup ) { popup.setAttribute( 'hidden', 'hidden' ); }
			}

			function submit( action, reloadOnSuccess ) {
				setBusy( true );
				setStatus( '' );

				var body = new URLSearchParams();
				body.set( 'action', action );
				body.set( 'attempt_id', config.attemptId );
				body.set( 'token', config.token );
				body.set( 'nonce', config.nonce );

				fetch( config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString(),
				} )
					.then( function ( response ) { return response.json(); } )
					.then( function ( json ) {
						if ( json && json.success ) {
							if ( reloadOnSuccess ) {
								setStatus( '<?php echo esc_js( __( 'Added! Refreshing your order...', 'post-purchase-upsell' ) ); ?>' );
								window.setTimeout( function () { window.location.reload(); }, 900 );
							} else {
								close();
							}
							return;
						}

						setBusy( false );
						setStatus( ( json && json.data && json.data.message ) || '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'post-purchase-upsell' ) ); ?>' );
					} )
					.catch( function () {
						setBusy( false );
						setStatus( '<?php echo esc_js( __( 'Network error. Please try again.', 'post-purchase-upsell' ) ); ?>' );
					} );
			}

			if ( acceptBtn ) {
				acceptBtn.addEventListener( 'click', function () { submit( 'pp_upsell_accept', true ); } );
			}
			if ( declineBtn ) {
				declineBtn.addEventListener( 'click', function () { submit( 'pp_upsell_decline', false ); } );
			}
		} )();
		</script>
		<?php
	}
}
