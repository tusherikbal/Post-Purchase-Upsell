<?php
/**
 * Activation handler.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PP_Upsell_Activator
 */
class PP_Upsell_Activator {

	/**
	 * Runs on plugin activation: creates the attempts table and seeds default
	 * settings. Does not touch rewrite rules -- the offer page is served via
	 * a query var on template_redirect (Phase 2), not a rewrite endpoint.
	 */
	public static function activate() {

		PP_Upsell_DB::create_table();
		update_option( PP_Upsell_Main::DB_VERSION_OPTION_KEY, PP_Upsell_Main::VERSION );

		$defaults = array(
			'offer_link_ttl_minutes'   => 60,
			'delete_data_on_uninstall' => false,
			'allow_cod_upsell'         => false,
		);

		add_option( PP_Upsell_Main::OPTION_KEY, $defaults );
	}
}
