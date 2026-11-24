<?php
/**
 * WP-CLI Commands Class
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for Oli Cron Jobs
 */
class Oli_CJ_CLI {

	/**
	 * List all cron events
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--hook=<hook>]
	 * : Filter by hook name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp oli-cron list
	 *     wp oli-cron list --format=json
	 *     wp oli-cron list --hook=my_custom_hook
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( $args, $assoc_args ) {
		$events = Oli_CJ_Cron_Manager::get_all_events();

		if ( empty( $events ) ) {
			WP_CLI::warning( __( 'No cron events found.', 'oli-cron-jobs' ) );
			return;
		}

		// Filter by hook if specified.
		if ( ! empty( $assoc_args['hook'] ) ) {
			$events = array_filter(
				$events,
				function ( $event ) use ( $assoc_args ) {
					return $event['hook'] === $assoc_args['hook'];
				}
			);
		}

		$items = array();
		foreach ( $events as $event ) {
			$items[] = array(
				'hook'       => $event['hook'],
				'next_run'   => gmdate( 'Y-m-d H:i:s', $event['timestamp'] ),
				'recurrence' => $event['schedule'] ? $event['schedule'] : __( 'Non-repeating', 'oli-cron-jobs' ),
				'status'     => $event['timestamp'] < time() ? __( 'Overdue', 'oli-cron-jobs' ) : __( 'Scheduled', 'oli-cron-jobs' ),
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $items, array( 'hook', 'next_run', 'recurrence', 'status' ) );
	}

	/**
	 * Run a cron event now
	 *
	 * ## OPTIONS
	 *
	 * <hook>
	 * : The hook name to run.
	 *
	 * [--args=<args>]
	 * : JSON encoded arguments to pass to the hook.
	 *
	 * ## EXAMPLES
	 *
	 *     wp oli-cron run my_custom_hook
	 *     wp oli-cron run my_custom_hook --args='["arg1","arg2"]'
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function run( $args, $assoc_args ) {
		$hook = $args[0];

		$event_args = array();
		if ( ! empty( $assoc_args['args'] ) ) {
			$event_args = json_decode( $assoc_args['args'], true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				WP_CLI::error( __( 'Invalid JSON in --args parameter.', 'oli-cron-jobs' ) );
				return;
			}
		}

		/* translators: %s: cron hook name */
		WP_CLI::log( sprintf( __( 'Running cron event: %s', 'oli-cron-jobs' ), $hook ) );

		Oli_CJ_Cron_Manager::run_event_now( $hook, $event_args );

		/* translators: %s: cron hook name */
		WP_CLI::success( sprintf( __( 'Cron event %s has been executed.', 'oli-cron-jobs' ), $hook ) );
	}

	/**
	 * Delete a cron event
	 *
	 * ## OPTIONS
	 *
	 * <hook>
	 * : The hook name to delete.
	 *
	 * [--all]
	 * : Delete all instances of this hook.
	 *
	 * ## EXAMPLES
	 *
	 *     wp oli-cron delete my_custom_hook --all
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		$hook = $args[0];

		if ( isset( $assoc_args['all'] ) ) {
			$count = Oli_CJ_Cron_Manager::delete_all_hook_events( $hook );
			if ( $count > 0 ) {
				WP_CLI::success(
					sprintf(
						/* translators: 1: number of events, 2: hook name */
						_n(
							'Deleted %1$d instance of %2$s.',
							'Deleted %1$d instances of %2$s.',
							$count,
							'oli-cron-jobs'
						),
						$count,
						$hook
					)
				);
			} else {
				/* translators: %s: cron hook name */
				WP_CLI::warning( sprintf( __( 'No events found for hook: %s', 'oli-cron-jobs' ), $hook ) );
			}
		} else {
			WP_CLI::error( __( 'Please specify --all flag to delete all instances of the hook.', 'oli-cron-jobs' ) );
		}
	}

	/**
	 * Pause a cron hook
	 *
	 * ## OPTIONS
	 *
	 * <hook>
	 * : The hook name to pause.
	 *
	 * ## EXAMPLES
	 *
	 *     wp oli-cron pause my_custom_hook
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function pause( $args, $assoc_args ) {
		unset( $assoc_args );
		$hook = $args[0];

		if ( Oli_CJ_Cron_Manager::pause_hook( $hook ) ) {
			/* translators: %s: cron hook name */
			WP_CLI::success( sprintf( __( 'Hook %s has been paused.', 'oli-cron-jobs' ), $hook ) );
		} else {
			/* translators: %s: cron hook name */
			WP_CLI::error( sprintf( __( 'Failed to pause hook: %s', 'oli-cron-jobs' ), $hook ) );
		}
	}

	/**
	 * Resume a paused cron hook
	 *
	 * ## OPTIONS
	 *
	 * <hook>
	 * : The hook name to resume.
	 *
	 * ## EXAMPLES
	 *
	 *     wp oli-cron resume my_custom_hook
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function resume( $args, $assoc_args ) {
		unset( $assoc_args );
		$hook = $args[0];

		if ( Oli_CJ_Cron_Manager::resume_hook( $hook ) ) {
			/* translators: %s: cron hook name */
			WP_CLI::success( sprintf( __( 'Hook %s has been resumed.', 'oli-cron-jobs' ), $hook ) );
		} else {
			/* translators: %s: cron hook name */
			WP_CLI::error( sprintf( __( 'Failed to resume hook: %s', 'oli-cron-jobs' ), $hook ) );
		}
	}
}
