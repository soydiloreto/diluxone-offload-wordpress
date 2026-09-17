<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

/**
 * The stream wrapper when things go wrong: no provider configured, the
 * provider throwing, downloads failing, the health fallback that cannot
 * write, stat() on missing blobs, oversized cache entries.
 */
class StreamWrapperEdgeCasesTest extends TestCase {

	private const P = 'diluxoneoffload';

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/dlx-wp-content' );
		}
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		$GLOBALS['_test_wp_hooks']      = array();
		unset( $GLOBALS['_test_wp_http'] );

		// The default WordPress layout, stated rather than assumed: the code
		// asks wp_upload_dir() where uploads are, so the test has to say.
		$GLOBALS['_test_wp_upload_dir'] = WP_CONTENT_DIR . '/uploads';
		$this->configure();
		CloudStreamWrapper::clear_stat_cache();
		CloudStreamWrapper::clear_file_cache();
		$this->resetClient();
		CloudStreamWrapper::register();
	}

	protected function tearDown(): void {
		CloudStreamWrapper::unregister();
		$this->resetClient();
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'], $GLOBALS['_test_wp_hooks'] );
		parent::tearDown();
	}

	private function configure(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config']       = array(
			'cloud_provider'  => 'azure',
			'provider_config' => array( 'storage_account' => 'edgeacct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ),
		);
		$GLOBALS['_test_wp_options']['diluxone_offload_plugin_state'] = PluginState::SYNCED;
	}

	private function unconfigure(): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_config'] = array( 'cloud_provider' => '', 'provider_config' => array() );
		$this->resetClient();
	}

	/** The wrapper memoises its client; each test wants a fresh one. */
	private function resetClient(): void {
		$p = new \ReflectionProperty( CloudStreamWrapper::class, 'cloud_client' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	private static function reply( int $code, string $body = '', array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => $headers );
	}

	private function unhealthy( int $failures = 3 ): void {
		$GLOBALS['_test_wp_options']['diluxone_offload_connection_health'] = array(
			'status'               => 'unhealthy',
			'consecutive_failures' => $failures,
			'error_code'           => '403',
			'error_message'        => 'x',
			'last_check'           => time(),
			'last_success'         => 0,
			'error_source'         => 'azure',
		);
	}

	// ── no provider ─────────────────────────────────────────

	public function test_nothing_works_without_a_provider(): void {
		$this->unconfigure();
		$this->assertFalse( @fopen( self::P . '://uploads/a.txt', 'w' ) );
		$this->assertFalse( @fopen( self::P . '://uploads/a.txt', 'r' ) );
		$this->assertFalse( @unlink( self::P . '://uploads/a.txt' ) );
		$this->assertFalse( @file_exists( self::P . '://uploads/a.txt' ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'] );
	}

	public function test_filter_upload_dir_without_a_provider_still_rewrites_paths(): void {
		$this->unconfigure();
		$out = CloudStreamWrapper::filter_upload_dir( array( 'path' => WP_CONTENT_DIR . '/uploads/2026', 'basedir' => WP_CONTENT_DIR . '/uploads', 'url' => 'http://x/u', 'baseurl' => 'http://x/u' ) );
		$this->assertSame( self::P . '://uploads/2026', $out['path'] );
		$this->assertSame( 'http://x/u', $out['url'], 'URL untouched without a provider' );
	}

	public function test_filter_upload_dir_skips_an_upgrade_basedir(): void {
		$in = array( 'path' => '/x/wp-content/uploads', 'basedir' => '/x/wp-content/upgrade/tmp', 'url' => 'u', 'baseurl' => 'u' );
		$this->assertSame( $in, CloudStreamWrapper::filter_upload_dir( $in ) );
	}

	// ── reads ───────────────────────────────────────────────

	public function test_a_failed_download_makes_open_fail(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 500 );
		$this->assertFalse( @fopen( self::P . '://uploads/missing.txt', 'r' ) );
	}

	public function test_a_transport_error_on_read_is_not_fatal(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$this->assertFalse( @fopen( self::P . '://uploads/missing.txt', 'r' ) );
	}

	public function test_append_to_a_blob_that_cannot_be_downloaded_starts_empty(): void {
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? self::reply( 404 ) : self::reply( 201 );
		$fh = fopen( self::P . '://uploads/app.txt', 'a' );
		fwrite( $fh, 'new' );
		fclose( $fh );
		$puts = array_values( array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => $r['method'] === 'PUT' ) );
		$this->assertSame( 'new', $puts[0]['args']['body'] );
	}

	public function test_append_survives_a_transport_error_on_the_download(): void {
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? new \WP_Error( 'x', 'reset' ) : self::reply( 201 );
		$fh = fopen( self::P . '://uploads/app.txt', 'a' );
		fwrite( $fh, 'new' );
		$this->assertTrue( fclose( $fh ) );
	}

	public function test_flush_on_a_read_handle_is_refused(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, 'abc' );
		$fh = fopen( self::P . '://uploads/r.txt', 'r' );
		$this->assertFalse( fflush( $fh ) );
		fclose( $fh );
	}

	public function test_an_unknown_mode_is_refused(): void {
		$this->assertFalse( @fopen( self::P . '://uploads/x.txt', 'x' ) );
	}

	// ── writes ──────────────────────────────────────────────

	public function test_writing_nothing_uploads_nothing(): void {
		$fh = fopen( self::P . '://uploads/empty.txt', 'w' );
		$this->assertTrue( fclose( $fh ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'] );
	}

	public function test_a_rejected_upload_records_the_failure_with_its_code(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 403 );
		$fh = fopen( self::P . '://uploads/no.txt', 'w' );
		fwrite( $fh, 'x' );
		$this->assertFalse( @fflush( $fh ) );
		fclose( $fh );
		$health = ConfigManager::get_connection_health();
		$this->assertSame( '403', $health['error_code'] );
		$this->assertSame( 'upload', $health['error_source'] );
	}

	public function test_a_transport_error_on_upload_is_recorded_as_an_exception(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$fh = fopen( self::P . '://uploads/no.txt', 'w' );
		fwrite( $fh, 'x' );
		$this->assertFalse( @fflush( $fh ) );
		fclose( $fh );
		$this->assertSame( 'unhealthy', ConfigManager::get_connection_health()['status'] );
	}

	public function test_a_successful_upload_heals_an_unhealthy_connection(): void {
		$this->unhealthy( 1 ); // below the fallback threshold
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$fh = fopen( self::P . '://uploads/ok.txt', 'w' );
		fwrite( $fh, 'x' );
		fclose( $fh );
		$this->assertSame( 'healthy', ConfigManager::get_connection_health()['status'] );
	}

	public function test_a_write_is_refused_at_open_while_the_connection_is_down(): void {
		$this->unhealthy(); // 3 failures, checked just now: no re-probe yet
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$this->assertFalse( @fopen( self::P . '://uploads/fb/new.txt', 'w' ) );
		$this->assertSame( array(), $GLOBALS['_test_wp_http_log'], 'nothing went to the cloud' );
		$this->assertFileDoesNotExist( WP_CONTENT_DIR . '/uploads/fb/new.txt', 'and nothing landed on disk' );
	}

	public function test_writes_reopen_once_a_fresh_health_check_passes(): void {
		$this->unhealthy(); // 3 failures...
		$GLOBALS['_test_wp_options']['diluxone_offload_connection_health']['last_check'] = time() - 400; // ...but last checked long enough ago to re-probe
		$GLOBALS['_test_wp_http'] = fn( string $method ) => 'GET' === $method ? self::reply( 200 ) : self::reply( 201 );
		$fh = fopen( self::P . '://uploads/back/x.txt', 'w' );
		$this->assertNotFalse( $fh, 'the probe passed, so the write is allowed' );
		fwrite( $fh, 'x' );
		$this->assertTrue( @fflush( $fh ) );
		fclose( $fh );
		$this->assertSame( 'healthy', ConfigManager::get_connection_health()['status'] );
		$methods = array_column( $GLOBALS['_test_wp_http_log'], 'method' );
		$this->assertContains( 'GET', $methods, 'one probe' );
		$this->assertContains( 'PUT', $methods, 'then the upload' );
	}

	// ── stat ────────────────────────────────────────────────

	public function test_stat_on_a_missing_blob_warns_and_fails(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 404 );
		$this->assertFalse( @stat( self::P . '://uploads/missing.jpg' ) );
	}

	public function test_stat_survives_a_transport_error(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$this->assertFalse( @file_exists( self::P . '://uploads/missing.jpg' ) );
	}

	public function test_stat_on_an_existing_blob_reports_a_file(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 200, '', array( 'content-length' => '5' ) );
		$this->assertTrue( is_file( self::P . '://uploads/there.jpg' ) );
		$this->assertFalse( is_dir( self::P . '://uploads/there.jpg' ) );
	}

	// ── cache ───────────────────────────────────────────────

	public function test_oversized_content_is_not_cached(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$big = str_repeat( 'x', CloudStreamWrapper::CACHE_MAX_BYTES + 1 );
		$fh  = fopen( self::P . '://uploads/big.bin', 'w' );
		fwrite( $fh, $big );
		fclose( $fh );
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? self::reply( 200, 'from-cloud' ) : self::reply( 200 );
		$this->assertSame( 'from-cloud', file_get_contents( self::P . '://uploads/big.bin' ), 'a second read goes to the cloud' );
	}

	public function test_read_write_mode_uploads_the_edited_blob_on_close(): void {
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? self::reply( 200, 'hello world' ) : self::reply( 201 );
		$fh = fopen( self::P . '://uploads/rw.txt', 'r+' );
		$this->assertSame( 'hello', fread( $fh, 5 ) );
		fseek( $fh, 6 );
		fwrite( $fh, 'there' );
		$this->assertTrue( fflush( $fh ) );
		fclose( $fh );
		$puts = array_values( array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => $r['method'] === 'PUT' ) );
		$this->assertCount( 1, $puts, 'flush uploads, close sees the cache and does not repeat it' );
		$this->assertSame( 'hello there', $puts[0]['args']['body'] );
	}

	public function test_read_write_mode_on_a_missing_blob_fails_to_open(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 404 );
		$this->assertFalse( @fopen( self::P . '://uploads/missing.txt', 'r+' ) );
	}

	public function test_read_write_mode_serves_a_cached_blob_without_a_download(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$fh = fopen( self::P . '://uploads/c.txt', 'w' );
		fwrite( $fh, 'cached' );
		fclose( $fh );
		$fh = fopen( self::P . '://uploads/c.txt', 'r+' );
		$this->assertSame( 'cached', fread( $fh, 10 ) );
		fclose( $fh );
		$this->assertCount( 0, array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => $r['method'] === 'GET' ) );
	}

	// ── buffer-backed handles ───────────────────────────────

	public function test_append_plus_reads_back_from_the_write_buffer(): void {
		$GLOBALS['_test_wp_http'] = fn( $m ) => $m === 'GET' ? self::reply( 200, 'old' ) : self::reply( 201 );
		$fh = fopen( self::P . '://uploads/rw.txt', 'a+' );
		fwrite( $fh, '-new' );
		rewind( $fh );
		$this->assertSame( 'old-new', fread( $fh, 100 ) );
		fclose( $fh );
	}

	public function test_fstat_on_a_write_stream_reports_the_buffered_size(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::reply( 201 );
		$fh = fopen( self::P . '://uploads/st.txt', 'w' );
		fwrite( $fh, 'twelve bytes' );
		$st = fstat( $fh );
		$this->assertSame( 12, $st['size'] );
		$this->assertSame( 0100777, $st['mode'] );
		fclose( $fh );
	}


	public function test_unlink_with_a_provider_that_throws_still_succeeds(): void {
		$GLOBALS['_test_wp_http'] = function () {
			throw new \RuntimeException( 'provider exploded' );
		};
		$this->assertTrue( unlink( self::P . '://uploads/x.jpg' ) );
	}

	public function test_rename_with_a_provider_that_throws_fails(): void {
		$GLOBALS['_test_wp_http'] = function () {
			throw new \RuntimeException( 'provider exploded' );
		};
		$this->assertFalse( @rename( self::P . '://uploads/a.jpg', self::P . '://uploads/b.jpg' ) );
	}
}
