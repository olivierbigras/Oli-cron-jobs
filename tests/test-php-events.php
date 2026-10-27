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
	 * User IDs created during a test.
	 *
	 * @var int[]
	 */
	private $created_users = array();

	/**
	 * Previous current user id.
	 *
	 * @var int
	 */
	private $previous_user_id = 0;

	/**
	 * Skip when WordPress is not bootstrapped.
	 */
	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Oli_CJ_PHP_Events' ) ) {
			$this->markTestSkipped( 'WordPress is not loaded. Set WP_ROOT to a working install.' );
		}

		Oli_CJ_Logger::create_table();
		$this->hook             = 'oli_cj_test_php_' . wp_generate_password( 8, false, false );
		$this->created_users    = array();
		$this->previous_user_id = get_current_user_id();
	}

	/**
	 * Remove stored PHP and scheduled events.
	 */
	protected function tearDown(): void {
		if ( ! class_exists( 'Oli_CJ_Cron_Manager' ) ) {
			return;
		}

		remove_filter( 'oli_cj_is_file_editing_disallowed', '__return_true' );
		remove_filter( 'oli_cj_is_file_editing_disallowed', '__return_false' );
		remove_all_filters( 'oli_cj_php_events_disabled' );
		remove_all_filters( 'oli_cj_php_events_capability' );
		remove_all_filters( 'wp_die_handler' );

		Oli_CJ_Cron_Manager::delete_all_hook_events( $this->hook );
		Oli_CJ_PHP_Events::delete_code( $this->hook );
		delete_option( 'oli_cj_test_php_ran' );

		if ( class_exists( 'Oli_CJ_PHP_Events' ) ) {
			Oli_CJ_PHP_Events::grant_default_caps();
		}

		if ( defined( 'ABSPATH' ) && ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( function_exists( 'wp_delete_user' ) ) {
			foreach ( $this->created_users as $user_id ) {
				wp_delete_user( $user_id );
			}
		}

		wp_set_current_user( $this->previous_user_id );
		$_GET  = array();
		$_POST = array();
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

	/**
	 * Administrators receive the dedicated PHP-event capability by default.
	 */
	public function test_php_events_capability_is_granted_to_administrators() {
		add_filter( 'oli_cj_is_file_editing_disallowed', '__return_false' );
		Oli_CJ_PHP_Events::grant_default_caps();

		$source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-php-events.php' );
		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'oli_cj_manage_php_events', $source );
		$this->assertStringContainsString( 'oli_cj_php_events_capability', $source );
		$this->assertMatchesRegularExpression( '/function can_manage\b[\s\S]*is_multisite[\s\S]*is_super_admin/', $source );

		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( Oli_CJ_PHP_Events::CAPABILITY ) );
		$this->assertSame( Oli_CJ_PHP_Events::CAPABILITY, Oli_CJ_PHP_Events::capability() );

		add_filter(
			'oli_cj_php_events_capability',
			static function () {
				return 'manage_network';
			}
		);
		$this->assertSame( 'manage_network', Oli_CJ_PHP_Events::capability() );
		remove_all_filters( 'oli_cj_php_events_capability' );

		$editor_id = $this->create_test_user( 'editor' );
		wp_set_current_user( $editor_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( Oli_CJ_PHP_Events::CAPABILITY ) );
		$this->assertFalse( Oli_CJ_PHP_Events::can_manage() );

		$admin_id = $this->create_test_user( 'administrator' );
		$admin    = new WP_User( $admin_id );
		$admin->add_cap( 'edit_files' );
		wp_set_current_user( $admin_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertTrue( current_user_can( Oli_CJ_PHP_Events::CAPABILITY ) );
		$this->assertTrue( Oli_CJ_PHP_Events::can_manage() );

		$role->remove_cap( Oli_CJ_PHP_Events::CAPABILITY );
		wp_set_current_user( 0 );
		wp_set_current_user( $admin_id );
		$this->assertFalse( current_user_can( Oli_CJ_PHP_Events::CAPABILITY ) );
		$this->assertFalse( Oli_CJ_PHP_Events::can_manage() );

		$role->add_cap( Oli_CJ_PHP_Events::CAPABILITY );
		wp_set_current_user( 0 );
		wp_set_current_user( $admin_id );
		$this->assertTrue( Oli_CJ_PHP_Events::can_manage() );
	}

	/**
	 * The disable switch hides management and refuses cron execution.
	 */
	public function test_php_events_disable_switch_refuses_execution() {
		Oli_CJ_PHP_Events::save_code( $this->hook, 'update_option( "oli_cj_test_php_ran", 1 );' );
		Oli_CJ_PHP_Events::register_handler( $this->hook );

		$source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-php-events.php' );
		$this->assertNotFalse( $source );
		$this->assertStringContainsString( 'OLI_CJ_DISABLE_PHP_EVENTS', $source );
		$this->assertStringContainsString( 'OLI_CJ_DISALLOW_PHP_EVENTS', $source );
		$this->assertStringContainsString( 'oli_cj_php_events_disabled', $source );

		$admin_source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'admin/class-oli-cj-admin.php' );
		$this->assertNotFalse( $admin_source );
		$this->assertMatchesRegularExpression( '/function render_php_warning\b[\s\S]*can_use_php_events/', $admin_source );

		add_filter( 'oli_cj_php_events_disabled', '__return_true' );

		$this->assertTrue( Oli_CJ_PHP_Events::are_disabled() );
		$this->assertFalse( Oli_CJ_PHP_Events::can_execute() );
		$this->assertFalse( Oli_CJ_PHP_Events::can_manage() );
		$this->assertFalse( Oli_CJ_PHP_Events::execute( $this->hook ) );
		$this->assertFalse( Oli_CJ_PHP_Events::last_execute_succeeded() );
		$this->assertFalse( get_option( 'oli_cj_test_php_ran' ) );

		do_action( $this->hook );
		$this->assertFalse( get_option( 'oli_cj_test_php_ran' ), 'Already-scheduled PHP events must not run when disabled.' );

		$this->assertFalse( Oli_CJ_Cron_Manager::run_event_now( $this->hook, array() ) );
		$this->assertFalse( get_option( 'oli_cj_test_php_ran' ) );

		remove_all_filters( 'oli_cj_php_events_disabled' );
		$this->assertFalse( Oli_CJ_PHP_Events::are_disabled() );
		$this->assertTrue( Oli_CJ_PHP_Events::execute( $this->hook ) );
		$this->assertSame( 1, (int) get_option( 'oli_cj_test_php_ran' ) );
	}

	/**
	 * Create, edit, run, and delete PHP-event actions are nonce-checked.
	 */
	public function test_php_event_actions_require_a_nonce() {
		$admin_source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'admin/class-oli-cj-admin.php' );
		$this->assertNotFalse( $admin_source );
		$this->assertStringContainsString( "'oli_cj_add_event'", $admin_source );
		$this->assertStringContainsString( "'oli_cj_edit_event_'", $admin_source );
		$this->assertStringContainsString( "'oli_cj_action'", $admin_source );
		$this->assertMatchesRegularExpression( '/function process_add_event_form\b[\s\S]*wp_verify_nonce[\s\S]*oli_cj_add_event/', $admin_source );
		$this->assertMatchesRegularExpression( '/function process_edit_event_form\b[\s\S]*wp_verify_nonce[\s\S]*edit_nonce_action/', $admin_source );
		$this->assertMatchesRegularExpression( '/function handle_actions\b[\s\S]*wp_verify_nonce\([\s\S]*oli_cj_action/', $admin_source );
		$this->assertStringContainsString( "'run_now'", $admin_source );
		$this->assertStringContainsString( "'delete_event'", $admin_source );

		if ( ! class_exists( 'Oli_CJ_Admin' ) ) {
			require_once OLI_CJ_PLUGIN_DIR . 'admin/class-oli-cj-admin.php';
		}

		add_filter( 'oli_cj_is_file_editing_disallowed', '__return_false' );
		Oli_CJ_PHP_Events::grant_default_caps();

		$admin_id = $this->create_test_user( 'administrator' );
		$admin    = new WP_User( $admin_id );
		$admin->add_cap( 'edit_files' );
		wp_set_current_user( $admin_id );

		$_POST = array(
			'oli_cj_add_event' => '1',
		);
		$_GET  = array();

		$caught = $this->catch_wp_die(
			static function () {
				Oli_CJ_Admin::get_instance()->handle_actions();
			}
		);
		$this->assertInstanceOf( RuntimeException::class, $caught );
		$this->assertSame( 403, $caught->getCode() );

		$_POST = array();
		$_GET  = array(
			'page'   => 'oli-cron-jobs',
			'action' => 'run_now',
			'hook'   => $this->hook,
		);

		$caught_run = $this->catch_wp_die(
			static function () {
				Oli_CJ_Admin::get_instance()->handle_actions();
			}
		);
		$this->assertInstanceOf( RuntimeException::class, $caught_run );
		$this->assertSame( 403, $caught_run->getCode() );

		$_GET['action'] = 'delete_event';
		$caught_delete  = $this->catch_wp_die(
			static function () {
				Oli_CJ_Admin::get_instance()->handle_actions();
			}
		);
		$this->assertInstanceOf( RuntimeException::class, $caught_delete );
		$this->assertSame( 403, $caught_delete->getCode() );
	}

	/**
	 * Insert a user with a role and remember it for teardown.
	 *
	 * @param string $role Role name.
	 * @return int
	 */
	private function create_test_user( $role ) {
		$login = 'oli_cj_' . $role . '_' . wp_generate_password( 6, false, false );
		$id    = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 16, true, true ),
				'user_email' => $login . '@example.com',
				'role'       => $role,
			)
		);

		$this->assertIsInt( $id );
		$this->created_users[] = $id;

		return $id;
	}

	/**
	 * Run a callback with wp_die converted to an exception.
	 *
	 * @param callable $callback Callback that may call wp_die().
	 * @return RuntimeException|null
	 */
	private function catch_wp_die( $callback ) {
		$handler = static function ( $message, $title = '', $args = array() ) {
			unset( $message, $title );
			$code = isset( $args['response'] ) ? (int) $args['response'] : 0;
			throw new RuntimeException( 'oli_cj_wp_die', $code );
		};

		$filter = static function () use ( $handler ) {
			return $handler;
		};

		add_filter( 'wp_die_handler', $filter );
		$caught = null;

		try {
			$callback();
		} catch ( RuntimeException $exception ) {
			$caught = $exception;
		}

		remove_filter( 'wp_die_handler', $filter );

		return $caught;
	}
}
