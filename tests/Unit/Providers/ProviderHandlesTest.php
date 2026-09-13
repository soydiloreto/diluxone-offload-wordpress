<?php
namespace Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Providers\AzureProvider;
use DiluxOneOffload\Providers\DiluxOneCloudProvider;

/**
 * The cURL handle builders both providers expose for the parallel sync
 * loops, plus the remaining DiluxOne operations (listing with paging, error
 * classification) and the Azure copy/info calls.
 *
 * Handle builders are asserted on what they return — a live cURL handle and
 * an open file handle, or a clean failure — without executing anything.
 */
class ProviderHandlesTest extends TestCase {

	private const API = 'https://api.diluxone.com/cloud-storage-wp/v1';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_wp_options']    = array();
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		unset( $GLOBALS['_test_wp_http'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_wp_http'], $GLOBALS['_test_wp_http_log'], $GLOBALS['_test_wp_transients'], $GLOBALS['_test_wp_options'] );
		parent::tearDown();
	}

	private function azure(): AzureProvider {
		return new AzureProvider( array( 'storage_account' => 'hacct', 'container_name' => 'media', 'access_key' => base64_encode( random_bytes( 32 ) ) ) );
	}

	private function diluxone(): DiluxOneCloudProvider {
		$GLOBALS['_test_wp_http'] = function ( $m, $url ) {
			if ( strpos( $url, self::API ) === 0 ) {
				return self::json( 200, array( 'data' => array( 'sasToken' => 'sv=1&sig=x', 'expiresIn' => 3600 ) ) );
			}
			return self::raw( 200 );
		};
		return new DiluxOneCloudProvider( array( 'api_key' => 'k', 'cdn_base_url' => 'https://cdn.example.net/c' ) );
	}

	private static function json( int $code, array $payload ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => (string) json_encode( $payload ), 'headers' => array() );
	}

