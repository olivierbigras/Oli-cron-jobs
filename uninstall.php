<?php
/**
 * Uninstall Oli Cron Jobs.
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin options, logs table, and scheduled cleanup for the current site.
 */
function oli_cj_uninstall_site() {
	global $wpdb;

	$options = array(
		'oli_cj_version',
		'oli_cj_log_retention_days',
		'oli_cj_paused_hooks',
		'oli_cj_managed_hooks',
		'oli_cj_url_events',
		'oli_cj_php_events',
		'oli_cj_custom_schedules',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	$table_name = $wpdb->prefix . 'oli_cj_logs';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops the custom logs table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );

	wp_clear_scheduled_hook( 'oli_cj_cleanup_logs' );
}

if ( is_multisite() ) {
	$oli_cj_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $oli_cj_site_ids as $oli_cj_site_id ) {
		switch_to_blog( (int) $oli_cj_site_id );
		oli_cj_uninstall_site();
		restore_current_blog();
	}
} else {
	oli_cj_uninstall_site();
}
