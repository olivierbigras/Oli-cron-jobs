<?php
/**
 * Log display translation tests.
 *
 * @package Oli_Cron_Jobs
 */

/**
 * Tests that stored log strings stay English and display maps known messages.
 */
class Oli_CJ_Log_I18n_Test extends PHPUnit\Framework\TestCase {

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
		if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Oli_CJ_Logger' ) ) {
			$this->markTestSkipped( 'WordPress is not loaded. Set WP_ROOT to a working install.' );
		}

		Oli_CJ_Logger::create_table();
		$this->hook = 'oli_cj_test_i18n_' . wp_generate_password( 8, false, false );
	}

	/**
	 * Gettext filter callback registered by a test.
	 *
	 * @var callable|null
	 */
	private $gettext_filter = null;

	/**
	 * Remove scheduled test events and gettext filters.
	 */
	protected function tearDown(): void {
		if ( null !== $this->gettext_filter ) {
			remove_filter( 'gettext', $this->gettext_filter, 10 );
			$this->gettext_filter = null;
		}

		if ( class_exists( 'Oli_CJ_Cron_Manager' ) && ! empty( $this->hook ) ) {
			Oli_CJ_Cron_Manager::delete_all_hook_events( $this->hook );
			Oli_CJ_Logger::delete_hook_logs( $this->hook );
		}
	}

	/**
	 * Known stored English strings are passed through __() at display time.
	 */
	public function test_known_log_messages_are_translated_at_display() {
		$known = array(
			'Hook is paused',
			'PHP events are disabled',
			'PHP event failed the integrity check',
			'URL event failed the integrity check',
			'PHP events cannot be executed while file editing is disabled.',
		);

		$this->gettext_filter = static function ( $translation, $text, $domain ) {
			if ( 'oli-cron-jobs' === $domain && 'Hook is paused' === $text ) {
				return 'FR:Hook is paused';
			}

			return $translation;
		};
		add_filter( 'gettext', $this->gettext_filter, 10, 3 );

		$this->assertSame( 'FR:Hook is paused', Oli_CJ_Logger::translate_error_message( 'Hook is paused' ) );

		foreach ( $known as $message ) {
			$this->assertNotSame( '', Oli_CJ_Logger::translate_error_message( $message ) );
		}
	}

	/**
	 * Unknown stored text is returned unchanged.
	 */
	public function test_unknown_log_messages_fall_back_to_stored_text() {
		$this->assertSame( 'Unknown boom', Oli_CJ_Logger::translate_error_message( 'Unknown boom' ) );
		$this->assertSame( '', Oli_CJ_Logger::translate_error_message( '' ) );
	}

	/**
	 * Event type slugs are translated for display; unknown slugs stay as stored.
	 */
	public function test_event_types_are_translated_at_display() {
		$this->gettext_filter = static function ( $translation, $text, $domain ) {
			if ( 'oli-cron-jobs' === $domain && 'Blocked' === $text ) {
				return 'FR:Blocked';
			}

			return $translation;
		};
		add_filter( 'gettext', $this->gettext_filter, 10, 3 );

		$this->assertSame( 'FR:Blocked', Oli_CJ_Logger::translate_event_type( 'blocked' ) );
		$this->assertSame( 'custom-type', Oli_CJ_Logger::translate_event_type( 'custom-type' ) );
	}

	/**
	 * Logging a paused hook still stores the English source string.
	 */
	public function test_paused_hook_log_stores_english_source() {
		Oli_CJ_Cron_Manager::pause_hook( $this->hook );
		Oli_CJ_Cron_Manager::run_event_now( $this->hook, array() );

		$logs = Oli_CJ_Logger::get_logs(
			array(
				'hook'  => $this->hook,
				'limit' => 5,
			)
		);

		$found = false;
		foreach ( $logs as $log ) {
			if ( $this->hook === $log->hook && 'Hook is paused' === $log->error_message ) {
				$found = true;
				$this->assertSame( 'Hook is paused', $log->error_message );
				$this->assertSame( 'Hook is paused', Oli_CJ_Logger::translate_error_message( $log->error_message ) );
				break;
			}
		}

		$this->assertTrue( $found, 'Expected a blocked log row with the English source string.' );
		Oli_CJ_Cron_Manager::resume_hook( $this->hook );
	}
}
