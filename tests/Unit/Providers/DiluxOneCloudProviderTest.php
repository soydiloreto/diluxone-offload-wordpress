<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Providers\DiluxOneCloudProvider;

/**
 * Unit tests for DiluxOneCloudProvider against a scripted HTTP layer.
 *
 * The provider talks to two things: the DiluxOne API (verify, SAS token,
 * stats) and, with the SAS token it gets back, Azure Blob directly. Both are
 * scripted through the wp_remote_* stubs, so the tests can pin the contract
 * with the API — status codes, the JSON envelope, quota handling — and the
 * SAS caching that keeps the API to about one call an hour.
 */
class DiluxOneCloudProviderTest extends TestCase {

	private const API = 'https://api.diluxone.com/cloud-storage-wp/v1';
	private const CDN = 'https://cdn.example.net/site-container';

	private DiluxOneCloudProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'] );

		$this->provider = new DiluxOneCloudProvider(
			array(
				'api_key'      => 'unit-api-key',
				'cdn_base_url' => self::CDN,
			)
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	// ── Helpers ─────────────────────────────────────────────

	private static function json( int $code, array $payload ): array {
		return array(
			'response' => array( 'code' => $code, 'message' => '' ),
			'body'     => (string) json_encode( $payload ),
			'headers'  => array(),
		);
	}

	private static function raw( int $code, string $body = '', array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code, 'message' => '' ),
			'body'     => $body,
			'headers'  => $headers,
		);
	}

	private function answer( callable $fn ): void {
		$GLOBALS['_test_wp_http'] = $fn;
	}

	/** @return array<int, array{method:string,url:string,args:array}> */
	private function requests(): array {
		return $GLOBALS['_test_wp_http_log'] ?? array();
	}

	/** Script: the API hands out a SAS token, Azure answers with $azure. */
	private function withSas( callable $azure, string $token = 'sv=1&sig=abc' ): void {
		$this->answer(
			function ( $method, $url, $args ) use ( $azure, $token ) {
				if ( strpos( $url, self::API ) === 0 ) {
					return self::json( 200, array( 'data' => array( 'sasToken' => $token, 'expiresIn' => 3600 ) ) );
				}
				return $azure( $method, $url, $args );
			}
		);
	}

	// ── Identity ────────────────────────────────────────────

	public function test_provider_name(): void {
		$this->assertSame( 'diluxone', $this->provider->get_provider_name() );
	}

	public function test_file_url_is_built_from_the_cdn_base(): void {
		$this->assertSame( self::CDN . '/2026/01/a.jpg', $this->provider->get_file_url( '/2026/01/a.jpg' ) );
	}

	// ── test_connection (API /auth/verify) ──────────────────

	public function test_connection_sends_the_bearer_key_and_domain(): void {
		$this->answer( fn() => self::json( 200, array( 'data' => array( 'plan' => 'Pro', 'storageUsedBytes' => 1048576, 'storageLimitBytes' => 10485760 ) ) ) );
		$r = $this->provider->test_connection();

		$this->assertTrue( $r['success'] );
		$this->assertStringContainsString( 'Pro', $r['message'] );
		$req = $this->requests()[0];
		$this->assertSame( self::API . '/auth/verify', $req['url'] );
		$this->assertSame( 'Bearer unit-api-key', $req['args']['headers']['Authorization'] );
		$this->assertArrayHasKey( 'X-WP-Domain', $req['args']['headers'] );
	}

	public function test_connection_adopts_the_cdn_base_url_the_api_returns(): void {
		$this->answer( fn() => self::json( 200, array( 'data' => array( 'cdnBaseUrl' => 'https://new.cdn/x/' ) ) ) );
		$r = $this->provider->test_connection();
		$this->assertSame( 'https://new.cdn/x', $r['cdn_base_url'], 'trailing slash trimmed' );
		$this->assertSame( 'https://new.cdn/x/a.jpg', $this->provider->get_file_url( 'a.jpg' ) );
	}

	public function test_connection_reports_the_apis_error_message(): void {
		$this->answer( fn() => self::json( 401, array( 'error' => array( 'message' => 'Invalid API key' ) ) ) );
		$r = $this->provider->test_connection();
		$this->assertFalse( $r['success'] );
		$this->assertSame( 'Invalid API key', $r['message'] );
	}

	public function test_connection_reports_a_transport_error(): void {
		$this->answer( fn() => new \WP_Error( 'http_request_failed', 'name resolution failed' ) );
		$r = $this->provider->test_connection();
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'name resolution failed', $r['message'] );
	}

	// ── SAS token lifecycle ─────────────────────────────────

	public function test_sas_token_is_requested_once_and_cached(): void {
		$this->withSas( fn() => self::raw( 200 ) );
		$this->provider->file_exists( 'a.jpg' );
		$this->provider->file_exists( 'b.jpg' );

		$sas_calls = array_filter( $this->requests(), fn( $r ) => strpos( $r['url'], '/storage/sas-token' ) !== false );
		$this->assertCount( 1, $sas_calls, 'second call must reuse the cached token' );
		$this->assertSame( 'sv=1&sig=abc', $GLOBALS['_test_wp_transients']['diluxone_offload_sas_token'] );
	}

	public function test_sas_token_is_appended_to_every_blob_url(): void {
		$this->withSas( fn() => self::raw( 200 ), 'sv=2&sig=zzz' );
		$this->provider->file_exists( '2026/01/a b.jpg' );
		$blob = array_values( array_filter( $this->requests(), fn( $r ) => strpos( $r['url'], self::CDN ) === 0 ) )[0];
		$this->assertStringEndsWith( '?sv=2&sig=zzz', $blob['url'] );
		$this->assertStringContainsString( '/2026/01/a%20b.jpg?', $blob['url'], 'path segments are url-encoded, slashes kept' );
	}

	public function test_quota_exceeded_is_reported_as_such(): void {
		$this->answer( fn() => self::json( 507, array( 'error' => array( 'message' => 'Plan limit reached' ) ) ) );
		$tmp = tempnam( sys_get_temp_dir(), 'dx' );
		file_put_contents( $tmp, 'x' );
		try {
			$this->provider->upload_file( $tmp, 'x.txt' );
			$this->fail( 'quota exhaustion must propagate' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'QUOTA_EXCEEDED', $e->getMessage() );
			$this->assertStringContainsString( 'Plan limit reached', $e->getMessage() );
		} finally {
			@unlink( $tmp );
		}
	}

	public function test_a_403_from_azure_invalidates_the_token_and_retries_once(): void {
		$GLOBALS['_test_wp_transients']['diluxone_offload_sas_token'] = 'stale';
		$azure_calls = 0;
		$this->answer(
			function ( $m, $url ) use ( &$azure_calls ) {
				if ( strpos( $url, self::API ) === 0 ) {
					return self::json( 200, array( 'data' => array( 'sasToken' => 'fresh', 'expiresIn' => 3600 ) ) );
				}
				++$azure_calls;
				return strpos( $url, 'stale' ) !== false ? self::raw( 403 ) : self::raw( 200 );
			}
		);

		$this->assertTrue( $this->provider->file_exists( 'a.jpg' ) );
		$this->assertSame( 2, $azure_calls, 'stale token → 403 → refresh → retry' );
		$this->assertSame( 'fresh', $GLOBALS['_test_wp_transients']['diluxone_offload_sas_token'] );
	}

	// ── Blob operations through the SAS URL ─────────────────

	public function test_upload_puts_the_body_and_reports_the_public_url(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'dx' );
		file_put_contents( $tmp, 'payload' );
		$this->withSas( fn() => self::raw( 201 ) );

		$r = $this->provider->upload_file( $tmp, '2026/01/p.txt' );
		@unlink( $tmp );

		$this->assertTrue( $r['success'] );
		$this->assertSame( self::CDN . '/2026/01/p.txt', $r['url'] );
		$put = array_values( array_filter( $this->requests(), fn( $x ) => $x['method'] === 'PUT' ) )[0];
		$this->assertSame( 'payload', $put['args']['body'] );
		$this->assertSame( 'BlockBlob', $put['args']['headers']['x-ms-blob-type'] );
	}

	public function test_download_streams_to_the_local_path(): void {
		$dest = sys_get_temp_dir() . '/dx-dl-' . uniqid() . '/nested/file.bin';
		$this->withSas( fn() => self::raw( 200 ) );

		$r = $this->provider->download_file( 'file.bin', $dest );

		$this->assertTrue( $r['success'] );
		$get = array_values( array_filter( $this->requests(), fn( $x ) => $x['method'] === 'GET' ) )[0];
		$this->assertTrue( $get['args']['stream'], 'must stream to disk, not buffer in memory' );
		$this->assertSame( $dest, $get['args']['filename'] );
		$this->assertDirectoryExists( dirname( $dest ), 'parent directory is created' );
		@rmdir( dirname( $dest ) );
		@rmdir( dirname( $dest, 2 ) );
	}

	public function test_download_failure_reports_the_status(): void {
		$dest = sys_get_temp_dir() . '/dx-dl-' . uniqid();
		$this->withSas( fn() => self::raw( 404 ) );
		$r = $this->provider->download_file( 'missing.bin', $dest );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( '404', $r['error'] );
	}

	public function test_delete_succeeds_on_202(): void {
		$this->withSas( fn() => self::raw( 202 ) );
		$this->assertTrue( $this->provider->delete_file( 'a.jpg' )['success'] );
	}

	public function test_copy_blob_sends_the_source_header(): void {
		$this->withSas( fn() => self::raw( 202 ) );
		$this->assertTrue( $this->provider->copy_blob( 'src.jpg', 'dst.jpg' )['success'] );
		$put = array_values( array_filter( $this->requests(), fn( $x ) => $x['method'] === 'PUT' ) )[0];
		$this->assertStringContainsString( '/src.jpg?', $put['args']['headers']['x-ms-copy-source'] );
		$this->assertStringContainsString( '/dst.jpg?', $put['url'] );
	}

	public function test_file_info_reads_the_headers(): void {
		$this->withSas(
			fn() => self::raw( 200, '', array( 'content-length' => '1234', 'content-md5' => 'md5==', 'last-modified' => 'Mon, 01 Jan 2026 00:00:00 GMT' ) )
		);
		$info = $this->provider->get_file_info( 'a.jpg' );
		$this->assertSame( 1234, $info['size'] );
		$this->assertSame( 'md5==', $info['md5'] );
		$this->assertSame( 'Mon, 01 Jan 2026 00:00:00 GMT', $info['last_modified'] );
	}

	public function test_file_info_takes_the_first_value_of_a_repeated_header(): void {
		$this->withSas( fn() => self::raw( 200, '', array( 'content-length' => array( '10', '20' ) ) ) );
		$this->assertSame( 10, $this->provider->get_file_info( 'a.jpg' )['size'] );
	}

	public function test_file_info_is_false_when_missing(): void {
		$this->withSas( fn() => self::raw( 404 ) );
		$this->assertFalse( $this->provider->get_file_info( 'a.jpg' ) );
	}

	// ── get_stats (API /stats) ──────────────────────────────

	public function test_stats_come_from_the_api_and_are_cached(): void {
		$this->answer( fn() => self::json( 200, array( 'data' => array( 'fileCount' => 7, 'bandwidthUsedBytes' => 99 ) ) ) );
		$r = $this->provider->get_stats( true );
		$this->assertTrue( $r['success'] );
		$this->assertSame( 7, $r['data']['fileCount'] );
		$this->assertSame( 7, $GLOBALS['_test_wp_transients']['diluxone_offload_stats']['fileCount'] );
	}

	public function test_stats_served_from_cache_skip_the_network(): void {
		$GLOBALS['_test_wp_transients']['diluxone_offload_stats'] = array( 'fileCount' => 3 );
		$this->answer( fn() => self::json( 500, array() ) );
		$r = $this->provider->get_stats();
		$this->assertSame( 3, $r['data']['fileCount'] );
		$this->assertSame( array(), $this->requests() );
	}

	public function test_stats_error_clears_the_cache(): void {
		$GLOBALS['_test_wp_transients']['diluxone_offload_stats'] = array( 'fileCount' => 3 );
		$this->answer( fn() => self::json( 500, array( 'error' => array( 'message' => 'boom' ) ) ) );
		$r = $this->provider->get_stats( true );
		$this->assertFalse( $r['success'] );
		$this->assertSame( 'boom', $r['message'] );
		$this->assertArrayNotHasKey( 'diluxone_offload_stats', $GLOBALS['_test_wp_transients'] );
	}
}
