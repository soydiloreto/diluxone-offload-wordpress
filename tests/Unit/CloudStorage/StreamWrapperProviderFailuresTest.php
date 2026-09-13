<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

/**
 * The wrapper on top of a provider that *throws*: the DiluxOne provider
 * raises after two 403s in a row (its SAS refresh did not help). Every
 * wrapper operation must survive that — an image upload must not take the
 * whole request down with an uncaught exception.
 */
class StreamWrapperProviderFailuresTest extends TestCase {

	private const P   = 'diluxoneoffload';
	private const API = 'https://api.diluxone.com/cloud-storage-wp/v1';

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/dlx-wp-content' );
		}
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		$GLOBALS['_test_wp_options']    = array(
			'diluxone_offload_config'       => array( 'cloud_provider' => 'diluxone', 'provider_config' => array( 'api_key' => 'k', 'cdn_base_url' => 'https://cdn.example.net/c' ) ),
			'diluxone_offload_plugin_state' => PluginState::SYNCED,
		);
		$GLOBALS['_test_wp_transients'] = array();
		$GLOBALS['_test_wp_http_log']   = array();
		$GLOBALS['_test_wp_hooks']      = array();
		// The API hands out a token; the blob store answers everything with 403.
		$GLOBALS['_test_wp_http'] = function ( $m, $url ) {
			if ( strpos( $url, self::API ) === 0 ) {
				return array( 'response' => array( 'code' => 200, 'message' => '' ), 'body' => json_encode( array( 'data' => array( 'sasToken' => 'sv=1', 'expiresIn' => 3600 ) ) ), 'headers' => array() );
			}
			return array( 'response' => array( 'code' => 403, 'message' => '' ), 'body' => '', 'headers' => array() );
		};
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

	private function resetClient(): void {
		$p = new \ReflectionProperty( CloudStreamWrapper::class, 'cloud_client' );
		$p->setValue( null, null );
	}

	public function test_a_write_whose_upload_throws_fails_and_records_the_failure(): void {
		$fh = fopen( self::P . '://uploads/x.txt', 'w' );
		fwrite( $fh, 'x' );
		$this->assertFalse( @fflush( $fh ) );
		$this->assertTrue( fclose( $fh ), 'close never throws' );
		$health = ConfigManager::get_connection_health();
		$this->assertSame( 'unhealthy', $health['status'] );
		$this->assertSame( 'exception', $health['error_code'] );
	}

	public function test_a_read_whose_download_throws_fails_to_open(): void {
		$this->assertFalse( @fopen( self::P . '://uploads/x.txt', 'r' ) );
	}

	public function test_append_whose_download_throws_starts_from_empty(): void {
		$fh = fopen( self::P . '://uploads/x.txt', 'a' );
		$this->assertNotFalse( $fh );
		fclose( $fh );
	}

	public function test_unlink_whose_delete_throws_still_reports_success(): void {
		$this->assertTrue( unlink( self::P . '://uploads/x.txt' ) );
	}

	public function test_rename_whose_copy_throws_fails(): void {
		$this->assertFalse( @rename( self::P . '://uploads/a.txt', self::P . '://uploads/b.txt' ) );
	}

	public function test_rename_whose_delete_throws_after_a_good_copy_still_succeeds(): void {
		$GLOBALS['_test_wp_http'] = function ( $m, $url ) {
			if ( strpos( $url, self::API ) === 0 ) {
				return array( 'response' => array( 'code' => 200, 'message' => '' ), 'body' => json_encode( array( 'data' => array( 'sasToken' => 'sv=1', 'expiresIn' => 3600 ) ) ), 'headers' => array() );
			}
			return array( 'response' => array( 'code' => $m === 'PUT' ? 202 : 403, 'message' => '' ), 'body' => '', 'headers' => array() );
		};
		$this->assertTrue( rename( self::P . '://uploads/a.txt', self::P . '://uploads/b.txt' ) );
	}

	public function test_stat_whose_head_throws_reports_missing(): void {
		$this->assertFalse( @file_exists( self::P . '://uploads/x.txt' ) );
	}
}
