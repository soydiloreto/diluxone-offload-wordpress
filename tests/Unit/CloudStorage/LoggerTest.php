<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Logger;

/**
 * Unit tests for Logger — the plugin's single error_log() sink.
 *
 * Two behaviours carry weight here. The level gate: errors and warnings must
 * always reach the log, info/debug only when verbose logging is on, because
 * a production site with verbose off must not fill its log with a line per
 * request. And the dedupe window: the stream wrapper can log the same message
 * hundreds of times in one page load, and without dedupe that is the log.
 *
 * The sink is captured by pointing PHP's error_log ini at a temp file, so
 * assertions read exactly what a real site would have written.
 */
class LoggerTest extends TestCase {

	private string $sink;
	private string $previous_sink;

	protected function setUp(): void {
		parent::setUp();
		$this->sink          = tempnam( sys_get_temp_dir(), 'dlx-log-' );
		$this->previous_sink = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->sink );
		$GLOBALS['_test_wp_options'] = array();
		// Known starting state, regardless of what a previous test left behind.
		Logger::set_verbose_logging( false );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->previous_sink );
		@unlink( $this->sink );
		unset( $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	private function written(): string {
		return (string) file_get_contents( $this->sink );
	}

	/** Every message is unique so the dedupe window never crosses tests. */
	private function msg( string $tag ): string {
		return '[LoggerTest] ' . $tag . ' ' . uniqid( '', true );
	}

	// ── Level gate ──────────────────────────────────────────

	public function test_error_is_logged_even_when_verbose_is_off(): void {
		$m = $this->msg( 'error' );
		Logger::error( $m );
		$this->assertStringContainsString( $m, $this->written() );
	}

	public function test_warning_is_logged_even_when_verbose_is_off(): void {
		$m = $this->msg( 'warning' );
		Logger::warning( $m );
		$this->assertStringContainsString( $m, $this->written() );
	}

	public function test_info_is_dropped_when_verbose_is_off(): void {
		$m = $this->msg( 'info' );
		Logger::info( $m );
		$this->assertStringNotContainsString( $m, $this->written() );
	}

	public function test_debug_is_dropped_when_verbose_is_off(): void {
		$m = $this->msg( 'debug' );
		Logger::debug( $m );
		$this->assertStringNotContainsString( $m, $this->written() );
	}

	public function test_info_is_logged_when_verbose_is_on(): void {
		Logger::set_verbose_logging( true );
		$m = $this->msg( 'info-verbose' );
		Logger::info( $m );
		$this->assertStringContainsString( $m, $this->written() );
	}

	public function test_debug_is_logged_when_verbose_is_on(): void {
		Logger::set_verbose_logging( true );
		$m = $this->msg( 'debug-verbose' );
		Logger::debug( $m );
		$this->assertStringContainsString( $m, $this->written() );
	}

	public function test_force_bypasses_the_level_gate(): void {
		$m = $this->msg( 'forced' );
		Logger::log( $m, 'debug', true );
		$this->assertStringContainsString( $m, $this->written() );
	}

	// ── Verbose flag and its sources ────────────────────────

	public function test_set_verbose_logging_is_reported_back(): void {
		Logger::set_verbose_logging( true );
		$this->assertTrue( Logger::is_verbose_logging() );
		Logger::set_verbose_logging( false );
		$this->assertFalse( Logger::is_verbose_logging() );
	}

	public function test_refresh_reads_the_debug_toggle_from_the_saved_config(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'enable_debug_logging' => true );
		Logger::refresh();
		$this->assertTrue( Logger::is_verbose_logging() );
	}

	public function test_refresh_with_the_toggle_off_disables_verbose(): void {
		Logger::set_verbose_logging( true );
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'enable_debug_logging' => false );
		Logger::refresh();
		$this->assertFalse( Logger::is_verbose_logging() );
	}

	public function test_refresh_with_no_config_leaves_verbose_off(): void {
		Logger::refresh();
		$this->assertFalse( Logger::is_verbose_logging() );
	}

	public function test_a_non_array_config_value_is_ignored(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = 'corrupt';
		Logger::refresh();
		$this->assertFalse( Logger::is_verbose_logging() );
	}

	// ── Dedupe window ───────────────────────────────────────

	public function test_the_same_message_is_written_once_within_the_window(): void {
		$m = $this->msg( 'dup' );
		Logger::error( $m );
		Logger::error( $m );
		Logger::error( $m );
		$this->assertSame( 1, substr_count( $this->written(), $m ) );
	}

	public function test_different_levels_of_the_same_text_are_not_deduped_together(): void {
		$m = $this->msg( 'level-key' );
		Logger::error( $m );
		Logger::warning( $m );
		$this->assertSame( 2, substr_count( $this->written(), $m ), 'the dedupe key includes the level' );
	}

	public function test_different_messages_are_all_written(): void {
		$a = $this->msg( 'a' );
		$b = $this->msg( 'b' );
		Logger::error( $a );
		Logger::error( $b );
		$out = $this->written();
		$this->assertStringContainsString( $a, $out );
		$this->assertStringContainsString( $b, $out );
	}
}
