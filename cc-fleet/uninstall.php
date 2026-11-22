<?php
/**
 * Runs when the plugin is deleted (not when deactivated).
 * Data is only removed if "Delete all fleet data when the plugin is deleted" is ticked in Settings.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'cc_fleet_settings', array() );
if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'riders', 'loans', 'log' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cc_{$t}" ); // phpcs:ignore
}
$bikes = get_posts( array( 'post_type' => 'cc_bike', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
foreach ( $bikes as $id ) {
	wp_delete_post( $id, true );
}
delete_option( 'cc_fleet_settings' );
delete_option( 'cc_fleet_db_version' );
remove_role( 'cc_fleet_manager' );
