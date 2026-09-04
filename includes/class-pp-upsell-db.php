<?php
/**
 * Database helper.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_DB
 *
 * Owns the plugin's custom "attempts" table: its name and its schema creation
 * via dbDelta. One row per (order, offer) post-purchase-offer attempt; this
 * table doubles as the idempotency/already-processed ledger (Phase 2/3) and
 * as the analytics events source (Phase 6) so the two never drift apart.
 */
class PP_Upsell_DB {

	/**
	 * Return the fully-prefixed custom table name.
	 *
	 * @return string Table name including the site table prefix.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'pp_upsell_attempts';
	}

	/**
	 * Create (or upgrade) the custom attempts table using dbDelta.
	 *
	 * dbDelta compares the desired schema against the existing one and applies
	 * the difference, so this method is safe to run on every activation.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// Note: dbDelta is whitespace and formatting sensitive, so the SQL
		// below follows its expected conventions (two spaces after PRIMARY KEY,
		// lowercase types, one field per line).
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			offer_id bigint(20) unsigned NOT NULL,
			customer_id bigint(20) unsigned NULL,
			token varchar(64) NOT NULL,
			idempotency_key varchar(64) NOT NULL,
			gateway_id varchar(50) NULL,
			payment_token_id bigint(20) unsigned NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			original_thankyou_url text NOT NULL,
			amount decimal(19,4) NULL,
			transaction_id varchar(191) NULL,
			error_message text NULL,
			viewed_at datetime NULL,
			responded_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY order_id (order_id),
			KEY offer_id (offer_id),
			KEY status (status),
			KEY offer_created (offer_id, created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Upgrade the schema when the stored DB version differs from the plugin's.
	 *
	 * dbDelta is safe to run repeatedly and will ALTER the existing table to
	 * add any new columns without the admin needing to deactivate/reactivate.
	 * Cheap to call, but we only run the heavier dbDelta when the version
	 * actually changed.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( PP_Upsell_Main::DB_VERSION_OPTION_KEY );

		if ( PP_Upsell_Main::VERSION === $installed ) {
			return;
		}

		self::create_table();
		update_option( PP_Upsell_Main::DB_VERSION_OPTION_KEY, PP_Upsell_Main::VERSION );
	}
}
