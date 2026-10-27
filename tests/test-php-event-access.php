<?php
/**
 * Access-control tests for PHP events in the admin (0.2.8 fixes A-F).
 *
 * @package Oli_Cron_Jobs
 */

if ( ! defined( 'OLI_CJ_LOAD_UNINSTALL_FN' ) ) {
	define( 'OLI_CJ_LOAD_UNINSTALL_FN', true );
}

/**
 * Admins without oli_cj_manage_php_events must not touch PHP events.
 */
class Oli_CJ_PHP_Event_Access_Test extends PHPUnit\Framework\TestCase {

	/**
	 * PHP hook.
	 *
	 * @var string
	 */
	private $php_hook;

	/**
	 * URL hook.
	 *
	 * @var string
	 */
	private $url_hook;

	/**
	 * Created users.
	 *
	 * @var int[]
	 */
	private $created_users = array();

	/**
	 * Previous user.
	 *
	 * @var int
	 */
	private $previous_user_id = 0;

	/**
	 * Set up events and filters.
	 */
	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Oli_CJ_PHP_Events' ) ) {
			$this->markTestSkipped( 'WordPress is not loaded. Set WP_ROOT to a working install.' );
		}

		if ( ! class_exists( 'Oli_CJ_Admin' ) ) {
			require_once OLI_CJ_PLUGIN_DIR . 'admin/class-oli-cj-admin.php';
		}

		Oli_CJ_Logger::create_table();
		Oli_CJ_PHP_Events::grant_default_caps();
		add_filter( 'oli_cj_is_file_editing_disallowed', '__return_false' );

		$this->previous_user_id = get_current_user_id();
		$suffix                 = wp_generate_password( 8, false, false );
		$this->php_hook         = 'oli_cj_acl_php_' . $suffix;
		$this->url_hook         = 'oli_cj_acl_url_' . $suffix;

		Oli_CJ_Cron_Manager::add_php_event( $this->php_hook, time() + 3600, 'update_option( "oli_cj_acl_ran", 1 ); /* ACL_SECRET */', array(), false );
		Oli_CJ_Cron_Manager::add_url_event( $this->url_hook, time() + 3600, 'https://example.com/', array(), false );
		Oli_CJ_Cron_Manager::reset_option_cache();
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		if ( ! class_exists( 'Oli_CJ_Cron_Manager' ) ) {
			return;
		}

		remove_all_filters( 'oli_cj_is_file_editing_disallowed' );
		remove_all_filters( 'oli_cj_php_events_disabled' );
		remove_all_filters( 'wp_die_handler' );
		remove_all_filters( 'wp_redirect' );

		foreach ( array( $this->php_hook, $this->url_hook ) as $hook ) {
			Oli_CJ_Cron_Manager::resume_hook( $hook );
			Oli_CJ_Cron_Manager::delete_all_hook_events( $hook );
			Oli_CJ_PHP_Events::delete_code( $hook );
		}

		Oli_CJ_PHP_Events::grant_default_caps();

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}

		wp_set_current_user( $this->previous_user_id );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Create an administrator, optionally without the PHP capability.
	 *
	 * @param bool $with_php_cap Keep oli_cj_manage_php_events.
	 * @return int
	 */
	private function make_admin( $with_php_cap ) {
		$login = 'oli_cj_acl_' . wp_generate_password( 6, false, false );
		$id    = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 16 ),
				'user_email' => $login . '@example.com',
				'role'       => 'administrator',
			)
		);
		$this->assertIsInt( $id );
		$this->created_users[] = $id;

		$user = new WP_User( $id );
		$user->add_cap( 'edit_files' );
		if ( ! $with_php_cap ) {
			// A user-level deny overrides the role grant.
			$user->add_cap( Oli_CJ_PHP_Events::CAPABILITY, false );
		}

		wp_set_current_user( 0 );
		wp_set_current_user( $id );
		$this->assertSame( $with_php_cap, Oli_CJ_PHP_Events::can_manage() );

		return $id;
	}

	/**
	 * Run a callback; return array( 'die' => code ) or array( 'redirect' => message ).
	 *
	 * @param callable $callback Callback.
	 * @return array
	 */
	private function run_handler( $callback ) {
		$die      = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				unset( $message, $title );
				throw new RuntimeException( 'die', isset( $args['response'] ) ? (int) $args['response'] : 0 );
			};
		};
		$redirect = static function ( $location ) {
			throw new LogicException( (string) $location );
		};
		add_filter( 'wp_die_handler', $die );
		add_filter( 'wp_redirect', $redirect );

		$result = array();
		try {
			$callback();
		} catch ( RuntimeException $e ) {
			$result['die'] = $e->getCode();
		} catch ( LogicException $e ) {
			$query = array();
			wp_parse_str( (string) wp_parse_url( $e->getMessage(), PHP_URL_QUERY ), $query );
			$result['redirect'] = isset( $query['oli_cj_message'] ) ? $query['oli_cj_message'] : $e->getMessage();
		}

		remove_filter( 'wp_die_handler', $die );
		remove_filter( 'wp_redirect', $redirect );

		return $result;
	}

	/**
	 * Find a scheduled event.
	 *
	 * @param string $hook Hook.
	 * @return array|null
	 */
	private function find_event( $hook ) {
		foreach ( Oli_CJ_Cron_Manager::get_all_events() as $event ) {
			if ( $event['hook'] === $hook ) {
				return $event;
			}
		}
		return null;
	}

	/**
	 * Build an edit POST turning $target into a standard event.
	 *
	 * @param array  $target Event being edited.
	 * @param string $nonce  Nonce to send.
	 */
	private function post_edit_to_standard( $target, $nonce ) {
		$_POST = array(
			'oli_cj_edit_event' => '1',
			'_wpnonce'          => $nonce,
			'old_hook'          => $target['hook'],
			'old_timestamp'     => (string) $target['timestamp'],
			'old_sig'           => $target['sig'],
			'event_type'        => 'standard',
			'hook'              => $target['hook'],
		);
		$_GET  = array();
	}

	/**
	 * A: the edit nonce of one event is rejected for another event (403).
	 */
	public function test_cross_event_edit_nonce_is_rejected() {
		$this->make_admin( false );
		$url_event = $this->find_event( $this->url_hook );
		$php_event = $this->find_event( $this->php_hook );
		$url_nonce = wp_create_nonce( Oli_CJ_Admin::edit_nonce_action( $url_event['hook'], $url_event['timestamp'], $url_event['sig'] ) );

		$this->post_edit_to_standard( $php_event, $url_nonce );
		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );

		$this->assertSame( array( 'die' => 403 ), $result );
		$this->assertNotFalse( Oli_CJ_PHP_Events::get_code( $this->php_hook ) );
		$this->assertNotNull( $this->find_event( $this->php_hook ) );
	}

	/**
	 * A: a PHP event edited to standard by an admin without the capability is refused,
	 * even with that event's own valid nonce.
	 */
	public function test_php_event_edited_to_standard_without_cap_is_refused() {
		$this->make_admin( false );
		$php_event = $this->find_event( $this->php_hook );
		$nonce     = wp_create_nonce( Oli_CJ_Admin::edit_nonce_action( $php_event['hook'], $php_event['timestamp'], $php_event['sig'] ) );

		$this->post_edit_to_standard( $php_event, $nonce );
		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );

		$this->assertSame( array( 'redirect' => 'php_event_forbidden' ), $result );
		$this->assertStringContainsString( 'ACL_SECRET', (string) Oli_CJ_PHP_Events::get_code( $this->php_hook ) );
		$this->assertTrue( Oli_CJ_Cron_Manager::is_php_event( $this->php_hook ) );
	}

	/**
	 * A (control): the same edit works for an admin with the capability.
	 */
	public function test_php_event_edit_with_cap_is_allowed() {
		$this->make_admin( true );
		$php_event = $this->find_event( $this->php_hook );
		$nonce     = wp_create_nonce( Oli_CJ_Admin::edit_nonce_action( $php_event['hook'], $php_event['timestamp'], $php_event['sig'] ) );

		$this->post_edit_to_standard( $php_event, $nonce );
		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );

		$this->assertSame( array( 'redirect' => 'event_updated' ), $result );
	}

	/**
	 * B: PHP rows and the PHP count are hidden; the edit view is refused.
	 */
	public function test_list_and_edit_view_hidden_without_cap() {
		$this->make_admin( false );

		$visible = wp_list_pluck( Oli_CJ_Admin::visible_events( Oli_CJ_Cron_Manager::get_all_events() ), 'hook' );
		$this->assertNotContains( $this->php_hook, $visible );
		$this->assertContains( $this->url_hook, $visible );

		$php_event = $this->find_event( $this->php_hook );
		$_GET      = array(
			'page'      => 'oli-cron-jobs-edit',
			'hook'      => $php_event['hook'],
			'timestamp' => (string) $php_event['timestamp'],
			'sig'       => $php_event['sig'],
		);

		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'load_edit_event_page' ) );
		$this->assertSame( array( 'die' => 403 ), $result );

		ob_start();
		Oli_CJ_Admin::get_instance()->render_edit_event_page();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'ACL_SECRET', $html );
		$this->assertStringNotContainsString( 'Stored PHP Code', $html );

		$admin_source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'admin/class-oli-cj-list-table.php' );
		$this->assertMatchesRegularExpression( '/function prepare_items\b[\s\S]*visible_events/', $admin_source );
		$this->assertMatchesRegularExpression( '/function get_views\b[\s\S]*visible_events[\s\S]*unset\( \$labels\[\'php\'\] \)/', $admin_source );
	}

	/**
	 * B (control): an admin with the capability sees the PHP event.
	 */
	public function test_list_shows_php_event_with_cap() {
		$this->make_admin( true );
		$visible = wp_list_pluck( Oli_CJ_Admin::visible_events( Oli_CJ_Cron_Manager::get_all_events() ), 'hook' );
		$this->assertContains( $this->php_hook, $visible );
	}

	/**
	 * C: pause and resume of a PHP event are refused without the capability.
	 */
	public function test_pause_and_resume_refused_without_cap() {
		$this->make_admin( false );
		$nonce = wp_create_nonce( 'oli_cj_action' );

		foreach ( array( 'pause_hook', 'resume_hook' ) as $action ) {
			$_POST  = array();
			$_GET   = array(
				'page'     => 'oli-cron-jobs',
				'action'   => $action,
				'hook'     => $this->php_hook,
				'_wpnonce' => $nonce,
			);
			$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
			$this->assertSame( array( 'redirect' => 'php_event_forbidden' ), $result, $action );
			$this->assertFalse( Oli_CJ_Cron_Manager::is_hook_paused( $this->php_hook ), $action );
		}

		// The URL event can still be paused.
		$_GET   = array(
			'page'     => 'oli-cron-jobs',
			'action'   => 'pause_hook',
			'hook'     => $this->url_hook,
			'_wpnonce' => $nonce,
		);
		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
		$this->assertSame( array( 'redirect' => 'hook_paused' ), $result );
	}

	/**
	 * C: run, delete, and export also refuse or omit PHP events without the capability.
	 */
	public function test_run_delete_export_refused_without_cap() {
		$this->make_admin( false );
		$nonce     = wp_create_nonce( 'oli_cj_action' );
		$php_event = $this->find_event( $this->php_hook );

		$_GET   = array(
			'page'     => 'oli-cron-jobs',
			'action'   => 'run_now',
			'hook'     => $this->php_hook,
			'_wpnonce' => $nonce,
		);
		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
		$this->assertSame( array( 'redirect' => 'php_event_forbidden' ), $result );
		$this->assertFalse( get_option( 'oli_cj_acl_ran' ) );

		$_GET   = array(
			'page'      => 'oli-cron-jobs',
			'action'    => 'delete_event',
			'hook'      => $this->php_hook,
			'timestamp' => (string) $php_event['timestamp'],
			'sig'       => $php_event['sig'],
			'_wpnonce'  => $nonce,
		);
		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
		$this->assertSame( array( 'redirect' => 'php_event_forbidden' ), $result );
		$this->assertNotNull( $this->find_event( $this->php_hook ) );

		$this->assertFalse( Oli_CJ_Admin::may_touch_hook( $this->php_hook ) );
		$this->assertTrue( Oli_CJ_Admin::may_touch_hook( $this->url_hook ) );

		$source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'admin/class-oli-cj-admin.php' );
		$this->assertMatchesRegularExpression( '/function handle_export\b[\s\S]*may_touch_hook[\s\S]*function handle_export_csv\b[\s\S]*visible_events/', $source );
		$this->assertMatchesRegularExpression( '/function handle_import\b[\s\S]*may_touch_hook[\s\S]*may_touch_hook[\s\S]*can_manage\(\)/', $source );
	}

	/**
	 * D: the eval() warning is hidden when the feature cannot be used.
	 */
	public function test_eval_warning_hidden_when_feature_unusable() {
		$method = new ReflectionMethod( 'Oli_CJ_Admin', 'render_php_warning' );
		$method->setAccessible( true );
		$render = static function () use ( $method ) {
			ob_start();
			$method->invoke( Oli_CJ_Admin::get_instance() );
			return (string) ob_get_clean();
		};

		$this->make_admin( true );
		$this->assertTrue( Oli_CJ_PHP_Events::can_use_php_events() );
		$this->assertStringContainsString( 'eval()', $render() );

		// DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS go through this filter.
		remove_all_filters( 'oli_cj_is_file_editing_disallowed' );
		add_filter( 'oli_cj_is_file_editing_disallowed', '__return_true' );
		$this->assertFalse( Oli_CJ_PHP_Events::can_use_php_events() );
		$this->assertSame( '', trim( $render() ) );
		remove_all_filters( 'oli_cj_is_file_editing_disallowed' );
		add_filter( 'oli_cj_is_file_editing_disallowed', '__return_false' );

		add_filter( 'oli_cj_php_events_disabled', '__return_true' );
		$this->assertSame( '', trim( $render() ) );
		remove_all_filters( 'oli_cj_php_events_disabled' );

		$this->make_admin( false );
		$this->assertFalse( Oli_CJ_PHP_Events::can_use_php_events() );
		$this->assertSame( '', trim( $render() ) );

		$php_source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'includes/class-oli-cj-php-events.php' );
		$this->assertMatchesRegularExpression( '/function can_use_php_events\b[\s\S]*DISALLOW|function can_use_php_events\b[\s\S]*constants_block_execution/', $php_source );
	}

	/**
	 * E: uninstall removes the capability from every role that has it.
	 */
	public function test_uninstall_removes_capability_from_all_roles() {
		require_once OLI_CJ_PLUGIN_DIR . 'uninstall.php';

		add_role( 'oli_cj_acl_role', 'ACL role', array( 'read' => true ) );
		get_role( 'oli_cj_acl_role' )->add_cap( Oli_CJ_PHP_Events::CAPABILITY );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Oli_CJ_PHP_Events::CAPABILITY ) );

		oli_cj_uninstall_site();
		wp_roles()->for_site();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Oli_CJ_PHP_Events::CAPABILITY ) );
		$this->assertFalse( get_role( 'oli_cj_acl_role' )->has_cap( Oli_CJ_PHP_Events::CAPABILITY ) );

		remove_role( 'oli_cj_acl_role' );
		Oli_CJ_Cron_Manager::reset_option_cache();
		Oli_CJ_Logger::create_table();
	}

	/**
	 * F: bulk delete with an invalid nonce ends with HTTP 403 before output.
	 */
	public function test_bulk_delete_invalid_nonce_is_403() {
		$this->make_admin( true );
		$url_event = $this->find_event( $this->url_hook );
		$_GET      = array(
			'page'     => 'oli-cron-jobs',
			'action'   => 'delete',
			'event'    => array( $url_event['hook'] . '|' . $url_event['timestamp'] . '|' . $url_event['sig'] ),
			'_wpnonce' => 'invalid',
		);
		$_REQUEST  = $_GET;

		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
		$this->assertSame( array( 'die' => 403 ), $result );
		$this->assertNotNull( $this->find_event( $this->url_hook ) );

		$_GET['_wpnonce']     = wp_create_nonce( 'bulk-cronevents' );
		$_REQUEST['_wpnonce'] = $_GET['_wpnonce'];
		$result               = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
		$this->assertSame( array( 'redirect' => 'event_deleted' ), $result );
		$this->assertNull( $this->find_event( $this->url_hook ) );
	}

	/**
	 * F: bulk delete skips PHP events for an admin without the capability.
	 */
	public function test_bulk_delete_skips_php_event_without_cap() {
		$this->make_admin( false );
		$php_event = $this->find_event( $this->php_hook );
		$_GET      = array(
			'page'     => 'oli-cron-jobs',
			'action'   => 'delete',
			'event'    => array( $php_event['hook'] . '|' . $php_event['timestamp'] . '|' . $php_event['sig'] ),
			'_wpnonce' => wp_create_nonce( 'bulk-cronevents' ),
		);
		$_REQUEST  = $_GET;

		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
		$this->assertSame( array( 'redirect' => 'php_event_forbidden' ), $result );
		$this->assertNotNull( $this->find_event( $this->php_hook ) );
	}

	/**
	 * Create: adding a PHP event, or a standard event on an existing PHP hook, is refused without the capability.
	 */
	public function test_create_php_event_refused_without_cap() {
		$this->make_admin( false );
		$nonce = wp_create_nonce( 'oli_cj_add_event' );

		foreach ( array( 'php', 'standard' ) as $type ) {
			$_GET   = array();
			$_POST  = array(
				'oli_cj_add_event' => '1',
				'_wpnonce'         => $nonce,
				'event_type'       => $type,
				'hook'             => $this->php_hook,
				'php_code'         => 'update_option( "oli_cj_acl_ran", 2 );',
			);
			$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
			$this->assertSame( array( 'redirect' => 'php_event_forbidden' ), $result, $type );
		}

		$this->assertStringContainsString( 'ACL_SECRET', (string) Oli_CJ_PHP_Events::get_code( $this->php_hook ) );
	}

	/**
	 * Import data used by the import tests.
	 *
	 * @return array
	 */
	private function import_payload() {
		return array(
			'events'       => array(
				array(
					'hook'      => $this->php_hook,
					'type'      => 'php',
					'php_code'  => '/* IMPORTED */',
					'timestamp' => time() + 7200,
				),
				array(
					'hook'      => $this->php_hook,
					'type'      => 'standard',
					'timestamp' => time() + 7200,
				),
				array(
					'hook'      => $this->url_hook . '_std',
					'type'      => 'standard',
					'timestamp' => time() + 7200,
				),
			),
			'paused_hooks' => array( $this->php_hook ),
		);
	}

	/**
	 * Import: missing or invalid nonce, or no manage_options, ends with 403 on admin_init.
	 */
	public function test_import_invalid_nonce_or_no_cap_is_403() {
		$this->make_admin( true );

		foreach ( array( null, 'invalid' ) as $nonce ) {
			$_GET  = array( 'page' => 'oli-cron-jobs-settings' );
			$_POST = array( 'oli_cj_import' => '1' );
			if ( null !== $nonce ) {
				$_POST['_wpnonce'] = $nonce;
			}
			$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
			$this->assertSame( array( 'die' => 403 ), $result, (string) $nonce );
		}

		$login     = 'oli_cj_acl_ed_' . wp_generate_password( 6, false, false );
		$editor_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 16 ),
				'user_email' => $login . '@example.com',
				'role'       => 'editor',
			)
		);
		$this->created_users[] = $editor_id;
		wp_set_current_user( $editor_id );
		$_POST  = array(
			'oli_cj_import' => '1',
			'_wpnonce'      => wp_create_nonce( 'oli_cj_import' ),
		);
		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
		$this->assertSame( array( 'die' => 403 ), $result );
		$this->assertFalse( Oli_CJ_Cron_Manager::is_hook_paused( $this->php_hook ) );
	}

	/**
	 * Import: a valid nonce is handled on admin_init and redirects (no upload here, so import_error).
	 */
	public function test_import_valid_nonce_redirects() {
		$this->make_admin( true );
		$_GET  = array( 'page' => 'oli-cron-jobs-settings' );
		$_POST = array(
			'oli_cj_import' => '1',
			'_wpnonce'      => wp_create_nonce( 'oli_cj_import' ),
		);

		$result = $this->run_handler( array( Oli_CJ_Admin::get_instance(), 'handle_actions' ) );
		$this->assertSame( array( 'redirect' => 'import_error' ), $result );

		$source = file_get_contents( OLI_CJ_PLUGIN_DIR . 'admin/class-oli-cj-admin.php' );
		$this->assertDoesNotMatchRegularExpression( '/function render_settings_page\b[^}]*handle_import/', $source );
	}

	/**
	 * Import: PHP rows and PHP pause state are skipped without the capability.
	 */
	public function test_import_skips_php_without_cap() {
		$this->make_admin( false );
		$count = Oli_CJ_Admin::import_data( $this->import_payload() );

		$this->assertSame( 1, $count );
		$this->assertStringContainsString( 'ACL_SECRET', (string) Oli_CJ_PHP_Events::get_code( $this->php_hook ) );
		$this->assertFalse( Oli_CJ_Cron_Manager::is_hook_paused( $this->php_hook ) );
		$this->assertNotNull( $this->find_event( $this->url_hook . '_std' ) );
		Oli_CJ_Cron_Manager::delete_all_hook_events( $this->url_hook . '_std' );
	}

	/**
	 * Import (control): with the capability, PHP rows and pause state are imported.
	 */
	public function test_import_php_with_cap() {
		$this->make_admin( true );
		Oli_CJ_Admin::import_data( $this->import_payload() );

		$this->assertStringContainsString( 'IMPORTED', (string) Oli_CJ_PHP_Events::get_code( $this->php_hook ) );
		$this->assertTrue( Oli_CJ_Cron_Manager::is_hook_paused( $this->php_hook ) );
		Oli_CJ_Cron_Manager::delete_all_hook_events( $this->url_hook . '_std' );
	}
}
