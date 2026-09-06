<?php
/**
 * Order bump custom post type.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_OrderBump_CPT
 *
 * Deliberately a SEPARATE CPT/storage from `pp_upsell_offer` -- order bumps
 * (checkout-page checkboxes) and post-purchase offers (after-checkout
 * upsells) are different features that must not be conflated in the admin,
 * per the PRD's explicit confusion-prevention requirement.
 *
 * Same trigger-product / offer-product pairing model as Offers: a bump only
 * shows at checkout when one of its trigger products is already in the
 * cart, and accepting it adds the (discounted) offer product as a real,
 * stock-tracked line item -- optionally with free shipping on the whole
 * order thrown in.
 */
class PP_Upsell_OrderBump_CPT {

	const POST_TYPE = 'pp_order_bump';

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta_box' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_distinction_notice' ) );
	}

	/**
	 * Register the CPT.
	 */
	public function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'              => __( 'Order Bumps', 'post-purchase-upsell' ),
				'labels'             => array(
					'name'               => __( 'Order Bumps', 'post-purchase-upsell' ),
					'singular_name'      => __( 'Order Bump', 'post-purchase-upsell' ),
					'add_new'            => __( 'Add New Order Bump', 'post-purchase-upsell' ),
					'add_new_item'       => __( 'Add New Order Bump', 'post-purchase-upsell' ),
					'edit_item'          => __( 'Edit Order Bump', 'post-purchase-upsell' ),
					'new_item'           => __( 'New Order Bump', 'post-purchase-upsell' ),
					'search_items'       => __( 'Search Order Bumps', 'post-purchase-upsell' ),
					'not_found'          => __( 'No order bumps found.', 'post-purchase-upsell' ),
					'not_found_in_trash' => __( 'No order bumps found in Trash.', 'post-purchase-upsell' ),
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
	 * Attach under the plugin's own top-level menu, as its own clearly
	 * labeled item -- deliberately not nested under "Offers".
	 */
	public function register_menu() {
		add_submenu_page(
			PP_Upsell_Admin::MENU_SLUG,
			__( 'Order Bumps', 'post-purchase-upsell' ),
			__( 'Order Bumps', 'post-purchase-upsell' ),
			PP_Upsell_Admin::REQUIRED_CAP,
			'edit.php?post_type=' . self::POST_TYPE
		);
	}

	/**
	 * A short admin notice on this CPT's own screens spelling out the
	 * difference from Post-Purchase Offers, per the PRD's requirement that
	 * store owners never confuse the two features.
	 */
	public function maybe_render_distinction_notice() {
		$screen = get_current_screen();

		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Order Bumps appear as a checkbox on your checkout page (only when one of the trigger products below is already in the cart) -- this is different from Post-Purchase Offers, which appear after checkout.', 'post-purchase-upsell' );
		echo '</p></div>';
	}

	/**
	 * Register the bump-details meta box.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'pp_order_bump_details',
			__( 'Order Bump Details', 'post-purchase-upsell' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the bump-details meta box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'pp_order_bump_save', 'pp_order_bump_nonce' );

		$trigger_products = get_post_meta( $post->ID, '_pp_bump_trigger_products', true );
		$trigger_products = is_array( $trigger_products ) ? $trigger_products : array();

		$offer_product_id = (int) get_post_meta( $post->ID, '_pp_bump_offer_product', true );
		$discount_type     = get_post_meta( $post->ID, '_pp_bump_discount_type', true );
		$discount_type     = in_array( $discount_type, array( 'fixed', 'percent' ), true ) ? $discount_type : 'percent';
		$discount_amount   = get_post_meta( $post->ID, '_pp_bump_discount_amount', true );
		$free_shipping     = 'yes' === get_post_meta( $post->ID, '_pp_bump_free_shipping', true );
		$priority          = get_post_meta( $post->ID, '_pp_bump_priority', true );
		$priority          = ( '' === $priority ) ? 10 : (int) $priority;
		?>
		<div class="pp-upsell-meta-box">
			<p class="form-field">
				<label for="pp_bump_trigger_products"><?php esc_html_e( 'Trigger product(s)', 'post-purchase-upsell' ); ?></label><br />
				<select class="wc-product-search" multiple="multiple" style="width: 100%;" id="pp_bump_trigger_products" name="pp_bump_trigger_products[]" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'post-purchase-upsell' ); ?>" data-action="woocommerce_json_search_products">
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
				<span class="description"><?php esc_html_e( 'Only show this bump at checkout when the cart contains any of these products.', 'post-purchase-upsell' ); ?></span>
			</p>

			<p class="form-field">
				<label for="pp_bump_offer_product"><?php esc_html_e( 'Offer product', 'post-purchase-upsell' ); ?></label><br />
				<select class="wc-product-search" style="width: 100%;" id="pp_bump_offer_product" name="pp_bump_offer_product" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'post-purchase-upsell' ); ?>" data-action="woocommerce_json_search_products">
					<?php
					if ( $offer_product_id ) {
						$offer_product = wc_get_product( $offer_product_id );
						if ( $offer_product ) {
							echo '<option value="' . esc_attr( $offer_product_id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $offer_product->get_formatted_name() ) ) . '</option>';
						}
					}
					?>
				</select>
				<span class="description"><?php esc_html_e( 'The real product added to the order (stock-tracked, shown with its name/image/price) when the customer checks this bump.', 'post-purchase-upsell' ); ?></span>
			</p>

			<p class="form-field">
				<label for="pp_bump_discount_type"><?php esc_html_e( 'Discount type', 'post-purchase-upsell' ); ?></label><br />
				<select id="pp_bump_discount_type" name="pp_bump_discount_type">
					<option value="percent" <?php selected( $discount_type, 'percent' ); ?>><?php esc_html_e( 'Percentage', 'post-purchase-upsell' ); ?></option>
					<option value="fixed" <?php selected( $discount_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed amount', 'post-purchase-upsell' ); ?></option>
				</select>
			</p>

			<p class="form-field">
				<label for="pp_bump_discount_amount"><?php esc_html_e( 'Discount amount', 'post-purchase-upsell' ); ?></label><br />
				<input type="number" step="0.01" min="0" id="pp_bump_discount_amount" name="pp_bump_discount_amount" value="<?php echo esc_attr( $discount_amount ); ?>" />
				<span class="description"><?php esc_html_e( 'Off the offer product\'s normal price. 0 charges full price.', 'post-purchase-upsell' ); ?></span>
			</p>

			<p class="form-field">
				<label>
					<input type="checkbox" id="pp_bump_free_shipping" name="pp_bump_free_shipping" value="yes" <?php checked( $free_shipping ); ?> />
					<?php esc_html_e( 'Grant free shipping on the whole order when this bump is accepted', 'post-purchase-upsell' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'Shipping cost is forced to $0 for the order, regardless of which shipping method the customer picks.', 'post-purchase-upsell' ); ?></span>
			</p>

			<p class="form-field">
				<label for="pp_bump_priority"><?php esc_html_e( 'Priority', 'post-purchase-upsell' ); ?></label><br />
				<input type="number" step="1" min="0" id="pp_bump_priority" name="pp_bump_priority" value="<?php echo esc_attr( $priority ); ?>" />
				<span class="description"><?php esc_html_e( 'Lower number shows first when more than one bump matches the cart.', 'post-purchase-upsell' ); ?></span>
			</p>

			<p class="form-field">
				<span class="description"><?php esc_html_e( 'Use the Publish/Draft status of this bump to make it active or inactive.', 'post-purchase-upsell' ); ?></span>
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

		if ( ! isset( $_POST['pp_order_bump_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pp_order_bump_nonce'] ) ), 'pp_order_bump_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$trigger_products = isset( $_POST['pp_bump_trigger_products'] ) ? (array) wp_unslash( $_POST['pp_bump_trigger_products'] ) : array();
		$trigger_products = array_values( array_filter( array_map( 'absint', $trigger_products ) ) );
		update_post_meta( $post_id, '_pp_bump_trigger_products', $trigger_products );

		$offer_product_id = isset( $_POST['pp_bump_offer_product'] ) ? absint( $_POST['pp_bump_offer_product'] ) : 0;
		update_post_meta( $post_id, '_pp_bump_offer_product', $offer_product_id );

		$discount_type = isset( $_POST['pp_bump_discount_type'] ) ? sanitize_key( wp_unslash( $_POST['pp_bump_discount_type'] ) ) : 'percent';
		$discount_type = in_array( $discount_type, array( 'fixed', 'percent' ), true ) ? $discount_type : 'percent';
		update_post_meta( $post_id, '_pp_bump_discount_type', $discount_type );

		$discount_amount = isset( $_POST['pp_bump_discount_amount'] ) ? (float) wp_unslash( $_POST['pp_bump_discount_amount'] ) : 0;
		update_post_meta( $post_id, '_pp_bump_discount_amount', max( 0, $discount_amount ) );

		$free_shipping = isset( $_POST['pp_bump_free_shipping'] ) && 'yes' === $_POST['pp_bump_free_shipping'];
		update_post_meta( $post_id, '_pp_bump_free_shipping', $free_shipping ? 'yes' : 'no' );

		$priority = isset( $_POST['pp_bump_priority'] ) ? absint( $_POST['pp_bump_priority'] ) : 10;
		update_post_meta( $post_id, '_pp_bump_priority', $priority );
	}

	/**
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['pp_bump_trigger']  = __( 'Trigger Product(s)', 'post-purchase-upsell' );
				$new['pp_bump_offer']    = __( 'Offer Product', 'post-purchase-upsell' );
				$new['pp_bump_discount'] = __( 'Discount', 'post-purchase-upsell' );
				$new['pp_bump_shipping'] = __( 'Free Shipping', 'post-purchase-upsell' );
				$new['pp_bump_priority'] = __( 'Priority', 'post-purchase-upsell' );
			}
		}

		return $new;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'pp_bump_trigger':
				$ids   = get_post_meta( $post_id, '_pp_bump_trigger_products', true );
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

			case 'pp_bump_offer':
				$product = PP_Upsell_OrderBump_Repository::get_offer_product( $post_id );
				echo $product ? esc_html( $product->get_name() ) : '&#8212;';
				break;

			case 'pp_bump_discount':
				$type   = get_post_meta( $post_id, '_pp_bump_discount_type', true );
				$amount = get_post_meta( $post_id, '_pp_bump_discount_amount', true );

				if ( 'fixed' === $type ) {
					echo wp_kses_post( wc_price( $amount ) );
				} else {
					echo esc_html( $amount . '%' );
				}
				break;

			case 'pp_bump_shipping':
				echo PP_Upsell_OrderBump_Repository::grants_free_shipping( $post_id ) ? '&#10003;' : '&#8212;';
				break;

			case 'pp_bump_priority':
				echo esc_html( get_post_meta( $post_id, '_pp_bump_priority', true ) );
				break;
		}
	}
}
