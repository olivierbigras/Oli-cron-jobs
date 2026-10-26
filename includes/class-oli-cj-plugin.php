<?php
/**
 * Main plugin class
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps Oli Cron Jobs.
 */
class Oli_CJ_Plugin {

	/**
	 * Single instance
	 *
	 * @var Oli_CJ_Plugin
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return Oli_CJ_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->load_dependencies();
		self::maybe_upgrade();
		$this->init_hooks();
	}

	/**
	 * Load plugin dependencies
	 */
	private function load_dependencies() {
		require_once OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-cron-manager.php';
		require_once OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-php-events.php';
		require_once OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-schedule-manager.php';
		require_once OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-logger.php';
		require_once OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-site-health.php';
		require_once OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-notices.php';

		if ( is_admin() ) {
			require_once OLI_CJ_PLUGIN_DIR . 'admin/class-oli-cj-admin.php';
			require_once OLI_CJ_PLUGIN_DIR . 'admin/class-oli-cj-list-table.php';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-cli.php';
		}
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'init' ) );

		// Register URL handlers and pause guards now, not on a later init callback.
		Oli_CJ_Cron_Manager::init();
	}

	/**
	 * Initialize plugin components
	 */
	public function init() {
		Oli_CJ_Cron_Manager::register_event_handlers();
		Oli_CJ_Cron_Manager::register_paused_hook_guards();
		Oli_CJ_Logger::get_instance();
		Oli_CJ_Site_Health::get_instance();
		Oli_CJ_Notices::get_instance();

		if ( is_admin() ) {
			Oli_CJ_Admin::get_instance();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'oli-cron', 'Oli_CJ_CLI' );
		}
	}

	/**
	 * Plugin activation
	 */
	public static function activate() {
		require_once OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-logger.php';

		if ( false === get_option( 'oli_cj_log_retention_days' ) ) {
			update_option( 'oli_cj_log_retention_days', 30 );
		}

		self::maybe_upgrade();

		flush_rewrite_rules();
	}

	/**
	 * Run versioned upgrades and copy any legacy options to oli_cj_* keys.
	 */
	public static function maybe_upgrade() {
		$stored = get_option( 'oli_cj_version', '' );

		if ( version_compare( (string) $stored, OLI_CJ_VERSION, '>=' ) ) {
			return;
		}

		require_once OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-logger.php';
		Oli_CJ_Logger::create_table();

		self::migrate_legacy_options();

		update_option( 'oli_cj_version', OLI_CJ_VERSION );
	}

	/**
	 * Copy pre-prefix options onto oli_cj_* keys, then delete the old keys.
	 *
	 * Options have used the oli_cj_ prefix since 0.1.0. This keeps upgrades
	 * lossless if an older unprefixed or alternate key is still present.
	 */
	private static function migrate_legacy_options() {
		$map = array(
			'oli_cron_jobs_log_retention_days' => 'oli_cj_log_retention_days',
			'oli_cron_jobs_paused_hooks'       => 'oli_cj_paused_hooks',
			'oli_cron_jobs_managed_hooks'      => 'oli_cj_managed_hooks',
			'oli_cron_jobs_url_events'         => 'oli_cj_url_events',
			'oli_cron_jobs_php_events'         => 'oli_cj_php_events',
			'oli_cron_jobs_custom_schedules'   => 'oli_cj_custom_schedules',
		);

		foreach ( $map as $old_key => $new_key ) {
			$old_value = get_option( $old_key, '__oli_cj_missing__' );

			if ( '__oli_cj_missing__' === $old_value ) {
				continue;
			}

			if ( '__oli_cj_missing__' === get_option( $new_key, '__oli_cj_missing__' ) ) {
				update_option( $new_key, $old_value );
			}

			delete_option( $old_key );
		}
	}

	/**
	 * Plugin deactivation
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
