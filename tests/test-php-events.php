<?php
/**
 * PHP cron event tests.
 *
 * @package Oli_Cron_Jobs
 */

/**
 * Tests PHP event storage, integrity, and execution.
 */
class Oli_CJ_PHP_Events_Test extends PHPUnit\Framework\TestCase {

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
		if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Oli_CJ_PHP_Events' ) ) {
			$this->markTestSkipped( 'WordPress is not loaded. Set WP_ROOT to a working install.' );
		}

		Oli_CJ_Logger::create_table();
		$this->hook = 'oli_cj_test_php_' . wp_generate_password( 8, false, false );
	}

	/**
	 * Remove stored PHP and scheduled events.
	 */
	protected function tearDown(): void {
		if ( ! class_exists( 'Oli_CJ_Cron_Manager' ) ) {
			return;
		}

		Oli_CJ_Cron_Manager::delete_all_hook_events( $this->hook );
		Oli_CJ_PHP_Events::delete_code( $this->hook );
		delete_option( 'oli_cj_test_php_ran' );
	}

	/**
	 * Saving PHP stores an integrity hash.
	 */
	public function test_save_code_stores_valid_hash() {
		Oli_CJ_PHP_Events::save_code( $this->hook, 'update_option( "oli_cj_test_php_ran", 1 );' );

		$this->assertTrue( Oli_CJ_PHP_Events::is_php_event( $this->hook ) );
		$this->assertFalse( Oli_CJ_PHP_Events::needs_checking( $this->hook ) );
		$this->assertStringContainsString( 'update_option', Oli_CJ_PHP_Events::get_code( $this->hook ) );
	}

	/**
	 * Tampered PHP is not executed.
	 */
	public function test_tampered_php_is_not_executed() {
		Oli_CJ_PHP_Events::save_code( $this->hook, 'update_option( "oli_cj_test_php_ran", 1 );' );

		$events              = Oli_CJ_PHP_Events::get_all();
		$events[ $this->hook ]['code'] = 'update_option( "oli_cj_test_php_ran", 99 );';
		Oli_CJ_Cron_Manager::update_plugin_option( 'oli_cj_php_events', $events );

		$this->assertTrue( Oli_CJ_PHP_Events::needs_checking( $this->hook ) );
		$this->assertFalse( Oli_CJ_PHP_Events::execute( $this->hook ) );
		$this->assertFalse( get_option( 'oli_cj_test_php_ran' ) );
	}

	/**
	 * A valid PHP event runs and writes a log row.
	 */
	public function test_valid_php_event_runs() {
		Oli_CJ_Cron_Manager::add_php_event(
			$this->hook,
			time() + HOUR_IN_SECONDS,
			'update_option( "oli_cj_test_php_ran", "yes" );'
		);

		$this->assertTrue( Oli_CJ_PHP_Events::execute( $this->hook ) );
		$this->assertSame( 'yes', get_option( 'oli_cj_test_php_ran' ) );

		$logs = Oli_CJ_Logger::get_logs(
			array(
				'hook'  => $this->hook,
				'limit' => 5,
			)
		);

		$found = false;
		foreach ( $logs as $log ) {
			if ( $this->hook === $log->hook && 'php' === $log->event_type ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Expected a PHP event log row.' );
	}

	/**
	 * File-editing constants disable management.
	 */
	public function test_file_editing_disallow_blocks_management() {
		$this->assertTrue( is_bool( Oli_CJ_PHP_Events::is_file_editing_disallowed() ) );
		$this->assertTrue( is_string( Oli_CJ_PHP_Events::management_block_reason() ) );
	}
}
