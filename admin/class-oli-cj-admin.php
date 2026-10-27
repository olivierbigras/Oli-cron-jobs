<?php
/**
 * Admin Class
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin interface
 */
class Oli_CJ_Admin {

	/**
	 * Single instance
	 *
	 * @var Oli_CJ_Admin
	 */
	private static $instance = null;

	/**
	 * Action values read by handle_actions() after its nonce check.
	 *
	 * @var array<string, string>
	 */
	private $action_values = array();

	/**
	 * Get singleton instance
	 *
	 * @return Oli_CJ_Admin
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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		$list_hook = add_management_page(
			__( 'Oli Cron Jobs', 'oli-cron-jobs' ),
			__( 'Cron Jobs', 'oli-cron-jobs' ),
			'manage_options',
			'oli-cron-jobs',
			array( $this, 'render_events_page' )
		);

		if ( $list_hook ) {
			add_action( 'load-' . $list_hook, array( $this, 'ensure_list_nonce' ) );
		}

		add_submenu_page(
			'tools.php',
			__( 'Add Cron Event', 'oli-cron-jobs' ),
			__( 'Add Cron Event', 'oli-cron-jobs' ),
			'manage_options',
			'oli-cron-jobs-add',
			array( $this, 'render_add_event_page' )
		);

		// Hidden Tools child. remove_submenu_page() drops the menu item, so
		// get_admin_page_title() would leave $title unset. Set it on load.
		$edit_hook = add_submenu_page(
			'tools.php',
			__( 'Edit Cron Event', 'oli-cron-jobs' ),
			__( 'Edit Cron Event', 'oli-cron-jobs' ),
			'manage_options',
			'oli-cron-jobs-edit',
			array( $this, 'render_edit_event_page' )
		);
		remove_submenu_page( 'tools.php', 'oli-cron-jobs-edit' );
		add_action( 'load-' . $edit_hook, array( $this, 'load_edit_event_page' ) );

		add_submenu_page(
			'tools.php',
			__( 'Cron Schedules', 'oli-cron-jobs' ),
			__( 'Cron Schedules', 'oli-cron-jobs' ),
			'manage_options',
			'oli-cron-jobs-schedules',
			array( $this, 'render_schedules_page' )
		);

		add_submenu_page(
			'tools.php',
			__( 'Cron Logs', 'oli-cron-jobs' ),
			__( 'Cron Logs', 'oli-cron-jobs' ),
			'manage_options',
			'oli-cron-jobs-logs',
			array( $this, 'render_logs_page' )
		);

		add_submenu_page(
			'tools.php',
			__( 'Cron Settings', 'oli-cron-jobs' ),
			__( 'Cron Settings', 'oli-cron-jobs' ),
			'manage_options',
			'oli-cron-jobs-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Give the hidden edit screen a title before admin-header.php runs.
	 *
	 * Calling remove_submenu_page() keeps the page off the Tools menu but also
	 * removes the only source WordPress uses for $title. PHP 8.4 then
	 * deprecates strip_tags( null ) in admin-header.php.
	 */
	public function load_edit_event_page() {
		global $title, $parent_file, $submenu_file;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Hidden edit screen must set $title before admin-header.php; parent/submenu keep Tools > Cron Jobs highlighted.
		$title        = __( 'Edit Cron Event', 'oli-cron-jobs' );
		$parent_file  = 'tools.php';
		$submenu_file = 'oli-cron-jobs';
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		add_filter( 'admin_title', array( $this, 'filter_edit_event_admin_title' ), 10, 2 );

		// Refuse an unsigned edit link, or the edit view of a PHP event, before any output (HTTP 403).
		$event_key = self::verified_edit_link();
		if ( null === $event_key ) {
			wp_die(
				esc_html__( 'This edit link is invalid or has expired. Open the event again from the Cron Jobs list.', 'oli-cron-jobs' ),
				esc_html__( 'Forbidden', 'oli-cron-jobs' ),
				array( 'response' => 403 )
			);
		}

		if ( ! self::may_touch_hook( $event_key['hook'] ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage PHP cron events.', 'oli-cron-jobs' ),
				esc_html__( 'Forbidden', 'oli-cron-jobs' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Status text with an icon so meaning is not color-only.
	 *
	 * @param string $tone  One of ok, warning, error, off, disabled, paused.
	 * @param string $label Visible label.
	 * @return string
	 */
	public static function status_markup( $tone, $label ) {
		$tones = array(
			'ok'       => array( 'oli-cj-status-ok', 'dashicons-yes-alt' ),
			'warning'  => array( 'oli-cj-status-warning', 'dashicons-warning' ),
			'error'    => array( 'oli-cj-status-error', 'dashicons-dismiss' ),
			'off'      => array( 'oli-cj-status-off', 'dashicons-minus' ),
			'disabled' => array( 'oli-cj-status-disabled', 'dashicons-warning' ),
			'paused'   => array( 'oli-cj-status-paused', 'dashicons-controls-pause' ),
		);

		if ( ! isset( $tones[ $tone ] ) ) {
			$tone = 'off';
		}

		return sprintf(
			'<span class="oli-cj-status %1$s"><span class="dashicons %2$s" aria-hidden="true"></span><span class="oli-cj-status-text">%3$s</span></span>',
			esc_attr( $tones[ $tone ][0] ),
			esc_attr( $tones[ $tone ][1] ),
			esc_html( $label )
		);
	}

	/**
	 * Print a status badge.
	 *
	 * @param string $tone  One of ok, warning, error, off, disabled, paused.
	 * @param string $label Visible label.
	 */
	public static function print_status( $tone, $label ) {
		echo wp_kses(
			self::status_markup( $tone, $label ),
			array(
				'span' => array(
					'class'       => true,
					'aria-hidden' => true,
				),
			)
		);
	}

	/**
	 * Keep the HTML document title populated if the screen title is missing.
	 *
	 * @param string $admin_title Full admin title.
	 * @param string $title       Screen title.
	 * @return string
	 */
	public function filter_edit_event_admin_title( $admin_title, $title ) {
		if ( is_string( $title ) && '' !== $title ) {
			return $admin_title;
		}

		return sprintf(
			'%1$s &lsaquo; %2$s',
			__( 'Edit Cron Event', 'oli-cron-jobs' ),
			get_bloginfo( 'name' )
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( ! in_array( $hook, array( 'tools_page_oli-cron-jobs', 'tools_page_oli-cron-jobs-add', 'tools_page_oli-cron-jobs-edit', 'admin_page_oli-cron-jobs-edit', 'tools_page_oli-cron-jobs-schedules', 'tools_page_oli-cron-jobs-logs', 'tools_page_oli-cron-jobs-settings' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'oli-cj-admin',
			OLI_CJ_PLUGIN_URL . 'assets/css/admin.css',
			array( 'dashicons' ),
			OLI_CJ_VERSION
		);

		wp_enqueue_script(
			'oli-cj-admin',
			OLI_CJ_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			OLI_CJ_VERSION,
			true
		);

		wp_localize_script(
			'oli-cj-admin',
			'oli_cj_admin',
			array(
				'confirmBulkDelete' => __( 'Are you sure you want to delete the selected events?', 'oli-cron-jobs' ),
				'confirmGeneric'    => __( 'Are you sure?', 'oli-cron-jobs' ),
			)
		);
	}

	/**
	 * Handle admin actions
	 */
	public function handle_actions() {
		// Import runs on admin_init so it can answer 403 and redirect before output.
		if ( isset( $_POST['oli_cj_import'] ) ) {
			$this->handle_import();
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['oli_cj_add_event'] ) ) {
			$this->process_add_event_form();
			return;
		}

		if ( isset( $_POST['oli_cj_edit_event'] ) ) {
			$this->process_edit_event_form();
			return;
		}

		// Verify the nonces first; the page and action slugs are read only afterwards.
		$action_nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'oli_cj_action' );
		$bulk_nonce_ok   = isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-' . sanitize_key( __( 'Cron Events', 'oli-cron-jobs' ) ) );

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'oli-cron-jobs' ) ) {
			return;
		}

		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$bulk    = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$bulk2   = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		$is_bulk = 'delete' === $bulk || ( ( '' === $bulk || '-1' === $bulk ) && 'delete' === $bulk2 );

		if ( 'oli-cron-jobs' === $page && $is_bulk ) {
			if ( ! $bulk_nonce_ok ) {
				$this->die_forbidden();
			}

			$packed_events = array();
			if ( isset( $_REQUEST['event'] ) && is_array( $_REQUEST['event'] ) ) {
				$packed_events = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['event'] ) );
			}

			$this->process_bulk_delete( $packed_events );
			return;
		}

		$known = array(
			'delete_event',
			'run_now',
			'pause_hook',
			'resume_hook',
			'delete_schedule',
			'clear_logs',
			'export',
			'export_csv',
			'test_spawn',
		);

		if ( ! in_array( $action, $known, true ) ) {
			return;
		}

		if ( ! $action_nonce_ok ) {
			$this->die_forbidden();
		}

		// Values used by the action handlers, read after the nonce check.
		$this->action_values = array();
		foreach ( array( 'hook', 'timestamp', 'sig', 'args', 'schedule' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				$this->action_values[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}

		switch ( $action ) {
			case 'delete_event':
				$this->handle_delete_event();
				break;
			case 'run_now':
				$this->handle_run_now();
				break;
			case 'pause_hook':
				$this->handle_pause_hook();
				break;
			case 'resume_hook':
				$this->handle_resume_hook();
				break;
			case 'delete_schedule':
				$this->handle_delete_schedule();
				break;
			case 'clear_logs':
				$this->handle_clear_logs();
				break;
			case 'export':
				$this->handle_export();
				break;
			case 'export_csv':
				$this->handle_export_csv();
				break;
			case 'test_spawn':
				$this->handle_test_spawn();
				break;
		}
	}

	/**
	 * Nonce action for the edit form of one specific event.
	 *
	 * Binding the nonce to the event key means the nonce printed on one
	 * event's edit screen cannot be replayed against another event.
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Timestamp.
	 * @param string $sig       Arguments signature.
	 * @return string
	 */
	public static function edit_nonce_action( $hook, $timestamp, $sig ) {
		return self::event_nonce_action( 'oli_cj_edit_event_', $hook, $timestamp, $sig );
	}

	/**
	 * Nonce action of the signed "Edit" link for one specific event.
	 *
	 * @since 0.2.10
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Timestamp.
	 * @param string $sig       Arguments signature.
	 * @return string
	 */
	public static function edit_link_nonce_action( $hook, $timestamp, $sig ) {
		return self::event_nonce_action( 'oli_cj_view_event_', $hook, $timestamp, $sig );
	}

	/**
	 * Signed URL of the edit screen for one event.
	 *
	 * @since 0.2.10
	 *
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Timestamp.
	 * @param string $sig       Arguments signature.
	 * @return string Unescaped URL.
	 */
	public static function edit_link_url( $hook, $timestamp, $sig ) {
		return add_query_arg(
			array(
				'page'      => 'oli-cron-jobs-edit',
				'hook'      => rawurlencode( (string) $hook ),
				'timestamp' => absint( $timestamp ),
				'sig'       => rawurlencode( (string) $sig ),
				'_wpnonce'  => wp_create_nonce( self::edit_link_nonce_action( $hook, $timestamp, $sig ) ),
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Event key of the current edit screen, when the link nonce and capability check pass.
	 *
	 * @since 0.2.10
	 *
	 * @return array{hook: string, timestamp: int, sig: string}|null
	 */
	public static function verified_edit_link() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		// The nonce is bound to the event key, so the key is read inside the check.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
			self::edit_link_nonce_action(
				isset( $_GET['hook'] ) ? sanitize_text_field( wp_unslash( $_GET['hook'] ) ) : '',
				isset( $_GET['timestamp'] ) ? absint( wp_unslash( $_GET['timestamp'] ) ) : 0,
				isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : ''
			)
		) ) {
			return null;
		}

		return array(
			'hook'      => isset( $_GET['hook'] ) ? sanitize_text_field( wp_unslash( $_GET['hook'] ) ) : '',
			'timestamp' => isset( $_GET['timestamp'] ) ? absint( wp_unslash( $_GET['timestamp'] ) ) : 0,
			'sig'       => isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '',
		);
	}

	/**
	 * Nonce action bound to one event key.
	 *
	 * @param string $prefix    Action prefix.
	 * @param string $hook      Hook name.
	 * @param int    $timestamp Timestamp.
	 * @param string $sig       Arguments signature.
	 * @return string
	 */
	private static function event_nonce_action( $prefix, $hook, $timestamp, $sig ) {
		return $prefix . md5( (string) $hook . '|' . absint( $timestamp ) . '|' . (string) $sig );
	}

	/**
	 * Events list filters, search and sort, honored only with the list nonce.
	 *
	 * The filter form, the view links and every redirect to the list carry
	 * oli_cj_list_nonce; ensure_list_nonce() adds it to a bare list URL.
	 *
	 * @since 0.2.10
	 *
	 * @return array<string, string>
	 */
	public static function list_filters() {
		$filters = array(
			'status_filter'   => '',
			'schedule_filter' => '',
			'type_filter'     => '',
			's'               => '',
			'orderby'         => '',
			'order'           => '',
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			return $filters;
		}

		if ( ! isset( $_REQUEST['oli_cj_list_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['oli_cj_list_nonce'] ) ), 'oli_cj_list' ) ) {
			return $filters;
		}

		foreach ( array_keys( $filters ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ) {
				$filters[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
			}
		}

		return $filters;
	}

	/**
	 * Give a bare events-list URL (menu link, sort or pagination link) a fresh list nonce.
	 *
	 * Runs on load-tools_page_oli-cron-jobs. Without a valid nonce, filter and sort
	 * arguments are dropped, so they are only applied from this plugin's own links.
	 *
	 * @since 0.2.10
	 */
	public function ensure_list_nonce() {
		if ( ! current_user_can( 'manage_options' ) || 'GET' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
			return;
		}

		if ( isset( $_GET['oli_cj_list_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['oli_cj_list_nonce'] ) ), 'oli_cj_list' ) ) {
			return;
		}

		$url = remove_query_arg( array( 'status_filter', 'schedule_filter', 'type_filter', 's', 'orderby', 'order', 'paged', 'oli_cj_list_nonce' ) );
		wp_safe_redirect( add_query_arg( 'oli_cj_list_nonce', wp_create_nonce( 'oli_cj_list' ), $url ) );
		exit;
	}

	/**
	 * Whether the current user may act on a hook that is (or would become) a PHP event.
	 *
	 * @param string $hook Hook name.
	 * @return bool True when the hook is not a PHP event or the user may manage PHP events.
	 */
	public static function may_touch_hook( $hook ) {
		return ! Oli_CJ_Cron_Manager::is_php_event( (string) $hook ) || Oli_CJ_PHP_Events::can_manage();
	}

	/**
	 * Bulk-delete events on admin_init, before any output.
	 *
	 * The caller handle_actions() verified the bulk nonce and read the packed event keys.
	 * PHP events are skipped for users who may not manage them. Success
	 * redirects back to the list.
	 *
	 * @since 0.2.10 Receives the verified event keys instead of reading the request.
	 *
	 * @param string[] $packed_events Sanitized "hook|timestamp|sig" keys.
	 */
	private function process_bulk_delete( $packed_events ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->die_forbidden();
		}

		$deleted   = 0;
		$forbidden = false;
		foreach ( $packed_events as $packed ) {
			$parts = explode( '|', $packed );
			if ( 3 !== count( $parts ) ) {
				continue;
			}

			if ( ! self::may_touch_hook( $parts[0] ) ) {
				$forbidden = true;
				continue;
			}

			if ( Oli_CJ_Cron_Manager::delete_event( $parts[0], absint( $parts[1] ), $parts[2] ) ) {
				++$deleted;
			}
		}

		$this->redirect_events( $forbidden ? 'php_event_forbidden' : 'event_deleted' );
	}

	/**
	 * Read a sanitized action value stored by handle_actions() after the nonce check.
	 *
	 * @param string $key           Query key.
	 * @param string $default_value Default value.
	 * @return string
	 */
	private function get_action_text( $key, $default_value = '' ) {
		return isset( $this->action_values[ $key ] ) ? $this->action_values[ $key ] : $default_value;
	}

	/**
	 * Die with HTTP 403 when a nonce is missing or invalid.
	 */
	private function die_forbidden() {
		wp_die(
			esc_html__( 'Security check failed.', 'oli-cron-jobs' ),
			esc_html__( 'Forbidden', 'oli-cron-jobs' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * URL carrying a status message, signed so render_messages() can trust it.
	 *
	 * Also adds the events-list nonce so a redirect to the list keeps its filters valid.
	 *
	 * @since 0.2.10
	 *
	 * @param string               $message Message slug.
	 * @param string               $url     Base admin URL.
	 * @param array<string, mixed> $extra   Extra query arguments.
	 * @return string Unescaped URL.
	 */
	private static function message_url( $message, $url, $extra = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'oli_cj_message'      => $message,
					'oli_cj_notice_nonce' => wp_create_nonce( 'oli_cj_notice' ),
					'oli_cj_list_nonce'   => wp_create_nonce( 'oli_cj_list' ),
				),
				$extra
			),
			$url
		);
	}

	/**
	 * Redirect to the events list with a status message.
	 *
	 * @param string $message Message slug.
	 */
	private function redirect_events( $message ) {
		wp_safe_redirect( self::message_url( $message, admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
		exit;
	}

	/**
	 * Redirect to the add-event screen with a status message.
	 *
	 * @param string $message Message slug.
	 */
	private function redirect_add( $message ) {
		wp_safe_redirect( self::message_url( $message, admin_url( 'tools.php?page=oli-cron-jobs-add' ) ) );
		exit;
	}

	/**
	 * Process the add-event form on admin_init before any output.
	 */
	private function process_add_event_form() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'oli_cj_add_event' ) ) {
			$this->die_forbidden();
		}

		$parsed     = $this->parse_event_form( 'oli_cj_add_event' );
		$event_type = $parsed['event_type'];
		$hook       = $parsed['hook'];
		$schedule   = $parsed['schedule'];
		$timestamp  = $parsed['timestamp'];
		$args       = $parsed['args'];

		if ( 'php' === $event_type ) {
			if ( ! Oli_CJ_PHP_Events::can_manage() ) {
				$this->redirect_add( 'php_event_forbidden' );
			}

			if ( '' === $hook ) {
				$hook = Oli_CJ_PHP_Events::generate_hook();
			}

			if ( '' === $parsed['php_code'] ) {
				$this->redirect_add( 'php_code_required' );
			}

			Oli_CJ_Cron_Manager::add_php_event( $hook, $timestamp, $parsed['php_code'], $args, $schedule );
			$this->redirect_events( 'event_added' );
		}

		if ( '' !== $hook && ! self::may_touch_hook( $hook ) ) {
			$this->redirect_add( 'php_event_forbidden' );
		}

		if ( 'url' === $event_type ) {
			if ( '' === $hook ) {
				$this->redirect_add( 'url_hook_required' );
			}

			if ( '' === $parsed['url'] ) {
				$this->redirect_add( 'url_required' );
			}

			Oli_CJ_Cron_Manager::add_url_event( $hook, $timestamp, $parsed['url'], $args, $schedule );
			$this->redirect_events( 'event_added' );
		}

		if ( '' === $hook ) {
			$this->redirect_add( 'hook_required' );
		}

		Oli_CJ_Cron_Manager::add_event( $hook, $timestamp, $args, $schedule );
		$this->redirect_events( 'event_added' );
	}

	/**
	 * Process the edit-event form on admin_init before any output.
	 */
	private function process_edit_event_form() {
		// oli_cj_edit_event nonce, bound to the edited event key (the key is read inside the check).
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
			self::edit_nonce_action(
				isset( $_POST['old_hook'] ) ? sanitize_text_field( wp_unslash( $_POST['old_hook'] ) ) : '',
				isset( $_POST['old_timestamp'] ) ? absint( wp_unslash( $_POST['old_timestamp'] ) ) : 0,
				isset( $_POST['old_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['old_sig'] ) ) : ''
			)
		) ) {
			$this->die_forbidden();
		}

		$old_hook      = isset( $_POST['old_hook'] ) ? sanitize_text_field( wp_unslash( $_POST['old_hook'] ) ) : '';
		$old_timestamp = isset( $_POST['old_timestamp'] ) ? absint( wp_unslash( $_POST['old_timestamp'] ) ) : 0;
		$old_sig       = isset( $_POST['old_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['old_sig'] ) ) : '';

		if ( ! $old_hook || ! $old_timestamp || ! $old_sig ) {
			$this->redirect_events( 'event_not_found' );
		}

		// Editing from a PHP event (to any type) needs the PHP capability.
		if ( ! self::may_touch_hook( $old_hook ) ) {
			$this->redirect_events( 'php_event_forbidden' );
		}

		$parsed         = $this->parse_event_form( self::edit_nonce_action( $old_hook, $old_timestamp, $old_sig ) );
		$new_event_type = $parsed['event_type'];
		$new_hook       = $parsed['hook'];
		$new_schedule   = $parsed['schedule'];
		$new_timestamp  = $parsed['timestamp'];
		$new_args       = $parsed['args'];
		$can_manage_php = Oli_CJ_PHP_Events::can_manage();

		if ( 'php' === $new_event_type && ! $can_manage_php ) {
			$this->redirect_events( 'php_event_forbidden' );
		}

		if ( '' === $new_hook && 'php' === $new_event_type && $can_manage_php ) {
			$new_hook = Oli_CJ_PHP_Events::generate_hook();
		}

		if ( 'url' === $new_event_type && '' === $new_hook ) {
			$this->redirect_events( 'url_hook_required' );
		}

		if ( 'url' === $new_event_type && '' === $parsed['url'] ) {
			$this->redirect_events( 'url_required' );
		}

		if ( '' === $new_hook ) {
			$this->redirect_events( 'hook_required' );
		}

		// Renaming onto an existing PHP event's hook also touches that event.
		if ( ! self::may_touch_hook( $new_hook ) ) {
			$this->redirect_events( 'php_event_forbidden' );
		}

		Oli_CJ_Cron_Manager::delete_event( $old_hook, $old_timestamp, $old_sig );

		if ( $new_hook !== $old_hook ) {
			Oli_CJ_PHP_Events::delete_code( $old_hook );
			$url_events = Oli_CJ_Cron_Manager::get_plugin_option( 'oli_cj_url_events', array() );
			if ( isset( $url_events[ $old_hook ] ) ) {
				unset( $url_events[ $old_hook ] );
				Oli_CJ_Cron_Manager::update_plugin_option( 'oli_cj_url_events', $url_events );
			}
		}

		if ( 'php' === $new_event_type ) {
			Oli_CJ_Cron_Manager::add_php_event( $new_hook, $new_timestamp, $parsed['php_code'], $new_args, $new_schedule );
		} elseif ( 'url' === $new_event_type ) {
			Oli_CJ_Cron_Manager::add_url_event( $new_hook, $new_timestamp, $parsed['url'], $new_args, $new_schedule );
		} else {
			Oli_CJ_Cron_Manager::add_event( $new_hook, $new_timestamp, $new_args, $new_schedule );
		}

		$this->redirect_events( 'event_updated' );
	}

	/**
	 * Parse shared add/edit event form fields.
	 *
	 * @since 0.2.10 Verifies the form nonce itself before reading any field.
	 *
	 * @param string $nonce_action Nonce action of the submitted form.
	 * @return array
	 */
	private function parse_event_form( $nonce_action ) {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			$this->die_forbidden();
		}

		$event_type    = isset( $_POST['event_type'] ) ? sanitize_key( wp_unslash( $_POST['event_type'] ) ) : 'standard';
		$hook          = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		$schedule      = isset( $_POST['schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) : '';
		$timestamp_raw = isset( $_POST['timestamp'] ) ? sanitize_text_field( wp_unslash( $_POST['timestamp'] ) ) : '';
		$timestamp     = Oli_CJ_Cron_Manager::parse_site_datetime( $timestamp_raw );
		$url           = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$php_code      = self::read_posted_php_code();

		if ( ! $timestamp ) {
			$timestamp = time() + 3600;
		}

		$args = array();
		if ( ! empty( $_POST['args'] ) ) {
			$args_json = sanitize_textarea_field( wp_unslash( $_POST['args'] ) );
			$args      = json_decode( $args_json, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$args = array();
			}
		}

		return array(
			'event_type' => $event_type,
			'hook'       => $hook,
			'schedule'   => $schedule ? $schedule : false,
			'timestamp'  => $timestamp,
			'args'       => $args,
			'url'        => $url,
			'php_code'   => is_string( $php_code ) ? $php_code : '',
		);
	}

	/**
	 * Read the posted PHP event code exactly as typed.
	 *
	 * PHP code cannot go through a text sanitizer without being altered, so it is
	 * read with filter_input() (the original request value, without WordPress
	 * slashes). Only called from parse_event_form() after its nonce check; the
	 * add/edit handlers store it only for users who pass Oli_CJ_PHP_Events::can_manage(),
	 * and it is HMAC-signed before storage.
	 *
	 * @since 0.2.10
	 *
	 * @return string
	 */
	private static function read_posted_php_code() {
		$code = filter_input( INPUT_POST, 'php_code', FILTER_UNSAFE_RAW );

		return is_string( $code ) ? $code : '';
	}

	/**
	 * Handle delete event action
	 */
	private function handle_delete_event() {
		$hook      = $this->get_action_text( 'hook' );
		$timestamp = absint( $this->get_action_text( 'timestamp', '0' ) );
		$sig       = $this->get_action_text( 'sig' );

		if ( Oli_CJ_Cron_Manager::is_php_event( $hook ) && ! Oli_CJ_PHP_Events::can_manage() ) {
			wp_safe_redirect( self::message_url( 'php_event_forbidden', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
			exit;
		}

		if ( Oli_CJ_Cron_Manager::delete_event( $hook, $timestamp, $sig ) ) {
			wp_safe_redirect( self::message_url( 'event_deleted', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
			exit;
		}
	}

	/**
	 * Handle run now action
	 */
	private function handle_run_now() {
		$hook      = $this->get_action_text( 'hook' );
		$args_json = $this->get_action_text( 'args', '[]' );
		$args      = json_decode( $args_json, true );
		$args      = is_array( $args ) ? $args : array();

		if ( Oli_CJ_Cron_Manager::is_php_event( $hook ) && ! Oli_CJ_PHP_Events::can_manage() ) {
			wp_safe_redirect( self::message_url( 'php_event_forbidden', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
			exit;
		}

		$result = Oli_CJ_Cron_Manager::run_event_now( $hook, $args );

		wp_safe_redirect(
			self::message_url( $result ? 'event_ran' : 'event_failed', admin_url( 'tools.php?page=oli-cron-jobs' ) )
		);
		exit;
	}

	/**
	 * Handle pause hook action
	 */
	private function handle_pause_hook() {
		$hook = $this->get_action_text( 'hook' );

		if ( ! self::may_touch_hook( $hook ) ) {
			$this->redirect_events( 'php_event_forbidden' );
		}

		if ( Oli_CJ_Cron_Manager::pause_hook( $hook ) ) {
			wp_safe_redirect( self::message_url( 'hook_paused', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
			exit;
		}
	}

	/**
	 * Handle resume hook action
	 */
	private function handle_resume_hook() {
		$hook = $this->get_action_text( 'hook' );

		if ( ! self::may_touch_hook( $hook ) ) {
			$this->redirect_events( 'php_event_forbidden' );
		}

		if ( Oli_CJ_Cron_Manager::resume_hook( $hook ) ) {
			wp_safe_redirect( self::message_url( 'hook_resumed', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
			exit;
		}
	}

	/**
	 * Handle delete schedule action
	 */
	private function handle_delete_schedule() {
		$schedule = $this->get_action_text( 'schedule' );

		if ( Oli_CJ_Schedule_Manager::delete_schedule( $schedule ) ) {
			wp_safe_redirect( self::message_url( 'schedule_deleted', admin_url( 'tools.php?page=oli-cron-jobs-schedules' ) ) );
			exit;
		}
	}

	/**
	 * Handle clear logs action
	 */
	private function handle_clear_logs() {
		if ( Oli_CJ_Logger::clear_all_logs() ) {
			wp_safe_redirect( self::message_url( 'logs_cleared', admin_url( 'tools.php?page=oli-cron-jobs-logs' ) ) );
			exit;
		}
	}

	/**
	 * Handle export action
	 */
	private function handle_export() {
		$export_data = array(
			'version'          => OLI_CJ_VERSION,
			'export_date'      => current_time( 'mysql' ),
			'events'           => array(),
			'custom_schedules' => Oli_CJ_Schedule_Manager::get_custom_schedules(),
			'paused_hooks'     => array_values( array_filter( Oli_CJ_Cron_Manager::get_paused_hooks(), array( __CLASS__, 'may_touch_hook' ) ) ),
		);

		// Export custom/managed events only (URL, PHP, and events created here).
		$events = Oli_CJ_Cron_Manager::get_all_events();
		foreach ( $events as $event ) {
			if ( ! Oli_CJ_Cron_Manager::is_managed( $event['hook'] ) ) {
				continue;
			}
			// PHP events (and their code) are exported only to users who may manage them.
			if ( ! self::may_touch_hook( $event['hook'] ) ) {
				continue;
			}
			$event_data = array(
				'hook'      => $event['hook'],
				'args'      => $event['args'],
				'timestamp' => $event['timestamp'],
				'schedule'  => $event['schedule'],
			);

			// Add URL or PHP code if applicable.
			$url      = Oli_CJ_Cron_Manager::get_url( $event['hook'] );
			$php_code = Oli_CJ_Cron_Manager::get_php_code( $event['hook'] );

			if ( $url ) {
				$event_data['type'] = 'url';
				$event_data['url']  = $url;
			} elseif ( $php_code ) {
				$event_data['type']     = 'php';
				$event_data['php_code'] = $php_code;
			} else {
				$event_data['type'] = 'standard';
			}

			$export_data['events'][] = $event_data;
		}

		// Output JSON file; wp_send_json() sets the JSON content type, encodes and ends the request.
		header( 'Content-Disposition: attachment; filename="oli-cron-jobs-export-' . gmdate( 'Y-m-d-H-i-s' ) . '.json"' );
		wp_send_json( $export_data, null, JSON_PRETTY_PRINT );
	}

	/**
	 * Drop PHP events for users who may not manage them.
	 *
	 * @param array $events Events from get_all_events().
	 * @return array
	 */
	public static function visible_events( $events ) {
		if ( Oli_CJ_PHP_Events::can_manage() ) {
			return $events;
		}

		return array_values(
			array_filter(
				$events,
				function ( $event ) {
					return ! Oli_CJ_Cron_Manager::is_php_event( $event['hook'] );
				}
			)
		);
	}

	/**
	 * Export the current event list as CSV.
	 */
	private function handle_export_csv() {
		$events   = self::visible_events( Oli_CJ_Cron_Manager::get_all_events() );
		$rows     = Oli_CJ_Cron_Manager::get_csv_rows( $events );
		$filename = 'oli-cron-jobs-events-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output stream for a CSV download.
		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- CSV download stream. Extra args avoid PHP 8.4 deprecation.
		fputcsv(
			$output,
			array(
				'Hook',
				'Arguments',
				'Next Run (site timezone)',
				'Next Run (UTC)',
				'Recurrence',
				'Callbacks',
				'Status',
			),
			',',
			'"',
			'\\'
		);

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- CSV download stream. Extra args avoid PHP 8.4 deprecation.
			fputcsv(
				$output,
				array(
					Oli_CJ_Cron_Manager::escape_csv_cell( $row['hook'] ),
					Oli_CJ_Cron_Manager::escape_csv_cell( $row['args'] ),
					Oli_CJ_Cron_Manager::escape_csv_cell( $row['next_run_site'] ),
					Oli_CJ_Cron_Manager::escape_csv_cell( $row['next_run_utc'] ),
					Oli_CJ_Cron_Manager::escape_csv_cell( $row['recurrence'] ),
					Oli_CJ_Cron_Manager::escape_csv_cell( $row['callbacks'] ),
					Oli_CJ_Cron_Manager::escape_csv_cell( $row['status'] ),
				),
				',',
				'"',
				'\\'
			);
		}

		exit;
	}

	/**
	 * Run a same-site WP-Cron spawn test and store the result.
	 */
	private function handle_test_spawn() {
		set_transient( 'oli_cj_spawn_test', Oli_CJ_Site_Health::run_spawn_test(), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::message_url( 'spawn_tested', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
		exit;
	}

	/**
	 * Handle import action
	 */
	private function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to import cron data.', 'oli-cron-jobs' ),
				esc_html__( 'Forbidden', 'oli-cron-jobs' ),
				array( 'response' => 403 )
			);
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'oli_cj_import' ) ) {
			$this->die_forbidden();
		}

		if ( ! isset( $_FILES['import_file'] ) || ! isset( $_FILES['import_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['import_file']['error'] ) {
			wp_safe_redirect( self::message_url( 'import_error', admin_url( 'tools.php?page=oli-cron-jobs-settings' ) ) );
			exit;
		}

		$tmp_name = isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['import_file']['tmp_name'] ) ) : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			wp_safe_redirect( self::message_url( 'import_error', admin_url( 'tools.php?page=oli-cron-jobs-settings' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local uploaded JSON file.
		$file_contents = file_get_contents( $tmp_name );
		$import_data   = json_decode( $file_contents, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $import_data ) ) {
			wp_safe_redirect( self::message_url( 'import_error', admin_url( 'tools.php?page=oli-cron-jobs-settings' ) ) );
			exit;
		}

		$imported_count = self::import_data( $import_data );

		wp_safe_redirect(
			self::message_url( 'imported', admin_url( 'tools.php?page=oli-cron-jobs-settings' ), array( 'oli_cj_count' => $imported_count ) )
		);
		exit;
	}

	/**
	 * Apply decoded import data. The caller checked the nonce and manage_options.
	 *
	 * PHP rows, rows on an existing PHP hook, and pause changes for PHP hooks
	 * are skipped unless the current user may manage PHP events.
	 *
	 * @param array $import_data Decoded JSON export.
	 * @return int Number of imported events.
	 */
	public static function import_data( $import_data ) {
		$imported_count = 0;

		// Import custom schedules.
		if ( ! empty( $import_data['custom_schedules'] ) && is_array( $import_data['custom_schedules'] ) ) {
			foreach ( $import_data['custom_schedules'] as $name => $schedule ) {
				if ( ! is_array( $schedule ) ) {
					continue;
				}

				$name     = sanitize_key( $name );
				$interval = isset( $schedule['interval'] ) ? absint( $schedule['interval'] ) : 0;
				$display  = isset( $schedule['display'] ) ? sanitize_text_field( $schedule['display'] ) : '';

				if ( $name && $interval && $display ) {
					Oli_CJ_Schedule_Manager::add_schedule( $name, $interval, $display );
				}
			}
		}

		// Import paused hooks.
		if ( ! empty( $import_data['paused_hooks'] ) && is_array( $import_data['paused_hooks'] ) ) {
			$paused_hooks = array_values(
				array_filter(
					array_map( 'sanitize_text_field', $import_data['paused_hooks'] )
				)
			);
			if ( ! Oli_CJ_PHP_Events::can_manage() ) {
				// Keep the current pause state of PHP events untouched.
				$paused_hooks = array_filter( $paused_hooks, array( __CLASS__, 'may_touch_hook' ) );
				foreach ( Oli_CJ_Cron_Manager::get_paused_hooks() as $paused_hook ) {
					if ( ! self::may_touch_hook( $paused_hook ) ) {
						$paused_hooks[] = $paused_hook;
					}
				}
				$paused_hooks = array_values( array_unique( $paused_hooks ) );
			}
			Oli_CJ_Cron_Manager::update_plugin_option( 'oli_cj_paused_hooks', $paused_hooks );
			Oli_CJ_Cron_Manager::register_paused_hook_guards();
		}

		// Import events.
		if ( ! empty( $import_data['events'] ) && is_array( $import_data['events'] ) ) {
			foreach ( $import_data['events'] as $event ) {
				if ( ! is_array( $event ) || empty( $event['hook'] ) ) {
					continue;
				}

				$hook      = sanitize_text_field( $event['hook'] );
				$args      = ( isset( $event['args'] ) && is_array( $event['args'] ) ) ? $event['args'] : array();
				$timestamp = isset( $event['timestamp'] ) ? absint( $event['timestamp'] ) : time();
				$schedule  = ! empty( $event['schedule'] ) ? sanitize_key( $event['schedule'] ) : false;
				$type      = isset( $event['type'] ) ? sanitize_key( $event['type'] ) : 'standard';

				if ( ! $hook ) {
					continue;
				}

				// Any imported row that targets an existing PHP event needs the PHP capability.
				if ( ! self::may_touch_hook( $hook ) ) {
					continue;
				}

				if ( 'php' === $type ) {
					if ( ! Oli_CJ_PHP_Events::can_manage() || empty( $event['php_code'] ) ) {
						continue;
					}

					Oli_CJ_Cron_Manager::add_php_event( $hook, $timestamp, $event['php_code'], $args, $schedule );
					++$imported_count;
					continue;
				}

				if ( 'url' === $type && ! empty( $event['url'] ) ) {
					Oli_CJ_Cron_Manager::add_url_event( $hook, $timestamp, esc_url_raw( $event['url'] ), $args, $schedule );
					++$imported_count;
				} else {
					Oli_CJ_Cron_Manager::add_event( $hook, $timestamp, $args, $schedule, false );
					++$imported_count;
				}
			}
		}

		return $imported_count;
	}

	/**
	 * Render events page
	 */
	public function render_events_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage cron events.', 'oli-cron-jobs' ) );
		}

		$list_table = new Oli_CJ_List_Table();
		$list_table->prepare_items();

		$this->render_page_header( __( 'Cron Events', 'oli-cron-jobs' ) );
		?>
		<div class="wrap" id="oli-cron-jobs">
			<h1>
				<?php echo esc_html__( 'Cron Events', 'oli-cron-jobs' ); ?>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=oli-cron-jobs-add' ) ); ?>" class="page-title-action">
					<?php echo esc_html__( 'Add New', 'oli-cron-jobs' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=oli-cron-jobs&action=export_csv' ), 'oli_cj_action' ) ); ?>" class="page-title-action">
					<?php echo esc_html__( 'Export CSV', 'oli-cron-jobs' ); ?>
				</a>
			</h1>

			<?php $this->render_messages(); ?>
			<?php $this->render_cron_status_panel(); ?>

			<form method="get" id="oli-cj-events-form">
				<input type="hidden" name="page" value="oli-cron-jobs" />
				<?php wp_nonce_field( 'oli_cj_list', 'oli_cj_list_nonce', false ); ?>
				<?php $list_table->views(); ?>
				<?php $list_table->search_box( __( 'Search Events', 'oli-cron-jobs' ), 'oli-cj-events' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Show WP-Cron status, constant warnings, and the spawn test.
	 */
	private function render_cron_status_panel() {
		$cron_disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$alternate_cron = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
		$spawn          = get_transient( 'oli_cj_spawn_test' );
		?>
		<div class="oli-cj-status-panel">
			<h2><?php echo esc_html__( 'WP-Cron Status', 'oli-cron-jobs' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Built-in WP-Cron', 'oli-cron-jobs' ); ?></th>
						<td>
							<?php if ( $cron_disabled ) : ?>
								<?php self::print_status( 'disabled', __( 'Disabled (DISABLE_WP_CRON)', 'oli-cron-jobs' ) ); ?>
								<p class="description"><?php echo esc_html__( 'WordPress will not spawn cron on page views. Configure a server cron job that requests wp-cron.php.', 'oli-cron-jobs' ); ?></p>
							<?php else : ?>
								<?php self::print_status( 'ok', __( 'Enabled', 'oli-cron-jobs' ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Alternate WP-Cron', 'oli-cron-jobs' ); ?></th>
						<td>
							<?php if ( $alternate_cron ) : ?>
								<?php self::print_status( 'warning', __( 'Enabled (ALTERNATE_WP_CRON)', 'oli-cron-jobs' ) ); ?>
								<p class="description"><?php echo esc_html__( 'WordPress redirects visitors to spawn cron. This can be unreliable on some hosts.', 'oli-cron-jobs' ); ?></p>
							<?php else : ?>
								<?php self::print_status( 'off', __( 'Off', 'oli-cron-jobs' ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Site timezone', 'oli-cron-jobs' ); ?></th>
						<td><?php echo esc_html( wp_timezone_string() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Spawn test', 'oli-cron-jobs' ); ?></th>
						<td>
							<?php if ( is_array( $spawn ) && ! empty( $spawn['message'] ) ) : ?>
								<?php
								self::print_status(
									! empty( $spawn['success'] ) ? 'ok' : 'error',
									$spawn['message']
								);
								?>
							<?php endif; ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=oli-cron-jobs&action=test_spawn' ), 'oli_cj_action' ) ); ?>" class="button">
								<?php echo esc_html__( 'Run spawn test', 'oli-cron-jobs' ); ?>
							</a>
							<p class="description"><?php echo esc_html__( 'Sends an HTTP POST request to this site\'s wp-cron.php to check that WordPress can spawn cron.', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render add event page
	 */
	public function render_add_event_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage cron events.', 'oli-cron-jobs' ) );
		}

		$this->render_page_header( __( 'Add Cron Event', 'oli-cron-jobs' ) );

		$schedules      = Oli_CJ_Schedule_Manager::get_all_schedules();
		$can_manage_php = Oli_CJ_PHP_Events::can_use_php_events();
		$php_block      = Oli_CJ_PHP_Events::management_block_reason();
		$next_run_input = Oli_CJ_Cron_Manager::format_site_datetime_input( time() + 3600 );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Add Cron Event', 'oli-cron-jobs' ); ?></h1>

			<?php $this->render_messages(); ?>
			<?php $this->render_php_warning(); ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'oli_cj_add_event' ); ?>
				
				<table class="form-table">
					<tr>
						<th scope="row"><label for="event_type"><?php echo esc_html__( 'Event Type', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<select name="event_type" id="event_type">
								<option value="standard"><?php echo esc_html__( 'Standard Event', 'oli-cron-jobs' ); ?></option>
								<option value="url"><?php echo esc_html__( 'URL Event', 'oli-cron-jobs' ); ?></option>
								<?php if ( $can_manage_php ) : ?>
									<option value="php"><?php echo esc_html__( 'PHP Event', 'oli-cron-jobs' ); ?></option>
								<?php endif; ?>
							</select>
							<?php if ( ! $can_manage_php && $php_block ) : ?>
								<p class="description"><?php echo esc_html( $php_block ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hook"><?php echo esc_html__( 'Hook Name', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<input type="text" name="hook" id="hook" class="regular-text" />
							<p class="description"><?php echo esc_html__( 'The name of the action hook to execute. Leave empty for a PHP event to generate one.', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="timestamp"><?php echo esc_html__( 'Next Run', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<input type="datetime-local" name="timestamp" id="timestamp" value="<?php echo esc_attr( $next_run_input ); ?>" required />
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: site timezone */
										__( 'When the event should first run, in the site timezone (%s).', 'oli-cron-jobs' ),
										wp_timezone_string()
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schedule"><?php echo esc_html__( 'Recurrence', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<select name="schedule" id="schedule">
								<option value=""><?php echo esc_html__( 'Non-repeating', 'oli-cron-jobs' ); ?></option>
								<?php foreach ( $schedules as $name => $schedule ) : ?>
									<option value="<?php echo esc_attr( $name ); ?>">
										<?php echo esc_html( $schedule['display'] . ' (' . Oli_CJ_Schedule_Manager::format_interval( $schedule['interval'] ) . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="args"><?php echo esc_html__( 'Arguments', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<textarea name="args" id="args" class="large-text" rows="3" placeholder="[]"></textarea>
							<p class="description"><?php echo esc_html__( 'JSON array of arguments to pass to the hook. Example: ["arg1", "arg2"]', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
					<tr id="url_field" class="oli-cj-hidden">
						<th scope="row"><label for="url"><?php echo esc_html__( 'URL', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<input type="url" name="url" id="url" class="regular-text" placeholder="https://example.com/api/endpoint" />
							<p class="description"><?php echo esc_html__( 'The URL to request when this cron event runs.', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
					<?php if ( $can_manage_php ) : ?>
					<tr id="php_field" class="oli-cj-hidden">
						<th scope="row"><label for="php_code"><?php echo esc_html__( 'PHP Code', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<textarea name="php_code" id="php_code" class="large-text code" rows="10" placeholder="<?php echo esc_attr( 'error_log( \'Oli Cron Jobs PHP event\' );' ); ?>"></textarea>
							<p class="description"><?php echo esc_html__( 'PHP to run when this event fires. Do not include the opening <?php tag. This has the same access as the plugin editor.', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				
				<p class="submit">
					<input type="submit" name="oli_cj_add_event" class="button button-primary" value="<?php echo esc_attr__( 'Add Event', 'oli-cron-jobs' ); ?>" />
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=oli-cron-jobs' ) ); ?>" class="button">
						<?php echo esc_html__( 'Cancel', 'oli-cron-jobs' ); ?>
					</a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render edit event page
	 */
	public function render_edit_event_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage cron events.', 'oli-cron-jobs' ) );
		}

		$this->render_page_header( __( 'Edit Cron Event', 'oli-cron-jobs' ) );

		$event_key = self::verified_edit_link();
		if ( null === $event_key ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'This edit link is invalid or has expired. Open the event again from the Cron Jobs list.', 'oli-cron-jobs' ) . '</p></div></div>';
			return;
		}

		$hook      = $event_key['hook'];
		$timestamp = $event_key['timestamp'];
		$sig       = $event_key['sig'];

		if ( ! $hook || ! $timestamp || ! $sig ) {
			wp_die( esc_html__( 'Invalid event parameters.', 'oli-cron-jobs' ) );
		}

		$event = Oli_CJ_Cron_Manager::get_event( $hook, $timestamp, $sig );

		if ( ! $event ) {
			wp_die( esc_html__( 'Event not found.', 'oli-cron-jobs' ) );
		}

		$url            = Oli_CJ_Cron_Manager::get_url( $hook );
		$php_code       = Oli_CJ_Cron_Manager::get_php_code( $hook );
		$can_manage_php = Oli_CJ_PHP_Events::can_use_php_events();
		$event_type     = 'standard';
		if ( $url ) {
			$event_type = 'url';
		} elseif ( false !== $php_code ) {
			$event_type = 'php';
		}

		if ( 'php' === $event_type && ! $can_manage_php ) {
			// Never show a PHP event or its stored code without the capability.
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to manage PHP cron events.', 'oli-cron-jobs' ) . '</p></div></div>';
			return;
		}

		$schedules        = Oli_CJ_Schedule_Manager::get_all_schedules();
		$current_schedule = isset( $event['schedule'] ) ? $event['schedule'] : '';
		$php_needs_check  = ( 'php' === $event_type && Oli_CJ_PHP_Events::needs_checking( $hook ) );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Edit Cron Event', 'oli-cron-jobs' ); ?></h1>

			<?php $this->render_messages(); ?>
			<?php $this->render_php_warning(); ?>
			<?php if ( $php_needs_check ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'This PHP event needs checking. Re-save it to refresh the integrity hash before it can run.', 'oli-cron-jobs' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( self::edit_nonce_action( $event['hook'], $event['timestamp'], $event['sig'] ) ); ?>
				<input type="hidden" name="old_hook" value="<?php echo esc_attr( $event['hook'] ); ?>" />
				<input type="hidden" name="old_timestamp" value="<?php echo esc_attr( (string) $event['timestamp'] ); ?>" />
				<input type="hidden" name="old_sig" value="<?php echo esc_attr( $event['sig'] ); ?>" />
				
				<table class="form-table">
					<tr>
						<th scope="row"><label for="event_type"><?php echo esc_html__( 'Event Type', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<select name="event_type" id="event_type">
								<option value="standard" <?php selected( $event_type, 'standard' ); ?>><?php echo esc_html__( 'Standard Event', 'oli-cron-jobs' ); ?></option>
								<option value="url" <?php selected( $event_type, 'url' ); ?>><?php echo esc_html__( 'URL Event', 'oli-cron-jobs' ); ?></option>
								<?php if ( $can_manage_php ) : ?>
									<option value="php" <?php selected( $event_type, 'php' ); ?>><?php echo esc_html__( 'PHP Event', 'oli-cron-jobs' ); ?></option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hook"><?php echo esc_html__( 'Hook Name', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<input type="text" name="hook" id="hook" class="regular-text" value="<?php echo esc_attr( $event['hook'] ); ?>" required />
							<p class="description"><?php echo esc_html__( 'The name of the action hook to execute.', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="timestamp"><?php echo esc_html__( 'Next Run', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<input type="datetime-local" name="timestamp" id="timestamp" value="<?php echo esc_attr( Oli_CJ_Cron_Manager::format_site_datetime_input( $event['timestamp'] ) ); ?>" required />
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: site timezone */
										__( 'When the event should first run, in the site timezone (%s).', 'oli-cron-jobs' ),
										wp_timezone_string()
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schedule"><?php echo esc_html__( 'Recurrence', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<select name="schedule" id="schedule">
								<option value="" <?php selected( $current_schedule, '' ); ?>><?php echo esc_html__( 'Non-repeating', 'oli-cron-jobs' ); ?></option>
								<?php foreach ( $schedules as $name => $schedule ) : ?>
									<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $current_schedule, $name ); ?>>
										<?php echo esc_html( $schedule['display'] . ' (' . Oli_CJ_Schedule_Manager::format_interval( $schedule['interval'] ) . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="args"><?php echo esc_html__( 'Arguments', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<textarea name="args" id="args" class="large-text" rows="3"><?php echo esc_textarea( wp_json_encode( $event['args'] ) ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'JSON array of arguments to pass to the hook. Example: ["arg1", "arg2"]', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
					<tr id="url_field"<?php echo 'url' === $event_type ? '' : ' class="oli-cj-hidden"'; ?>>
						<th scope="row"><label for="url"><?php echo esc_html__( 'URL', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<input type="url" name="url" id="url" class="regular-text" value="<?php echo esc_attr( $url ? $url : '' ); ?>" placeholder="https://example.com/api/endpoint" />
							<p class="description"><?php echo esc_html__( 'The URL to request when this cron event runs.', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
					<?php if ( $can_manage_php ) : ?>
					<tr id="php_field"<?php echo 'php' === $event_type ? '' : ' class="oli-cj-hidden"'; ?>>
						<th scope="row"><label for="php_code"><?php echo esc_html__( 'PHP Code', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<textarea name="php_code" id="php_code" class="large-text code" rows="10"><?php echo esc_textarea( $php_code ? $php_code : '' ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'PHP to run when this event fires. Do not include the opening <?php tag.', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				
				<p class="submit">
					<input type="submit" name="oli_cj_edit_event" class="button button-primary" value="<?php echo esc_attr__( 'Update Event', 'oli-cron-jobs' ); ?>" />
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=oli-cron-jobs' ) ); ?>" class="button">
						<?php echo esc_html__( 'Cancel', 'oli-cron-jobs' ); ?>
					</a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render schedules page
	 */
	public function render_schedules_page() {
		$this->render_page_header( __( 'Cron Schedules', 'oli-cron-jobs' ) );

		// Handle form submission.
		if ( isset( $_POST['oli_cj_add_schedule'] ) && check_admin_referer( 'oli_cj_add_schedule' ) && current_user_can( 'manage_options' ) ) {
			$name     = isset( $_POST['schedule_name'] ) ? sanitize_key( wp_unslash( $_POST['schedule_name'] ) ) : '';
			$interval = isset( $_POST['schedule_interval'] ) ? absint( wp_unslash( $_POST['schedule_interval'] ) ) : 0;
			$display  = isset( $_POST['schedule_display'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_display'] ) ) : '';

			if ( $name && $interval && $display ) {
				Oli_CJ_Schedule_Manager::add_schedule( $name, $interval, $display );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Schedule added successfully.', 'oli-cron-jobs' ) . '</p></div>';
			}
		}

		$schedules = Oli_CJ_Schedule_Manager::get_all_schedules();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Cron Schedules', 'oli-cron-jobs' ); ?></h1>

			<?php $this->render_messages(); ?>

			<h2><?php echo esc_html__( 'Existing Schedules', 'oli-cron-jobs' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Name', 'oli-cron-jobs' ); ?></th>
						<th><?php echo esc_html__( 'Display Name', 'oli-cron-jobs' ); ?></th>
						<th><?php echo esc_html__( 'Interval', 'oli-cron-jobs' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'oli-cron-jobs' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $schedules as $name => $schedule ) : ?>
						<tr>
							<td><code><?php echo esc_html( $name ); ?></code></td>
							<td><?php echo esc_html( $schedule['display'] ); ?></td>
							<td><?php echo esc_html( Oli_CJ_Schedule_Manager::format_interval( $schedule['interval'] ) ); ?></td>
							<td>
								<?php if ( Oli_CJ_Schedule_Manager::is_custom_schedule( $name ) ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=oli-cron-jobs-schedules&action=delete_schedule&schedule=' . rawurlencode( $name ) ), 'oli_cj_action' ) ); ?>" class="delete oli-cj-confirm" data-oli-cj-confirm="<?php echo esc_attr__( 'Are you sure?', 'oli-cron-jobs' ); ?>">
										<?php echo esc_html__( 'Delete', 'oli-cron-jobs' ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html__( 'Built-in', 'oli-cron-jobs' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Add New Schedule', 'oli-cron-jobs' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'oli_cj_add_schedule' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="schedule_name"><?php echo esc_html__( 'Internal Name', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<input type="text" name="schedule_name" id="schedule_name" class="regular-text" required />
							<p class="description"><?php echo esc_html__( 'Lowercase letters, numbers, and underscores only.', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schedule_display"><?php echo esc_html__( 'Display Name', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<input type="text" name="schedule_display" id="schedule_display" class="regular-text" required />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schedule_interval"><?php echo esc_html__( 'Interval (seconds)', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<input type="number" name="schedule_interval" id="schedule_interval" class="regular-text" required min="1" />
							<p class="description"><?php echo esc_html__( 'Number of seconds between executions.', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="oli_cj_add_schedule" class="button button-primary" value="<?php echo esc_attr__( 'Add Schedule', 'oli-cron-jobs' ); ?>" />
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render logs page
	 */
	public function render_logs_page() {
		$this->render_page_header( __( 'Cron Logs', 'oli-cron-jobs' ) );

		$logs           = Oli_CJ_Logger::get_logs( array( 'limit' => 100 ) );
		$total_logs     = Oli_CJ_Logger::get_log_count();
		$retention_days = (int) get_option( 'oli_cj_log_retention_days', 30 );
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html__( 'Cron Logs', 'oli-cron-jobs' ); ?>
				<?php if ( $total_logs > 0 ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=oli-cron-jobs-logs&action=clear_logs' ), 'oli_cj_action' ) ); ?>" class="page-title-action oli-cj-confirm" data-oli-cj-confirm="<?php echo esc_attr__( 'Are you sure you want to clear all logs?', 'oli-cron-jobs' ); ?>">
						<?php echo esc_html__( 'Clear All Logs', 'oli-cron-jobs' ); ?>
					</a>
				<?php else : ?>
					<span class="page-title-action oli-cj-clear-logs-disabled" aria-disabled="true"><?php echo esc_html__( 'Clear All Logs', 'oli-cron-jobs' ); ?></span>
				<?php endif; ?>
			</h1>

			<?php $this->render_messages(); ?>

			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of log entries shown, 2: total log entries */
						__( 'Showing %1$d of %2$d log entries', 'oli-cron-jobs' ),
						count( $logs ),
						$total_logs
					)
				);
				?>
			</p>

			<table class="wp-list-table widefat fixed striped oli-cj-logs-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Date/Time', 'oli-cron-jobs' ); ?></th>
						<th><?php echo esc_html__( 'Hook', 'oli-cron-jobs' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'oli-cron-jobs' ); ?></th>
						<th><?php echo esc_html__( 'Duration', 'oli-cron-jobs' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'oli-cron-jobs' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr>
							<td colspan="5">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: log retention days */
										__( 'No executions have been recorded yet. Entries appear here after cron runs. Logs are kept for %d days (change this under Settings).', 'oli-cron-jobs' ),
										$retention_days
									)
								);
								?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $this->format_log_datetime( $log->created_at ) ); ?></td>
								<td><code><?php echo esc_html( $log->hook ); ?></code></td>
								<td><?php echo esc_html( Oli_CJ_Logger::translate_event_type( $log->event_type ) ); ?></td>
								<td>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: duration in seconds */
											__( '%ss', 'oli-cron-jobs' ),
											number_format( (float) $log->duration, 4 )
										)
									);
									?>
								</td>
								<td>
									<?php
									self::print_status(
										! empty( $log->error_message ) ? 'error' : 'ok',
										! empty( $log->error_message ) ? Oli_CJ_Logger::translate_error_message( $log->error_message ) : __( 'Success', 'oli-cron-jobs' )
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		$this->render_page_header( __( 'Cron Settings', 'oli-cron-jobs' ) );

		// Handle form submission.
		if ( isset( $_POST['oli_cj_save_settings'] ) && check_admin_referer( 'oli_cj_settings' ) && current_user_can( 'manage_options' ) ) {
			$retention_days = isset( $_POST['log_retention_days'] ) ? absint( wp_unslash( $_POST['log_retention_days'] ) ) : 30;
			update_option( 'oli_cj_log_retention_days', $retention_days );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully.', 'oli-cron-jobs' ) . '</p></div>';
		}

		$retention_days = get_option( 'oli_cj_log_retention_days', 30 );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Cron Settings', 'oli-cron-jobs' ); ?></h1>

			<?php $this->render_messages(); ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'oli_cj_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="log_retention_days"><?php echo esc_html__( 'Log Retention (days)', 'oli-cron-jobs' ); ?></label></th>
						<td>
							<input type="number" name="log_retention_days" id="log_retention_days" value="<?php echo esc_attr( $retention_days ); ?>" class="small-text" min="1" />
							<p class="description"><?php echo esc_html__( 'Number of days to keep log entries before automatic cleanup.', 'oli-cron-jobs' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="oli_cj_save_settings" class="button button-primary" value="<?php echo esc_attr__( 'Save Settings', 'oli-cron-jobs' ); ?>" />
				</p>
			</form>

			<h2><?php echo esc_html__( 'System Information', 'oli-cron-jobs' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__( 'WP-Cron Status', 'oli-cron-jobs' ); ?></th>
					<td>
						<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
							<?php self::print_status( 'disabled', __( 'Disabled (DISABLE_WP_CRON)', 'oli-cron-jobs' ) ); ?>
							<p class="description"><?php echo esc_html__( 'WP-Cron is disabled. Make sure you have a server cron job configured.', 'oli-cron-jobs' ); ?></p>
						<?php else : ?>
							<?php self::print_status( 'ok', __( 'Enabled', 'oli-cron-jobs' ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Alternate WP-Cron', 'oli-cron-jobs' ); ?></th>
					<td>
						<?php if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) : ?>
							<?php self::print_status( 'warning', __( 'Enabled (ALTERNATE_WP_CRON)', 'oli-cron-jobs' ) ); ?>
							<p class="description"><?php echo esc_html__( 'WordPress redirects visitors to spawn cron. This can be unreliable on some hosts.', 'oli-cron-jobs' ); ?></p>
						<?php else : ?>
							<?php self::print_status( 'off', __( 'Off', 'oli-cron-jobs' ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Total Events', 'oli-cron-jobs' ); ?></th>
					<td><?php echo esc_html( count( Oli_CJ_Cron_Manager::get_all_events() ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Total Logs', 'oli-cron-jobs' ); ?></th>
					<td><?php echo esc_html( Oli_CJ_Logger::get_log_count() ); ?></td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'Export / Import', 'oli-cron-jobs' ); ?></h2>
			
			<h3><?php echo esc_html__( 'Export Events and Schedules', 'oli-cron-jobs' ); ?></h3>
			<p><?php echo esc_html__( 'Export custom cron events, custom schedules, and paused hooks as a JSON file.', 'oli-cron-jobs' ); ?></p>
			<p>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=oli-cron-jobs-settings&action=export' ), 'oli_cj_action' ) ); ?>" class="button button-secondary">
					<?php echo esc_html__( 'Export Data', 'oli-cron-jobs' ); ?>
				</a>
			</p>

			<h3><?php echo esc_html__( 'Import Events and Schedules', 'oli-cron-jobs' ); ?></h3>
			<p><?php echo esc_html__( 'Import events and schedules from a previously exported JSON file. This will not delete existing events.', 'oli-cron-jobs' ); ?></p>
			<form method="post" action="" enctype="multipart/form-data">
				<?php wp_nonce_field( 'oli_cj_import' ); ?>
				<p>
					<label for="oli-cj-import-file"><?php echo esc_html__( 'JSON file to import', 'oli-cron-jobs' ); ?></label><br />
					<input type="file" name="import_file" id="oli-cj-import-file" accept=".json" required />
				</p>
				<p>
					<input type="submit" name="oli_cj_import" class="button button-secondary" value="<?php echo esc_attr__( 'Import Data', 'oli-cron-jobs' ); ?>" />
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render page header
	 *
	 * @param string $title Page title.
	 */
	private function render_page_header( $title ) {
		// This could be used for common page elements.
	}

	/**
	 * Format a stored MySQL datetime with the site date and time formats.
	 *
	 * @param string $mysql_datetime Datetime from current_time( 'mysql' ).
	 * @return string
	 */
	private function format_log_datetime( $mysql_datetime ) {
		$timestamp = mysql2date( 'U', (string) $mysql_datetime, false );
		if ( ! $timestamp ) {
			return (string) $mysql_datetime;
		}

		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$formatted = wp_date( $format, (int) $timestamp );

		return is_string( $formatted ) ? $formatted : (string) $mysql_datetime;
	}

	/**
	 * Render admin messages
	 */
	private function render_messages() {
		if ( ! isset( $_GET['oli_cj_message'], $_GET['oli_cj_notice_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['oli_cj_notice_nonce'] ) ), 'oli_cj_notice' ) ) {
			return;
		}

		$message  = sanitize_text_field( wp_unslash( $_GET['oli_cj_message'] ) );
		$messages = array(
			'event_added'         => __( 'Cron event added successfully.', 'oli-cron-jobs' ),
			'event_deleted'       => __( 'Event deleted successfully.', 'oli-cron-jobs' ),
			'event_updated'       => __( 'Event updated successfully.', 'oli-cron-jobs' ),
			'event_ran'           => __( 'Event executed successfully.', 'oli-cron-jobs' ),
			'event_failed'        => __( 'The event did not run successfully.', 'oli-cron-jobs' ),
			'event_not_found'     => __( 'Event not found.', 'oli-cron-jobs' ),
			'php_event_blocked'   => __( 'This PHP cron event could not be executed.', 'oli-cron-jobs' ),
			'php_event_forbidden' => __( 'You do not have permission to manage PHP cron events.', 'oli-cron-jobs' ),
			'php_code_required'   => __( 'Enter PHP code for this event.', 'oli-cron-jobs' ),
			'url_hook_required'   => __( 'A hook name is required for URL cron events.', 'oli-cron-jobs' ),
			'url_required'        => __( 'Enter a URL for this event.', 'oli-cron-jobs' ),
			'hook_required'       => __( 'A hook name is required.', 'oli-cron-jobs' ),
			'hook_paused'         => __( 'Hook paused successfully.', 'oli-cron-jobs' ),
			'hook_resumed'        => __( 'Hook resumed successfully.', 'oli-cron-jobs' ),
			'schedule_deleted'    => __( 'Schedule deleted successfully.', 'oli-cron-jobs' ),
			'logs_cleared'        => __( 'Logs cleared successfully.', 'oli-cron-jobs' ),
			'exported'            => __( 'Data exported successfully.', 'oli-cron-jobs' ),
			'imported'            => __( 'Data imported successfully.', 'oli-cron-jobs' ),
			'import_error'        => __( 'The import file could not be read.', 'oli-cron-jobs' ),
			'spawn_tested'        => __( 'Cron spawn test finished. See the WP-Cron status panel for the result.', 'oli-cron-jobs' ),
		);

		if ( 'imported' === $message ) {
			$imported_count = isset( $_GET['oli_cj_count'] ) ? absint( wp_unslash( $_GET['oli_cj_count'] ) ) : 0;
			if ( $imported_count > 0 ) {
				$messages['imported'] = sprintf(
					/* translators: %d: number of imported items */
					_n( '%d item imported successfully.', '%d items imported successfully.', $imported_count, 'oli-cron-jobs' ),
					$imported_count
				);
			}
		}

		if ( isset( $messages[ $message ] ) ) {
			$warning_messages = array(
				'php_event_blocked',
				'php_event_forbidden',
				'event_failed',
				'event_not_found',
				'php_code_required',
				'url_hook_required',
				'url_required',
				'hook_required',
				'import_error',
			);
			$notice_class     = in_array( $message, $warning_messages, true ) ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $messages[ $message ] ) . '</p></div>';
		}
	}

	/**
	 * Warning shown on add/edit screens for PHP cron events.
	 */
	private function render_php_warning() {
		if ( ! Oli_CJ_PHP_Events::can_use_php_events() ) {
			return;
		}

		?>
		<div class="oli-cj-php-warning">
			<h3><?php echo esc_html__( 'PHP cron events', 'oli-cron-jobs' ); ?></h3>
			<p><?php echo esc_html__( 'PHP events run stored PHP with eval() when they fire. This is equivalent in risk to the plugin editor: the code runs with the same privileges as WordPress. Managing them requires manage_options and the oli_cj_manage_php_events capability (administrators have it by default), plus edit_files. On a multisite network only a super administrator may manage PHP events.', 'oli-cron-jobs' ); ?></p>
			<p><?php echo esc_html__( 'Stored PHP is protected with an integrity hash. If the code is changed outside this screen, the event will not run until it is re-saved. They cannot be added, edited, or executed when DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS is set. Disable the feature entirely with OLI_CJ_DISABLE_PHP_EVENTS. Only create PHP events with code you trust.', 'oli-cron-jobs' ); ?></p>
		</div>
		<?php
	}
}
