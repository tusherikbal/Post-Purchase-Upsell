<?php
/**
 * Uninstall handler.
 *
 * Only deletes data if the store owner opted in via Settings
 * (delete_data_on_uninstall). Deactivating/reactivating the plugin never
 * triggers this file -- only deleting it from the Plugins screen does.
 *
 * @package Post_Purchase_Upsell
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$pp_upsell_settings = get_option( 'pp_upsell_settings', array() );

if ( empty( $pp_upsell_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$pp_upsell_post_ids = get_posts(
	array(
		'post_type'      => array( 'pp_upsell_offer', 'pp_order_bump' ),
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
	)
);

foreach ( $pp_upsell_post_ids as $pp_upsell_post_id ) {
	wp_delete_post( $pp_upsell_post_id, true );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pp_upsell_attempts" );

delete_option( 'pp_upsell_settings' );
delete_option( 'pp_upsell_db_version' );
