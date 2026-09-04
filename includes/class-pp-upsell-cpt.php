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
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the offer-details meta box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_meta_box( $post ) {
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
		}
	}
}
