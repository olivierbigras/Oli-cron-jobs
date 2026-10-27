<?php
/**
 * Uninstall ownership tests.
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'OLI_CJ_LOAD_UNINSTALL_FN' ) ) {
	define( 'OLI_CJ_LOAD_UNINSTALL_FN', true );
}

/**
 * Tests that uninstall leaves foreign crons and removes plugin-created ones.
 */
class Oli_CJ_Uninstall_Test extends PHPUnit\Framework\TestCase {

	/**
	 * Unique hook for this test case.
	 *
	 * @var string
	 */
	private $hook;

	/**
	 * Skip when WordPress is not bootstrapped.
	 */
	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Oli_CJ_Cron_Manager' ) ) {
			$this->markTestSkipped( 'WordPress is not loaded. Set WP_ROOT to a working install.' );
		}

		require_once dirname( __DIR__ ) . '/uninstall.php';
		Oli_CJ_Logger::create_table();
		Oli_CJ_Cron_Manager::reset_option_cache();
		$this->hook = 'custom_created_cron_' . wp_generate_password( 8, false, false );
	}

	/**
	 * Remove scheduled test events.
	 */
	protected function tearDown(): void {
		if ( class_exists( 'Oli_CJ_Cron_Manager' ) && ! empty( $this->hook ) ) {
			Oli_CJ_Cron_Manager::delete_all_hook_events( $this->hook );
			Oli_CJ_Cron_Manager::reset_option_cache();
		}

		Oli_CJ_Logger::create_table();
	}

	/**
	 * Uninstall keeps a core cron even when it was listed as managed.
	 */
	public function test_uninstall_keeps_core_wp_version_check() {
		if ( ! wp_next_scheduled( 'wp_version_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'wp_version_check' );
		}

		Oli_CJ_Cron_Manager::update_plugin_option( 'oli_cj_managed_hooks', array( 'wp_version_check' ) );
		Oli_CJ_Cron_Manager::pause_hook( 'wp_version_check' );

		oli_cj_uninstall_site();
		Oli_CJ_Cron_Manager::reset_option_cache();

		$this->assertNotFalse( wp_next_scheduled( 'wp_version_check' ) );
		$this->assertFalse( get_option( 'oli_cj_paused_hooks', false ) );
	}

	/**
	 * Uninstall keeps a third-party hook that was only modified or imported.
	 */
	public function test_uninstall_keeps_modified_or_imported_third_party_cron() {
		$third = 'other_plugin_cron_' . wp_generate_password( 8, false, false );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $third );

		Oli_CJ_Cron_Manager::update_plugin_option( 'oli_cj_managed_hooks', array( $third ) );
		Oli_CJ_Cron_Manager::add_event( $third, time() + 2 * HOUR_IN_SECONDS, array(), false, false );

		oli_cj_uninstall_site();
		Oli_CJ_Cron_Manager::reset_option_cache();

		$this->assertNotFalse( wp_next_scheduled( $third ) );
		wp_clear_scheduled_hook( $third );
	}

	/**
	 * Uninstall removes a hook this plugin created.
	 */
	public function test_uninstall_removes_plugin_created_event() {
		Oli_CJ_Cron_Manager::add_event( $this->hook, time() + HOUR_IN_SECONDS, array(), false );

		$this->assertTrue( Oli_CJ_Cron_Manager::is_plugin_created_hook( $this->hook ) );
		$this->assertNotFalse( wp_next_scheduled( $this->hook ) );

		oli_cj_uninstall_site();
		Oli_CJ_Cron_Manager::reset_option_cache();

		$this->assertFalse( wp_next_scheduled( $this->hook ) );
	}

	/**
	 * Legacy managed names that are not PHP/URL/oli_cj_* are dropped.
	 */
	public function test_managed_hooks_migration_drops_foreign_names() {
		$kept = oli_cj_migrate_managed_hooks(
			array( 'wp_version_check', 'other_plugin_cron', 'oli_cj_php_abc123' ),
			array(),
			array( 'oli_cj_php_abc123' => array( 'code' => 'return;', 'hash' => 'x' ) )
		);

		$this->assertContains( 'oli_cj_php_abc123', $kept );
		$this->assertNotContains( 'wp_version_check', $kept );
		$this->assertNotContains( 'other_plugin_cron', $kept );
	}
}
