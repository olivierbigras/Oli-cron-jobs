<?php
/**
 * Upgrade cleanup for removed PHP events.
 *
 * @package Oli_Cron_Jobs
 */

/**
 * Tests that maybe_upgrade unschedules PHP events and drops related options/caps.
 */
class Oli_CJ_Upgrade_Php_Cleanup_Test extends PHPUnit\Framework\TestCase {

	/**
	 * Unique PHP hook used by this test.
	 *
	 * @var string
	 */
	private $php_hook;

	/**
	 * Skip when WordPress is not bootstrapped.
	 */
	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Oli_CJ_Plugin' ) ) {
			$this->markTestSkipped( 'WordPress is not loaded. Set WP_ROOT to a working install.' );
		}

		Oli_CJ_Cron_Manager::reset_option_cache();
		$this->php_hook = 'oli_cj_php_upgrade_' . wp_generate_password( 8, false, false );
	}

	/**
	 * Clear leftover schedules and options.
	 */
	protected function tearDown(): void {
		if ( ! empty( $this->php_hook ) ) {
			wp_clear_scheduled_hook( $this->php_hook );
		}

		delete_option( 'oli_cj_php_events' );
		delete_option( 'oli_cron_jobs_php_events' );
		Oli_CJ_Cron_Manager::reset_option_cache();

		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( 'oli_cj_manage_php_events' ) ) {
			// Leave site as found; upgrade already removed the cap when present.
		}
	}

	/**
	 * Upgrade clears scheduled PHP hooks, options, list entries, and the capability.
	 */
	public function test_upgrade_removes_php_events() {
		$hook = $this->php_hook;
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook );

		update_option(
			'oli_cj_php_events',
			array(
				$hook => array(
					'code' => 'return;',
					'hash' => 'x',
				),
			)
		);
		update_option(
			'oli_cron_jobs_php_events',
			array(
				$hook => array(
					'code' => 'return;',
					'hash' => 'legacy',
				),
			)
		);
		update_option( 'oli_cj_paused_hooks', array( $hook, 'keep_paused_other' ) );
		update_option( 'oli_cj_managed_hooks', array( $hook, 'keep_managed_other' ) );
		update_option( 'oli_cj_created_hooks', array( $hook, 'keep_created_other' ) );

		$admin = get_role( 'administrator' );
		$this->assertNotNull( $admin );
		$admin->add_cap( 'oli_cj_manage_php_events' );

		update_option( 'oli_cj_version', '0.0.1' );
		Oli_CJ_Cron_Manager::reset_option_cache();

		Oli_CJ_Plugin::maybe_upgrade();
		Oli_CJ_Cron_Manager::reset_option_cache();

		$this->assertFalse( wp_next_scheduled( $hook ) );
		$this->assertFalse( get_option( 'oli_cj_php_events', false ) );
		$this->assertFalse( get_option( 'oli_cron_jobs_php_events', false ) );
		$this->assertSame( OLI_CJ_VERSION, get_option( 'oli_cj_version' ) );

		$paused  = get_option( 'oli_cj_paused_hooks', array() );
		$managed = get_option( 'oli_cj_managed_hooks', array() );
		$created = get_option( 'oli_cj_created_hooks', array() );

		$this->assertNotContains( $hook, is_array( $paused ) ? $paused : array() );
		$this->assertNotContains( $hook, is_array( $managed ) ? $managed : array() );
		$this->assertNotContains( $hook, is_array( $created ) ? $created : array() );
		$this->assertContains( 'keep_paused_other', is_array( $paused ) ? $paused : array() );

		$this->assertFalse( $admin->has_cap( 'oli_cj_manage_php_events' ) );

		// Import still skips type php (admin class is only loaded in is_admin()).
		require_once OLI_CJ_PLUGIN_DIR . 'admin/class-oli-cj-admin.php';
		$count = Oli_CJ_Admin::import_data(
			array(
				'events' => array(
					array(
						'hook'     => 'oli_cj_import_php_skip',
						'type'     => 'php',
						'php_code' => '/* should not import */',
						'timestamp' => time() + HOUR_IN_SECONDS,
					),
					array(
						'hook'      => 'oli_cj_import_standard_ok',
						'type'      => 'standard',
						'timestamp' => time() + HOUR_IN_SECONDS,
						'args'      => array(),
					),
				),
			)
		);
		$this->assertSame( 1, $count );
		$this->assertFalse( wp_next_scheduled( 'oli_cj_import_php_skip' ) );
		$this->assertNotFalse( wp_next_scheduled( 'oli_cj_import_standard_ok' ) );
		Oli_CJ_Cron_Manager::delete_all_hook_events( 'oli_cj_import_standard_ok' );
	}
}
