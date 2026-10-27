<?php
/**
 * Site Health Class
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates with WordPress Site Health
 */
class Oli_CJ_Site_Health {

	/**
	 * Single instance
	 *
	 * @var Oli_CJ_Site_Health
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return Oli_CJ_Site_Health
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
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_filter( 'debug_information', array( $this, 'add_debug_info' ) );
	}

	/**
	 * Add Site Health tests
	 *
	 * @param array $tests Tests array.
	 * @return array
	 */
	public function add_tests( $tests ) {
		$tests['direct']['oli_cj_cron_disabled'] = array(
			'label' => __( 'WP-Cron Status', 'oli-cron-jobs' ),
			'test'  => array( $this, 'test_cron_disabled' ),
		);

		$tests['direct']['oli_cj_cron_spawn'] = array(
			'label' => __( 'WP-Cron Spawn', 'oli-cron-jobs' ),
			'test'  => array( $this, 'test_cron_spawn' ),
		);

		$tests['direct']['oli_cj_missed_events'] = array(
			'label' => __( 'Missed Cron Events', 'oli-cron-jobs' ),
			'test'  => array( $this, 'test_missed_events' ),
		);

		return $tests;
	}

	/**
	 * Test if WP-Cron is disabled
	 *
	 * @return array
	 */
	public function test_cron_disabled() {
		$result = array(
			'label'       => __( 'WP-Cron is enabled', 'oli-cron-jobs' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'oli-cron-jobs' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'The built-in WP-Cron system is functioning normally.', 'oli-cron-jobs' )
			),
			'test'        => 'oli_cj_cron_disabled',
		);

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'WP-Cron is disabled', 'oli-cron-jobs' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'WP-Cron is disabled via the DISABLE_WP_CRON constant. Make sure you have set up a server cron job to trigger wp-cron.php regularly.', 'oli-cron-jobs' )
			);
		} elseif ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Alternate WP-Cron is enabled', 'oli-cron-jobs' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'ALTERNATE_WP_CRON is enabled. WordPress will redirect visitors to spawn cron, which can be unreliable on some hosts.', 'oli-cron-jobs' )
			);
		}

		return $result;
	}

	/**
	 * Test if WP-Cron can spawn
	 *
	 * @return array
	 */
	public function test_cron_spawn() {
		$result = array(
			'label'       => __( 'WP-Cron spawns successfully', 'oli-cron-jobs' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'oli-cron-jobs' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'WordPress can successfully spawn cron processes.', 'oli-cron-jobs' )
			),
			'test'        => 'oli_cj_cron_spawn',
		);

		$cron_url      = site_url( 'wp-cron.php' );
		$doing_wp_cron = sprintf( '%.22F', microtime( true ) );

		$cron_request = apply_filters(
			'cron_request', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			array(
				'url'  => add_query_arg( 'doing_wp_cron', $doing_wp_cron, $cron_url ),
				'key'  => $doing_wp_cron,
				'args' => array(
					'timeout'   => 3,
					'blocking'  => true,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
				),
			)
		);

		$cron_request['args']['blocking'] = true;

		$response = wp_remote_post( $cron_request['url'], $cron_request['args'] );

		if ( is_wp_error( $response ) ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'WP-Cron cannot spawn', 'oli-cron-jobs' );
			$result['description'] = sprintf(
				'<p>%s</p><p>%s</p>',
				__( 'WordPress is unable to spawn cron processes. This may prevent scheduled tasks from running.', 'oli-cron-jobs' ),
				sprintf(
					/* translators: %s: error message */
					__( 'Error: %s', 'oli-cron-jobs' ),
					$response->get_error_message()
				)
			);
		} elseif ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'WP-Cron spawn returned an error', 'oli-cron-jobs' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'WP-Cron spawn returned HTTP status %d. Scheduled tasks may not run reliably.', 'oli-cron-jobs' ),
					wp_remote_retrieve_response_code( $response )
				)
			);
		}

		return $result;
	}

	/**
	 * Test for missed cron events
	 *
	 * @return array
	 */
	public function test_missed_events() {
		$result = array(
			'label'       => __( 'No missed cron events', 'oli-cron-jobs' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'oli-cron-jobs' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'All cron events are running on schedule.', 'oli-cron-jobs' )
			),
			'test'        => 'oli_cj_missed_events',
		);

		$missed = $this->get_missed_events();

		if ( ! empty( $missed ) ) {
			$count            = count( $missed );
			$result['status'] = 'recommended';
			$result['label']  = sprintf(
				/* translators: %d: number of missed events */
				_n( '%d missed cron event', '%d missed cron events', $count, 'oli-cron-jobs' ),
				$count
			);
			$result['description'] = sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %d: number of missed events */
					_n(
						'There is %d cron event that should have run but did not. This may indicate a problem with WP-Cron.',
						'There are %d cron events that should have run but did not. This may indicate a problem with WP-Cron.',
						$count,
						'oli-cron-jobs'
					),
					$count
				)
			);
		}

		return $result;
	}

	/**
	 * Get missed cron events
	 *
	 * @return array
	 */
	private function get_missed_events() {
		$crons        = _get_cron_array();
		$missed       = array();
		$current_time = time();

		if ( empty( $crons ) ) {
			return $missed;
		}

		foreach ( $crons as $timestamp => $cron ) {
			// Event is missed if it's more than 10 minutes in the past.
			if ( $timestamp < ( $current_time - 600 ) ) {
				foreach ( $cron as $hook => $dings ) {
					foreach ( $dings as $sig => $data ) {
						$missed[] = array(
							'hook'      => $hook,
							'timestamp' => $timestamp,
							'delay'     => $current_time - $timestamp,
						);
					}
				}
			}
		}

		return $missed;
	}

	/**
	 * Add debug information
	 *
	 * @param array $debug_info Debug info array.
	 * @return array
	 */
	public function add_debug_info( $debug_info ) {
		$crons        = _get_cron_array();
		$total_events = 0;

		if ( ! empty( $crons ) ) {
			foreach ( $crons as $timestamp => $cron ) {
				foreach ( $cron as $hook => $dings ) {
					$total_events += count( $dings );
				}
			}
		}

		$schedules        = wp_get_schedules();
		$custom_schedules = Oli_CJ_Schedule_Manager::get_custom_schedules();

		$debug_info['oli-cron-jobs'] = array(
			'label'  => __( 'Oli Cron Jobs', 'oli-cron-jobs' ),
			'fields' => array(
				'plugin_version'   => array(
					'label' => __( 'Plugin Version', 'oli-cron-jobs' ),
					'value' => OLI_CJ_VERSION,
				),
				'total_events'     => array(
					'label' => __( 'Total Cron Events', 'oli-cron-jobs' ),
					'value' => $total_events,
				),
				'total_schedules'  => array(
					'label' => __( 'Total Schedules', 'oli-cron-jobs' ),
					'value' => count( $schedules ),
				),
				'custom_schedules' => array(
					'label' => __( 'Custom Schedules', 'oli-cron-jobs' ),
					'value' => count( $custom_schedules ),
				),
				'cron_disabled'    => array(
					'label' => __( 'WP-Cron Disabled', 'oli-cron-jobs' ),
					'value' => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? __( 'Yes', 'oli-cron-jobs' ) : __( 'No', 'oli-cron-jobs' ),
				),
				'alternate_cron'   => array(
					'label' => __( 'Alternate WP-Cron', 'oli-cron-jobs' ),
					'value' => ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) ? __( 'Yes', 'oli-cron-jobs' ) : __( 'No', 'oli-cron-jobs' ),
				),
				'log_retention'    => array(
					'label' => __( 'Log Retention (days)', 'oli-cron-jobs' ),
					'value' => get_option( 'oli_cj_log_retention_days', 30 ),
				),
				'total_logs'       => array(
					'label' => __( 'Total Log Entries', 'oli-cron-jobs' ),
					'value' => Oli_CJ_Logger::get_log_count(),
				),
			),
		);

		return $debug_info;
	}

	/**
	 * Check if there are any issues
	 *
	 * @return array
	 */
	public static function get_issues() {
		$issues = array();

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			$issues[] = array(
				'type'    => 'warning',
				'message' => __( 'ALTERNATE_WP_CRON is enabled. Cron spawning uses a visitor redirect and may be unreliable.', 'oli-cron-jobs' ),
			);
		}

		$instance = self::get_instance();
		$missed   = $instance->get_missed_events();
		$count    = count( $missed );

		if ( $cron_disabled && $count > 0 ) {
			$issues[] = array(
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: %d: number of overdue events */
					_n(
						'WP-Cron is disabled (DISABLE_WP_CRON): %d event is overdue. Check the server cron job.',
						'WP-Cron is disabled (DISABLE_WP_CRON): %d events are overdue. Check the server cron job.',
						$count,
						'oli-cron-jobs'
					),
					$count
				),
			);
		} elseif ( $cron_disabled ) {
			$issues[] = array(
				'type'    => 'warning',
				'message' => __( 'WP-Cron is disabled via DISABLE_WP_CRON. Make sure you have a server cron job configured.', 'oli-cron-jobs' ),
			);
		} elseif ( $count > 0 ) {
			$issues[] = array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %d: number of missed events */
					_n( '%d cron event is overdue.', '%d cron events are overdue.', $count, 'oli-cron-jobs' ),
					$count
				),
			);
		}

		return $issues;
	}

	/**
	 * Request this site's wp-cron.php to test whether cron can spawn.
	 *
	 * @return array {
	 *     @type bool   $success Whether the spawn request succeeded.
	 *     @type string $message Result message.
	 * }
	 */
	public static function run_spawn_test() {
		$cron_url      = site_url( 'wp-cron.php' );
		$doing_wp_cron = sprintf( '%.22F', microtime( true ) );

		$cron_request = apply_filters(
			'cron_request', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			array(
				'url'  => add_query_arg( 'doing_wp_cron', $doing_wp_cron, $cron_url ),
				'key'  => $doing_wp_cron,
				'args' => array(
					'timeout'   => 5,
					'blocking'  => true,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
				),
			)
		);

		$cron_request['args']['blocking'] = true;
		$response                         = wp_remote_post( $cron_request['url'], $cron_request['args'] );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Cron spawn failed: %s', 'oli-cron-jobs' ),
					$response->get_error_message()
				),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'Cron spawn returned HTTP status %d.', 'oli-cron-jobs' ),
					$code
				),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Cron spawn succeeded. WordPress was able to request wp-cron.php on this site.', 'oli-cron-jobs' ),
		);
	}
}
