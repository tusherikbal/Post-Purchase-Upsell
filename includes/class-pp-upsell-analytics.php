<?php
/**
 * Analytics aggregation.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Analytics
 *
 * Pure SQL aggregation against pp_upsell_attempts, no hooks. All events
 * (view/accept/decline/charge) are already captured there as status
 * transitions + timestamps by the redirect controller, popup renderer, and
 * payment handler -- no separate events table needed.
 */
class PP_Upsell_Analytics {

	/**
	 * @return array {views, accepted, declined, failed, revenue, conversion_rate}
	 */
	public static function get_summary() {
		global $wpdb;
		$table = PP_Upsell_DB::table_name();

		$row = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN viewed_at IS NOT NULL THEN 1 ELSE 0 END) AS views,
				SUM(CASE WHEN status = 'charged' THEN 1 ELSE 0 END) AS accepted,
				SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) AS declined,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
				SUM(CASE WHEN status = 'charged' THEN amount ELSE 0 END) AS revenue
			 FROM {$table}"
		);

		$views    = $row ? (int) $row->views : 0;
		$accepted = $row ? (int) $row->accepted : 0;

		return array(
			'views'           => $views,
			'accepted'        => $accepted,
			'declined'        => $row ? (int) $row->declined : 0,
			'failed'          => $row ? (int) $row->failed : 0,
			'revenue'         => $row ? (float) $row->revenue : 0.0,
			'conversion_rate' => $views > 0 ? round( ( $accepted / $views ) * 100, 1 ) : 0.0,
		);
	}

	/**
	 * @param int $offer_id Offer post id.
	 * @return array {views, accepted, conversion_rate}
	 */
	public static function get_summary_for_offer( $offer_id ) {
		global $wpdb;
		$table = PP_Upsell_DB::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN viewed_at IS NOT NULL THEN 1 ELSE 0 END) AS views,
					SUM(CASE WHEN status = 'charged' THEN 1 ELSE 0 END) AS accepted
				 FROM {$table}
				 WHERE offer_id = %d",
				$offer_id
			)
		);

		$views    = $row ? (int) $row->views : 0;
		$accepted = $row ? (int) $row->accepted : 0;

		return array(
			'views'           => $views,
			'accepted'        => $accepted,
			'conversion_rate' => $views > 0 ? round( ( $accepted / $views ) * 100, 1 ) : 0.0,
		);
	}

	/**
	 * @return array[] Rows: {offer_id, offer_title, views, accepted, declined, revenue, conversion_rate}, revenue DESC.
	 */
	public static function get_offer_breakdown() {
		global $wpdb;
		$table = PP_Upsell_DB::table_name();

		$rows = $wpdb->get_results(
			"SELECT
				a.offer_id,
				p.post_title AS offer_title,
				SUM(CASE WHEN a.viewed_at IS NOT NULL THEN 1 ELSE 0 END) AS views,
				SUM(CASE WHEN a.status = 'charged' THEN 1 ELSE 0 END) AS accepted,
				SUM(CASE WHEN a.status = 'declined' THEN 1 ELSE 0 END) AS declined,
				SUM(CASE WHEN a.status = 'charged' THEN a.amount ELSE 0 END) AS revenue
			 FROM {$table} a
			 LEFT JOIN {$wpdb->posts} p ON p.ID = a.offer_id
			 GROUP BY a.offer_id
			 ORDER BY revenue DESC"
		);

		$result = array();

		foreach ( $rows as $row ) {
			$views    = (int) $row->views;
			$accepted = (int) $row->accepted;

			$result[] = array(
				'offer_id'        => (int) $row->offer_id,
				'offer_title'     => $row->offer_title ? $row->offer_title : __( '(deleted offer)', 'post-purchase-upsell' ),
				'views'           => $views,
				'accepted'        => $accepted,
				'declined'        => (int) $row->declined,
				'revenue'         => (float) $row->revenue,
				'conversion_rate' => $views > 0 ? round( ( $accepted / $views ) * 100, 1 ) : 0.0,
			);
		}

		return $result;
	}

	/**
	 * @param int $days Number of trailing days to include (today counts as 1).
	 * @return array[] One row per day, oldest first: {date, views, accepted, revenue}. Days with no
	 *                 attempts are filled with zeros so the chart doesn't skip them.
	 */
	public static function get_daily_trend( $days = 30 ) {
		global $wpdb;
		$table = PP_Upsell_DB::table_name();

		$since = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $days - 1 ) . ' days' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(created_at) AS day,
					SUM(CASE WHEN viewed_at IS NOT NULL THEN 1 ELSE 0 END) AS views,
					SUM(CASE WHEN status = 'charged' THEN 1 ELSE 0 END) AS accepted,
					SUM(CASE WHEN status = 'charged' THEN amount ELSE 0 END) AS revenue
				 FROM {$table}
				 WHERE created_at >= %s
				 GROUP BY DATE(created_at)",
				$since
			)
		);

		$by_day = array();

		foreach ( $rows as $row ) {
			$by_day[ $row->day ] = array(
				'views'    => (int) $row->views,
				'accepted' => (int) $row->accepted,
				'revenue'  => (float) $row->revenue,
			);
		}

		$result = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day  = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$data = isset( $by_day[ $day ] ) ? $by_day[ $day ] : array(
				'views'    => 0,
				'accepted' => 0,
				'revenue'  => 0.0,
			);

			$result[] = array_merge( array( 'date' => $day ), $data );
		}

		return $result;
	}
}
