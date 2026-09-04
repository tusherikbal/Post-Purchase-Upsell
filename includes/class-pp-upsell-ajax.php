<?php
/**
 * AJAX handlers for the offer page's Accept/Decline buttons.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Ajax
 *
 * Registered for both logged-in and nopriv requests, since customers on the
 * offer page may be guests. The real authorization boundary is the
 * per-attempt unguessable token (required, since the nopriv nonce action is
 * shared across all anonymous visitors) -- the nonce check below is
 * defense-in-depth only, checked but not fatal on its own.
 */
class PP_Upsell_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_pp_upsell_accept', array( $this, 'handle_accept' ) );
		add_action( 'wp_ajax_nopriv_pp_upsell_accept', array( $this, 'handle_accept' ) );
		add_action( 'wp_ajax_pp_upsell_decline', array( $this, 'handle_decline' ) );
		add_action( 'wp_ajax_nopriv_pp_upsell_decline', array( $this, 'handle_decline' ) );
	}

	/**
	 * Read + validate the attempt referenced by the current request.
	 *
	 * @return object|null
	 */
	private function get_requested_attempt() {
		check_ajax_referer( 'pp_upsell_offer', 'nonce', false );

		$attempt_id = isset( $_POST['attempt_id'] ) ? absint( $_POST['attempt_id'] ) : 0;
		$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( ! $attempt_id || ! $token ) {
			return null;
		}

		return PP_Upsell_Attempts_Repository::get_by_token( $attempt_id, $token );
	}

	/**
	 * Decline: pending -> declined.
	 */
	public function handle_decline() {
		$attempt = $this->get_requested_attempt();

		if ( ! $attempt ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or expired offer.', 'post-purchase-upsell' ) ) );
		}

		if ( PP_Upsell_Attempts_Repository::maybe_expire( $attempt->id ) ) {
			wp_send_json_success( array( 'redirect' => $attempt->original_thankyou_url ) );
		}

		PP_Upsell_Attempts_Repository::try_transition(
			$attempt->id,
			$attempt->token,
			'pending',
			'declined',
			array( 'responded_at' => current_time( 'mysql', true ) )
		);

		wp_send_json_success( array( 'redirect' => $attempt->original_thankyou_url ) );
	}

	/**
	 * Accept -- delegates to the payment handler, which owns the
	 * pending -> processing -> charged|failed transitions (its own
	 * compare-and-swap is the duplicate-request guard). The customer is
	 * always sent on to the real thank-you page, whether the charge
	 * succeeded or not -- a failed upsell must never block their
	 * already-successful original order.
	 */
	public function handle_accept() {
		$attempt = $this->get_requested_attempt();

		if ( ! $attempt ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or expired offer.', 'post-purchase-upsell' ) ) );
		}

		$result = PP_Upsell_Payment_Handler::process_accept( $attempt->id, $attempt->token );

		wp_send_json_success( array( 'redirect' => $result['redirect'] ) );
	}
}
