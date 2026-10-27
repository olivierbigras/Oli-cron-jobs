<?php
/**
 * Ownership helpers for plugin-created cron hooks.
 *
 * Used by the cron manager and by uninstall.php so both apply the same
 * conservative rules: when the origin of a hook is unclear, treat it as
 * not created by this plugin.
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a hook is owned because it is a PHP event, URL event, or oli_cj_* name.
 *
 * @param string     $hook       Hook name.
 * @param array|null $url_events URL event map. Null reads the option.
 * @param array|null $php_events PHP event map. Null reads the option.
 * @return bool
 */
function oli_cj_is_clearly_owned_hook( $hook, $url_events = null, $php_events = null ) {
	$hook = (string) $hook;
	if ( '' === $hook ) {
		return false;
	}

	if ( 0 === strpos( $hook, 'oli_cj_' ) ) {
		return true;
	}

	if ( null === $url_events ) {
		$url_events = get_option( 'oli_cj_url_events', array() );
	}
	if ( null === $php_events ) {
		$php_events = get_option( 'oli_cj_php_events', array() );
	}

	if ( is_array( $url_events ) && isset( $url_events[ $hook ] ) ) {
		return true;
	}

	return is_array( $php_events ) && isset( $php_events[ $hook ] );
}

/**
 * Whether this plugin created the hook.
 *
 * Trusts PHP/URL/oli_cj_* data and the post-0.2.6 created list. The legacy
 * managed list is not enough on its own.
 *
 * @param string     $hook          Hook name.
 * @param array|null $created_hooks Created-hook list. Null reads the option.
 * @param array|null $url_events    URL event map. Null reads the option.
 * @param array|null $php_events    PHP event map. Null reads the option.
 * @return bool
 */
function oli_cj_is_plugin_created_hook( $hook, $created_hooks = null, $url_events = null, $php_events = null ) {
	if ( oli_cj_is_clearly_owned_hook( $hook, $url_events, $php_events ) ) {
		return true;
	}

	if ( null === $created_hooks ) {
		$created_hooks = get_option( 'oli_cj_created_hooks', array() );
	}

	return is_array( $created_hooks ) && in_array( (string) $hook, $created_hooks, true );
}

/**
 * Hooks this plugin may unschedule on uninstall.
 *
 * @param array|null $created_hooks Created-hook list. Null reads the option.
 * @param array|null $url_events    URL event map. Null reads the option.
 * @param array|null $php_events    PHP event map. Null reads the option.
 * @param array|null $managed       Legacy managed list. Null reads the option.
 * @return string[]
 */
function oli_cj_hooks_to_unschedule_on_uninstall( $created_hooks = null, $url_events = null, $php_events = null, $managed = null ) {
	if ( null === $created_hooks ) {
		$created_hooks = get_option( 'oli_cj_created_hooks', array() );
	}
	if ( null === $url_events ) {
		$url_events = get_option( 'oli_cj_url_events', array() );
	}
	if ( null === $php_events ) {
		$php_events = get_option( 'oli_cj_php_events', array() );
	}
	if ( null === $managed ) {
		$managed = get_option( 'oli_cj_managed_hooks', array() );
	}

	$hooks = array( 'oli_cj_cleanup_logs' );

	if ( is_array( $url_events ) ) {
		$hooks = array_merge( $hooks, array_keys( $url_events ) );
	}
	if ( is_array( $php_events ) ) {
		$hooks = array_merge( $hooks, array_keys( $php_events ) );
	}
	if ( is_array( $created_hooks ) ) {
		$hooks = array_merge( $hooks, $created_hooks );
	}
	if ( is_array( $managed ) ) {
		foreach ( $managed as $hook ) {
			if ( oli_cj_is_clearly_owned_hook( $hook, $url_events, $php_events ) ) {
				$hooks[] = $hook;
			}
		}
	}

	return array_values( array_unique( array_filter( $hooks ) ) );
}

/**
 * Drop legacy managed names that were only edited, imported, or paused.
 *
 * @param array $managed    Current managed list.
 * @param array $url_events URL event map.
 * @param array $php_events PHP event map.
 * @return string[]
 */
function oli_cj_migrate_managed_hooks( $managed, $url_events, $php_events ) {
	$kept = array();

	if ( is_array( $managed ) ) {
		foreach ( $managed as $hook ) {
			$hook = (string) $hook;
			if ( oli_cj_is_clearly_owned_hook( $hook, $url_events, $php_events ) ) {
				$kept[] = $hook;
			}
		}
	}

	if ( is_array( $url_events ) ) {
		$kept = array_merge( $kept, array_keys( $url_events ) );
	}
	if ( is_array( $php_events ) ) {
		$kept = array_merge( $kept, array_keys( $php_events ) );
	}

	return array_values( array_unique( array_filter( $kept ) ) );
}
