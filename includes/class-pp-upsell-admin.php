<?php
/**
 * Admin menu, settings page, and asset loading.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Admin
 */
class PP_Upsell_Admin {

	const REQUIRED_CAP  = 'manage_woocommerce';
	const MENU_SLUG     = 'edit.php?post_type=' . PP_Upsell_CPT::POST_TYPE;
	const SETTINGS_SLUG = 'pp-upsell-settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the top-level menu (backed by the offers CPT list table) and
	 * its submenus.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Post-Purchase Upsell', 'post-purchase-upsell' ),
			__( 'PP Upsell', 'post-purchase-upsell' ),
			self::REQUIRED_CAP,
			self::MENU_SLUG,
			'',
			'dashicons-tickets-alt',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Offers', 'post-purchase-upsell' ),
			__( 'Offers', 'post-purchase-upsell' ),
			self::REQUIRED_CAP,
			self::MENU_SLUG
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add New Offer', 'post-purchase-upsell' ),
			__( 'Add New Offer', 'post-purchase-upsell' ),
			self::REQUIRED_CAP,
			'post-new.php?post_type=' . PP_Upsell_CPT::POST_TYPE
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'post-purchase-upsell' ),
			__( 'Settings', 'post-purchase-upsell' ),
			self::REQUIRED_CAP,
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the plugin's single settings option.
	 */
	public function register_settings() {
		register_setting(
			'pp_upsell_settings_group',
			PP_Upsell_Main::OPTION_KEY,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	/**
	 * Sanitize the settings option on save.
	 *
	 * @param array $input Raw settings input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();

		$ttl = isset( $input['offer_link_ttl_minutes'] ) ? absint( $input['offer_link_ttl_minutes'] ) : 60;

		$display_mode = isset( $input['display_mode'] ) && 'popup' === $input['display_mode'] ? 'popup' : 'page';

		return array(
			'offer_link_ttl_minutes'   => max( 1, $ttl ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
			'allow_cod_upsell'         => ! empty( $input['allow_cod_upsell'] ),
			'display_mode'             => $display_mode,
		);
	}

	/**
	 * Render the Settings admin page.
	 */
	public function render_settings_page() {
		$settings = wp_parse_args(
			get_option( PP_Upsell_Main::OPTION_KEY, array() ),
			array(
				'offer_link_ttl_minutes'   => 60,
				'delete_data_on_uninstall' => false,
				'allow_cod_upsell'         => false,
				'display_mode'             => 'page',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Post-Purchase Upsell Settings', 'post-purchase-upsell' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'pp_upsell_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="pp_offer_link_ttl_minutes"><?php esc_html_e( 'Offer link expiry (minutes)', 'post-purchase-upsell' ); ?></label>
						</th>
						<td>
							<input type="number" min="1" step="1" id="pp_offer_link_ttl_minutes"
								name="<?php echo esc_attr( PP_Upsell_Main::OPTION_KEY ); ?>[offer_link_ttl_minutes]"
								value="<?php echo esc_attr( $settings['offer_link_ttl_minutes'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'How long a post-purchase offer link stays valid before it expires.', 'post-purchase-upsell' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="pp_display_mode"><?php esc_html_e( 'Offer display style', 'post-purchase-upsell' ); ?></label>
						</th>
						<td>
							<select id="pp_display_mode" name="<?php echo esc_attr( PP_Upsell_Main::OPTION_KEY ); ?>[display_mode]">
								<option value="page" <?php selected( $settings['display_mode'], 'page' ); ?>><?php esc_html_e( 'Full page (customer is redirected to a dedicated offer page)', 'post-purchase-upsell' ); ?></option>
								<option value="popup" <?php selected( $settings['display_mode'], 'popup' ); ?>><?php esc_html_e( 'Popup (shown on top of the real Thank You page, no redirect)', 'post-purchase-upsell' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Popup mode keeps the customer on your normal Thank You page and shows the offer as an overlay instead of sending them to a separate page.', 'post-purchase-upsell' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cash on Delivery', 'post-purchase-upsell' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( PP_Upsell_Main::OPTION_KEY ); ?>[allow_cod_upsell]" value="1" <?php checked( $settings['allow_cod_upsell'] ); ?> />
								<?php esc_html_e( 'Also show post-purchase offers on Cash on Delivery orders.', 'post-purchase-upsell' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'No online charge is made for a COD upsell -- accepting it just adds the product to the same order, and the customer pays for it in cash along with everything else on delivery. Works for guest checkouts too, since no saved payment method is needed.', 'post-purchase-upsell' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On uninstall', 'post-purchase-upsell' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( PP_Upsell_Main::OPTION_KEY ); ?>[delete_data_on_uninstall]" value="1" <?php checked( $settings['delete_data_on_uninstall'] ); ?> />
								<?php esc_html_e( 'Delete all Post-Purchase Upsell data (offers, order bumps, attempt history, settings) when the plugin is deleted.', 'post-purchase-upsell' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets, scoped to this plugin's own screens only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();

		if ( ! $screen || PP_Upsell_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_style(
			'pp-upsell-admin',
			PP_UPSELL_URL . 'assets/css/admin.css',
			array(),
			PP_Upsell_Main::VERSION
		);

		wp_enqueue_script(
			'pp-upsell-admin',
			PP_UPSELL_URL . 'assets/js/admin.js',
			array( 'jquery', 'wc-enhanced-select', 'wp-color-picker' ),
			PP_Upsell_Main::VERSION,
			true
		);
	}
}
