<?php
/**
 * Notices Class
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages admin notices and admin bar items
 */
class Oli_CJ_Notices {

	/**
	 * Single instance
	 *
	 * @var Oli_CJ_Notices
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return Oli_CJ_Notices
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
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 100 );
	}

	/**
	 * Show admin notices
	 */
	public function show_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_plugin_screen() ) {
			return;
		}

		$issues = Oli_CJ_Site_Health::get_issues();

		foreach ( $issues as $issue ) {
			$class = 'error' === $issue['type'] ? 'notice-error' : 'notice-warning';
			printf(
				'<div class="notice %s is-dismissible"><p><strong>%s:</strong> %s</p></div>',
				esc_attr( $class ),
				esc_html__( 'Oli Cron Jobs', 'oli-cron-jobs' ),
				esc_html( $issue['message'] )
			);
		}
	}

	/**
	 * Whether the current admin screen belongs to this plugin.
	 *
	 * @return bool
	 */
	private function is_plugin_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}

		return false !== strpos( $screen->id, 'oli-cron-jobs' );
	}

	/**
	 * Add admin bar menu
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar object.
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$issues     = Oli_CJ_Site_Health::get_issues();
		$has_issues = ! empty( $issues );

		$title = __( 'Cron Jobs', 'oli-cron-jobs' );
		if ( $has_issues ) {
			$count  = count( $issues );
			$title .= sprintf( ' <span class="ab-label awaiting-mod count-%d">%d</span>', $count, $count );
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'oli-cron-jobs',
				'title' => $title,
				'href'  => admin_url( 'tools.php?page=oli-cron-jobs' ),
			)
		);

		// Add submenu items.
		$wp_admin_bar->add_node(
			array(
				'parent' => 'oli-cron-jobs',
				'id'     => 'oli-cron-jobs-events',
				'title'  => __( 'View Events', 'oli-cron-jobs' ),
				'href'   => admin_url( 'tools.php?page=oli-cron-jobs' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'parent' => 'oli-cron-jobs',
				'id'     => 'oli-cron-jobs-schedules',
				'title'  => __( 'Schedules', 'oli-cron-jobs' ),
				'href'   => admin_url( 'tools.php?page=oli-cron-jobs-schedules' ),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'parent' => 'oli-cron-jobs',
				'id'     => 'oli-cron-jobs-logs',
				'title'  => __( 'Logs', 'oli-cron-jobs' ),
				'href'   => admin_url( 'tools.php?page=oli-cron-jobs-logs' ),
			)
		);

		if ( $has_issues ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'oli-cron-jobs',
					'id'     => 'oli-cron-jobs-issues',
					'title'  => sprintf(
						/* translators: %d: number of issues */
						_n( '%d Issue', '%d Issues', count( $issues ), 'oli-cron-jobs' ),
						count( $issues )
					),
					'href'   => admin_url( 'tools.php?page=oli-cron-jobs' ),
					'meta'   => array(
						'class' => 'oli-cj-issues',
					),
				)
			);
		}
	}
}
