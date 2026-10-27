<?php
/**
 * URL cron event and paused-hook regression tests.
 *
 * @package Oli_Cron_Jobs
 */

/**
 * Tests URL cron execution and paused-hook option reads.
 */
class Oli_CJ_URL_Cron_Event_Test extends PHPUnit\Framework\TestCase {

	/**
	 * Skip when WordPress is not bootstrapped.
	 */
	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Oli_CJ_Cron_Manager' ) ) {
			$this->markTestSkipped( 'WordPress is not loaded. Set WP_ROOT to a working install.' );
		}

		Oli_CJ_Logger::create_table();
	}

	/**
	 * Reading paused hooks must not recurse when option filters fire.
	 */
	public function test_is_hook_paused_does_not_recurse_on_option_hooks() {
		$calls = 0;

		$callback = static function () use ( &$calls ) {
			++$calls;
			Oli_CJ_Cron_Manager::is_hook_paused( 'oli_cj_reentry_probe' );
		};

		add_action( 'all', $callback, 1 );

		Oli_CJ_Cron_Manager::is_hook_paused( 'oli_cj_reentry_probe' );
		get_option( 'blogname' );

		remove_action( 'all', $callback, 1 );

		$this->assertGreaterThan( 0, $calls );
	}

	/**
	 * `wp cron event run` path: do_action_ref_array must request the URL and log it.
	 */
	public function test_wp_cron_event_run_url_event_requests_and_logs() {
		$hook         = 'oli_cj_test_url_event_' . wp_generate_password( 8, false, false );
		$url          = 'https://example.com/oli-cj-url-event-test';
		$requested    = '';
		$request_args = null;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $request_url ) use ( $url, &$requested, &$request_args ) {
				if ( $request_url !== $url ) {
					return $preempt;
				}

				$requested    = $request_url;
				$request_args = $args;

				return array(
					'headers'  => array(),
					'body'     => 'ok',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		Oli_CJ_Cron_Manager::add_url_event( $hook, time() + HOUR_IN_SECONDS, $url );

		if ( ! defined( 'DOING_CRON' ) ) {
			define( 'DOING_CRON', true );
		}

		// Same execution path as `wp cron event run <hook>`.
		do_action_ref_array( $hook, array() );

		$this->assertSame( $url, $requested );
		$this->assertIsArray( $request_args );
		$this->assertTrue( ! empty( $request_args['reject_unsafe_urls'] ) );

		$source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-cron-manager.php' );
		$this->assertNotFalse( $source );
		$this->assertMatchesRegularExpression( '/\$response\s*=\s*wp_safe_remote_get\s*\(/', $source );

		$logs = Oli_CJ_Logger::get_logs(
			array(
				'hook'  => $hook,
				'limit' => 5,
			)
		);

		$this->assertNotEmpty( $logs );

		$found_url_log = false;
		foreach ( $logs as $log ) {
			if ( $hook === $log->hook && 'url' === $log->event_type ) {
				$found_url_log = true;
				break;
			}
		}

		$this->assertTrue( $found_url_log, 'Expected a URL event log row after cron run.' );

		Oli_CJ_Cron_Manager::delete_all_hook_events( $hook );
	}
}
