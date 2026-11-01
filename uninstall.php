<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiEntryInsights
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3ei_settings = get_option( 'dev3ei_settings', array() );
if ( empty( $dev3ei_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3ei_entries', 'dev3ei_usage', 'dev3ei_core_cache', 'dev3ei_core_jobs', 'dev3ei_core_embeddings' ) as $dev3ei_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3ei_table}" );
}
foreach ( array( 'dev3ei_settings', 'dev3ei_license', 'dev3ei_db_version', 'dev3ei_digest' ) as $dev3ei_option ) {
	delete_option( $dev3ei_option );
}
delete_transient( 'dev3ei_models' );
delete_site_transient( 'dev3ei_update_info' );
wp_unschedule_hook( 'dev3ei_run_job' );
wp_unschedule_hook( 'dev3ei_process_queue' );
wp_unschedule_hook( 'dev3ei_daily' );
