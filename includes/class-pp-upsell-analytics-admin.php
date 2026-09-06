<?php
/**
 * Analytics dashboard admin page.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Analytics_Admin
 */
class PP_Upsell_Analytics_Admin {

	const PAGE_SLUG = 'pp-upsell-analytics';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Self-registers under the plugin's own top-level menu, same pattern as
	 * PP_Upsell_OrderBump_CPT -- decoupled from PP_Upsell_Admin.
	 */
	public function register_menu() {
		add_submenu_page(
			PP_Upsell_Admin::MENU_SLUG,
			__( 'Analytics', 'post-purchase-upsell' ),
			__( 'Analytics', 'post-purchase-upsell' ),
			PP_Upsell_Admin::REQUIRED_CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Scoped to this screen only via the `page` query arg (simpler and just
	 * as reliable as a hook-suffix comparison, since the slug is fixed).
	 */
	public function enqueue_assets() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_style(
			'pp-upsell-admin',
			PP_UPSELL_URL . 'assets/css/admin.css',
			array(),
			PP_Upsell_Main::VERSION
		);

		wp_enqueue_script(
			'pp-upsell-analytics',
			PP_UPSELL_URL . 'assets/js/analytics.js',
			array(),
			PP_Upsell_Main::VERSION,
			true
		);

		wp_localize_script( 'pp-upsell-analytics', 'ppUpsellTrend', PP_Upsell_Analytics::get_daily_trend( 30 ) );
	}

	/**
	 * Render the Analytics admin page.
	 */
	public function render_page() {
		$summary   = PP_Upsell_Analytics::get_summary();
		$breakdown = PP_Upsell_Analytics::get_offer_breakdown();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Post-Purchase Upsell Analytics', 'post-purchase-upsell' ); ?></h1>

			<div class="pp-upsell-stats">
				<div class="pp-upsell-stat">
					<span class="pp-upsell-stat__value"><?php echo esc_html( number_format_i18n( $summary['views'] ) ); ?></span>
					<span class="pp-upsell-stat__label"><?php esc_html_e( 'Views', 'post-purchase-upsell' ); ?></span>
				</div>
				<div class="pp-upsell-stat">
					<span class="pp-upsell-stat__value"><?php echo esc_html( number_format_i18n( $summary['accepted'] ) ); ?></span>
					<span class="pp-upsell-stat__label"><?php esc_html_e( 'Accepted', 'post-purchase-upsell' ); ?></span>
				</div>
				<div class="pp-upsell-stat">
					<span class="pp-upsell-stat__value"><?php echo esc_html( number_format_i18n( $summary['declined'] ) ); ?></span>
					<span class="pp-upsell-stat__label"><?php esc_html_e( 'Declined', 'post-purchase-upsell' ); ?></span>
				</div>
				<div class="pp-upsell-stat">
					<span class="pp-upsell-stat__value"><?php echo esc_html( $summary['conversion_rate'] ); ?>%</span>
					<span class="pp-upsell-stat__label"><?php esc_html_e( 'Conversion rate', 'post-purchase-upsell' ); ?></span>
				</div>
				<div class="pp-upsell-stat">
					<span class="pp-upsell-stat__value"><?php echo wp_kses_post( wc_price( $summary['revenue'] ) ); ?></span>
					<span class="pp-upsell-stat__label"><?php esc_html_e( 'Revenue', 'post-purchase-upsell' ); ?></span>
				</div>
				<?php if ( $summary['failed'] > 0 ) : ?>
				<div class="pp-upsell-stat pp-upsell-stat--warning">
					<span class="pp-upsell-stat__value"><?php echo esc_html( number_format_i18n( $summary['failed'] ) ); ?></span>
					<span class="pp-upsell-stat__label"><?php esc_html_e( 'Failed charges', 'post-purchase-upsell' ); ?></span>
				</div>
				<?php endif; ?>
			</div>

			<h2><?php esc_html_e( 'Last 30 days', 'post-purchase-upsell' ); ?></h2>
			<div class="pp-upsell-chart-wrap">
				<canvas id="pp-upsell-trend-chart" height="220"></canvas>
			</div>

			<h2><?php esc_html_e( 'By offer', 'post-purchase-upsell' ); ?></h2>
			<?php if ( empty( $breakdown ) ) : ?>
				<p><?php esc_html_e( 'No offer activity yet.', 'post-purchase-upsell' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Offer', 'post-purchase-upsell' ); ?></th>
							<th><?php esc_html_e( 'Views', 'post-purchase-upsell' ); ?></th>
							<th><?php esc_html_e( 'Accepted', 'post-purchase-upsell' ); ?></th>
							<th><?php esc_html_e( 'Declined', 'post-purchase-upsell' ); ?></th>
							<th><?php esc_html_e( 'Conversion', 'post-purchase-upsell' ); ?></th>
							<th><?php esc_html_e( 'Revenue', 'post-purchase-upsell' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $breakdown as $row ) : ?>
							<tr>
								<td>
									<?php if ( $row['offer_id'] ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $row['offer_id'] ) ); ?>"><?php echo esc_html( $row['offer_title'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $row['offer_title'] ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['accepted'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['declined'] ) ); ?></td>
								<td><?php echo esc_html( $row['conversion_rate'] ); ?>%</td>
								<td><?php echo wp_kses_post( wc_price( $row['revenue'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
