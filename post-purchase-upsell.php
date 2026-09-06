<?php
/**
 * Plugin Name: Post-Purchase Upsell
 * Description: One-click post-purchase upsell offers and checkout order bumps for WooCommerce, charged against the customer's saved payment method.
 * Version: 1.0.0
 * Author: Tusher Ikbal
 * Author URI: https://tusherikbal.online
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: post-purchase-upsell
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce HPOS Compatibility
 */
add_action( 'before_woocommerce_init', function () {

	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

/**
 * Main Plugin Class
 */
final class PP_Upsell_Main {

	public const VERSION     = '1.0.0';
	public const PLUGIN_SLUG = 'post-purchase-upsell';
	public const OPTION_KEY  = 'pp_upsell_settings';
	public const DB_VERSION_OPTION_KEY = 'pp_upsell_db_version';
	public const TEXT_DOMAIN = 'post-purchase-upsell';

	private static ?PP_Upsell_Main $instance = null;

	/**
	 * Singleton Instance
	 */
	public static function instance(): PP_Upsell_Main {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {

		$this->define_constants();

		add_action(
			'plugins_loaded',
			array( $this, 'on_plugins_loaded' )
		);
	}

	/**
	 * Define Plugin Constants
	 */
	private function define_constants(): void {

		if ( ! defined( 'PP_UPSELL_FILE' ) ) {
			define( 'PP_UPSELL_FILE', __FILE__ );
		}

		if ( ! defined( 'PP_UPSELL_DIR' ) ) {
			define( 'PP_UPSELL_DIR', plugin_dir_path( __FILE__ ) );
		}

		if ( ! defined( 'PP_UPSELL_URL' ) ) {
			define( 'PP_UPSELL_URL', plugin_dir_url( __FILE__ ) );
		}
	}

	/**
	 * On Plugins Loaded
	 */
	public function on_plugins_loaded(): void {

		if ( ! class_exists( 'WooCommerce' ) ) {

			add_action(
				'admin_notices',
				array( $this, 'render_missing_woocommerce_notice' )
			);

			return;
		}

		$this->includes();
		$this->init();
	}

	/**
	 * Include Required Files
	 */
	private function includes(): void {

		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-db.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-cpt.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-admin.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-offer-repository.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-attempts-repository.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-offer-page.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-redirect-controller.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-popup-renderer.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-order-manager.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-payment-handler.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-ajax.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-orderbump-repository.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-orderbump-cpt.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-orderbump-frontend.php';
		require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-analytics.php';

		// The Blocks-checkout integration implements a WooCommerce Blocks
		// interface at class-declaration time, so it's only require_once'd
		// once that interface is confirmed present -- guards against very
		// old WooCommerce versions that predate Blocks being bundled in core.
		if ( interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
			require_once PP_UPSELL_DIR . 'includes/class-pp-orderbump-blocks.php';
		}

		if ( is_admin() ) {
			require_once PP_UPSELL_DIR . 'includes/class-pp-upsell-analytics-admin.php';
		}
	}

	/**
	 * Init Plugin
	 *
	 * Runs on 'plugins_loaded', not 'init' -- classes below hook 'init'
	 * themselves (e.g. PP_Upsell_CPT::register()) and must be instantiated
	 * before 'init' fires, otherwise a same-hook nested add_action() would
	 * never run in the current request (WP_Hook snapshots callbacks per
	 * priority level at the start of each do_action() pass).
	 */
	public function init(): void {

		PP_Upsell_DB::maybe_upgrade();

		new PP_Upsell_CPT();
		new PP_Upsell_Redirect_Controller();
		new PP_Upsell_Popup_Renderer();
		new PP_Upsell_Payment_Handler();
		new PP_Upsell_Ajax();
		new PP_Upsell_OrderBump_CPT();
		new PP_Upsell_OrderBump_Frontend();

		if ( class_exists( 'PP_Upsell_OrderBump_Blocks' ) ) {
			new PP_Upsell_OrderBump_Blocks();
		}

		if ( is_admin() ) {
			new PP_Upsell_Admin();
			new PP_Upsell_Analytics_Admin();
		}
	}

	/**
	 * Missing WooCommerce Notice
	 */
	public function render_missing_woocommerce_notice(): void {

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error is-dismissible">';
		echo '<p>';

		echo esc_html__(
			'Post-Purchase Upsell requires WooCommerce. Please install and activate WooCommerce before using this plugin.',
			'post-purchase-upsell'
		);

		echo '</p>';
		echo '</div>';
	}
}

/**
 * Plugin Instance Helper
 */
function pp_upsell(): PP_Upsell_Main {

	return PP_Upsell_Main::instance();
}

/**
 * Plugin Activation
 */
function pp_upsell_activate(): void {

	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {

		deactivate_plugins( plugin_basename( __FILE__ ) );

		wp_die(
			esc_html__(
				'Post-Purchase Upsell requires PHP 7.4 or higher.',
				'post-purchase-upsell'
			)
		);
	}

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-pp-upsell-db.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-pp-upsell-activator.php';

	PP_Upsell_Activator::activate();
}

/**
 * Plugin Deactivation
 */
function pp_upsell_deactivate(): void {

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-pp-upsell-deactivator.php';

	PP_Upsell_Deactivator::deactivate();
}

/**
 * Hooks
 */
register_activation_hook(
	__FILE__,
	'pp_upsell_activate'
);

register_deactivation_hook(
	__FILE__,
	'pp_upsell_deactivate'
);

/**
 * Boot Plugin
 */
pp_upsell();
