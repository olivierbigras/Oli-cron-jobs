<?php
/**
 * 0.2.10: every request read is gated by a nonce and a capability.
 *
 * @package Oli_Cron_Jobs
 */

/**
 * Nonce-first request reads.
 */
class Oli_CJ_Request_Nonces_Test extends PHPUnit\Framework\TestCase {

	/**
	 * Users created by the test.
	 *
	 * @var int[]
	 */
	private $created_users = array();

	/**
	 * Skip without WordPress; start each test with empty superglobals.
	 */
	protected function setUp(): void {
		if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Oli_CJ_Admin' ) ) {
			$this->markTestSkipped( 'WordPress is not loaded. Set WP_ROOT to a working install.' );
		}

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Remove test users and reset the request.
	 */
	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->created_users as $id ) {
			wp_delete_user( $id );
		}
		$this->created_users = array();
		wp_set_current_user( 0 );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Log in a fresh user with a role.
	 *
	 * @param string $role Role.
	 */
	private function login( $role ) {
		$login = 'oli_cj_rn_' . wp_generate_password( 6, false, false );
		$id    = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 16 ),
				'user_email' => $login . '@example.com',
				'role'       => $role,
			)
		);
		$this->created_users[] = $id;
		wp_set_current_user( $id );
	}

	/**
	 * List filters are ignored without the list nonce or without manage_options.
	 */
	public function test_list_filters_need_nonce_and_capability() {
		$this->login( 'administrator' );
		$_REQUEST = array(
			'type_filter' => 'core',
			's'           => 'wp_version',
			'orderby'     => 'hook',
		);
		$this->assertSame( '', Oli_CJ_Admin::list_filters()['type_filter'] );

		$_REQUEST['oli_cj_list_nonce'] = 'forged';
		$this->assertSame( '', Oli_CJ_Admin::list_filters()['s'] );

		$_REQUEST['oli_cj_list_nonce'] = wp_create_nonce( 'oli_cj_list' );
		$filters                       = Oli_CJ_Admin::list_filters();
		$this->assertSame( 'core', $filters['type_filter'] );
		$this->assertSame( 'wp_version', $filters['s'] );
		$this->assertSame( 'hook', $filters['orderby'] );

		$this->login( 'editor' );
		$_REQUEST['oli_cj_list_nonce'] = wp_create_nonce( 'oli_cj_list' );
		$this->assertSame( '', Oli_CJ_Admin::list_filters()['type_filter'] );
	}

	/**
	 * The edit screen only trusts a link signed for that exact event.
	 */
	public function test_edit_link_is_bound_to_the_event() {
		$this->login( 'administrator' );
		$url = Oli_CJ_Admin::edit_link_url( 'oli_cj_rn_hook', 1893456000, 'abc' );
		$this->assertStringContainsString( '_wpnonce=', $url );

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET = $query;
		$this->assertSame(
			array(
				'hook'      => 'oli_cj_rn_hook',
				'timestamp' => 1893456000,
				'sig'       => 'abc',
			),
			Oli_CJ_Admin::verified_edit_link()
		);

		$_GET['hook'] = 'another_hook';
		$this->assertNull( Oli_CJ_Admin::verified_edit_link() );

		unset( $_GET['_wpnonce'] );
		$_GET['hook'] = 'oli_cj_rn_hook';
		$this->assertNull( Oli_CJ_Admin::verified_edit_link() );
	}

	/**
	 * Status messages render only with the notice nonce.
	 */
	public function test_messages_need_notice_nonce() {
		$this->login( 'administrator' );
		$render = new ReflectionMethod( 'Oli_CJ_Admin', 'render_messages' );
		$render->setAccessible( true );

		$_GET = array( 'oli_cj_message' => 'event_added' );
		ob_start();
		$render->invoke( Oli_CJ_Admin::get_instance() );
		$this->assertSame( '', trim( ob_get_clean() ) );

		$_GET['oli_cj_notice_nonce'] = wp_create_nonce( 'oli_cj_notice' );
		ob_start();
		$render->invoke( Oli_CJ_Admin::get_instance() );
		$this->assertStringContainsString( 'Cron event added successfully.', ob_get_clean() );
	}

	/**
	 * Log queries are fully prepared and still filter by hook and type.
	 */
	public function test_log_queries_filter_with_fixed_sql() {
		Oli_CJ_Logger::create_table();
		Oli_CJ_Logger::log_event( 'oli_cj_rn_a', array(), 'manual' );
		Oli_CJ_Logger::log_event( 'oli_cj_rn_b', array(), 'standard' );

		$this->assertSame( 1, Oli_CJ_Logger::get_log_count( array( 'hook' => 'oli_cj_rn_a' ) ) );
		$this->assertSame( 1, Oli_CJ_Logger::get_log_count( array( 'hook' => 'oli_cj_rn_b', 'event_type' => 'standard' ) ) );
		$this->assertSame( 0, Oli_CJ_Logger::get_log_count( array( 'hook' => 'oli_cj_rn_b', 'event_type' => 'manual' ) ) );
		$this->assertGreaterThanOrEqual( 2, Oli_CJ_Logger::get_log_count() );

		$rows = Oli_CJ_Logger::get_logs(
			array(
				'hook'  => 'oli_cj_rn_a',
				'order' => 'ASC',
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( 'oli_cj_rn_a', $rows[0]->hook );

		Oli_CJ_Logger::delete_hook_logs( 'oli_cj_rn_a' );
		Oli_CJ_Logger::delete_hook_logs( 'oli_cj_rn_b' );
	}

	/**
	 * Shipped code has no phpcs:ignore on nonce, escaping, input or prepared SQL.
	 */
	public function test_no_security_ignores_left() {
		$files = array_merge( glob( OLI_CJ_PLUGIN_DIR . 'admin/*.php' ), glob( OLI_CJ_PLUGIN_DIR . 'includes/*.php' ), array( OLI_CJ_PLUGIN_DIR . 'uninstall.php' ) );
		foreach ( $files as $file ) {
			$this->assertSame(
				0,
				preg_match( '/phpcs:(ignore|disable)[^\n]*(NonceVerification|EscapeOutput|ValidatedSanitizedInput|PreparedSQL|UnescapedDBParameter)/', (string) file_get_contents( $file ) ),
				basename( $file )
			);
		}
	}
}
