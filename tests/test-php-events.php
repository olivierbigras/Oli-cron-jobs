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

		remove_filter( 'oli_cj_is_file_editing_disallowed', '__return_true' );
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

	/**
	 * DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS refuse execution even from cron.
	 *
	 * Constants cannot be defined for one test only, so the dedicated filter
	 * simulates them. execute() also rechecks the constants independently of
	 * can_execute().
	 */
	public function test_execute_refused_when_file_edit_disallowed() {
		Oli_CJ_PHP_Events::save_code( $this->hook, 'update_option( "oli_cj_test_php_ran", 1 );' );
		Oli_CJ_PHP_Events::register_handler( $this->hook );

		$source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-php-events.php' );
		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'constants_block_execution', $source );
		$this->assertStringContainsString( 'DISALLOW_FILE_EDIT', $source );
		$this->assertStringContainsString( 'DISALLOW_FILE_MODS', $source );
		$this->assertMatchesRegularExpression( '/function execute\b[\s\S]*constants_block_execution[\s\S]*can_execute/', $source );

		add_filter( 'oli_cj_is_file_editing_disallowed', '__return_true' );

		$this->assertFalse( Oli_CJ_PHP_Events::can_execute() );
		$this->assertFalse( Oli_CJ_PHP_Events::execute( $this->hook ) );
		$this->assertFalse( Oli_CJ_PHP_Events::last_execute_succeeded() );
		$this->assertFalse( get_option( 'oli_cj_test_php_ran' ) );

		do_action( $this->hook );
		$this->assertFalse( get_option( 'oli_cj_test_php_ran' ), 'Cron/do_action must not run PHP when file editing is disallowed.' );

		$this->assertFalse( Oli_CJ_Cron_Manager::run_event_now( $this->hook, array() ) );
		$this->assertFalse( get_option( 'oli_cj_test_php_ran' ) );

		remove_filter( 'oli_cj_is_file_editing_disallowed', '__return_true' );
	}

	/**
	 * PHP events execute via eval() after HMAC, and require edit_files to manage.
	 */
	public function test_php_event_executes_via_eval_with_hmac() {
		$source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-php-events.php' );
		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'eval(', $source );
		$this->assertStringContainsString( 'hash_hmac', $source );
		$this->assertStringContainsString( 'edit_files', $source );
		$this->assertStringNotContainsString( 'tempnam', $source );
		$this->assertDoesNotMatchRegularExpression( '/include\s*\(\s*\$/', $source );

		$code = 'update_option( "oli_cj_test_php_ran", "eval-ok" );';
		Oli_CJ_PHP_Events::save_code( $this->hook, $code );

		$entry = Oli_CJ_PHP_Events::get_entry( $this->hook );
		$this->assertIsArray( $entry );
		$this->assertSame( Oli_CJ_PHP_Events::hash_code( $code ), $entry['hash'] );
		$this->assertTrue( Oli_CJ_PHP_Events::is_entry_valid( $entry ) );

		Oli_CJ_PHP_Events::register_handler( $this->hook );
		do_action( $this->hook );

		$this->assertSame( 'eval-ok', get_option( 'oli_cj_test_php_ran' ) );
		$this->assertTrue( Oli_CJ_PHP_Events::last_execute_succeeded() );
	}

	/**
	 * A PHP event that throws must report failure, not success.
	 */
	public function test_php_event_error_reports_failure() {
		Oli_CJ_PHP_Events::save_code( $this->hook, 'throw new RuntimeException( "oli-cj-test-boom" );' );

		$this->assertFalse( Oli_CJ_PHP_Events::execute( $this->hook ) );
		$this->assertFalse( Oli_CJ_PHP_Events::last_execute_succeeded() );
	}
}
