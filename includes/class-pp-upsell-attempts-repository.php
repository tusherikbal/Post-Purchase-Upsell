<?php
/**
 * Data access for the pp_upsell_attempts table.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Attempts_Repository
 *
 * One row per (order, offer) post-purchase-offer attempt. Doubles as the
 * idempotency ledger (via try_transition()'s atomic compare-and-swap) and as
 * the analytics events source (Phase 6) so the two never drift apart.
 */
class PP_Upsell_Attempts_Repository {

	/**
	 * @return string
	 */
	private static function table() {
		return PP_Upsell_DB::table_name();
	}

	/**
	 * Insert a new attempt row.
	 *
	 * @param array $data Column => value.
	 * @return int Inserted row id, 0 on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$data = wp_parse_args(
			$data,
			array(
				'status'     => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$inserted = $wpdb->insert( self::table(), $data );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int $id Attempt id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id )
		);
	}

	/**
	 * Fetch an attempt only if the token matches -- this is the real
	 * authorization boundary for the nopriv offer page/AJAX handlers.
	 *
	 * @param int    $id    Attempt id.
	 * @param string $token Attempt token.
	 * @return object|null
	 */
	public static function get_by_token( $id, $token ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND token = %s', $id, $token )
		);
	}

	/**
	 * Record the first view of the offer page (idempotent -- only sets
	 * viewed_at once).
	 *
	 * @param int $id Attempt id.
	 */
	public static function mark_viewed( $id ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET viewed_at = %s WHERE id = %d AND viewed_at IS NULL',
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	/**
	 * Atomically move an attempt from one status to another. This single
	 * compare-and-swap is the idempotency / already-processed guard used
	 * everywhere: it only succeeds if the row is still in $from_status at
	 * the moment of the UPDATE, so a duplicate accept/decline/charge
	 * request (double-click, browser back+resubmit, two tabs) can never
	 * both "win".
	 *
	 * @param int    $id          Attempt id.
	 * @param string $token       Attempt token (authorization check).
	 * @param string $from_status Required current status.
	 * @param string $to_status   New status to set.
	 * @param array  $extra       Additional columns to set (e.g. amount, transaction_id).
	 * @return bool True if exactly one row was updated.
	 */
	public static function try_transition( $id, $token, $from_status, $to_status, array $extra = array() ) {
		global $wpdb;

		$extra['status']     = $to_status;
		$extra['updated_at'] = current_time( 'mysql', true );

		$set_sql    = array();
		$set_values = array();

		foreach ( $extra as $column => $value ) {
			$set_sql[]    = '`' . $column . '` = %s';
			$set_values[] = $value;
		}

		$sql = 'UPDATE ' . self::table() . ' SET ' . implode( ', ', $set_sql ) . ' WHERE id = %d AND token = %s AND status = %s';

		$values = array_merge( $set_values, array( $id, $token, $from_status ) );

		$wpdb->query( $wpdb->prepare( $sql, $values ) );

		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Expire attempts older than the configured TTL that are still pending.
	 * Called opportunistically when an offer page is requested, not via cron.
	 *
	 * @param int $id Attempt id.
	 * @return bool True if the attempt is expired (either already was, or just got marked so).
	 */
	public static function maybe_expire( $id ) {
		$attempt = self::get( $id );

		if ( ! $attempt ) {
			return true;
		}

		if ( 'pending' !== $attempt->status ) {
			return 'expired' === $attempt->status;
		}

		$settings    = get_option( PP_Upsell_Main::OPTION_KEY, array() );
		$ttl_minutes = isset( $settings['offer_link_ttl_minutes'] ) ? (int) $settings['offer_link_ttl_minutes'] : 60;

		$created_ts = strtotime( $attempt->created_at . ' UTC' );
		$age_minutes = ( time() - $created_ts ) / 60;

		if ( $age_minutes <= $ttl_minutes ) {
			return false;
		}

		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'     => 'expired',
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $id,
				'status' => 'pending',
			)
		);

		return true;
	}
}
