<?php
/**
 * Event CRUD and run-now tests.
 *
 * @package Oli_Cron_Jobs
 */

/**
 * Tests add, edit, delete, and run-now for cron events.
 */
class Oli_CJ_Event_CRUD_Test extends PHPUnit\Framework\TestCase {

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

		Oli_CJ_Logger::create_table();
		$this->hook = 'oli_cj_test_event_' . wp_generate_password( 8, false, false );
	}

	/**
	 * Remove scheduled test events.
	 */
	protected function tearDown(): void {
		if ( class_exists( 'Oli_CJ_Cron_Manager' ) && ! empty( $this->hook ) ) {
			Oli_CJ_Cron_Manager::delete_all_hook_events( $this->hook );
		}
	}

	/**
	 * Add a single event and read it back.
	 */
	public function test_add_and_get_event() {
		$timestamp = time() + HOUR_IN_SECONDS;
		$args      = array( 'hello', 2 );
		$result    = Oli_CJ_Cron_Manager::add_event( $this->hook, $timestamp, $args, false );

		$this->assertNotFalse( $result );

		$events = array_values(
			array_filter(
				Oli_CJ_Cron_Manager::get_all_events(),
				function ( $event ) {
					return $event['hook'] === $this->hook;
				}
			)
		);

		$this->assertCount( 1, $events );
		$this->assertSame( $args, $events[0]['args'] );
		$this->assertFalse( $events[0]['schedule'] );

		$loaded = Oli_CJ_Cron_Manager::get_event( $this->hook, $events[0]['timestamp'], $events[0]['sig'] );
		$this->assertIsArray( $loaded );
		$this->assertSame( $this->hook, $loaded['hook'] );
	}

	/**
	 * Edit replaces the old event with a new hook, time, and schedule.
	 */
	public function test_edit_event() {
		$timestamp = time() + HOUR_IN_SECONDS;
		Oli_CJ_Cron_Manager::add_event( $this->hook, $timestamp, array( 'old' ), false );

		$original = $this->first_event( $this->hook );
		$this->assertNotEmpty( $original );

		$new_hook      = $this->hook . '_edited';
		$new_timestamp = $timestamp + HOUR_IN_SECONDS;
		Oli_CJ_Cron_Manager::edit_event(
			$original['hook'],
			$original['timestamp'],
			$original['sig'],
			$new_hook,
			$new_timestamp,
			array( 'new' ),
			'hourly'
		);

		$this->assertFalse( $this->first_event( $this->hook ) );

		$edited = $this->first_event( $new_hook );
		$this->assertIsArray( $edited );
		$this->assertSame( array( 'new' ), $edited['args'] );
		$this->assertSame( 'hourly', $edited['schedule'] );

		Oli_CJ_Cron_Manager::delete_all_hook_events( $new_hook );
	}

	/**
	 * Delete removes a single scheduled instance.
	 */
	public function test_delete_event() {
		$timestamp = time() + HOUR_IN_SECONDS;
		Oli_CJ_Cron_Manager::add_event( $this->hook, $timestamp, array(), false );

		$event = $this->first_event( $this->hook );
		$this->assertIsArray( $event );

		$this->assertTrue( Oli_CJ_Cron_Manager::delete_event( $event['hook'], $event['timestamp'], $event['sig'] ) );
		$this->assertFalse( $this->first_event( $this->hook ) );
	}

	/**
	 * Run now fires the hook callbacks.
	 */
	public function test_run_event_now() {
		$ran = false;

		$callback = static function () use ( &$ran ) {
			$ran = true;
		};

		add_action( $this->hook, $callback );
		$result = Oli_CJ_Cron_Manager::run_event_now( $this->hook, array() );
		remove_action( $this->hook, $callback );

		$this->assertTrue( $result );
		$this->assertTrue( $ran, 'Expected run-now to fire the hook callback.' );
	}

	/**
	 * Pause blocks run-now.
	 */
	public function test_pause_blocks_run_now() {
		Oli_CJ_Cron_Manager::pause_hook( $this->hook );
		$ran = false;

		add_action(
			$this->hook,
			static function () use ( &$ran ) {
				$ran = true;
			}
		);

		$result = Oli_CJ_Cron_Manager::run_event_now( $this->hook, array() );
		Oli_CJ_Cron_Manager::resume_hook( $this->hook );

		$this->assertFalse( $result );
		$this->assertFalse( $ran );
	}

	/**
	 * CSV export includes the expected columns.
	 */
	public function test_csv_rows_include_site_and_utc_times() {
		$timestamp = time() + HOUR_IN_SECONDS;
		Oli_CJ_Cron_Manager::add_event( $this->hook, $timestamp, array( 'a' ), false );

		$events = array_values(
			array_filter(
				Oli_CJ_Cron_Manager::get_all_events(),
				function ( $event ) {
					return $event['hook'] === $this->hook;
				}
			)
		);

		$rows = Oli_CJ_Cron_Manager::get_csv_rows( $events );
		$this->assertNotEmpty( $rows );
		$this->assertArrayHasKey( 'next_run_site', $rows[0] );
		$this->assertArrayHasKey( 'next_run_utc', $rows[0] );
		$this->assertSame( $this->hook, $rows[0]['hook'] );
	}

	/**
	 * CSV cells that look like spreadsheet formulas are prefixed with a quote.
	 */
	public function test_csv_escapes_formula_injection() {
		$this->assertSame( "'=1+1", Oli_CJ_Cron_Manager::escape_csv_cell( '=1+1' ) );
		$this->assertSame( "'+cmd", Oli_CJ_Cron_Manager::escape_csv_cell( '+cmd' ) );
		$this->assertSame( "'-1+2", Oli_CJ_Cron_Manager::escape_csv_cell( '-1+2' ) );
		$this->assertSame( "'@SUM(A1)", Oli_CJ_Cron_Manager::escape_csv_cell( '@SUM(A1)' ) );
		$this->assertSame( "'\tTAB", Oli_CJ_Cron_Manager::escape_csv_cell( "\tTAB" ) );
		$this->assertSame( "'\rCR", Oli_CJ_Cron_Manager::escape_csv_cell( "\rCR" ) );
		$this->assertSame( 'safe-hook', Oli_CJ_Cron_Manager::escape_csv_cell( 'safe-hook' ) );
		$this->assertSame( '', Oli_CJ_Cron_Manager::escape_csv_cell( '' ) );
		$this->assertSame( "'=1+1", Oli_CJ_Cron_Manager::escape_csv_cell( "'=1+1" ) );

		$events = array(
			array(
				'hook'      => '=HYPERLINK("http://evil.example")',
				'args'      => array( '+payload' ),
				'timestamp' => time(),
				'schedule'  => false,
			),
		);

		$rows = Oli_CJ_Cron_Manager::get_csv_rows( $events );
		$this->assertNotEmpty( $rows );
		$this->assertSame( "'=HYPERLINK(\"http://evil.example\")", $rows[0]['hook'] );
	}

	/**
	 * Find the first scheduled event for a hook.
	 *
	 * @param string $hook Hook name.
	 * @return array|false
	 */
	private function first_event( $hook ) {
		foreach ( Oli_CJ_Cron_Manager::get_all_events() as $event ) {
			if ( $event['hook'] === $hook ) {
				return $event;
			}
		}

		return false;
	}
}
