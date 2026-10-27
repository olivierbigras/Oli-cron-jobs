<?php
/**
 * List Table Class
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays cron events in a list table
 */
class Oli_CJ_List_Table extends WP_List_Table {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Cron Event', 'oli-cron-jobs' ),
				'plural'   => __( 'Cron Events', 'oli-cron-jobs' ),
				'ajax'     => false,
			)
		);
	}

	/**
	 * Display filter dropdowns
	 *
	 * @param string $which Top or bottom table nav.
	 */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list-table filters via GET.
		$status_filter   = isset( $_REQUEST['status_filter'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status_filter'] ) ) : '';
		$schedule_filter = isset( $_REQUEST['schedule_filter'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['schedule_filter'] ) ) : '';
		$type_filter     = isset( $_REQUEST['type_filter'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['type_filter'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$schedules = Oli_CJ_Schedule_Manager::get_all_schedules();
		?>
		<div class="alignleft actions">
			<label for="oli-cj-status-filter" class="screen-reader-text"><?php echo esc_html__( 'Filter by status', 'oli-cron-jobs' ); ?></label>
			<select name="status_filter" id="oli-cj-status-filter">
				<option value=""><?php echo esc_html__( 'All Statuses', 'oli-cron-jobs' ); ?></option>
				<option value="scheduled" <?php selected( $status_filter, 'scheduled' ); ?>><?php echo esc_html__( 'Scheduled', 'oli-cron-jobs' ); ?></option>
				<option value="late" <?php selected( $status_filter, 'late' ); ?>><?php echo esc_html__( 'Late', 'oli-cron-jobs' ); ?></option>
				<option value="missed" <?php selected( $status_filter, 'missed' ); ?>><?php echo esc_html__( 'Missed', 'oli-cron-jobs' ); ?></option>
				<option value="paused" <?php selected( $status_filter, 'paused' ); ?>><?php echo esc_html__( 'Paused', 'oli-cron-jobs' ); ?></option>
			</select>

			<label for="oli-cj-schedule-filter" class="screen-reader-text"><?php echo esc_html__( 'Filter by schedule', 'oli-cron-jobs' ); ?></label>
			<select name="schedule_filter" id="oli-cj-schedule-filter">
				<option value=""><?php echo esc_html__( 'All Schedules', 'oli-cron-jobs' ); ?></option>
				<option value="single" <?php selected( $schedule_filter, 'single' ); ?>><?php echo esc_html__( 'Single Events', 'oli-cron-jobs' ); ?></option>
				<?php foreach ( $schedules as $name => $schedule ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $schedule_filter, $name ); ?>>
						<?php echo esc_html( $schedule['display'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="oli-cj-type-filter" class="screen-reader-text"><?php echo esc_html__( 'Filter by type', 'oli-cron-jobs' ); ?></label>
			<select name="type_filter" id="oli-cj-type-filter">
				<option value=""><?php echo esc_html__( 'All Types', 'oli-cron-jobs' ); ?></option>
				<option value="noaction" <?php selected( $type_filter, 'noaction' ); ?>><?php echo esc_html__( 'Events with no action', 'oli-cron-jobs' ); ?></option>
				<option value="core" <?php selected( $type_filter, 'core' ); ?>><?php echo esc_html__( 'WordPress Core', 'oli-cron-jobs' ); ?></option>
				<option value="custom" <?php selected( $type_filter, 'custom' ); ?>><?php echo esc_html__( 'Custom events', 'oli-cron-jobs' ); ?></option>
				<?php if ( Oli_CJ_PHP_Events::can_manage() ) : ?>
					<option value="php" <?php selected( $type_filter, 'php' ); ?>><?php echo esc_html__( 'PHP Events', 'oli-cron-jobs' ); ?></option>
				<?php endif; ?>
				<option value="url" <?php selected( $type_filter, 'url' ); ?>><?php echo esc_html__( 'URL Events', 'oli-cron-jobs' ); ?></option>
				<option value="plugin" <?php selected( $type_filter, 'plugin' ); ?>><?php echo esc_html__( 'Plugin', 'oli-cron-jobs' ); ?></option>
			</select>

			<input type="submit" class="button" value="<?php echo esc_attr__( 'Filter', 'oli-cron-jobs' ); ?>" />
		</div>
		<?php
	}

	/**
	 * Get columns
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'hook'       => __( 'Hook', 'oli-cron-jobs' ),
			'args'       => __( 'Arguments', 'oli-cron-jobs' ),
			'next_run'   => __( 'Next Run', 'oli-cron-jobs' ),
			'recurrence' => __( 'Recurrence', 'oli-cron-jobs' ),
			'callbacks'  => __( 'Callbacks', 'oli-cron-jobs' ),
			'status'     => __( 'Status', 'oli-cron-jobs' ),
		);
	}

	/**
	 * Get sortable columns
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'hook'     => array( 'hook', false ),
			'next_run' => array( 'timestamp', false ),
		);
	}

	/**
	 * Get bulk actions
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'oli-cron-jobs' ),
		);
	}

	/**
	 * Prepare items for display
	 */
	public function prepare_items() {
		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		// Bulk delete runs earlier, on admin_init (Oli_CJ_Admin::process_bulk_delete()).
		$events = Oli_CJ_Admin::visible_events( Oli_CJ_Cron_Manager::get_all_events() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list-table filters via GET.

		// Filter by status.
		if ( isset( $_REQUEST['status_filter'] ) && ! empty( $_REQUEST['status_filter'] ) ) {
			$status_filter = sanitize_text_field( wp_unslash( $_REQUEST['status_filter'] ) );
			$events        = array_filter(
				$events,
				function ( $event ) use ( $status_filter ) {
					return Oli_CJ_Cron_Manager::get_event_status( $event ) === $status_filter;
				}
			);
		}

		// Filter by schedule.
		if ( isset( $_REQUEST['schedule_filter'] ) && ! empty( $_REQUEST['schedule_filter'] ) ) {
			$schedule_filter = sanitize_text_field( wp_unslash( $_REQUEST['schedule_filter'] ) );
			$events          = array_filter(
				$events,
				function ( $event ) use ( $schedule_filter ) {
					if ( 'single' === $schedule_filter ) {
						return ! $event['schedule'];
					} else {
						return $event['schedule'] === $schedule_filter;
					}
				}
			);
		}

		// Filter by hook type.
		if ( isset( $_REQUEST['type_filter'] ) && ! empty( $_REQUEST['type_filter'] ) ) {
			$type_filter = sanitize_text_field( wp_unslash( $_REQUEST['type_filter'] ) );
			$events      = array_filter(
				$events,
				function ( $event ) use ( $type_filter ) {
					if ( 'noaction' === $type_filter ) {
						return ! Oli_CJ_Cron_Manager::event_has_action( $event['hook'] );
					}

					return Oli_CJ_Cron_Manager::get_event_kind( $event['hook'] ) === $type_filter;
				}
			);
		}

		// Search.
		if ( isset( $_REQUEST['s'] ) && ! empty( $_REQUEST['s'] ) ) {
			$search = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
			$events = array_filter(
				$events,
				function ( $event ) use ( $search ) {
					return false !== stripos( $event['hook'], $search );
				}
			);
		}

		// Sort.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'timestamp';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'asc';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		usort(
			$events,
			function ( $a, $b ) use ( $orderby, $order ) {
				if ( 'hook' === $orderby ) {
					$result = strcmp( $a['hook'], $b['hook'] );
				} else {
					$result = $a['timestamp'] - $b['timestamp'];
				}

				return ( 'asc' === $order ) ? $result : -$result;
			}
		);

		$total_items = count( $events );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);

		$this->items = array_slice( $events, ( ( $current_page - 1 ) * $per_page ), $per_page );
	}

	/**
	 * Views for the main event-type filters.
	 *
	 * @return array
	 */
	protected function get_views() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list-table filter.
		$current = isset( $_REQUEST['type_filter'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['type_filter'] ) ) : '';
		$events  = Oli_CJ_Admin::visible_events( Oli_CJ_Cron_Manager::get_all_events() );
		$counts  = array(
			'all'      => 0,
			'noaction' => 0,
			'core'     => 0,
			'custom'   => 0,
			'php'      => 0,
		);

		foreach ( $events as $event ) {
			++$counts['all'];
			if ( ! Oli_CJ_Cron_Manager::event_has_action( $event['hook'] ) ) {
				++$counts['noaction'];
			}

			$kind = Oli_CJ_Cron_Manager::get_event_kind( $event['hook'] );
			if ( isset( $counts[ $kind ] ) ) {
				++$counts[ $kind ];
			}
		}

		$labels = array(
			'all'      => __( 'All', 'oli-cron-jobs' ),
			'noaction' => __( 'Events with no action', 'oli-cron-jobs' ),
			'core'     => __( 'WordPress core', 'oli-cron-jobs' ),
			'custom'   => __( 'Custom events', 'oli-cron-jobs' ),
			'php'      => __( 'PHP', 'oli-cron-jobs' ),
		);

		if ( ! Oli_CJ_PHP_Events::can_manage() ) {
			unset( $labels['php'] );
		}

		$views = array();
		foreach ( $labels as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page'        => 'oli-cron-jobs',
					'type_filter' => 'all' === $slug ? false : $slug,
				),
				admin_url( 'tools.php' )
			);

			$views[ $slug ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				( ( 'all' === $slug && '' === $current ) || $current === $slug ) ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				absint( $counts[ $slug ] )
			);
		}

		return $views;
	}

	/**
	 * Column checkbox
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_cb( $item ) {
		$checkbox_id = 'cb-select-' . md5( $item['hook'] . '|' . $item['timestamp'] . '|' . $item['sig'] );

		return sprintf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="checkbox" id="%1$s" name="event[]" value="%3$s" />',
			esc_attr( $checkbox_id ),
			esc_html(
				sprintf(
					/* translators: %s: cron hook name */
					__( 'Select %s', 'oli-cron-jobs' ),
					$item['hook']
				)
			),
			esc_attr( $item['hook'] . '|' . $item['timestamp'] . '|' . $item['sig'] )
		);
	}

	/**
	 * Column hook
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_hook( $item ) {
		$actions = array();

		$nonce = wp_create_nonce( 'oli_cj_action' );

		$is_php_event   = Oli_CJ_Cron_Manager::is_php_event( $item['hook'] );
		$can_manage_php = Oli_CJ_PHP_Events::can_manage();
		$can_run        = ! $is_php_event || $can_manage_php;
		$can_mutate     = ! $is_php_event || $can_manage_php;

		if ( $can_run ) {
			$actions['run'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=oli-cron-jobs&action=run_now&hook=' . rawurlencode( $item['hook'] ) . '&args=' . rawurlencode( wp_json_encode( $item['args'] ) ) . '&_wpnonce=' . $nonce ) ),
				__( 'Run Now', 'oli-cron-jobs' )
			);
		}

		if ( $can_mutate ) {
			$actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=oli-cron-jobs-edit&hook=' . rawurlencode( $item['hook'] ) . '&timestamp=' . $item['timestamp'] . '&sig=' . rawurlencode( $item['sig'] ) ) ),
				__( 'Edit', 'oli-cron-jobs' )
			);
		}

		if ( $can_mutate ) {
			$actions['delete'] = sprintf(
				'<a href="%s" class="delete oli-cj-confirm" data-oli-cj-confirm="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=oli-cron-jobs&action=delete_event&hook=' . rawurlencode( $item['hook'] ) . '&timestamp=' . $item['timestamp'] . '&sig=' . rawurlencode( $item['sig'] ) . '&_wpnonce=' . $nonce ) ),
				esc_attr( __( 'Are you sure?', 'oli-cron-jobs' ) ),
				__( 'Delete', 'oli-cron-jobs' )
			);

			if ( Oli_CJ_Cron_Manager::is_hook_paused( $item['hook'] ) ) {
				$actions['resume'] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'tools.php?page=oli-cron-jobs&action=resume_hook&hook=' . rawurlencode( $item['hook'] ) . '&_wpnonce=' . $nonce ) ),
					__( 'Resume', 'oli-cron-jobs' )
				);
			} else {
				$actions['pause'] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'tools.php?page=oli-cron-jobs&action=pause_hook&hook=' . rawurlencode( $item['hook'] ) . '&_wpnonce=' . $nonce ) ),
					__( 'Pause', 'oli-cron-jobs' )
				);
			}
		}

		return sprintf( '<code>%s</code>%s', $this->breakable_code( $item['hook'] ), $this->row_actions( $actions ) );
	}

	/**
	 * Column args
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_args( $item ) {
		if ( empty( $item['args'] ) ) {
			return '<em>' . esc_html__( 'None', 'oli-cron-jobs' ) . '</em>';
		}

		return '<code>' . esc_html( wp_json_encode( $item['args'] ) ) . '</code>';
	}

	/**
	 * Column next run
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_next_run( $item ) {
		$timestamp    = $item['timestamp'];
		$current_time = time();
		$site_time    = Oli_CJ_Cron_Manager::format_site_datetime( $timestamp );
		$utc_time     = Oli_CJ_Cron_Manager::format_utc_datetime( $timestamp );
		$relative     = ( $timestamp < $current_time )
			? sprintf(
				/* translators: %s: human-readable time difference */
				__( '%s ago', 'oli-cron-jobs' ),
				human_time_diff( $timestamp, $current_time )
			)
			: sprintf(
				/* translators: %s: human-readable time difference */
				__( 'in %s', 'oli-cron-jobs' ),
				human_time_diff( $current_time, $timestamp )
			);

		$class = ( $timestamp < $current_time ) ? 'oli-cj-status-overdue' : '';

		return sprintf(
			'<span class="%1$s">%2$s</span><br /><small>%3$s</small><br /><small>%4$s</small>',
			esc_attr( $class ),
			esc_html( $site_time ),
			esc_html(
				sprintf(
					/* translators: 1: UTC datetime, 2: relative time */
					__( 'UTC: %1$s · %2$s', 'oli-cron-jobs' ),
					$utc_time,
					$relative
				)
			),
			esc_html( wp_timezone_string() )
		);
	}

	/**
	 * Column recurrence
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_recurrence( $item ) {
		if ( ! $item['schedule'] ) {
			return '<em>' . esc_html__( 'Non-repeating', 'oli-cron-jobs' ) . '</em>';
		}

		$display  = Oli_CJ_Schedule_Manager::get_schedule_display( $item['schedule'] );
		$interval = Oli_CJ_Schedule_Manager::format_interval( $item['interval'] );

		return sprintf(
			'%s<br /><small>%s</small>',
			esc_html( $display ),
			esc_html( $interval )
		);
	}

	/**
	 * Column callbacks
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_callbacks( $item ) {
		$callbacks = Oli_CJ_Cron_Manager::get_hook_callbacks( $item['hook'] );

		if ( empty( $callbacks ) ) {
			return '<em>' . esc_html__( 'None', 'oli-cron-jobs' ) . '</em>';
		}

		$output = array();
		foreach ( $callbacks as $callback ) {
			$output[] = sprintf(
				'<code>%s</code> <small>(%s)</small><br><small>%s</small>',
				$this->breakable_code( $callback['function'] ),
				esc_html(
					sprintf(
						/* translators: %d: callback priority */
						__( 'priority: %d', 'oli-cron-jobs' ),
						absint( $callback['priority'] )
					)
				),
				esc_html( $callback['source'] )
			);
		}

		return implode( '<br />', $output );
	}

	/**
	 * Column status
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	protected function column_status( $item ) {
		$status = Oli_CJ_Cron_Manager::get_event_status( $item );
		$labels = array(
			'scheduled'      => array( 'ok', __( 'Scheduled', 'oli-cron-jobs' ) ),
			'late'           => array( 'warning', __( 'Late', 'oli-cron-jobs' ) ),
			'missed'         => array( 'error', __( 'Missed', 'oli-cron-jobs' ) ),
			'paused'         => array( 'paused', __( 'Paused', 'oli-cron-jobs' ) ),
			'needs_checking' => array( 'warning', __( 'Needs checking', 'oli-cron-jobs' ) ),
		);

		if ( ! isset( $labels[ $status ] ) ) {
			$status = 'scheduled';
		}

		return Oli_CJ_Admin::status_markup( $labels[ $status ][0], $labels[ $status ][1] );
	}

	/**
	 * Escape code and allow wraps after underscores and object operators.
	 *
	 * @param string $text Raw code text.
	 * @return string
	 */
	private function breakable_code( $text ) {
		$escaped = esc_html( (string) $text );
		return str_replace( array( '_', '-&gt;' ), array( '_<wbr>', '-&gt;<wbr>' ), $escaped );
	}

	/**
	 * Empty-state text, including a search-specific message.
	 */
	public function no_items() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search query.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		if ( '' !== $search ) {
			echo esc_html(
				sprintf(
					/* translators: %s: search query */
					__( 'No events match "%s".', 'oli-cron-jobs' ),
					$search
				)
			);
			echo ' <a href="' . esc_url( admin_url( 'tools.php?page=oli-cron-jobs' ) ) . '">' . esc_html__( 'Clear search', 'oli-cron-jobs' ) . '</a>';
			return;
		}

		echo esc_html__( 'No cron events found.', 'oli-cron-jobs' );
	}

	/**
	 * Render a single table row with status classes.
	 *
	 * @param array $item Item data.
	 */
	public function single_row( $item ) {
		$class = '';

		$status = Oli_CJ_Cron_Manager::get_event_status( $item );
		if ( 'paused' === $status || 'needs_checking' === $status ) {
			$class = 'oli-cj-event-paused';
		} elseif ( 'missed' === $status ) {
			$class = 'oli-cj-event-overdue';
		} elseif ( 'late' === $status ) {
			$class = 'oli-cj-event-late';
		}

		echo '<tr' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Default column
	 *
	 * @param array  $item Item data.
	 * @param string $column_name Column name.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}
}
