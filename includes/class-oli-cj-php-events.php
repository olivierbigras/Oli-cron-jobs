<?php
/**
 * PHP cron event storage, integrity, and execution.
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages PHP cron events with capability gates and HMAC integrity.
 */
class Oli_CJ_PHP_Events {

	/**
	 * Option key for stored PHP events.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'oli_cj_php_events';

	/**
	 * Dedicated capability for creating, editing, deleting, or running PHP events.
	 *
	 * Granted to the administrator role by default. Filterable via
	 * oli_cj_php_events_capability.
	 *
	 * @var string
	 */
	const CAPABILITY = 'oli_cj_manage_php_events';

	/**
	 * Capability required to manage PHP cron events.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filter the capability required to manage PHP cron events.
		 *
		 * @param string $capability Default oli_cj_manage_php_events.
		 */
		$capability = apply_filters( 'oli_cj_php_events_capability', self::CAPABILITY );

		return is_string( $capability ) && '' !== $capability ? $capability : self::CAPABILITY;
	}

	/**
	 * Grant the dedicated PHP-event capability to administrators.
	 */
	public static function grant_default_caps() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Whether PHP cron events are fully disabled.
	 *
	 * Honors OLI_CJ_DISABLE_PHP_EVENTS, the older OLI_CJ_DISALLOW_PHP_EVENTS
	 * alias, and the oli_cj_php_events_disabled filter.
	 *
	 * @return bool
	 */
	public static function are_disabled() {
		$disabled = false;

		if ( defined( 'OLI_CJ_DISABLE_PHP_EVENTS' ) && OLI_CJ_DISABLE_PHP_EVENTS ) {
			$disabled = true;
		}

		if ( defined( 'OLI_CJ_DISALLOW_PHP_EVENTS' ) && OLI_CJ_DISALLOW_PHP_EVENTS ) {
			$disabled = true;
		}

		/**
		 * Filter whether PHP cron events are disabled entirely.
		 *
		 * When true, the PHP event UI is hidden and stored events will not run.
		 *
		 * @param bool $disabled Whether PHP events are disabled.
		 */
		return (bool) apply_filters( 'oli_cj_php_events_disabled', $disabled );
	}

	/**
	 * Last execute() result for the current request.
	 *
	 * @var bool
	 */
	private static $last_execute_ok = false;

	/**
	 * Whether WordPress file editing is disabled.
	 *
	 * @return bool
	 */
	public static function is_file_editing_disallowed() {
		$disallowed = false;

		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			$disallowed = true;
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			$disallowed = true;
		}

		/**
		 * Filter whether file editing (and therefore PHP event execution) is disallowed.
		 *
		 * @param bool $disallowed Whether file editing is disallowed.
		 */
		return (bool) apply_filters( 'oli_cj_is_file_editing_disallowed', $disallowed );
	}

	/**
	 * Whether the current user may add, edit, delete, or run PHP events.
	 *
	 * Requires manage_options and the dedicated oli_cj_manage_php_events
	 * capability (granted to administrators by default). On multisite only a
	 * super admin may manage PHP events. File-editing constants and the
	 * plugin disable switch still hide the UI.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		$allowed = false;

		if ( ! self::are_disabled() && ! self::is_file_editing_disallowed() ) {
			$allowed = current_user_can( 'manage_options' )
				&& current_user_can( self::capability() )
				&& current_user_can( 'edit_files' );

			if ( is_multisite() && ! is_super_admin() ) {
				$allowed = false;
			}
		}

		return $allowed;
	}

	/**
	 * Whether the PHP event UI (type option, code field, eval() warning) may be shown.
	 *
	 * False when the feature is disabled, when DISALLOW_FILE_EDIT or
	 * DISALLOW_FILE_MODS is set, or when the current user lacks the dedicated
	 * capability. Execution keeps its own checks in can_execute().
	 *
	 * @return bool
	 */
	public static function can_use_php_events() {
		if ( self::are_disabled() || self::constants_block_execution() || self::is_file_editing_disallowed() ) {
			return false;
		}

		return self::can_manage();
	}

	/**
	 * Whether DISALLOW_* or the plugin disable switch forbids PHP execution.
	 *
	 * This is the cron-safe gate and is checked again inside execute() so
	 * can_execute() is never the only check.
	 *
	 * @return bool
	 */
	public static function constants_block_execution() {
		if ( self::are_disabled() ) {
			return true;
		}

		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return true;
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether stored PHP may be executed, including by WP-Cron.
	 *
	 * Execution is refused when file editing is disabled, when the dedicated
	 * constant is set, or when the integrity hash is missing. Cron has no
	 * current user, so capability checks happen at save time only.
	 *
	 * @return bool
	 */
	public static function can_execute() {
		if ( self::constants_block_execution() || self::is_file_editing_disallowed() ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the last execute() call succeeded.
	 *
	 * @return bool
	 */
	public static function last_execute_succeeded() {
		return self::$last_execute_ok;
	}

	/**
	 * Human-readable reason PHP events cannot be managed, or empty.
	 *
	 * @return string
	 */
	public static function management_block_reason() {
		if ( self::are_disabled() ) {
			return __( 'PHP cron events are disabled by OLI_CJ_DISABLE_PHP_EVENTS or OLI_CJ_DISALLOW_PHP_EVENTS.', 'oli-cron-jobs' );
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return __( 'PHP cron events cannot be managed because DISALLOW_FILE_MODS is enabled.', 'oli-cron-jobs' );
		}

		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return __( 'PHP cron events cannot be managed because DISALLOW_FILE_EDIT is enabled.', 'oli-cron-jobs' );
		}

		if ( is_multisite() && ! is_super_admin() ) {
			return __( 'PHP cron events can only be managed by a super administrator on a multisite network.', 'oli-cron-jobs' );
		}

		if ( ! current_user_can( self::capability() ) ) {
			return sprintf(
				/* translators: %s: capability name */
				__( 'PHP cron events require the %s capability.', 'oli-cron-jobs' ),
				self::capability()
			);
		}

		if ( ! current_user_can( 'edit_files' ) ) {
			return __( 'PHP cron events require the edit_files capability (the same permission used by the plugin and theme editors).', 'oli-cron-jobs' );
		}

		return '';
	}

	/**
	 * Get all stored PHP events.
	 *
	 * @return array
	 */
	public static function get_all() {
		$php_events = Oli_CJ_Cron_Manager::get_plugin_option( self::OPTION_KEY, array() );
		return is_array( $php_events ) ? $php_events : array();
	}

	/**
	 * Whether any PHP events are stored.
	 *
	 * @return bool
	 */
	public static function has_any() {
		return ! empty( self::get_all() );
	}

	/**
	 * Normalize a stored PHP event entry.
	 *
	 * @param mixed $raw Stored value.
	 * @return array|false
	 */
	public static function normalize_entry( $raw ) {
		if ( is_string( $raw ) ) {
			return array(
				'code' => $raw,
				'hash' => '',
			);
		}

		if ( is_array( $raw ) && isset( $raw['code'] ) ) {
			return array(
				'code' => (string) $raw['code'],
				'hash' => isset( $raw['hash'] ) ? (string) $raw['hash'] : '',
			);
		}

		return false;
	}

	/**
	 * Get a stored PHP event entry.
	 *
	 * @param string $hook Hook name.
	 * @return array|false
	 */
	public static function get_entry( $hook ) {
		$php_events = self::get_all();
		if ( ! isset( $php_events[ $hook ] ) ) {
			return false;
		}

		return self::normalize_entry( $php_events[ $hook ] );
	}

	/**
	 * Get stored PHP code for a hook.
	 *
	 * @param string $hook Hook name.
	 * @return string|false
	 */
	public static function get_code( $hook ) {
		$entry = self::get_entry( $hook );
		return $entry ? $entry['code'] : false;
	}

	/**
	 * Whether a hook is a PHP event.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public static function is_php_event( $hook ) {
		return false !== self::get_entry( $hook );
	}

	/**
	 * HMAC for stored PHP code.
	 *
	 * @param string $code PHP code.
	 * @return string
	 */
	public static function hash_code( $code ) {
		return hash_hmac( 'sha256', (string) $code, wp_salt( 'auth' ) );
	}

	/**
	 * Whether a stored entry has a valid integrity hash.
	 *
	 * @param array $entry Normalized entry.
	 * @return bool
	 */
	public static function is_entry_valid( $entry ) {
		if ( ! is_array( $entry ) || '' === $entry['hash'] ) {
			return false;
		}

		return hash_equals( $entry['hash'], self::hash_code( $entry['code'] ) );
	}

	/**
	 * Whether a hook's stored PHP needs to be re-saved.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public static function needs_checking( $hook ) {
		$entry = self::get_entry( $hook );
		if ( ! $entry ) {
			return false;
		}

		return ! self::is_entry_valid( $entry );
	}

	/**
	 * Store PHP code for a hook.
	 *
	 * @param string $hook Hook name.
	 * @param string $code PHP code without an opening tag.
	 * @return bool
	 */
	public static function save_code( $hook, $code ) {
		$code                = self::strip_opening_tag( $code );
		$php_events          = self::get_all();
		$php_events[ $hook ] = array(
			'code' => $code,
			'hash' => self::hash_code( $code ),
		);

		return Oli_CJ_Cron_Manager::update_plugin_option( self::OPTION_KEY, $php_events );
	}

	/**
	 * Remove stored PHP for a hook.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public static function delete_code( $hook ) {
		$php_events = self::get_all();
		if ( ! isset( $php_events[ $hook ] ) ) {
			return true;
		}

		unset( $php_events[ $hook ] );
		return Oli_CJ_Cron_Manager::update_plugin_option( self::OPTION_KEY, $php_events );
	}

	/**
	 * Register execution callbacks for stored PHP events.
	 */
	public static function register_handlers() {
		foreach ( array_keys( self::get_all() ) as $hook ) {
			self::register_handler( $hook );
		}
	}

	/**
	 * Attach the PHP execution callback to a hook once.
	 *
	 * @param string $hook Hook name.
	 */
	public static function register_handler( $hook ) {
		if ( ! is_string( $hook ) || '' === $hook ) {
			return;
		}

		if ( ! has_action( $hook, array( __CLASS__, 'execute_wrapper' ) ) ) {
			add_action( $hook, array( __CLASS__, 'execute_wrapper' ) );
		}
	}

	/**
	 * Wrapper for executing PHP events.
	 */
	public static function execute_wrapper() {
		$hook = current_filter();
		self::execute( $hook );
	}

	/**
	 * Execute a stored PHP event.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	public static function execute( $hook ) {
		self::$last_execute_ok = false;

		if ( Oli_CJ_Cron_Manager::is_hook_paused( $hook ) ) {
			Oli_CJ_Logger::log_event( $hook, array(), 'blocked', 0, 'Hook is paused' );
			return false;
		}

		// Recheck constants here. can_execute() alone is not the execution gate.
		if ( self::constants_block_execution() ) {
			Oli_CJ_Logger::log_event( $hook, array(), 'blocked', 0, 'PHP events are disabled' );
			return false;
		}

		if ( self::is_file_editing_disallowed() ) {
			Oli_CJ_Logger::log_event( $hook, array(), 'blocked', 0, 'PHP events are disabled' );
			return false;
		}

		if ( ! self::can_execute() ) {
			Oli_CJ_Logger::log_event( $hook, array(), 'blocked', 0, 'PHP events are disabled' );
			return false;
		}

		$entry = self::get_entry( $hook );
		if ( ! $entry ) {
			return false;
		}

		if ( ! self::is_entry_valid( $entry ) ) {
			Oli_CJ_Logger::log_event( $hook, array(), 'blocked', 0, 'PHP event failed the integrity check' );
			return false;
		}

		$start_time = microtime( true );
		$error      = '';

		try {
			self::run_code( $entry['code'] );
		} catch ( Throwable $throwable ) {
			$error = $throwable->getMessage();
		}

		$duration              = microtime( true ) - $start_time;
		self::$last_execute_ok = ( '' === $error );
		Oli_CJ_Logger::log_event( $hook, array(), 'php', $duration, $error );

		return self::$last_execute_ok;
	}

	/**
	 * Remove a leading PHP opening tag if the user included one.
	 *
	 * @param string $code PHP code.
	 * @return string
	 */
	public static function strip_opening_tag( $code ) {
		$code = (string) $code;
		$code = preg_replace( '/^\s*<\?(php)?\s*/i', '', $code, 1 );
		return is_string( $code ) ? $code : '';
	}

	/**
	 * Generate a unique hook name for a PHP event.
	 *
	 * @return string
	 */
	public static function generate_hook() {
		return 'oli_cj_php_' . wp_generate_password( 12, false, false );
	}

	/**
	 * Run stored PHP with eval() after HMAC and file-edit gates.
	 *
	 * @param string $code PHP code without an opening tag.
	 * @return void
	 * @throws RuntimeException If execution is not permitted.
	 */
	private static function run_code( $code ) {
		if ( self::constants_block_execution() || self::is_file_editing_disallowed() ) {
			throw new RuntimeException( 'PHP events cannot be executed while file editing is disabled.' );
		}

		$code = self::strip_opening_tag( $code );

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- HMAC-checked PHP cron event; gated by oli_cj_manage_php_events, manage_options, edit_files, super admin on multisite, and OLI_CJ_DISABLE_PHP_EVENTS / DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS.
		eval( $code );
	}
}
