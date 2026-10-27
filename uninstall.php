<?php
/**
 * Uninstall Oli Cron Jobs.
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) && ! defined( 'OLI_CJ_LOAD_UNINSTALL_FN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/oli-cj-ownership.php';

/**
 * Remove the PHP events capability from every role of the current site that has it.
 */
function oli_cj_uninstall_remove_capability() {
	$roles = wp_roles();
	foreach ( $roles->role_objects as $role ) {
		if ( $role->has_cap( 'oli_cj_manage_php_events' ) ) {
			$role->remove_cap( 'oli_cj_manage_php_events' );
		}
	}
}

/**
 * Delete plugin options, logs table, and plugin-created cron events for the current site.
 */
function oli_cj_uninstall_site() {
	global $wpdb;

	$options = array(
		'oli_cj_version',
		'oli_cj_log_retention_days',
		'oli_cj_paused_hooks',
		'oli_cj_managed_hooks',
		'oli_cj_created_hooks',
		'oli_cj_url_events',
		'oli_cj_php_events',
		'oli_cj_custom_schedules',
	);

	$managed    = get_option( 'oli_cj_managed_hooks', array() );
	$created    = get_option( 'oli_cj_created_hooks', array() );
	$url_events = get_option( 'oli_cj_url_events', array() );
	$php_events = get_option( 'oli_cj_php_events', array() );

	$hooks = oli_cj_hooks_to_unschedule_on_uninstall( $created, $url_events, $php_events, $managed );

	foreach ( $hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	delete_transient( 'oli_cj_spawn_test' );

	oli_cj_uninstall_remove_capability();

	$table_name = $wpdb->prefix . 'oli_cj_logs';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops the custom logs table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
}

if ( defined( 'WP_UNINSTALL_PLUGIN' ) && WP_UNINSTALL_PLUGIN ) {
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
}
