<?php
/**
 * Post-purchase offer custom post type.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_CPT
 *
 * Registers the `pp_upsell_offer` CPT and its "Offer Details" meta box.
 * Active/inactive is the post_status (publish/draft) -- no redundant meta
 * flag. show_in_menu is false because PP_Upsell_Admin attaches the list
 * table under the plugin's own top-level menu instead of the default one.
 */
class PP_Upsell_CPT {

	const POST_TYPE = 'pp_upsell_offer';

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta_box' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/**
	 * Register the CPT.
	 */
	public function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'              => __( 'Post-Purchase Offers', 'post-purchase-upsell' ),
				'labels'             => array(
					'name'               => __( 'Post-Purchase Offers', 'post-purchase-upsell' ),
					'singular_name'      => __( 'Offer', 'post-purchase-upsell' ),
					'add_new'            => __( 'Add New Offer', 'post-purchase-upsell' ),
					'add_new_item'       => __( 'Add New Offer', 'post-purchase-upsell' ),
					'edit_item'          => __( 'Edit Offer', 'post-purchase-upsell' ),
					'new_item'           => __( 'New Offer', 'post-purchase-upsell' ),
					'view_item'          => __( 'View Offer', 'post-purchase-upsell' ),
					'search_items'       => __( 'Search Offers', 'post-purchase-upsell' ),
					'not_found'          => __( 'No offers found.', 'post-purchase-upsell' ),
					'not_found_in_trash' => __( 'No offers found in Trash.', 'post-purchase-upsell' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Register the offer-details meta box.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'pp_upsell_offer_details',
			__( 'Offer Details', 'post-purchase-upsell' ),
			array( $this, 'render_details_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'pp_upsell_offer_appearance',
			__( 'Offer Page Text & Colors', 'post-purchase-upsell' ),
			array( $this, 'render_appearance_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);

		// 'default' priority in the 'side' context renders right after core's
		// own Publish box (which uses 'core' priority) -- so this appears
		// directly below Publish, as a live mirror of the meta box fields.
		add_meta_box(
			'pp_upsell_offer_preview',
			__( 'Offer Preview', 'post-purchase-upsell' ),
			array( $this, 'render_preview_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the offer-details meta box: trigger/offer product, discount,
	 * priority. The nonce field lives here since both meta boxes share one
	 * save handler and one <form>.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_details_meta_box( $post ) {
		wp_nonce_field( 'pp_upsell_offer_save', 'pp_upsell_offer_nonce' );

		$trigger_products = get_post_meta( $post->ID, '_pp_trigger_products', true );
		$trigger_products = is_array( $trigger_products ) ? $trigger_products : array();

		$offer_product  = (int) get_post_meta( $post->ID, '_pp_offer_product', true );
		$discount_type  = get_post_meta( $post->ID, '_pp_discount_type', true );
		$discount_type  = in_array( $discount_type, array( 'fixed', 'percent' ), true ) ? $discount_type : 'percent';
		$discount_amount = get_post_meta( $post->ID, '_pp_discount_amount', true );
		$priority        = get_post_meta( $post->ID, '_pp_priority', true );
		$priority        = ( '' === $priority ) ? 10 : (int) $priority;
		?>
		<div class="pp-upsell-meta-box">
			<p class="form-field">
				<label for="pp_trigger_products"><?php esc_html_e( 'Trigger product(s)', 'post-purchase-upsell' ); ?></label><br />
				<select class="wc-product-search" multiple="multiple" style="width: 100%;" id="pp_trigger_products" name="pp_trigger_products[]" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'post-purchase-upsell' ); ?>" data-action="woocommerce_json_search_products">
					<?php
					if ( ! empty( $trigger_products ) ) {
						_prime_post_caches( $trigger_products );
					}
					foreach ( $trigger_products as $product_id ) {
						$product = wc_get_product( $product_id );
						if ( $product ) {
							echo '<option value="' . esc_attr( $product_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
						}
					}
					?>
				</select>
				<span class="description"><?php esc_html_e( 'Show this offer when the customer buys any of these products.', 'post-purchase-upsell' ); ?></span>
			</p>

			<p class="form-field">
				<label for="pp_offer_product"><?php esc_html_e( 'Offer product', 'post-purchase-upsell' ); ?></label><br />
				<select class="wc-product-search" style="width: 100%;" id="pp_offer_product" name="pp_offer_product" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'post-purchase-upsell' ); ?>" data-action="woocommerce_json_search_products">
					<?php
					if ( $offer_product ) {
						$product = wc_get_product( $offer_product );
						if ( $product ) {
							echo '<option value="' . esc_attr( $offer_product ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
						}
					}
					?>
				</select>
				<span class="description"><?php esc_html_e( 'The product to offer as the one-click upsell.', 'post-purchase-upsell' ); ?></span>
			</p>

			<p class="form-field">
				<label for="pp_discount_type"><?php esc_html_e( 'Discount type', 'post-purchase-upsell' ); ?></label><br />
				<select id="pp_discount_type" name="pp_discount_type">
					<option value="percent" <?php selected( $discount_type, 'percent' ); ?>><?php esc_html_e( 'Percentage', 'post-purchase-upsell' ); ?></option>
					<option value="fixed" <?php selected( $discount_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed amount', 'post-purchase-upsell' ); ?></option>
				</select>
			</p>

			<p class="form-field">
				<label for="pp_discount_amount"><?php esc_html_e( 'Discount amount', 'post-purchase-upsell' ); ?></label><br />
				<input type="number" step="0.01" min="0" id="pp_discount_amount" name="pp_discount_amount" value="<?php echo esc_attr( $discount_amount ); ?>" />
				<span class="description" id="pp_discount_amount_hint"></span>
			</p>

			<p class="form-field">
				<label for="pp_priority"><?php esc_html_e( 'Priority', 'post-purchase-upsell' ); ?></label><br />
				<input type="number" step="1" min="0" id="pp_priority" name="pp_priority" value="<?php echo esc_attr( $priority ); ?>" />
				<span class="description"><?php esc_html_e( 'Lower number = higher priority when more than one offer matches.', 'post-purchase-upsell' ); ?></span>
			</p>

			<p class="form-field">
				<span class="description"><?php esc_html_e( 'Use the Publish/Draft status of this offer to make it active or inactive.', 'post-purchase-upsell' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the offer-appearance meta box: the customer-facing text and
	 * colors used on the offer page/popup, separate from the offer-matching
	 * details above.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_appearance_meta_box( $post ) {
		?>
		<div class="pp-upsell-meta-box">
			<p class="description"><?php esc_html_e( 'Leave any field blank to use the default text shown as its placeholder.', 'post-purchase-upsell' ); ?></p>
			<?php
			$defaults = PP_Upsell_Offer_Repository::get_default_copy();
			$raw      = array();
			foreach ( array_keys( $defaults ) as $key ) {
				$raw[ $key ] = get_post_meta( $post->ID, '_pp_text_' . $key, true );
			}
			?>
			<p class="form-field">
				<label for="pp_text_eyebrow"><?php esc_html_e( 'Eyebrow (small text above the headline)', 'post-purchase-upsell' ); ?></label><br />
				<input type="text" class="widefat" id="pp_text_eyebrow" name="pp_text_eyebrow" value="<?php echo esc_attr( $raw['eyebrow'] ); ?>" placeholder="<?php echo esc_attr( $defaults['eyebrow'] ); ?>" />
			</p>
			<p class="form-field">
				<label for="pp_text_headline"><?php esc_html_e( 'Headline', 'post-purchase-upsell' ); ?></label><br />
				<input type="text" class="widefat" id="pp_text_headline" name="pp_text_headline" value="<?php echo esc_attr( $raw['headline'] ); ?>" placeholder="<?php echo esc_attr( $defaults['headline'] ); ?>" />
			</p>
			<p class="form-field">
				<label for="pp_text_accept"><?php esc_html_e( 'Accept button text', 'post-purchase-upsell' ); ?></label><br />
				<input type="text" class="widefat" id="pp_text_accept" name="pp_text_accept" value="<?php echo esc_attr( $raw['accept'] ); ?>" placeholder="<?php echo esc_attr( $defaults['accept'] ); ?>" />
				<span class="description"><?php esc_html_e( 'The price is always added automatically, e.g. "Yes, Add This (49.50)".', 'post-purchase-upsell' ); ?></span>
			</p>
			<p class="form-field">
				<label for="pp_text_decline"><?php esc_html_e( 'Decline link text', 'post-purchase-upsell' ); ?></label><br />
				<input type="text" class="widefat" id="pp_text_decline" name="pp_text_decline" value="<?php echo esc_attr( $raw['decline'] ); ?>" placeholder="<?php echo esc_attr( $defaults['decline'] ); ?>" />
			</p>
			<p class="form-field">
				<label for="pp_text_note_charge"><?php esc_html_e( 'Reassurance note (card/Stripe orders)', 'post-purchase-upsell' ); ?></label><br />
				<input type="text" class="widefat" id="pp_text_note_charge" name="pp_text_note_charge" value="<?php echo esc_attr( $raw['note_charge'] ); ?>" placeholder="<?php echo esc_attr( $defaults['note_charge'] ); ?>" />
			</p>
			<p class="form-field">
				<label for="pp_text_note_cod"><?php esc_html_e( 'Reassurance note (Cash on Delivery orders)', 'post-purchase-upsell' ); ?></label><br />
				<input type="text" class="widefat" id="pp_text_note_cod" name="pp_text_note_cod" value="<?php echo esc_attr( $raw['note_cod'] ); ?>" placeholder="<?php echo esc_attr( $defaults['note_cod'] ); ?>" />
			</p>

			<h3><?php esc_html_e( 'Colors', 'post-purchase-upsell' ); ?></h3>
			<?php $colors = PP_Upsell_Offer_Repository::get_offer_colors( $post->ID ); ?>
			<p class="form-field">
				<label for="pp_color_accent"><?php esc_html_e( 'Accent color', 'post-purchase-upsell' ); ?></label><br />
				<input type="text" class="pp-color-field" id="pp_color_accent" name="pp_color_accent" value="<?php echo esc_attr( $colors['accent'] ); ?>" data-default-color="<?php echo esc_attr( PP_Upsell_Offer_Repository::get_default_colors()['accent'] ); ?>" />
				<span class="description"><?php esc_html_e( 'Used for the Accept button and the discounted price.', 'post-purchase-upsell' ); ?></span>
			</p>
			<p class="form-field">
				<label for="pp_color_button_text"><?php esc_html_e( 'Accept button text color', 'post-purchase-upsell' ); ?></label><br />
				<input type="text" class="pp-color-field" id="pp_color_button_text" name="pp_color_button_text" value="<?php echo esc_attr( $colors['button_text'] ); ?>" data-default-color="<?php echo esc_attr( PP_Upsell_Offer_Repository::get_default_colors()['button_text'] ); ?>" />
			</p>
		</div>
		<?php
	}

	/**
	 * Render the sidebar "Offer Preview" meta box -- a live, client-side
	 * mirror of the text/color fields above (assets/js/admin.js wires it up
	 * on input/change, no AJAX/reload needed), against a placeholder
	 * $100 "Sample Product" since the real offer product's price isn't
	 * known without an extra request.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_preview_meta_box( $post ) {
		$copy     = PP_Upsell_Offer_Repository::get_offer_copy( $post->ID );
		$colors   = PP_Upsell_Offer_Repository::get_offer_colors( $post->ID );
		$settings = get_option( PP_Upsell_Main::OPTION_KEY, array() );
		$mode     = PP_Upsell_Redirect_Controller::get_display_mode( $settings );
		?>
		<div class="pp-upsell-preview-tabs">
			<button type="button" class="pp-upsell-preview-tab<?php echo 'popup' === $mode ? ' is-active' : ''; ?>" data-context="popup"><?php esc_html_e( 'Popup', 'post-purchase-upsell' ); ?></button>
			<button type="button" class="pp-upsell-preview-tab<?php echo 'page' === $mode ? ' is-active' : ''; ?>" data-context="page"><?php esc_html_e( 'Full page', 'post-purchase-upsell' ); ?></button>
		</div>
		<div id="pp-upsell-preview-context" class="pp-upsell-preview-context pp-upsell-preview-context--<?php echo esc_attr( $mode ); ?>">
		<div id="pp-upsell-preview" class="pp-upsell-preview" style="--pp-accent: <?php echo esc_attr( $colors['accent'] ); ?>; --pp-accent-text: <?php echo esc_attr( $colors['button_text'] ); ?>;">
			<p class="pp-upsell-preview__eyebrow" id="pp-preview-eyebrow"><?php echo esc_html( $copy['eyebrow'] ); ?></p>
			<p class="pp-upsell-preview__headline" id="pp-preview-headline"><?php echo esc_html( $copy['headline'] ); ?></p>
			<div class="pp-upsell-preview__product">
				<div class="pp-upsell-preview__thumb"></div>
				<div>
					<div class="pp-upsell-preview__name"><?php esc_html_e( 'Sample Product', 'post-purchase-upsell' ); ?></div>
					<div class="pp-upsell-preview__price">
						<span class="pp-upsell-preview__price-regular" id="pp-preview-price-regular">$100.00</span>
						<span class="pp-upsell-preview__price-final" id="pp-preview-price-final">$100.00</span>
					</div>
				</div>
			</div>
			<button type="button" class="pp-upsell-preview__accept" id="pp-preview-accept"><?php echo esc_html( $copy['accept'] ); ?> ($100.00)</button>
			<p class="pp-upsell-preview__decline" id="pp-preview-decline"><?php echo esc_html( $copy['decline'] ); ?></p>
			<p class="pp-upsell-preview__note" id="pp-preview-note"><?php echo esc_html( $copy['note_charge'] ); ?></p>
		</div>
		</div>
		<p class="description"><?php esc_html_e( 'Updates live as you edit the fields above. Uses a placeholder $100 sample price -- the real price depends on the offer product you pick.', 'post-purchase-upsell' ); ?></p>
		<p class="description"><?php esc_html_e( 'Popup and Full page share the same card design -- the difference is just how the customer reaches it (an overlay on your normal Thank You page, vs. being sent to a dedicated page). Switch the tabs above to see both; the Settings page controls which one is actually used.', 'post-purchase-upsell' ); ?></p>
		<?php
	}

	/**
	 * Persist the meta box fields.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_box( $post_id ) {

		if ( ! isset( $_POST['pp_upsell_offer_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pp_upsell_offer_nonce'] ) ), 'pp_upsell_offer_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$trigger_products = isset( $_POST['pp_trigger_products'] ) ? (array) wp_unslash( $_POST['pp_trigger_products'] ) : array();
		$trigger_products = array_values( array_filter( array_map( 'absint', $trigger_products ) ) );
		update_post_meta( $post_id, '_pp_trigger_products', $trigger_products );

		$offer_product = isset( $_POST['pp_offer_product'] ) ? absint( $_POST['pp_offer_product'] ) : 0;
		update_post_meta( $post_id, '_pp_offer_product', $offer_product );

		$discount_type = isset( $_POST['pp_discount_type'] ) ? sanitize_key( wp_unslash( $_POST['pp_discount_type'] ) ) : 'percent';
		$discount_type = in_array( $discount_type, array( 'fixed', 'percent' ), true ) ? $discount_type : 'percent';
		update_post_meta( $post_id, '_pp_discount_type', $discount_type );

		$discount_amount = isset( $_POST['pp_discount_amount'] ) ? (float) wp_unslash( $_POST['pp_discount_amount'] ) : 0;
		update_post_meta( $post_id, '_pp_discount_amount', max( 0, $discount_amount ) );

		$priority = isset( $_POST['pp_priority'] ) ? absint( $_POST['pp_priority'] ) : 10;
		update_post_meta( $post_id, '_pp_priority', $priority );

		foreach ( array_keys( PP_Upsell_Offer_Repository::get_default_copy() ) as $key ) {
			$field = 'pp_text_' . $key;
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			update_post_meta( $post_id, '_pp_text_' . $key, $value );
		}

		foreach ( array_keys( PP_Upsell_Offer_Repository::get_default_colors() ) as $key ) {
			$field = 'pp_color_' . $key;
			$value = isset( $_POST[ $field ] ) ? sanitize_hex_color( wp_unslash( $_POST[ $field ] ) ) : '';
			update_post_meta( $post_id, '_pp_color_' . $key, (string) $value );
		}
	}

	/**
	 * Add list-table columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['pp_trigger_products'] = __( 'Trigger Product(s)', 'post-purchase-upsell' );
				$new['pp_offer_product']    = __( 'Offer Product', 'post-purchase-upsell' );
				$new['pp_discount']         = __( 'Discount', 'post-purchase-upsell' );
				$new['pp_priority']         = __( 'Priority', 'post-purchase-upsell' );
				$new['pp_views']            = __( 'Views', 'post-purchase-upsell' );
				$new['pp_accepted']         = __( 'Accepted', 'post-purchase-upsell' );
				$new['pp_conversion']       = __( 'Conversion', 'post-purchase-upsell' );
			}
		}

		return $new;
	}

	/**
	 * Render a custom list-table column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'pp_trigger_products':
				$ids   = get_post_meta( $post_id, '_pp_trigger_products', true );
				$ids   = is_array( $ids ) ? $ids : array();
				$names = array();

				foreach ( $ids as $id ) {
					$product = wc_get_product( $id );
					if ( $product ) {
						$names[] = esc_html( $product->get_name() );
					}
				}

				echo $names ? esc_html( implode( ', ', $names ) ) : '&#8212;';
				break;

			case 'pp_offer_product':
				$id      = (int) get_post_meta( $post_id, '_pp_offer_product', true );
				$product = $id ? wc_get_product( $id ) : false;
				echo $product ? esc_html( $product->get_name() ) : '&#8212;';
				break;

			case 'pp_discount':
				$type   = get_post_meta( $post_id, '_pp_discount_type', true );
				$amount = get_post_meta( $post_id, '_pp_discount_amount', true );

				if ( 'fixed' === $type ) {
					echo wp_kses_post( wc_price( $amount ) );
				} else {
					echo esc_html( $amount . '%' );
				}
				break;

			case 'pp_priority':
				echo esc_html( get_post_meta( $post_id, '_pp_priority', true ) );
				break;

			case 'pp_views':
				echo esc_html( number_format_i18n( $this->get_offer_stats( $post_id )['views'] ) );
				break;

			case 'pp_accepted':
				echo esc_html( number_format_i18n( $this->get_offer_stats( $post_id )['accepted'] ) );
				break;

			case 'pp_conversion':
				echo esc_html( $this->get_offer_stats( $post_id )['conversion_rate'] ) . '%';
				break;
		}
	}

	/**
	 * Per-request memoization -- each list-table row renders three stat
	 * columns, and without this each would run its own SUM() query.
	 *
	 * @param int $post_id Offer post id.
	 * @return array
	 */
	private function get_offer_stats( $post_id ) {
		static $cache = array();

		if ( ! isset( $cache[ $post_id ] ) ) {
			$cache[ $post_id ] = PP_Upsell_Analytics::get_summary_for_offer( $post_id );
		}

		return $cache[ $post_id ];
	}
}
