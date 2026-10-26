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
		add_management_page(
			__( 'Oli Cron Jobs', 'oli-cron-jobs' ),
			__( 'Cron Jobs', 'oli-cron-jobs' ),
			'manage_options',
			'oli-cron-jobs',
			array( $this, 'render_events_page' )
		);

		add_submenu_page(
			'tools.php',
			__( 'Add Cron Event', 'oli-cron-jobs' ),
			__( 'Add Cron Event', 'oli-cron-jobs' ),
			'manage_options',
			'oli-cron-jobs-add',
			array( $this, 'render_add_event_page' )
		);

		// Edit page (hidden from menu).
		add_submenu_page(
			null,
			__( 'Edit Cron Event', 'oli-cron-jobs' ),
			__( 'Edit Cron Event', 'oli-cron-jobs' ),
			'manage_options',
			'oli-cron-jobs-edit',
			array( $this, 'render_edit_event_page' )
		);

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
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( ! in_array( $hook, array( 'tools_page_oli-cron-jobs', 'tools_page_oli-cron-jobs-add', 'admin_page_oli-cron-jobs-edit', 'tools_page_oli-cron-jobs-schedules', 'tools_page_oli-cron-jobs-logs', 'tools_page_oli-cron-jobs-settings' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'oli-cj-admin',
			OLI_CJ_PLUGIN_URL . 'assets/css/admin.css',
			array(),
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page slug is used only to scope our actions.
		if ( 0 !== strpos( $page, 'oli-cron-jobs' ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Unknown actions return before nonce use.
		$known  = array(
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

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'oli_cj_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'oli-cron-jobs' ) );
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
	 * Read a sanitized GET value after handle_actions() verified the nonce.
	 *
	 * @param string $key           Query key.
	 * @param string $default_value Default value.
	 * @return string
	 */
	private function get_action_text( $key, $default_value = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in handle_actions().
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default_value;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in handle_actions().
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * Handle delete event action
	 */
	private function handle_delete_event() {
		$hook      = $this->get_action_text( 'hook' );
		$timestamp = absint( $this->get_action_text( 'timestamp', '0' ) );
		$sig       = $this->get_action_text( 'sig' );

		if ( Oli_CJ_Cron_Manager::is_php_event( $hook ) && ! Oli_CJ_PHP_Events::can_manage() ) {
			wp_safe_redirect( add_query_arg( 'message', 'php_event_forbidden', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
			exit;
		}

		if ( Oli_CJ_Cron_Manager::delete_event( $hook, $timestamp, $sig ) ) {
			wp_safe_redirect( add_query_arg( 'message', 'event_deleted', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
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
			wp_safe_redirect( add_query_arg( 'message', 'php_event_forbidden', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
			exit;
		}

		Oli_CJ_Cron_Manager::run_event_now( $hook, $args );

		wp_safe_redirect( add_query_arg( 'message', 'event_ran', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
		exit;
	}

	/**
	 * Handle pause hook action
	 */
	private function handle_pause_hook() {
		$hook = $this->get_action_text( 'hook' );

		if ( Oli_CJ_Cron_Manager::pause_hook( $hook ) ) {
			wp_safe_redirect( add_query_arg( 'message', 'hook_paused', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
			exit;
		}
	}

	/**
	 * Handle resume hook action
	 */
	private function handle_resume_hook() {
		$hook = $this->get_action_text( 'hook' );

		if ( Oli_CJ_Cron_Manager::resume_hook( $hook ) ) {
			wp_safe_redirect( add_query_arg( 'message', 'hook_resumed', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
			exit;
		}
	}

	/**
	 * Handle delete schedule action
	 */
	private function handle_delete_schedule() {
		$schedule = $this->get_action_text( 'schedule' );

		if ( Oli_CJ_Schedule_Manager::delete_schedule( $schedule ) ) {
			wp_safe_redirect( add_query_arg( 'message', 'schedule_deleted', admin_url( 'tools.php?page=oli-cron-jobs-schedules' ) ) );
			exit;
		}
	}

	/**
	 * Handle clear logs action
	 */
	private function handle_clear_logs() {
		if ( Oli_CJ_Logger::clear_all_logs() ) {
			wp_safe_redirect( add_query_arg( 'message', 'logs_cleared', admin_url( 'tools.php?page=oli-cron-jobs-logs' ) ) );
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
			'paused_hooks'     => Oli_CJ_Cron_Manager::get_paused_hooks(),
		);

		// Export custom/managed events only (URL, PHP, and events created here).
		$events = Oli_CJ_Cron_Manager::get_all_events();
		foreach ( $events as $event ) {
			if ( ! Oli_CJ_Cron_Manager::is_managed( $event['hook'] ) ) {
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

		// Output JSON file.
		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="oli-cron-jobs-export-' . gmdate( 'Y-m-d-H-i-s' ) . '.json"' );
		echo wp_json_encode( $export_data, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Export the current event list as CSV.
	 */
	private function handle_export_csv() {
		$events   = Oli_CJ_Cron_Manager::get_all_events();
		$rows     = Oli_CJ_Cron_Manager::get_csv_rows( $events );
		$filename = 'oli-cron-jobs-events-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output stream for a CSV download.
		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- CSV download stream.
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
			)
		);

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- CSV download stream.
			fputcsv(
				$output,
				array(
					$row['hook'],
					$row['args'],
					$row['next_run_site'],
					$row['next_run_utc'],
					$row['recurrence'],
					$row['callbacks'],
					$row['status'],
				)
			);
		}

		exit;
	}

	/**
	 * Run a same-site WP-Cron spawn test and store the result.
	 */
	private function handle_test_spawn() {
		set_transient( 'oli_cj_spawn_test', Oli_CJ_Site_Health::run_spawn_test(), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'message', 'spawn_tested', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
		exit;
	}

	/**
	 * Handle import action
	 */
	private function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import cron data.', 'oli-cron-jobs' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'oli_cj_import' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'oli-cron-jobs' ) );
		}

		if ( ! isset( $_FILES['import_file'] ) || ! isset( $_FILES['import_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['import_file']['error'] ) {
			wp_safe_redirect( add_query_arg( 'message', 'import_error', admin_url( 'tools.php?page=oli-cron-jobs-settings' ) ) );
			exit;
		}

		$tmp_name = isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['import_file']['tmp_name'] ) ) : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			wp_safe_redirect( add_query_arg( 'message', 'import_error', admin_url( 'tools.php?page=oli-cron-jobs-settings' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local uploaded JSON file.
		$file_contents = file_get_contents( $tmp_name );
		$import_data   = json_decode( $file_contents, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $import_data ) ) {
			wp_safe_redirect( add_query_arg( 'message', 'import_error', admin_url( 'tools.php?page=oli-cron-jobs-settings' ) ) );
			exit;
		}

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
					Oli_CJ_Cron_Manager::add_event( $hook, $timestamp, $args, $schedule );
					++$imported_count;
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'message' => 'imported',
					'count'   => $imported_count,
				),
				admin_url( 'tools.php?page=oli-cron-jobs-settings' )
			)
		);
		exit;
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
		<div class="wrap">
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
								<span class="oli-cj-status-disabled"><?php echo esc_html__( 'Disabled (DISABLE_WP_CRON)', 'oli-cron-jobs' ); ?></span>
								<p class="description"><?php echo esc_html__( 'WordPress will not spawn cron on page views. Configure a server cron job that requests wp-cron.php.', 'oli-cron-jobs' ); ?></p>
							<?php else : ?>
								<span class="oli-cj-status-success"><?php echo esc_html__( 'Enabled', 'oli-cron-jobs' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Alternate WP-Cron', 'oli-cron-jobs' ); ?></th>
						<td>
							<?php if ( $alternate_cron ) : ?>
								<span class="oli-cj-status-warning"><?php echo esc_html__( 'Enabled (ALTERNATE_WP_CRON)', 'oli-cron-jobs' ); ?></span>
								<p class="description"><?php echo esc_html__( 'WordPress redirects visitors to spawn cron. This can be unreliable on some hosts.', 'oli-cron-jobs' ); ?></p>
							<?php else : ?>
								<span class="oli-cj-status-success"><?php echo esc_html__( 'Off', 'oli-cron-jobs' ); ?></span>
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
								<p class="<?php echo ! empty( $spawn['success'] ) ? 'oli-cj-status-success' : 'oli-cj-status-error'; ?>">
									<?php echo esc_html( $spawn['message'] ); ?>
								</p>
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

		// Handle form submission.
		if ( isset( $_POST['oli_cj_add_event'] ) && check_admin_referer( 'oli_cj_add_event' ) ) {
			$event_type    = isset( $_POST['event_type'] ) ? sanitize_key( wp_unslash( $_POST['event_type'] ) ) : 'standard';
			$hook          = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
			$schedule      = isset( $_POST['schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) : '';
			$timestamp_raw = isset( $_POST['timestamp'] ) ? sanitize_text_field( wp_unslash( $_POST['timestamp'] ) ) : '';
			$timestamp     = Oli_CJ_Cron_Manager::parse_site_datetime( $timestamp_raw );

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

			if ( 'php' === $event_type ) {
				if ( ! Oli_CJ_PHP_Events::can_manage() ) {
					echo '<div class="notice notice-error"><p>' . esc_html( Oli_CJ_PHP_Events::management_block_reason() ) . '</p></div>';
				} else {
					$php_code = isset( $_POST['php_code'] ) ? wp_unslash( $_POST['php_code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP events store user-authored code after capability checks.
					$php_code = is_string( $php_code ) ? $php_code : '';
					if ( '' === $hook ) {
						$hook = Oli_CJ_PHP_Events::generate_hook();
					}
					if ( '' !== $php_code ) {
						Oli_CJ_Cron_Manager::add_php_event( $hook, $timestamp, $php_code, $args, $schedule ? $schedule : false );
						echo '<div class="notice notice-success"><p>' . esc_html__( 'PHP cron event added successfully.', 'oli-cron-jobs' ) . '</p></div>';
					}
				}
			} elseif ( $hook && 'url' === $event_type && ! empty( $_POST['url'] ) ) {
				$url = esc_url_raw( wp_unslash( $_POST['url'] ) );
				Oli_CJ_Cron_Manager::add_url_event( $hook, $timestamp, $url, $args, $schedule ? $schedule : false );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'URL cron event added successfully.', 'oli-cron-jobs' ) . '</p></div>';
			} elseif ( $hook ) {
				Oli_CJ_Cron_Manager::add_event( $hook, $timestamp, $args, $schedule ? $schedule : false );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Cron event added successfully.', 'oli-cron-jobs' ) . '</p></div>';
			}
		}

		$schedules      = Oli_CJ_Schedule_Manager::get_all_schedules();
		$can_manage_php = Oli_CJ_PHP_Events::can_manage();
		$php_block      = Oli_CJ_PHP_Events::management_block_reason();
		$next_run_input = Oli_CJ_Cron_Manager::format_site_datetime_input( time() + 3600 );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Add Cron Event', 'oli-cron-jobs' ); ?></h1>

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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Edit screen is capability-gated; identifiers come from admin links.
		$hook      = isset( $_GET['hook'] ) ? sanitize_text_field( wp_unslash( $_GET['hook'] ) ) : '';
		$timestamp = isset( $_GET['timestamp'] ) ? absint( $_GET['timestamp'] ) : 0;
		$sig       = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $hook || ! $timestamp || ! $sig ) {
			wp_die( esc_html__( 'Invalid event parameters.', 'oli-cron-jobs' ) );
		}

		$event = Oli_CJ_Cron_Manager::get_event( $hook, $timestamp, $sig );

		if ( ! $event ) {
			wp_die( esc_html__( 'Event not found.', 'oli-cron-jobs' ) );
		}

		$url            = Oli_CJ_Cron_Manager::get_url( $hook );
		$php_code       = Oli_CJ_Cron_Manager::get_php_code( $hook );
		$can_manage_php = Oli_CJ_PHP_Events::can_manage();
		$event_type     = 'standard';
		if ( $url ) {
			$event_type = 'url';
		} elseif ( false !== $php_code ) {
			$event_type = 'php';
		}

		if ( 'php' === $event_type && ! $can_manage_php ) {
			$this->render_legacy_php_event_view( $event );
			return;
		}

		// Handle form submission.
		if ( isset( $_POST['oli_cj_edit_event'] ) && check_admin_referer( 'oli_cj_edit_event' ) ) {
			$new_event_type    = isset( $_POST['event_type'] ) ? sanitize_key( wp_unslash( $_POST['event_type'] ) ) : 'standard';
			$new_hook          = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
			$new_schedule      = isset( $_POST['schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule'] ) ) : '';
			$new_timestamp_raw = isset( $_POST['timestamp'] ) ? sanitize_text_field( wp_unslash( $_POST['timestamp'] ) ) : '';
			$new_timestamp     = Oli_CJ_Cron_Manager::parse_site_datetime( $new_timestamp_raw );

			if ( ! $new_timestamp ) {
				$new_timestamp = time() + 3600;
			}

			$new_args = array();
			if ( ! empty( $_POST['args'] ) ) {
				$args_json = sanitize_textarea_field( wp_unslash( $_POST['args'] ) );
				$new_args  = json_decode( $args_json, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					$new_args = array();
				}
			}

			if ( 'php' === $new_event_type && ! $can_manage_php ) {
				echo '<div class="notice notice-error"><p>' . esc_html( Oli_CJ_PHP_Events::management_block_reason() ) . '</p></div>';
			} elseif ( $new_hook || ( 'php' === $new_event_type && $can_manage_php ) ) {
				if ( '' === $new_hook && 'php' === $new_event_type ) {
					$new_hook = Oli_CJ_PHP_Events::generate_hook();
				}

				Oli_CJ_Cron_Manager::delete_event( $hook, $timestamp, $sig );

				if ( $new_hook !== $hook ) {
					Oli_CJ_PHP_Events::delete_code( $hook );
					$url_events = Oli_CJ_Cron_Manager::get_plugin_option( 'oli_cj_url_events', array() );
					if ( isset( $url_events[ $hook ] ) ) {
						unset( $url_events[ $hook ] );
						Oli_CJ_Cron_Manager::update_plugin_option( 'oli_cj_url_events', $url_events );
					}
				}

				if ( 'php' === $new_event_type ) {
					$new_php = isset( $_POST['php_code'] ) ? wp_unslash( $_POST['php_code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP events store user-authored code after capability checks.
					$new_php = is_string( $new_php ) ? $new_php : '';
					Oli_CJ_Cron_Manager::add_php_event( $new_hook, $new_timestamp, $new_php, $new_args, $new_schedule ? $new_schedule : false );
				} elseif ( 'url' === $new_event_type && ! empty( $_POST['url'] ) ) {
					$new_url = esc_url_raw( wp_unslash( $_POST['url'] ) );
					Oli_CJ_Cron_Manager::add_url_event( $new_hook, $new_timestamp, $new_url, $new_args, $new_schedule ? $new_schedule : false );
				} else {
					Oli_CJ_Cron_Manager::add_event( $new_hook, $new_timestamp, $new_args, $new_schedule ? $new_schedule : false );
				}

				wp_safe_redirect( add_query_arg( 'message', 'event_updated', admin_url( 'tools.php?page=oli-cron-jobs' ) ) );
				exit;
			}
		}

		$schedules        = Oli_CJ_Schedule_Manager::get_all_schedules();
		$current_schedule = isset( $event['schedule'] ) ? $event['schedule'] : '';
		$php_needs_check  = ( 'php' === $event_type && Oli_CJ_PHP_Events::needs_checking( $hook ) );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Edit Cron Event', 'oli-cron-jobs' ); ?></h1>

			<?php $this->render_php_warning(); ?>
			<?php if ( $php_needs_check ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'This PHP event needs checking. Re-save it to refresh the integrity hash before it can run.', 'oli-cron-jobs' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'oli_cj_edit_event' ); ?>
				
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
	 * Show a stored PHP event as read-only. Code cannot be edited or executed.
	 *
	 * @param array $event Event data.
	 */
	private function render_legacy_php_event_view( $event ) {
		$php_code         = Oli_CJ_Cron_Manager::get_php_code( $event['hook'] );
		$current_schedule = isset( $event['schedule'] ) ? $event['schedule'] : '';
		$schedule_label   = $current_schedule ? Oli_CJ_Schedule_Manager::get_schedule_display( $current_schedule ) : __( 'Non-repeating', 'oli-cron-jobs' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'PHP Cron Event', 'oli-cron-jobs' ); ?></h1>

			<div class="notice notice-warning">
				<p><?php echo esc_html( Oli_CJ_PHP_Events::management_block_reason() ? Oli_CJ_PHP_Events::management_block_reason() : __( 'You do not have permission to edit this PHP cron event.', 'oli-cron-jobs' ) ); ?></p>
			</div>

			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Event Type', 'oli-cron-jobs' ); ?></th>
					<td><?php echo esc_html__( 'PHP Event (unsupported)', 'oli-cron-jobs' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Hook Name', 'oli-cron-jobs' ); ?></th>
					<td><code><?php echo esc_html( $event['hook'] ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Next Run', 'oli-cron-jobs' ); ?></th>
					<td>
						<?php
						echo esc_html( Oli_CJ_Cron_Manager::format_site_datetime( $event['timestamp'] ) );
						echo ' / ';
						echo esc_html( Oli_CJ_Cron_Manager::format_utc_datetime( $event['timestamp'] ) . ' UTC' );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Recurrence', 'oli-cron-jobs' ); ?></th>
					<td><?php echo esc_html( $schedule_label ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Arguments', 'oli-cron-jobs' ); ?></th>
					<td><code><?php echo esc_html( wp_json_encode( $event['args'] ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Stored PHP Code', 'oli-cron-jobs' ); ?></th>
					<td>
						<textarea class="large-text code" rows="10" readonly="readonly"><?php echo esc_textarea( $php_code ? $php_code : '' ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'This code is read-only because PHP event editing is not currently permitted.', 'oli-cron-jobs' ); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=oli-cron-jobs' ) ); ?>" class="button">
					<?php echo esc_html__( 'Back to Events', 'oli-cron-jobs' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render schedules page
	 */
	public function render_schedules_page() {
		$this->render_page_header( __( 'Cron Schedules', 'oli-cron-jobs' ) );

		// Handle form submission.
		if ( isset( $_POST['oli_cj_add_schedule'] ) && check_admin_referer( 'oli_cj_add_schedule' ) ) {
			$name     = isset( $_POST['schedule_name'] ) ? sanitize_key( $_POST['schedule_name'] ) : '';
			$interval = isset( $_POST['schedule_interval'] ) ? absint( $_POST['schedule_interval'] ) : 0;
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

		$logs       = Oli_CJ_Logger::get_logs( array( 'limit' => 100 ) );
		$total_logs = Oli_CJ_Logger::get_log_count();
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html__( 'Cron Logs', 'oli-cron-jobs' ); ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=oli-cron-jobs-logs&action=clear_logs' ), 'oli_cj_action' ) ); ?>" class="page-title-action oli-cj-confirm" data-oli-cj-confirm="<?php echo esc_attr__( 'Are you sure you want to clear all logs?', 'oli-cron-jobs' ); ?>">
					<?php echo esc_html__( 'Clear All Logs', 'oli-cron-jobs' ); ?>
				</a>
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

			<table class="wp-list-table widefat fixed striped">
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
							<td colspan="5"><?php echo esc_html__( 'No logs found.', 'oli-cron-jobs' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log->created_at ); ?></td>
								<td><code><?php echo esc_html( $log->hook ); ?></code></td>
								<td><?php echo esc_html( ucfirst( $log->event_type ) ); ?></td>
								<td><?php echo esc_html( number_format( $log->duration, 4 ) ); ?>s</td>
								<td>
									<?php if ( ! empty( $log->error_message ) ) : ?>
										<span class="oli-cj-status-error"><?php echo esc_html( $log->error_message ); ?></span>
									<?php else : ?>
										<span class="oli-cj-status-success"><?php echo esc_html__( 'Success', 'oli-cron-jobs' ); ?></span>
									<?php endif; ?>
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

		// Handle import submission.
		if ( isset( $_POST['oli_cj_import'] ) && check_admin_referer( 'oli_cj_import' ) ) {
			$this->handle_import();
		}

		// Handle form submission.
		if ( isset( $_POST['oli_cj_save_settings'] ) && check_admin_referer( 'oli_cj_settings' ) ) {
			$retention_days = isset( $_POST['log_retention_days'] ) ? absint( $_POST['log_retention_days'] ) : 30;
			update_option( 'oli_cj_log_retention_days', $retention_days );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully.', 'oli-cron-jobs' ) . '</p></div>';
		}

		$retention_days = get_option( 'oli_cj_log_retention_days', 30 );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Cron Settings', 'oli-cron-jobs' ); ?></h1>

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
							<span class="oli-cj-status-disabled"><?php echo esc_html__( 'Disabled (DISABLE_WP_CRON)', 'oli-cron-jobs' ); ?></span>
							<p class="description"><?php echo esc_html__( 'WP-Cron is disabled. Make sure you have a server cron job configured.', 'oli-cron-jobs' ); ?></p>
						<?php else : ?>
							<span class="oli-cj-status-success"><?php echo esc_html__( 'Enabled', 'oli-cron-jobs' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Alternate WP-Cron', 'oli-cron-jobs' ); ?></th>
					<td>
						<?php if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) : ?>
							<span class="oli-cj-status-warning"><?php echo esc_html__( 'Enabled (ALTERNATE_WP_CRON)', 'oli-cron-jobs' ); ?></span>
							<p class="description"><?php echo esc_html__( 'WordPress redirects visitors to spawn cron. This can be unreliable on some hosts.', 'oli-cron-jobs' ); ?></p>
						<?php else : ?>
							<span class="oli-cj-status-success"><?php echo esc_html__( 'Off', 'oli-cron-jobs' ); ?></span>
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
					<input type="file" name="import_file" accept=".json" required />
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
	 * Render admin messages
	 */
	private function render_messages() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect message.
		if ( ! isset( $_GET['message'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect message.
		$message  = sanitize_text_field( wp_unslash( $_GET['message'] ) );
		$messages = array(
			'event_deleted'       => __( 'Event deleted successfully.', 'oli-cron-jobs' ),
			'event_updated'       => __( 'Event updated successfully.', 'oli-cron-jobs' ),
			'event_ran'           => __( 'Event executed successfully.', 'oli-cron-jobs' ),
			'php_event_blocked'   => __( 'This PHP cron event could not be executed.', 'oli-cron-jobs' ),
			'php_event_forbidden' => __( 'You do not have permission to manage PHP cron events.', 'oli-cron-jobs' ),
			'hook_paused'         => __( 'Hook paused successfully.', 'oli-cron-jobs' ),
			'hook_resumed'        => __( 'Hook resumed successfully.', 'oli-cron-jobs' ),
			'schedule_deleted'    => __( 'Schedule deleted successfully.', 'oli-cron-jobs' ),
			'logs_cleared'        => __( 'Logs cleared successfully.', 'oli-cron-jobs' ),
			'exported'            => __( 'Data exported successfully.', 'oli-cron-jobs' ),
			'imported'            => __( 'Data imported successfully.', 'oli-cron-jobs' ),
			'spawn_tested'        => __( 'Cron spawn test finished. See the WP-Cron status panel for the result.', 'oli-cron-jobs' ),
		);

		if ( isset( $messages[ $message ] ) ) {
			$warning_messages = array( 'php_event_blocked', 'php_event_forbidden' );
			$notice_class     = in_array( $message, $warning_messages, true ) ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $messages[ $message ] ) . '</p></div>';
		}
	}

	/**
	 * Warning shown on add/edit screens for PHP cron events.
	 */
	private function render_php_warning() {
		?>
		<div class="oli-cj-php-warning">
			<h3><?php echo esc_html__( 'PHP cron events', 'oli-cron-jobs' ); ?></h3>
			<p><?php echo esc_html__( 'PHP events execute arbitrary PHP when they run. Managing them requires the edit_files capability, the same permission as the plugin and theme editors. They cannot be added or edited when DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS is set.', 'oli-cron-jobs' ); ?></p>
			<p><?php echo esc_html__( 'Stored PHP is protected with an integrity hash. If the code is changed outside this screen, the event will not run until it is re-saved.', 'oli-cron-jobs' ); ?></p>
		</div>
		<?php
	}
}
