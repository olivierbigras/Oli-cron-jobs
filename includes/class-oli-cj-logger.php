<?php
/**
 * Logger Class
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs cron event executions
 */
class Oli_CJ_Logger {

	/**
	 * Single instance
	 *
	 * @var Oli_CJ_Logger
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return Oli_CJ_Logger
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
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'oli_cj_cleanup_logs', array( $this, 'cleanup_old_logs' ) );

		if ( ! wp_next_scheduled( 'oli_cj_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'oli_cj_cleanup_logs' );
		}
	}

	/**
	 * Table name
	 *
	 * @return string
	 */
	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'oli_cj_logs';
	}

	/**
	 * Create logs table
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			hook varchar(191) NOT NULL,
			args longtext,
			event_type varchar(20) NOT NULL DEFAULT 'standard',
			duration float,
			error_message text,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY hook (hook),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log a cron event
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Event arguments.
	 * @param string $event_type Type of event (standard, manual, php, url, blocked).
	 * @param float  $duration Duration in seconds.
	 * @param string $error_message Error message if any.
	 * @return int|false Insert ID or false on failure.
	 */
	public static function log_event( $hook, $args = array(), $event_type = 'standard', $duration = 0, $error_message = '' ) {
		global $wpdb;

		$data = array(
			'hook'          => sanitize_text_field( $hook ),
			'args'          => wp_json_encode( $args ),
			'event_type'    => sanitize_text_field( $event_type ),
			'duration'      => floatval( $duration ),
			'error_message' => sanitize_textarea_field( $error_message ),
			'created_at'    => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table.
		$result = $wpdb->insert( self::table_name(), $data );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get logs
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_logs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'hook'       => '',
			'event_type' => '',
			'limit'      => 100,
			'offset'     => 0,
			'order'      => 'DESC',
		);

		$args         = wp_parse_args( $args, $defaults );
		$table_name   = self::table_name();
		$limit        = absint( $args['limit'] );
		$offset       = absint( $args['offset'] );
		$order        = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$where_sql    = '1=1';
		$where_values = array();

		if ( ! empty( $args['hook'] ) ) {
			$where_sql     .= ' AND hook = %s';
			$where_values[] = $args['hook'];
		}

		if ( ! empty( $args['event_type'] ) ) {
			$where_sql     .= ' AND event_type = %s';
			$where_values[] = $args['event_type'];
		}

		$where_values[] = $limit;
		$where_values[] = $offset;

		if ( 'ASC' === $order ) {
			$sql = "SELECT * FROM %i WHERE $where_sql ORDER BY created_at ASC LIMIT %d OFFSET %d";
		} else {
			$sql = "SELECT * FROM %i WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
		}

		array_unshift( $where_values, $table_name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; SQL built from whitelisted fragments.
		return $wpdb->get_results( $wpdb->prepare( $sql, $where_values ) );
	}

	/**
	 * Get log count
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public static function get_log_count( $args = array() ) {
		global $wpdb;

		$table_name   = self::table_name();
		$where_sql    = '1=1';
		$where_values = array( $table_name );

		if ( ! empty( $args['hook'] ) ) {
			$where_sql     .= ' AND hook = %s';
			$where_values[] = $args['hook'];
		}

		if ( ! empty( $args['event_type'] ) ) {
			$where_sql     .= ' AND event_type = %s';
			$where_values[] = $args['event_type'];
		}

		$sql = "SELECT COUNT(*) FROM %i WHERE $where_sql";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; SQL built from whitelisted fragments.
		return absint( $wpdb->get_var( $wpdb->prepare( $sql, $where_values ) ) );
	}

	/**
	 * Delete old logs based on retention setting
	 */
	public function cleanup_old_logs() {
		global $wpdb;

		$retention_days = absint( get_option( 'oli_cj_log_retention_days', 30 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				self::table_name(),
				$retention_days
			)
		);
	}

	/**
	 * Clear all logs
	 *
	 * @return bool
	 */
	public static function clear_all_logs() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$result = $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table_name() ) );

		return false !== $result;
	}

	/**
	 * Delete logs for a specific hook
	 *
	 * @param string $hook Hook name.
	 * @return int|false Number of rows deleted or false on failure.
	 */
	public static function delete_hook_logs( $hook ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		return $wpdb->delete(
			self::table_name(),
			array( 'hook' => $hook ),
			array( '%s' )
		);
	}
}
