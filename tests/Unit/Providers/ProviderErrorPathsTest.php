<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\Providers\AzureProvider;
use DiluxOneOffload\Providers\DiluxOneCloudProvider;
use DiluxOneOffload\ConfigManager;

/**
 * What each provider does when the cloud says no: transport errors, 4xx/5xx
 * answers, quota, expired SAS tokens (retry once after a refresh), and the
 * chunked upload path for large files, whose block PUTs go through the WP HTTP
 * API and whose final commit is the one cURL handle SyncManager runs.
 */
class ProviderErrorPathsTest extends TestCase {

	private const API = 'https://api.diluxone.com/cloud-storage-wp/v1';
	private static ?LocalBlobServer $server = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$server = new LocalBlobServer( 8772 );
	}

	public static function tearDownAfterClass(): void {
		if ( self::$server ) {
			self::$server->stop();
			self::$server = null;
		}
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array(
			'diluxone_offload_config' => array( 'cloud_provider' => 'diluxone', 'provider_config' => array( 'api_key' => 'k', 'cdn_base_url' => '' ) ),
		);
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	private static function json( int $code, array $payload ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => (string) json_encode( $payload ), 'headers' => array() );
	}

	private static function raw( int $code, string $body = '', array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => $headers );
	}

	private static function sas( string $token = 'sv=1' ): array {
		return self::json( 200, array( 'data' => array( 'sasToken' => $token, 'expiresIn' => 3600 ) ) );
	}

	/** Scripts the API (SAS) and blob answers separately. */
	private function script( callable $blob, $api = null ): void {
		$api = $api ?? self::sas();
		$GLOBALS['_test_wp_http'] = function ( $m, $url, $args ) use ( $blob, $api ) {
			if ( strpos( $url, self::API ) === 0 ) {
				return $api;
			}
			return $blob( $m, $url, $args );
		};
	}

	private function diluxone( string $cdn = 'https://cdn.example.net/c' ): DiluxOneCloudProvider {
		return new DiluxOneCloudProvider( array( 'api_key' => 'k', 'cdn_base_url' => $cdn ) );
	}

	private function azure(): AzureProvider {
		return new AzureProvider( array( 'storage_account' => 'eacct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ) );
	}

	private function blobCalls(): array {
		return array_values( array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => strpos( $r['url'], self::API ) !== 0 ) );
	}

	private function tmp( int $bytes ): string {
		$f = tempnam( sys_get_temp_dir(), 'pe' );
		file_put_contents( $f, str_repeat( 'x', $bytes ) );
		return $f;
	}

	// ── DiluxOne: SAS token ─────────────────────────────────

	public function test_sas_quota_exceeded_is_reported_as_such(): void {
		$this->script( fn() => self::raw( 200 ), self::json( 507, array( 'error' => array( 'message' => 'Plan full' ) ) ) );
		$this->expectExceptionMessage( 'QUOTA_EXCEEDED' );
		$this->diluxone()->delete_file( 'a.jpg' );
	}

	public function test_sas_refusal_carries_the_api_message(): void {
		$this->script( fn() => self::raw( 200 ), self::json( 401, array( 'error' => array( 'message' => 'bad key' ) ) ) );
		$this->expectExceptionMessage( 'SAS token error: bad key' );
		$this->diluxone()->delete_file( 'a.jpg' );
	}

	public function test_sas_transport_error(): void {
		$this->script( fn() => self::raw( 200 ), new \WP_Error( 'x', 'dns down' ) );
		$this->expectExceptionMessage( 'Failed to get SAS token' );
		$this->diluxone()->delete_file( 'a.jpg' );
	}

	public function test_sas_is_cached_and_the_container_url_is_persisted(): void {
		$this->script( fn() => self::raw( 202 ), self::json( 200, array( 'data' => array( 'sasToken' => 'sv=9', 'expiresIn' => 3600, 'containerUrl' => 'https://c.example.net/media/' ) ) ) );
		$p = $this->diluxone( '' );
		$this->assertTrue( $p->delete_file( 'a.jpg' )['success'] );
		$this->assertTrue( $p->delete_file( 'b.jpg' )['success'] );
		$api_calls = count( $GLOBALS['_test_wp_http_log'] ) - count( $this->blobCalls() );
		$this->assertSame( 1, $api_calls, 'second call reuses the cached token' );
		$this->assertSame( 'https://c.example.net/media', $GLOBALS['_test_wp_options']['diluxone_offload_config']['provider_config']['cdn_base_url'] );
		$this->assertStringStartsWith( 'https://c.example.net/media/b.jpg?', $this->blobCalls()[1]['url'] );
	}

	// ── DiluxOne: 403 → refresh once, then give up ──────────

	public function test_a_403_refreshes_the_token_and_retries_once(): void {
		$calls = 0;
		$this->script( function () use ( &$calls ) {
			return ++$calls === 1 ? self::raw( 403 ) : self::raw( 202 );
		} );
		$this->assertTrue( $this->diluxone()->delete_file( 'a.jpg' )['success'] );
		$this->assertCount( 2, $this->blobCalls() );
	}

	public function test_two_403s_surface_as_an_exception(): void {
		$this->script( fn() => self::raw( 403 ) );
		$this->expectExceptionMessage( 'HTTP 403' );
		$this->diluxone()->upload_file( $this->tmp( 3 ), 'a.jpg' );
	}

	// ── DiluxOne: per-operation failures ────────────────────

	public function test_upload_reports_a_missing_local_file_and_server_errors(): void {
		$this->script( fn() => self::raw( 500 ) );
		$p = $this->diluxone();
		$this->assertStringContainsString( 'not found', $p->upload_file( '/nope/x.jpg', 'x.jpg' )['error'] );
		$r = $p->upload_file( $this->tmp( 2 ), 'x.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'HTTP 500', $r['error'] );
	}

	public function test_upload_transport_error_names_the_cause(): void {
		$this->script( fn() => new \WP_Error( 'x', 'reset by peer' ) );
		$r = $this->diluxone()->upload_file( $this->tmp( 2 ), 'x.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'reset by peer', $r['error'] );
	}

	public function test_download_failure_removes_the_partial_file(): void {
		$dest = sys_get_temp_dir() . '/dlx-pe-' . uniqid() . '/d.bin';
		$this->script( fn() => self::raw( 404 ) );
		$r = $this->diluxone()->download_file( 'd.bin', $dest );
		$this->assertFalse( $r['success'] );
		$this->assertFileDoesNotExist( $dest );
		@rmdir( dirname( $dest ) );
	}

	public function test_delete_and_copy_failures(): void {
		$this->script( fn() => self::raw( 500 ) );
		$p = $this->diluxone();
		$this->assertStringContainsString( 'HTTP 500', $p->delete_file( 'a.jpg' )['error'] );
		$this->assertStringContainsString( 'HTTP 500', $p->copy_blob( 'a.jpg', 'b.jpg' )['error'] );
		$this->script( fn() => new \WP_Error( 'x', 'gone' ) );
		$this->assertStringContainsString( 'gone', $p->delete_file( 'a.jpg' )['error'] );
		$this->assertStringContainsString( 'gone', $p->copy_blob( 'a.jpg', 'b.jpg' )['error'] );
	}

	public function test_head_based_lookups_swallow_errors(): void {
		$p = $this->diluxone();
		$this->script( fn() => new \WP_Error( 'x', 'down' ) );
		$this->assertFalse( $p->file_exists( 'a.jpg' ) );
		$this->assertFalse( $p->get_file_checksum( 'a.jpg' ) );
		$this->assertFalse( $p->get_file_info( 'a.jpg' ) );
		$this->script( fn() => self::raw( 403 ) );
		$this->assertFalse( $p->file_exists( 'a.jpg' ) );
		$this->script( fn() => self::raw( 200 ) );
		$this->assertFalse( $p->get_file_checksum( 'a.jpg' ), 'no md5 header' );
		$this->script( fn() => self::raw( 404 ) );
		$this->assertFalse( $p->get_file_info( 'a.jpg' ) );
	}

	public function test_connection_test_outcomes(): void {
		$p = $this->diluxone( '' );
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'offline' );
		$this->assertStringContainsString( 'offline', $p->test_connection()['message'] );
		$GLOBALS['_test_wp_http'] = fn() => self::json( 401, array( 'error' => array( 'message' => 'invalid key' ) ) );
		$this->assertSame( 'invalid key', $p->test_connection()['message'] );
		$GLOBALS['_test_wp_http'] = fn() => self::json( 200, array( 'data' => array( 'plan' => 'pro', 'storageUsedBytes' => 1048576, 'storageLimitBytes' => 10485760, 'cdnBaseUrl' => 'https://cdn.example.net/x/' ) ) );
		$r = $p->test_connection();
		$this->assertTrue( $r['success'] );
		$this->assertStringContainsString( 'pro', $r['message'] );
		$this->assertSame( 'https://cdn.example.net/x', $r['cdn_base_url'] );
	}

	public function test_stats_are_cached_and_failures_are_recorded(): void {
		$p = $this->diluxone();
		$GLOBALS['_test_wp_http'] = fn() => self::json( 200, array( 'data' => array( 'fileCount' => 3 ) ) );
		$this->assertSame( 3, $p->get_stats()['data']['fileCount'] );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 500 );
		$this->assertSame( 3, $p->get_stats()['data']['fileCount'], 'served from the transient' );
		$r = $p->get_stats( true );
		$this->assertFalse( $r['success'] );
		$this->assertSame( 'unhealthy', ConfigManager::get_connection_health()['status'] );
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'timeout' );
		$this->assertStringContainsString( 'timeout', $p->get_stats( true )['message'] );
	}

	// ── DiluxOne: chunked (large file) upload ───────────────

	public function test_chunked_upload_puts_every_block_and_hands_back_the_commit_handle(): void {
		$this->script( fn() => self::raw( 201 ) );
		$p    = $this->diluxone();
		$file = $this->tmp( 5 * 1024 * 1024 ); // two 4 MB blocks
		$r    = $p->prepare_chunked_upload_handle( array( 'local_path' => $file, 'remote_path' => 'uploads/big.bin', 'size' => filesize( $file ) ) );
		$this->assertTrue( $r['success'], $r['error'] ?? '' );
		$blocks = array_values( array_filter( $this->blobCalls(), fn( $c ) => strpos( $c['url'], 'comp=block&' ) !== false ) );
		$this->assertCount( 2, $blocks, 'one request per 4 MB block, through the HTTP API' );
		$this->assertSame( 'PUT', $blocks[0]['method'] );
		$this->assertSame( 4194304, strlen( $blocks[0]['args']['body'] ) );
		$this->assertTrue( $r['handle'] instanceof \CurlHandle || is_resource( $r['handle'] ) );
		$this->assertNull( $r['file_handle'] );
		$this->assertStringContainsString( 'comp=blocklist', curl_getinfo( $r['handle'], CURLINFO_EFFECTIVE_URL ) );
		unlink( $file );
	}

	public function test_chunked_upload_stops_at_the_first_rejected_block(): void {
		$this->script( fn() => self::raw( 500 ) );
		$p    = $this->diluxone();
		$file = $this->tmp( 10 );
		$r    = $p->prepare_chunked_upload_handle( array( 'local_path' => $file, 'remote_path' => 'uploads/small.bin', 'size' => 10 ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'block 0', $r['error'] );
		$this->assertStringContainsString( 'HTTP 500', $r['error'] );
		$this->assertCount( 1, $this->blobCalls(), 'it gives up instead of uploading the rest' );
		unlink( $file );
	}

	public function test_chunked_upload_of_a_missing_file_fails_cleanly(): void {
		$this->script( fn() => self::raw( 200 ) );
		$r = $this->diluxone()->prepare_chunked_upload_handle( array( 'local_path' => '/nope/x', 'remote_path' => 'x', 'size' => 1 ) );
		$this->assertFalse( $r['success'] );
	}

	public function test_azure_chunked_upload_of_a_missing_file_fails_cleanly(): void {
		$r = $this->azure()->prepare_chunked_upload_handle( array( 'local_path' => '/nope/x', 'remote_path' => 'x', 'size' => 1 ) );
		$this->assertFalse( $r['success'] );
		$this->assertNull( $r['file_handle'] );
	}

	public function test_handle_builders_report_a_sas_failure_instead_of_throwing(): void {
		$this->script( fn() => self::raw( 200 ), self::json( 401, array( 'error' => array( 'message' => 'bad key' ) ) ) );
		$p = $this->diluxone();
		$f = $this->tmp( 2 );
		$r = $p->prepare_batch_upload_handle( array( 'local_path' => $f, 'remote_path' => 'uploads/x', 'size' => 2 ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'bad key', $r['error'] );
		$r = $p->prepare_download_handle( array( 'remote_path' => 'uploads/x', 'local_path' => sys_get_temp_dir() . '/dlx-h-' . uniqid() ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'bad key', $r['error'] );
		unlink( $f );
	}

	public function test_persistent_403s_on_lookups_and_copy(): void {
		$this->script( fn() => self::raw( 403 ) );
		$p = $this->diluxone();
		$this->assertFalse( $p->get_file_checksum( 'a.jpg' ) );
		$this->assertFalse( $p->get_file_info( 'a.jpg' ) );
		$this->expectExceptionMessage( 'HTTP 403' );
		$p->copy_blob( 'a.jpg', 'b.jpg' );
	}

	public function test_download_creates_the_directory_and_drops_a_stale_partial_on_failure(): void {
		$dir  = sys_get_temp_dir() . '/dlx-dl-' . uniqid();
		$dest = $dir . '/sub/file.bin';
		$this->script( fn() => self::raw( 200, 'fresh' ) );
		$p = $this->diluxone();
		$this->assertTrue( $p->download_file( 'file.bin', $dest )['success'] );
		$this->assertSame( 'fresh', file_get_contents( $dest ) );
		$this->script( fn() => self::raw( 500 ) );
		$this->assertFalse( $p->download_file( 'file.bin', $dest )['success'] );
		$this->assertFileDoesNotExist( $dest, 'the old copy is not left behind as if it were current' );
		@rmdir( dirname( $dest ) );
		@rmdir( $dir );
	}

	public function test_a_transport_that_throws_is_reported_not_propagated(): void {
		$GLOBALS['_test_wp_http'] = function () {
			throw new \RuntimeException( 'transport exploded' );
		};
		$p = $this->diluxone( '' );
		$this->assertStringContainsString( 'transport exploded', $p->test_connection()['message'] );
		$this->assertStringContainsString( 'transport exploded', $p->get_stats( true )['message'] );
	}

	public function test_list_retries_transport_errors_three_times(): void {
		$this->script( fn() => new \WP_Error( 'x', 'reset' ) );
		try {
			$this->diluxone()->list_files( 'uploads/' );
			$this->fail( 'expected an exception' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'after 3 attempts', $e->getMessage() );
		}
		$this->assertCount( 3, $this->blobCalls() );
	}

	public function test_list_treats_a_403_as_final_after_one_token_refresh(): void {
		$this->script( fn() => self::raw( 403 ) );
		try {
			$this->diluxone()->list_files( 'uploads/' );
			$this->fail( 'expected an exception' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( '403', $e->getMessage() );
		}
		$this->assertCount( 2, $this->blobCalls(), 'one refresh, then give up' );
		$this->assertSame( '403', ConfigManager::get_connection_health()['error_code'] );
	}

	public function test_list_rejects_an_empty_body(): void {
		$this->script( fn() => self::raw( 200, '' ) );
		$this->expectExceptionMessage( 'after 3 attempts' );
		$this->diluxone()->list_files( 'uploads/' );
	}

	// ── Azure ───────────────────────────────────────────────

	public function test_azure_connection_failures(): void {
		$p = $this->azure();
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'offline' );
		$this->assertStringContainsString( 'offline', $p->test_connection()['message'] );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404, '<Error><Message>ContainerNotFound</Message></Error>' );
		$this->assertStringContainsString( 'ContainerNotFound', $p->test_connection()['message'] );
	}

	public function test_azure_upload_failures(): void {
		$p = $this->azure();
		$this->assertStringContainsString( 'not found', $p->upload_file( '/nope', 'x.jpg' )['error'] );
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$this->assertStringContainsString( 'reset', $p->upload_file( $this->tmp( 2 ), 'x.jpg' )['error'] );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 500 );
		$this->assertStringContainsString( '500', $p->upload_file( $this->tmp( 2 ), 'x.jpg' )['error'] );
	}

	public function test_azure_download_outcomes(): void {
		$p    = $this->azure();
		$dest = sys_get_temp_dir() . '/dlx-az-' . uniqid() . '/d.bin';
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$this->assertStringContainsString( 'reset', $p->download_file( 'd.bin', $dest )['error'] );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404 );
		$this->assertStringContainsString( '404', $p->download_file( 'd.bin', $dest )['error'] );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, 'payload' );
		$this->assertTrue( $p->download_file( 'd.bin', $dest )['success'] );
		$this->assertSame( 'payload', file_get_contents( $dest ) );
		unlink( $dest );
		rmdir( dirname( $dest ) );
	}

	public function test_azure_head_lookups_swallow_transport_errors(): void {
		$p = $this->azure();
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'down' );
		$this->assertFalse( $p->file_exists( 'a.jpg' ) );
		$this->assertFalse( $p->get_file_checksum( 'a.jpg' ) );
		$this->assertFalse( $p->get_file_info( 'a.jpg' ) );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404 );
		$this->assertFalse( $p->get_file_checksum( 'a.jpg' ) );
	}

	public function test_azure_copy_reports_the_body_on_failure(): void {
		$p = $this->azure();
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 409, '<Error>busy</Error>' );
		$r = $p->copy_blob( 'a.jpg', 'b.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'busy', $r['error'] );
	}

	public function test_azure_list_gives_up_on_a_non_retryable_error_and_records_it(): void {
		$p = $this->azure();
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 403, '<Error><Message>AuthenticationFailed</Message></Error>' );
		try {
			$p->list_files( 'uploads/' );
			$this->fail( 'expected an exception' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( '403', $e->getMessage() );
		}
		$this->assertCount( 1, $GLOBALS['_test_wp_http_log'], 'no retries on 403' );
		$this->assertSame( '403', ConfigManager::get_connection_health()['error_code'] );
	}

	public function test_azure_list_rejects_an_empty_body_and_garbage_xml_after_retries(): void {
		$p = $this->azure();
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, '' );
		try {
			$p->list_files( 'uploads/' );
			$this->fail( 'expected an exception' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'after 3 attempts', $e->getMessage() );
		}
	}

	public function test_azure_delete_transport_error(): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'reset' );
		$r = $this->azure()->delete_file( 'a.jpg' );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'reset', $r['error'] );
	}

	public function test_azure_download_to_an_unwritable_target_fails_cleanly(): void {
		$blocker = sys_get_temp_dir() . '/dlx-blk-' . uniqid();
		file_put_contents( $blocker, 'a file where a directory is needed' );
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, 'payload' );
		$r = $this->azure()->download_file( 'd.bin', $blocker . '/d.bin' );
		$this->assertFalse( $r['success'] );
		unlink( $blocker );
	}

	/** Points the provider at the local stand-in instead of *.blob.core.windows.net. */
	private function azureAt( string $endpoint, string $container = 'media' ): AzureProvider {
		$p    = new AzureProvider( array( 'storage_account' => 'eacct', 'container_name' => $container, 'access_key' => base64_encode( random_bytes( 32 ) ) ) );
		$prop = new \ReflectionProperty( $p, 'endpoint' );
		$prop->setValue( $p, $endpoint );
		return $p;
	}

	public function test_azure_chunked_upload_puts_every_block_and_hands_back_the_commit(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 201 );
		$file = $this->tmp( 5 * 1024 * 1024 );
		$r    = $this->azure()->prepare_chunked_upload_handle( array( 'local_path' => $file, 'remote_path' => 'uploads/big.bin', 'size' => filesize( $file ) ) );
		$this->assertTrue( $r['success'], $r['error'] ?? '' );
		$this->assertCount( 2, $GLOBALS['_test_wp_http_log'], 'one HTTP API request per 4 MB block' );
		$this->assertStringContainsString( 'comp=block&', $GLOBALS['_test_wp_http_log'][0]['url'] );
		$this->assertArrayHasKey( 'Authorization', $GLOBALS['_test_wp_http_log'][0]['args']['headers'] );
		$this->assertStringContainsString( 'comp=blocklist', curl_getinfo( $r['handle'], CURLINFO_EFFECTIVE_URL ) );
		$this->assertNull( $r['file_handle'] );
		unlink( $file );
	}

	public function test_azure_chunked_upload_stops_at_the_first_rejected_block(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 500, 'nope' );
		$file = $this->tmp( 10 );
		$r    = $this->azure()->prepare_chunked_upload_handle( array( 'local_path' => $file, 'remote_path' => 'uploads/small.bin', 'size' => 10 ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'block 0', $r['error'] );
		$this->assertStringContainsString( 'HTTP 500', $r['error'] );
		$this->assertStringContainsString( 'nope', $r['error'], 'the body is quoted back' );
		unlink( $file );
	}

	public function test_azure_constructor_logs_an_invalid_config_without_throwing(): void {
		$p = new AzureProvider( array( 'storage_account' => 'Not Valid', 'container_name' => 'media', 'access_key' => 'k' ) );
		$this->assertInstanceOf( AzureProvider::class, $p );
	}

	public function test_azure_operations_survive_a_transport_that_throws(): void {
		$GLOBALS['_test_wp_http'] = function () {
			throw new \RuntimeException( 'transport exploded' );
		};
		$p = $this->azure();
		$this->assertStringContainsString( 'transport exploded', $p->test_connection()['message'] );
		$this->assertStringContainsString( 'transport exploded', $p->upload_file( $this->tmp( 2 ), 'x.jpg' )['error'] );
		$this->assertStringContainsString( 'transport exploded', $p->download_file( 'x.jpg', sys_get_temp_dir() . '/dlx-x-' . uniqid() )['error'] );
		$this->assertFalse( $p->file_exists( 'x.jpg' ) );
		$this->assertFalse( $p->get_file_checksum( 'x.jpg' ) );
		$this->assertFalse( $p->get_file_info( 'x.jpg' ) );
		$this->assertStringContainsString( 'transport exploded', $p->copy_blob( 'a', 'b' )['error'] );
		$this->assertStringContainsString( 'transport exploded', $p->delete_file( 'a' )['error'] );
	}

	public function test_azure_connection_refusal_with_an_unparseable_body(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404, '<<not xml' );
		$this->assertStringContainsString( '404', $this->azure()->test_connection()['message'] );
	}

	/** @dataProvider retryableTransportErrors */
	public function test_azure_list_retries_transport_errors_and_classifies_them( string $message, string $code ): void {
		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', $message );
		try {
			$this->azure()->list_files( 'uploads/' );
			$this->fail( 'expected an exception' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'after 3 attempts', $e->getMessage() );
		}
		$this->assertCount( 3, $GLOBALS['_test_wp_http_log'] );
		$this->assertNotSame( $code, ConfigManager::get_connection_health()['error_code'], 'retryable errors are not recorded as final' );
	}

	/** @return array<string, array{string,string}> */
	public function retryableTransportErrors(): array {
		return array(
			'timeout' => array( 'Operation timed out after 60000 ms', 'timeout' ),
			'network' => array( 'cURL error 7: Failed to connect', 'network' ),
		);
	}

	public function test_azure_list_gives_up_on_garbage_xml(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, '<<not xml' );
		$this->expectExceptionMessage( 'after 3 attempts' );
		$this->azure()->list_files( 'uploads/' );
	}

	// ── chunked upload edge cases, both providers ───────────

	/** @return array<string, array{string}> */
	public function providersWithChunkedUpload(): array {
		return array( 'azure' => array( 'azure' ), 'diluxone' => array( 'diluxone' ) );
	}

	private function chunkedProvider( string $which ) {
		return $which === 'azure' ? $this->azure() : $this->diluxone();
	}

	/** @dataProvider providersWithChunkedUpload */
	public function test_chunked_upload_of_an_unreadable_file_fails_cleanly( string $which ): void {
		if ( posix_geteuid() === 0 ) {
			$this->markTestSkipped( 'root can read anything' );
		}
		$file = $this->tmp( 10 );
		chmod( $file, 0000 );
		$this->script( fn() => self::raw( 201 ) );
		$r = $this->chunkedProvider( $which )->prepare_chunked_upload_handle( array( 'local_path' => $file, 'remote_path' => 'uploads/x.bin', 'size' => 10 ) );
		chmod( $file, 0600 );
		unlink( $file );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'open', strtolower( $r['error'] ) );
	}

	/** @dataProvider providersWithChunkedUpload */
	public function test_chunked_upload_of_an_empty_file_commits_an_empty_block_list( string $which ): void {
		$this->script( fn() => self::raw( 201 ) );
		$file = $this->tmp( 0 );
		$r    = $this->chunkedProvider( $which )->prepare_chunked_upload_handle( array( 'local_path' => $file, 'remote_path' => 'uploads/empty.bin', 'size' => 0 ) );
		unlink( $file );
		$this->assertTrue( $r['success'], $r['error'] ?? '' );
	}

	/** @dataProvider providersWithChunkedUpload */
	public function test_chunked_upload_reports_a_transport_error( string $which ): void {
		$this->script( fn() => new \WP_Error( 'http_request_failed', 'Failed to connect' ) );
		$file = $this->tmp( 10 );
		$r    = $this->chunkedProvider( $which )->prepare_chunked_upload_handle( array( 'local_path' => $file, 'remote_path' => 'uploads/x.bin', 'size' => 10 ) );
		unlink( $file );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'Failed to connect', $r['error'] );
	}

	public function test_azure_stats_classify_files_by_type(): void {
		$p   = $this->azure();
		$xml = '<?xml version="1.0"?><EnumerationResults><Blobs>'
			. '<Blob><Name>uploads/a.jpg</Name><Properties><Content-Length>10</Content-Length><Last-Modified>x</Last-Modified></Properties></Blob>'
			. '<Blob><Name>uploads/b.mp4</Name><Properties><Content-Length>20</Content-Length><Last-Modified>x</Last-Modified></Properties></Blob>'
			. '<Blob><Name>uploads/c.mp3</Name><Properties><Content-Length>30</Content-Length><Last-Modified>x</Last-Modified></Properties></Blob>'
			. '<Blob><Name>uploads/d.pdf</Name><Properties><Content-Length>40</Content-Length><Last-Modified>x</Last-Modified></Properties></Blob>'
			. '</Blobs><NextMarker></NextMarker></EnumerationResults>';
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, $xml );
		$r = $p->get_container_stats( true );
		$this->assertTrue( $r['success'] );
		$this->assertSame( 4, $r['data']['fileCount'] );
		$this->assertSame( 100, $r['data']['storageUsedBytes'] );
		$this->assertSame( array( 'images' => 1, 'videos' => 1, 'audio' => 1, 'other' => 1 ), $r['data']['filesByType'] );
	}
}
