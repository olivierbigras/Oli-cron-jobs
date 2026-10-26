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
	 * Whether PHP cron events are fully disabled.
	 *
	 * @return bool
	 */
	public static function are_disabled() {
		return defined( 'OLI_CJ_DISALLOW_PHP_EVENTS' ) && OLI_CJ_DISALLOW_PHP_EVENTS;
	}

	/**
	 * Whether WordPress file editing is disabled.
	 *
	 * @return bool
	 */
	public static function is_file_editing_disallowed() {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return true;
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the current user may add, edit, delete, or run PHP events.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		if ( self::are_disabled() || self::is_file_editing_disallowed() ) {
			return false;
		}

		return current_user_can( 'manage_options' ) && current_user_can( 'edit_files' );
	}

	/**
	 * Whether stored PHP may be executed by cron.
	 *
	 * Scheduled execution is not tied to the current user. Integrity and the
	 * OLI_CJ_DISALLOW_PHP_EVENTS constant still apply.
	 *
	 * @return bool
	 */
	public static function can_execute() {
		return ! self::are_disabled();
	}

	/**
	 * Human-readable reason PHP events cannot be managed, or empty.
	 *
	 * @return string
	 */
	public static function management_block_reason() {
		if ( self::are_disabled() ) {
			return __( 'PHP cron events are disabled by the OLI_CJ_DISALLOW_PHP_EVENTS constant.', 'oli-cron-jobs' );
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return __( 'PHP cron events cannot be managed because DISALLOW_FILE_MODS is enabled.', 'oli-cron-jobs' );
		}

		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return __( 'PHP cron events cannot be managed because DISALLOW_FILE_EDIT is enabled.', 'oli-cron-jobs' );
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
		if ( Oli_CJ_Cron_Manager::is_hook_paused( $hook ) ) {
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

		$duration = microtime( true ) - $start_time;
		Oli_CJ_Logger::log_event( $hook, array(), 'php', $duration, $error );

		return '' === $error;
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
	 * Run stored PHP by including a short-lived file. Does not use eval().
	 *
	 * @param string $code PHP code without an opening tag.
	 * @return void
	 * @throws RuntimeException If the temporary file cannot be created or written.
	 */
	private static function run_code( $code ) {
		$dir = get_temp_dir();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Temporary runtime file, not a theme/plugin edit.
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			throw new RuntimeException( 'The temporary directory is not writable.' );
		}

		$base = tempnam( $dir, 'oli_cj_' );
		if ( ! $base ) {
			throw new RuntimeException( 'Unable to create a temporary PHP event file.' );
		}

		$file = $base . '.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Temporary runtime file rename.
		if ( ! rename( $base, $file ) ) {
			wp_delete_file( $base );
			throw new RuntimeException( 'Unable to prepare a temporary PHP event file.' );
		}

		$wrapped = "<?php\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n" . self::strip_opening_tag( $code );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Short-lived runtime include, not a theme/plugin edit.
		$written = file_put_contents( $file, $wrapped, LOCK_EX );
		if ( false === $written ) {
			wp_delete_file( $file );
			throw new RuntimeException( 'Unable to write the temporary PHP event file.' );
		}

		try {
			include $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Integrity-checked user PHP event.
		} finally {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