	private static function raw( int $code, string $body = '', array $headers = array() ): array {
		return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => $headers );
	}

	private function tmpFile( string $content = 'data' ): string {
		$f = tempnam( sys_get_temp_dir(), 'ph' );
		file_put_contents( $f, $content );
		return $f;
	}

	private static function isCurl( $h ): bool {
		return $h instanceof \CurlHandle || ( is_resource( $h ) && get_resource_type( $h ) === 'curl' );
	}

	// ── Azure handles ───────────────────────────────────────

	public function test_azure_batch_upload_handle(): void {
		$f = $this->tmpFile();
		$r = $this->azure()->prepare_batch_upload_handle( array( 'local_path' => $f, 'remote_path' => 'uploads/x.txt', 'size' => 4 ) );
		$this->assertTrue( $r['success'] );
		$this->assertTrue( self::isCurl( $r['handle'] ) );
		$this->assertIsResource( $r['file_handle'] );
		fclose( $r['file_handle'] );
		unlink( $f );
	}

	public function test_azure_batch_upload_handle_for_a_missing_file(): void {
		$r = $this->azure()->prepare_batch_upload_handle( array( 'local_path' => '/nope/x', 'remote_path' => 'uploads/x.txt', 'size' => 1 ) );
		$this->assertFalse( $r['success'] );
		$this->assertNull( $r['file_handle'] );
	}

	public function test_azure_download_handle(): void {
		$dest = sys_get_temp_dir() . '/dlx-dl-' . uniqid() . '/a/b.bin';
		$r = $this->azure()->prepare_download_handle( array( 'remote_path' => 'uploads/b.bin', 'local_path' => $dest ) );
		$this->assertTrue( $r['success'], $r['error'] ?? '' );
		$this->assertTrue( self::isCurl( $r['handle'] ) );
		fclose( $r['file_handle'] );
		$this->assertFileExists( $dest );
		@unlink( $dest ); @rmdir( dirname( $dest ) ); @rmdir( dirname( $dest, 2 ) );
	}

	// ── Azure copy / info ───────────────────────────────────

	public function test_azure_copy_blob_success_and_failure(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 202 );
		$this->assertTrue( $this->azure()->copy_blob( 'a.jpg', 'b.jpg' )['success'] );
		$req = $GLOBALS['_test_wp_http_log'][0];
		$this->assertSame( 'PUT', $req['method'] );
		$this->assertStringContainsString( '/media/a.jpg', $req['args']['headers']['x-ms-copy-source'] );

		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404 );
		$this->assertFalse( $this->azure()->copy_blob( 'a.jpg', 'b.jpg' )['success'] );

		$GLOBALS['_test_wp_http'] = fn() => new \WP_Error( 'x', 'down' );
		$this->assertFalse( $this->azure()->copy_blob( 'a.jpg', 'b.jpg' )['success'] );
	}

	public function test_azure_file_info_from_headers(): void {
		$GLOBALS['_test_wp_http'] = fn() => self::raw( 200, '', array( 'content-length' => '77', 'content-md5' => 'abc', 'last-modified' => 'Mon, 01 Jan 2026 00:00:00 GMT' ) );
		$info = $this->azure()->get_file_info( 'a.jpg' );
		$this->assertSame( 77, (int) $info['size'] );
		$this->assertSame( 'abc', $info['md5'] );

		$GLOBALS['_test_wp_http'] = fn() => self::raw( 404 );
		$this->assertFalse( $this->azure()->get_file_info( 'a.jpg' ) );
	}

	// ── DiluxOne handles ────────────────────────────────────

	public function test_diluxone_batch_upload_handle(): void {
		$p = $this->diluxone();
		$f = $this->tmpFile();
		$r = $p->prepare_batch_upload_handle( array( 'local_path' => $f, 'remote_path' => 'uploads/x.txt', 'size' => 4 ) );
		$this->assertTrue( $r['success'], $r['error'] ?? '' );
		$this->assertTrue( self::isCurl( $r['handle'] ) );
		fclose( $r['file_handle'] );
		unlink( $f );
	}

	public function test_diluxone_batch_upload_handle_for_a_missing_file(): void {
		$r = $this->diluxone()->prepare_batch_upload_handle( array( 'local_path' => '/nope', 'remote_path' => 'uploads/x.txt', 'size' => 1 ) );
		$this->assertFalse( $r['success'] );
	}

	public function test_diluxone_download_handle(): void {
		$dest = sys_get_temp_dir() . '/dlx-dl-' . uniqid() . '/c.bin';
		$r = $this->diluxone()->prepare_download_handle( array( 'remote_path' => 'uploads/c.bin', 'local_path' => $dest ) );
		$this->assertTrue( $r['success'], $r['error'] ?? '' );
		$this->assertTrue( self::isCurl( $r['handle'] ) );
		fclose( $r['file_handle'] );
		@unlink( $dest ); @rmdir( dirname( $dest ) );
	}

	// ── DiluxOne list / checksum / errors ───────────────────

	private static function blobsXml( array $blobs, string $next = '' ): string {
		$items = '';
		foreach ( $blobs as $name => $size ) {
			$items .= "<Blob><Name>{$name}</Name><Properties><Content-Length>{$size}</Content-Length><Last-Modified>x</Last-Modified></Properties></Blob>";
		}
		return '<?xml version="1.0"?><EnumerationResults><Blobs>' . $items . "</Blobs><NextMarker>{$next}</NextMarker></EnumerationResults>";
	}

	public function test_diluxone_list_files_pages_through_markers(): void {
		$p = $this->diluxone();
		$GLOBALS['_test_wp_http'] = function ( $m, $url ) {
			if ( strpos( $url, self::API ) === 0 ) {
				return self::json( 200, array( 'data' => array( 'sasToken' => 'sv=1', 'expiresIn' => 3600 ) ) );
			}
			return strpos( $url, 'marker=' ) === false
				? self::raw( 200, self::blobsXml( array( 'uploads/1.jpg' => 1 ), 'M' ) )
				: self::raw( 200, self::blobsXml( array( 'uploads/2.jpg' => 2 ) ) );
		};
		$files = $p->list_files( 'uploads/' );
		$this->assertCount( 2, $files );
		$this->assertSame( 'uploads/2.jpg', $files[1]['path'] );
	}

	public function test_diluxone_list_files_gives_up_on_a_non_retryable_error(): void {
		$p = $this->diluxone();
		$GLOBALS['_test_wp_http'] = function ( $m, $url ) {
			if ( strpos( $url, self::API ) === 0 ) {
				return self::json( 200, array( 'data' => array( 'sasToken' => 'sv=1', 'expiresIn' => 3600 ) ) );
			}
			return self::raw( 404, '<Error><Message>no container</Message></Error>' );
		};
		$files = $p->list_files( 'uploads/' );
		$this->assertSame( array(), $files );
		$blob_calls = array_filter( $GLOBALS['_test_wp_http_log'], fn( $r ) => strpos( $r['url'], self::API ) !== 0 );
		$this->assertLessThanOrEqual( 2, count( $blob_calls ), 'a 404 is not retried three times' );
	}

	public function test_diluxone_list_files_rejects_garbage_xml(): void {
		$p = $this->diluxone();
		$GLOBALS['_test_wp_http'] = function ( $m, $url ) {
			if ( strpos( $url, self::API ) === 0 ) {
				return self::json( 200, array( 'data' => array( 'sasToken' => 'sv=1', 'expiresIn' => 3600 ) ) );
			}
			return self::raw( 200, '<<not xml' );
		};
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid XML' );
		$p->list_files( 'uploads/' );
	}

	public function test_diluxone_checksum(): void {
		$p = $this->diluxone();
		$GLOBALS['_test_wp_http'] = function ( $m, $url ) {
			if ( strpos( $url, self::API ) === 0 ) {
				return self::json( 200, array( 'data' => array( 'sasToken' => 'sv=1', 'expiresIn' => 3600 ) ) );
			}
			return self::raw( 200, '', array( 'content-md5' => 'sum==' ) );
		};
		$this->assertSame( 'sum==', $p->get_file_checksum( 'a.jpg' ) );
	}
}
