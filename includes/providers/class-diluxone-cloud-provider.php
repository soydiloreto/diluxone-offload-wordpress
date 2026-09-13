<?php
/**
 * DiluxOne Cloud Provider
 *
 * Uses SAS tokens for direct Azure Blob Storage access.
 *
 * cURL is confined to the parallel/streaming transfer path:
 * prepare_batch_upload_handle(), prepare_chunked_upload_handle() and
 * prepare_download_handle() build raw handles that SyncManager drives through
 * curl_multi_*, so many files move at once and multi-GB bodies stream from a
 * file handle instead of being buffered in PHP memory. The WP HTTP API has no
 * equivalent: it offers no streamed request body and no parallel transport.
 * Every other operation in this class — auth, metadata, existence checks,
 * checksums, delete, copy, single-file upload and download — goes through
 * wp_remote_*.
 *
 * The fopen/fread/fclose/file_get_contents calls operate on the local temp
 * files feeding those transfers, not on anything under /wp-content/uploads/,
 * so \WP_Filesystem does not apply.
 *
 * Only the rules below are suppressed, and only because of the above:
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_exec
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 *
 * Architecture:
 *   WordPress Plugin --SAS token--> Azure Blob Storage (direct, no proxy)
 *          |
 *          +-- DiluxOne API (verify + sas-token only, ~1 call/hour)
 *
 * @package DiluxOneOffload\Providers
 * @since 1.0.0
 */

namespace DiluxOneOffload\Providers;

use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Logger;
use DiluxOneOffload\MimeHelper;
use DiluxOneOffload\DTOs\FileInfo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DiluxOne Cloud provider — managed cloud storage backed by the DiluxOne Offload
 * One Cloud REST API.
 *
 * All file operations route through https://api.diluxone.com/cloud-storage-wp/v1
 * authenticated with the API key the user enters in the admin Cloud
 * Provider tab. Implements the full CloudStorageClientInterface contract
 * including parallel and chunked-upload helpers used by SyncManager.
 */
class DiluxOneCloudProvider implements CloudStorageClientInterface {

	/** @var string DiluxOne API key */

	private string $api_key;

	/** @var string DiluxOne API base URL */
	private string $api_base_url;

	/** @var string CDN base URL for public file access (from API) */
	private string $cdn_base_url;

	/** @var string WordPress site domain for X-WP-Domain header */
	private string $wp_domain;

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $config Configuration array with 'api_key' and optional 'cdn_base_url'
	 */
	public function __construct( array $config = array() ) {
		$this->api_key      = $config['api_key'] ?? '';
		$this->cdn_base_url = $config['cdn_base_url'] ?? '';
		$this->api_base_url = defined( 'DILUX_API_URL' )
			? DILUX_API_URL
			: 'https://api.diluxone.com/cloud-storage-wp/v1';
		$parsed_host        = wp_parse_url( site_url(), PHP_URL_HOST );
		$this->wp_domain    = ( is_string( $parsed_host ) && $parsed_host !== '' ) ? $parsed_host : 'localhost';
	}

	// ========================================================================
	// SAS Token Management (private)
	// ========================================================================

	/**
	 * Get SAS token from cache or request a new one from the API
	 *
	 * @return string SAS token query string (without leading '?')
	 * @throws \Exception If token cannot be obtained
	 */
	private function get_or_refresh_sas_token(): string {
		$cached = get_transient( 'diluxone_offload_sas_token' );
		if ( $cached !== false ) {
			return $cached;
		}

		$response = wp_remote_post(
			$this->api_base_url . '/storage/sas-token',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
					'X-WP-Domain'   => $this->wp_domain,
				),
				'body'    => '{}',
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'Failed to get SAS token: ' . esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 507 ) {
			$msg = $body['error']['message'] ?? 'Storage quota exceeded';
			throw new \Exception( 'QUOTA_EXCEEDED: ' . esc_html( $msg ) );
		}

		if ( $code !== 200 || ! isset( $body['data']['sasToken'] ) ) {
			$error = $body['error']['message'] ?? "HTTP $code";
			throw new \Exception( 'SAS token error: ' . esc_html( $error ) );
		}

		$sas_token  = $body['data']['sasToken'];
		$expires_in = $body['data']['expiresIn'] ?? 3600;

		// Cache at 83% of expiry (~50 min for 60 min token)
		set_transient( 'diluxone_offload_sas_token', $sas_token, (int) ( $expires_in * 0.83 ) );

		// Update cdn_base_url if provided
		if ( ! empty( $body['data']['containerUrl'] ) ) {
			$this->cdn_base_url = rtrim( $body['data']['containerUrl'], '/' );
			$this->persist_cdn_base_url( $this->cdn_base_url );
		}

		return $sas_token;
	}

	/**
	 * Invalidate cached SAS token (for retry on 403)
	 *
	 * @return void
	 */
	private function invalidate_sas_token(): void {
		delete_transient( 'diluxone_offload_sas_token' );
	}

	/**
	 * Persist cdn_base_url in plugin configuration
	 *
	 * @param string $url CDN base URL
	 * @return void
	 */
	private function persist_cdn_base_url( string $url ): void {
		// Route through ConfigManager so credentials are decrypted on read and
		// re-encrypted on save — keeps the at-rest encryption invariant intact.
		$config = ConfigManager::get_config();
		if ( isset( $config['provider_config'] ) && is_array( $config['provider_config'] ) ) {
			$config['provider_config']['cdn_base_url'] = rtrim( $url, '/' );
			ConfigManager::save_config( $config );
		}
	}

	/**
	 * Execute operation with automatic retry on 403 (SAS token expired)
	 *
	 * @param callable $operation Function that uses SAS token
	 * @return mixed Result of the operation
	 * @throws \Exception If operation fails after retry
	 */
	private function execute_with_retry( callable $operation ) {
		try {
			return $operation();
		} catch ( \Exception $e ) {
			if ( strpos( $e->getMessage(), '403' ) !== false ||
				strpos( $e->getMessage(), 'AuthenticationFailed' ) !== false ||
				strpos( $e->getMessage(), 'AuthorizationFailure' ) !== false ) {
				$this->invalidate_sas_token();
				return $operation();
			}
			throw $e;
		}
	}

	/**
	 * Build Azure Blob URL with SAS token
	 *
	 * @param string $remote_path File path (e.g., "uploads/2025/01/image.jpg")
	 * @param string $extra_params Additional query params (e.g., "comp=block&blockid=xxx")
	 * @return string Full URL with SAS token
	 * @throws \Exception If SAS token cannot be obtained
	 */
	private function build_azure_url( string $remote_path, string $extra_params = '' ): string {
		$sas_token   = $this->get_or_refresh_sas_token();
		$remote_path = ltrim( $remote_path, '/' );

		// URL-encode path components (but not slashes)
		$path_parts    = explode( '/', $remote_path );
		$encoded_parts = array_map( 'rawurlencode', $path_parts );
		$encoded_path  = implode( '/', $encoded_parts );

		$base = rtrim( $this->cdn_base_url, '/' ) . '/' . $encoded_path;

		if ( ! empty( $extra_params ) ) {
			return $base . '?' . $extra_params . '&' . $sas_token;
		}

		return $base . '?' . $sas_token;
	}

	// ========================================================================
	// CloudStorageClientInterface Implementation
	// ========================================================================

	/**
	 * Test connection to DiluxOne API
	 *
	 * @return array<string, mixed> ['success' => bool, 'message' => string]
	 */
	public function test_connection(): array {
		try {
			$response = wp_remote_get(
				$this->api_base_url . '/auth/verify',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->api_key,
						'X-WP-Domain'   => $this->wp_domain,
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => 'Connection failed: ' . $response->get_error_message(),
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code === 200 && isset( $body['data'] ) ) {
				$data = $body['data'];

				// Persist cdn_base_url
				if ( ! empty( $data['cdnBaseUrl'] ) ) {
					$this->cdn_base_url = rtrim( $data['cdnBaseUrl'], '/' );
					$this->persist_cdn_base_url( $this->cdn_base_url );
				}

				$used_mb  = round( ( $data['storageUsedBytes'] ?? 0 ) / 1048576 );
				$limit_mb = round( ( $data['storageLimitBytes'] ?? 0 ) / 1048576 );

				return array(
					'success'      => true,
					'message'      => sprintf(
						'Connected — Plan: %s, %s MB / %s MB used',
						$data['plan'] ?? 'unknown',
						number_format( $used_mb ),
						number_format( $limit_mb )
					),
					'cdn_base_url' => $this->cdn_base_url,
				);
			}

			$error = $body['error']['message'] ?? "HTTP $code";
			return array(
				'success' => false,
				'message' => $error,
			);

		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => 'Connection error: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Get storage usage statistics from DiluxOne API
	 *
	 * Calls GET /stats and caches result in transient for 5 minutes.
	 * This method is specific to DiluxOne (not in CloudStorageClientInterface).
	 *
	 * @param bool $force_refresh Skip transient cache and fetch fresh data
	 * @return array{success: bool, data?: array<string, mixed>, message?: string}
	 */
	public function get_stats( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( 'diluxone_offload_stats' );
			if ( $cached !== false ) {
				return array(
					'success' => true,
					'data'    => $cached,
				);
			}
		}

		try {
			$response = wp_remote_get(
				$this->api_base_url . '/stats',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->api_key,
						'X-WP-Domain'   => $this->wp_domain,
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( $code !== 200 || ! isset( $body['data'] ) ) {
				$error = $body['error']['message'] ?? "HTTP $code";
				delete_transient( 'diluxone_offload_stats' );
				$error_code = (string) $code;
				\DiluxOneOffload\ConfigManager::record_connection_failure( $error_code, $error, 'stats_refresh' );
				return array(
					'success' => false,
					'message' => $error,
				);
			}

			$data = $body['data'];
			set_transient( 'diluxone_offload_stats', $data, 300 );

			return array(
				'success' => true,
				'data'    => $data,
			);

		} catch ( \Exception $e ) {
			delete_transient( 'diluxone_offload_stats' );
			\DiluxOneOffload\ConfigManager::record_connection_failure( 'exception', $e->getMessage(), 'stats_refresh' );
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Upload file to Azure Blob Storage via SAS token
	 *
	 * @param string               $local_path Local file path
	 * @param string               $remote_path Remote path in cloud
	 * @param array<string, mixed> $options Additional options
	 * @return array<string, mixed> ['success' => bool, 'url' => string, 'error' => string]
	 */
	public function upload_file( string $local_path, string $remote_path, array $options = array() ): array {
		return $this->execute_with_retry(
			function () use ( $local_path, $remote_path, $options ) {
				try {
					if ( ! file_exists( $local_path ) ) {
						return array(
							'success' => false,
							'url'     => '',
							'error'   => 'Local file not found: ' . $local_path,
						);
					}

					$remote_path = ltrim( $remote_path, '/' );
					$url         = $this->build_azure_url( $remote_path );

					$path_for_mime = $options['mime_type_from_path'] ?? $local_path;
					$content_type  = MimeHelper::get_mime_type( $path_for_mime );

					$file_content = file_get_contents( $local_path );
					if ( $file_content === false ) {
						return array(
							'success' => false,
							'url'     => '',
							'error'   => 'Failed to open file for reading',
						);
					}

					$response = wp_remote_request(
						$url,
						array(
							'method'  => 'PUT',
							'headers' => array(
								'Content-Type'   => $content_type,
								'x-ms-blob-type' => 'BlockBlob',
								'x-ms-version'   => '2020-04-08',
							),
							'body'    => $file_content,
							'timeout' => 300,
						)
					);

					$http_code  = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
					$curl_error = is_wp_error( $response ) ? $response->get_error_message() : '';

					if ( $http_code === 201 ) {
						$public_url = $this->get_file_url( $remote_path );
						return array(
							'success' => true,
							'url'     => $public_url,
							'error'   => '',
						);
					}

					$error_msg = "Upload failed: HTTP $http_code";
					if ( ! empty( $curl_error ) ) {
						$error_msg .= " - $curl_error";
					}
					if ( $http_code === 403 ) {
						throw new \Exception( $error_msg );
					}

					return array(
						'success' => false,
						'url'     => '',
						'error'   => $error_msg,
					);

				} catch ( \Exception $e ) {
					throw $e;
				}
			}
		);
	}

	/**
	 * Download file from Azure Blob Storage via SAS token
	 *
	 * @param string $remote_path Remote path in cloud
	 * @param string $local_path Local destination path
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function download_file( string $remote_path, string $local_path ): array {
		return $this->execute_with_retry(
			function () use ( $remote_path, $local_path ) {
				try {
					$remote_path = ltrim( $remote_path, '/' );
					$url         = $this->build_azure_url( $remote_path );

					// Create directory if needed
					$dir = dirname( $local_path );
					if ( ! is_dir( $dir ) ) {
						wp_mkdir_p( $dir );
					}

					// 'stream' writes the body straight to disk, so a large blob
					// never has to sit in PHP's memory.
					$response = wp_remote_get(
						$url,
						array(
							'headers'  => array( 'x-ms-version' => '2020-04-08' ),
							'timeout'  => 300,
							'stream'   => true,
							'filename' => $local_path,
						)
					);

					$http_code  = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
					$curl_error = is_wp_error( $response ) ? $response->get_error_message() : '';

					if ( $http_code === 200 ) {
						return array(
							'success' => true,
							'error'   => '',
						);
					}

					// Clean up failed download
					if ( file_exists( $local_path ) ) {
						wp_delete_file( $local_path );
					}

					$error_msg = "Download failed: HTTP $http_code";
					if ( ! empty( $curl_error ) ) {
						$error_msg .= " - $curl_error";
					}
					if ( $http_code === 403 ) {
						throw new \Exception( $error_msg );
					}

					return array(
						'success' => false,
						'error'   => $error_msg,
					);

				} catch ( \Exception $e ) {
					throw $e;
				}
			}
		);
	}

	/**
	 * Check if file exists in Azure Blob Storage
	 *
	 * @param string $remote_path Remote path
	 * @return bool
	 */
	public function file_exists( string $remote_path ): bool {
		try {
			return $this->execute_with_retry(
				function () use ( $remote_path ) {
					$remote_path = ltrim( $remote_path, '/' );
					$url         = $this->build_azure_url( $remote_path );

					$response = wp_remote_head(
						$url,
						array(
							'headers' => array( 'x-ms-version' => '2020-04-08' ),
							'timeout' => 30,
						)
					);

					if ( is_wp_error( $response ) ) {
						throw new \Exception( esc_html( $response->get_error_message() ) );
					}

					$http_code = wp_remote_retrieve_response_code( $response );
					if ( $http_code === 403 ) {
						throw new \Exception( 'HTTP 403' );
					}

					return $http_code === 200;
				}
			);
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Get file checksum from Azure Blob Storage
	 *
	 * @param string $remote_path Remote path
	 * @return string|false MD5 hash (base64) or false
	 */
	public function get_file_checksum( string $remote_path ) {
		try {
			return $this->execute_with_retry(
				function () use ( $remote_path ) {
					$remote_path = ltrim( $remote_path, '/' );
					$url         = $this->build_azure_url( $remote_path );

					$response = wp_remote_head(
						$url,
						array(
							'headers' => array( 'x-ms-version' => '2020-04-08' ),
							'timeout' => 30,
						)
					);

					if ( is_wp_error( $response ) ) {
						throw new \Exception( esc_html( $response->get_error_message() ) );
					}

					$http_code = wp_remote_retrieve_response_code( $response );
					if ( $http_code === 403 ) {
						throw new \Exception( 'HTTP 403' );
					}

					if ( $http_code === 200 ) {
						$md5 = self::header_value( $response, 'content-md5' );
						if ( $md5 !== null ) {
							return $md5;
						}
					}

					return false;
				}
			);
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Get file information from Azure Blob Storage
	 *
	 * @param string $remote_path Remote path
	 * @return array<string, mixed>|false ['size' => int, 'md5' => string, 'last_modified' => string] or false
	 */
	public function get_file_info( string $remote_path ) {
		try {
			return $this->execute_with_retry(
				function () use ( $remote_path ) {
					$remote_path = ltrim( $remote_path, '/' );
					$url         = $this->build_azure_url( $remote_path );

					$response = wp_remote_head(
						$url,
						array(
							'headers' => array( 'x-ms-version' => '2020-04-08' ),
							'timeout' => 30,
						)
					);

					if ( is_wp_error( $response ) ) {
						throw new \Exception( esc_html( $response->get_error_message() ) );
					}

					$http_code = wp_remote_retrieve_response_code( $response );
					if ( $http_code === 403 ) {
						throw new \Exception( 'HTTP 403' );
					}

					if ( $http_code !== 200 ) {
						return false;
					}

					$size = (int) ( self::header_value( $response, 'content-length' ) ?? '0' );

					$file_info = new FileInfo(
						$remote_path,
						$size,
						self::header_value( $response, 'content-md5' ),
						self::header_value( $response, 'last-modified' )
					);
					return $file_info->toArray();
				}
			);
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Delete file from Azure Blob Storage via SAS token
	 *
	 * @param string $remote_path Remote path
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function delete_file( string $remote_path ): array {
		return $this->execute_with_retry(
			function () use ( $remote_path ) {
				try {
					$remote_path = ltrim( $remote_path, '/' );
					$url         = $this->build_azure_url( $remote_path );

					$response = wp_remote_request(
						$url,
						array(
							'method'  => 'DELETE',
							'headers' => array( 'x-ms-version' => '2020-04-08' ),
							'timeout' => 30,
						)
					);

					$http_code  = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
					$curl_error = is_wp_error( $response ) ? $response->get_error_message() : '';

					if ( $http_code === 202 ) {
						return array(
							'success' => true,
							'error'   => '',
						);
					}

					$error_msg = "Delete failed: HTTP $http_code";
					if ( ! empty( $curl_error ) ) {
						$error_msg .= " - $curl_error";
					}
					if ( $http_code === 403 ) {
						throw new \Exception( $error_msg );
					}

					return array(
						'success' => false,
						'error'   => $error_msg,
					);

				} catch ( \Exception $e ) {
					throw $e;
				}
			}
		);
	}

	/**
	 * Copy blob within Azure Blob Storage using server-side copy
	 *
	 * @param string $source_path Source blob path
	 * @param string $dest_path Destination blob path
	 * @return array<string, mixed> ['success' => bool, 'error' => string]
	 */
	public function copy_blob( string $source_path, string $dest_path ): array {
		return $this->execute_with_retry(
			function () use ( $source_path, $dest_path ) {
				try {
					$source_path = ltrim( $source_path, '/' );
					$dest_path   = ltrim( $dest_path, '/' );

					$source_url = $this->build_azure_url( $source_path );
					$dest_url   = $this->build_azure_url( $dest_path );

					$response = wp_remote_request(
						$dest_url,
						array(
							'method'  => 'PUT',
							'headers' => array(
								'x-ms-copy-source' => $source_url,
								'x-ms-version'     => '2020-04-08',
								'Content-Length'   => '0',
							),
							'timeout' => 30,
						)
					);

					$http_code  = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
					$curl_error = is_wp_error( $response ) ? $response->get_error_message() : '';

					if ( $http_code === 202 ) {
						return array(
							'success' => true,
							'error'   => '',
						);
					}

					$error_msg = "Copy failed: HTTP $http_code";
					if ( ! empty( $curl_error ) ) {
						$error_msg .= " - $curl_error";
					}
					if ( $http_code === 403 ) {
						throw new \Exception( $error_msg );
					}

					return array(
						'success' => false,
						'error'   => $error_msg,
					);

				} catch ( \Exception $e ) {
					throw $e;
				}
			}
		);
	}

	/**
	 * List files in Azure Blob Storage
	 *
	 * @param string $remote_path Prefix filter (default: 'uploads/')
	 * @return array<string, mixed> Array of file info arrays
	 *
	 * @throws \Exception When the DiluxOne / Azure REST call fails after all retries.
	 */
	public function list_files( string $remote_path = 'uploads/' ): array {
		$max_retries = 3;
		$retry_delay = 2;

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			try {
				return $this->execute_with_retry(
					function () use ( $remote_path ) {
						$files  = array();
						$marker = null;

						do {
							$params = 'restype=container&comp=list';
							if ( $remote_path ) {
								$params .= '&prefix=' . rawurlencode( $remote_path );
							}
							if ( $marker ) {
								$params .= '&marker=' . rawurlencode( $marker );
							}

							$sas_token = $this->get_or_refresh_sas_token();
							$url       = rtrim( $this->cdn_base_url, '/' ) . '?' . $params . '&' . $sas_token;

							$response = wp_remote_get( $url, array( 'timeout' => 60 ) );

							if ( is_wp_error( $response ) ) {
								throw new \Exception( 'List files error: ' . esc_html( $response->get_error_message() ) );
							}

							$http_code = wp_remote_retrieve_response_code( $response );
							if ( $http_code === 403 ) {
								throw new \Exception( 'HTTP 403: SAS token expired or invalid' );
							}

							$body = wp_remote_retrieve_body( $response );
							if ( empty( $body ) ) {
								throw new \Exception( 'Empty response from Azure' );
							}

							$xml = self::parse_xml( $body );
							if ( $xml === null ) {
								throw new \Exception( 'Invalid XML response from Azure' );
							}

							if ( isset( $xml->Blobs->Blob ) ) {
								foreach ( $xml->Blobs->Blob as $blob ) {
									$files[] = new FileInfo(
										(string) $blob->Name,
										(int) $blob->Properties->{'Content-Length'},
										isset( $blob->Properties->{'Content-MD5'} ) ? (string) $blob->Properties->{'Content-MD5'} : null,
										(string) $blob->Properties->{'Last-Modified'}
									);
								}
							}

							$marker = isset( $xml->NextMarker ) && ! empty( $xml->NextMarker ) ? (string) $xml->NextMarker : null;

						} while ( $marker !== null );

						return array_map(
							function ( FileInfo $fi ) {
								return $fi->toArray();
							},
							$files
						);
					}
				);

			} catch ( \Exception $e ) {
				$error_code = $this->extract_error_code( $e->getMessage() );

				// Do NOT retry client errors (4xx)
				if ( $this->is_non_retryable_error( $error_code ) ) {
					Logger::info( '[DiluxOne Offload DiluxOneProvider] Non-retryable error (' . $error_code . '): ' . $e->getMessage() );
					\DiluxOneOffload\ConfigManager::record_connection_failure(
						$error_code,
						$e->getMessage(),
						'list_files'
					);
					throw $e;
				}

				if ( $attempt < $max_retries ) {
					Logger::info( '[DiluxOne Offload DiluxOneProvider] list_files attempt ' . $attempt . ' failed, retrying: ' . $e->getMessage() );
					sleep( $retry_delay );
					continue;
				}
				throw new \Exception( 'Failed to list files after ' . esc_html( (string) $max_retries ) . ' attempts: ' . esc_html( $e->getMessage() ) );
			}
		}

		throw new \Exception( 'Unexpected error in list_files retry loop' );
	}

	/**
	 * Get public URL for file (without SAS token)
	 *
	 * @param string $remote_path Remote path
	 * @return string Public URL
	 */
	public function get_file_url( string $remote_path ): string {
		$remote_path = ltrim( $remote_path, '/' );
		return rtrim( $this->cdn_base_url, '/' ) . '/' . $remote_path;
	}

	/**
	 * Get provider name
	 *
	 * @return string
	 */
	public function get_provider_name(): string {
		return 'diluxone';
	}

	/**
	 * Prepare batch upload handle for parallel sync (curl_multi)
	 *
	 * @param array<string, mixed> $file_info ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_batch_upload_handle( array $file_info ): array {
		$local_path  = $file_info['local_path'];
		$remote_path = $file_info['remote_path'];

		if ( ! file_exists( $local_path ) ) {
			return array(
				'success'     => false,
				'error'       => 'File not found: ' . $local_path,
				'file_handle' => null,
			);
		}

		try {
			$file_size    = filesize( $local_path );
			$remote_path  = ltrim( $remote_path, '/' );
			$url          = $this->build_azure_url( $remote_path );
			$content_type = MimeHelper::get_mime_type( $remote_path );

			$file_handle = fopen( $local_path, 'rb' );
			if ( ! $file_handle ) {
				return array(
					'success'     => false,
					'error'       => 'Failed to open file for reading',
					'file_handle' => null,
				);
			}

			$ch = curl_init();
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL            => $url,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CUSTOMREQUEST  => 'PUT',
					CURLOPT_UPLOAD         => true,
					CURLOPT_INFILE         => $file_handle,
					CURLOPT_INFILESIZE     => $file_size,
					CURLOPT_HTTPHEADER     => array(
						'Content-Type: ' . $content_type,
						'Content-Length: ' . $file_size,
						'x-ms-blob-type: BlockBlob',
						'x-ms-version: 2020-04-08',
					),
					CURLOPT_TIMEOUT        => 90,
					CURLOPT_CONNECTTIMEOUT => 30,
				)
			);

			return array(
				'success'     => true,
				'handle'      => $ch,
				'file_handle' => $file_handle,
			);

		} catch ( \Exception $e ) {
			return array(
				'success'     => false,
				'error'       => 'Exception: ' . $e->getMessage(),
				'file_handle' => null,
			);
		}
	}

	/**
	 * Prepare chunked upload handle for large files (>10MB)
	 * Uses Azure Put Block + Put Block List with SAS token
	 *
	 * @param array<string, mixed> $file_info ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_chunked_upload_handle( array $file_info ): array {
		$local_path  = $file_info['local_path'];
		$remote_path = $file_info['remote_path'];

		try {
			$remote_path = ltrim( $remote_path, '/' );
			$sas_token   = $this->get_or_refresh_sas_token();

			// URL-encode path components
			$path_parts    = explode( '/', $remote_path );
			$encoded_parts = array_map( 'rawurlencode', $path_parts );
			$encoded_path  = implode( '/', $encoded_parts );

			$base_url = rtrim( $this->cdn_base_url, '/' ) . '/' . $encoded_path;

			$chunk_size = 4194304; // 4MB
			$block_ids  = array();

			$fp = fopen( $local_path, 'rb' );
			if ( ! $fp ) {
				return array(
					'success'     => false,
					'error'       => 'Failed to open file for chunked upload',
					'file_handle' => null,
				);
			}

			$block_index = 0;
			while ( ! feof( $fp ) ) {
				$chunk = fread( $fp, $chunk_size );
				if ( $chunk === false || strlen( $chunk ) === 0 ) {
					break;
				}

				$block_id    = base64_encode( str_pad( (string) $block_index, 6, '0', STR_PAD_LEFT ) );
				$block_ids[] = $block_id;

				$block_url = $base_url . '?comp=block&blockid=' . rawurlencode( $block_id ) . '&' . $sas_token;

				$ch = curl_init();
				curl_setopt_array(
					$ch,
					array(
						CURLOPT_URL            => $block_url,
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_CUSTOMREQUEST  => 'PUT',
						CURLOPT_POSTFIELDS     => $chunk,
						CURLOPT_HTTPHEADER     => array(
							'Content-Length: ' . strlen( $chunk ),
							'x-ms-version: 2020-04-08',
						),
						CURLOPT_TIMEOUT        => 60,
						CURLOPT_CONNECTTIMEOUT => 30,
					)
				);

				$response   = curl_exec( $ch );
				$http_code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				$curl_error = curl_error( $ch );
				if ( $http_code !== 201 ) {
					fclose( $fp );
					$error_msg = "Failed to upload block $block_index: HTTP $http_code";
					if ( ! empty( $curl_error ) ) {
						$error_msg .= " - $curl_error";
					}
					return array(
						'success'     => false,
						'error'       => $error_msg,
						'file_handle' => null,
					);
				}

				++$block_index;
			}

			fclose( $fp );

			// Commit blocks with Put Block List
			$blocklist_url = $base_url . '?comp=blocklist&' . $sas_token;
			$content_type  = MimeHelper::get_mime_type( $remote_path );

			$block_list_xml = '<?xml version="1.0" encoding="utf-8"?><BlockList>';
			foreach ( $block_ids as $block_id ) {
				$block_list_xml .= '<Latest>' . $block_id . '</Latest>';
			}
			$block_list_xml .= '</BlockList>';

			$ch = curl_init();
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL            => $blocklist_url,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CUSTOMREQUEST  => 'PUT',
					CURLOPT_POSTFIELDS     => $block_list_xml,
					CURLOPT_HTTPHEADER     => array(
						'Content-Type: application/xml',
						'Content-Length: ' . strlen( $block_list_xml ),
						'x-ms-blob-content-type: ' . $content_type,
						'x-ms-version: 2020-04-08',
					),
					CURLOPT_TIMEOUT        => 60,
					CURLOPT_CONNECTTIMEOUT => 30,
				)
			);

			return array(
				'success'     => true,
				'handle'      => $ch,
				'file_handle' => null,
			);

		} catch ( \Exception $e ) {
			return array(
				'success'     => false,
				'error'       => 'Chunked upload exception: ' . $e->getMessage(),
				'file_handle' => null,
			);
		}
	}

	/**
	 * Prepare download handle for parallel downloads (reverse sync)
	 *
	 * @param array<string, mixed> $file_info ['local_path' => string, 'remote_path' => string]
	 * @return array<string, mixed> ['success' => bool, 'handle' => resource|null, 'error' => string, 'file_handle' => resource|null]
	 */
	public function prepare_download_handle( array $file_info ): array {
		$remote_path = $file_info['remote_path'];
		$local_path  = $file_info['local_path'];

		try {
			$remote_path = ltrim( $remote_path, '/' );
			$url         = $this->build_azure_url( $remote_path );

			// Create directory if needed
			$dir = dirname( $local_path );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$file_handle = fopen( $local_path, 'wb' );
			if ( ! $file_handle ) {
				return array(
					'success'     => false,
					'error'       => 'Failed to open local file for writing',
					'file_handle' => null,
				);
			}

			$ch = curl_init();
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL            => $url,
					CURLOPT_RETURNTRANSFER => false,
					CURLOPT_FILE           => $file_handle,
					CURLOPT_HTTPHEADER     => array(
						'x-ms-version: 2020-04-08',
					),
					CURLOPT_TIMEOUT        => 90,
					CURLOPT_CONNECTTIMEOUT => 30,
					CURLOPT_FOLLOWLOCATION => false,
				)
			);

			return array(
				'success'     => true,
				'handle'      => $ch,
				'file_handle' => $file_handle,
			);

		} catch ( \Exception $e ) {
			return array(
				'success'     => false,
				'error'       => 'Download preparation exception: ' . $e->getMessage(),
				'file_handle' => null,
			);
		}
	}

	/**
	 * Extract HTTP error code from exception message.
	 *
	 * @param string $message Caught exception message
	 * @return string Error code (e.g. '403', '401', 'network')
	 */
	private function extract_error_code( string $message ): string {
		if ( preg_match( '/\b(400|401|403|404|409|500|502|503)\b/', $message, $matches ) ) {
			return $matches[1];
		}
		if ( stripos( $message, 'timeout' ) !== false ) {
			return 'timeout';
		}
		if ( stripos( $message, 'cURL' ) !== false || stripos( $message, 'network' ) !== false ) {
			return 'network';
		}
		return 'unknown';
	}

	/**
	 * Check if error code is non-retryable (client errors).
	 *
	 * @param string $error_code Error code from extract_error_code()
	 * @return bool True if error should NOT be retried
	 */
	private function is_non_retryable_error( string $error_code ): bool {
		return in_array( $error_code, array( '400', '401', '403', '404', '409' ), true );
	}

	/**
	 * Parse an Azure REST error body without emitting PHP warnings.
	 *
	 * Azure returns its error detail as XML, but an error body is not
	 * guaranteed to be well-formed (proxies and gateways sometimes return
	 * HTML). libxml's internal error buffer is the supported way to parse
	 * untrusted XML quietly; the `@` operator would hide real problems too.
	 *
	 * @param string $body Raw response body.
	 * @return \SimpleXMLElement|null Parsed XML, or null when it is not valid XML.
	 */
	private static function parse_xml( string $body ): ?\SimpleXMLElement {
		if ( trim( $body ) === '' ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $xml === false ? null : $xml;
	}

	/**
	 * Read a single response header as a trimmed string.
	 *
	 * WordPress returns an array from wp_remote_retrieve_header() when a header
	 * appears more than once in the response; take the first value in that case
	 * so callers always get a scalar.
	 *
	 * @param array<string, mixed>|\WP_Error $response Response from wp_remote_*.
	 * @param string                         $header   Header name, lowercase.
	 * @return string|null Trimmed value, or null when absent or empty.
	 */
	private static function header_value( $response, string $header ): ?string {
		$value = wp_remote_retrieve_header( $response, $header );

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		return $value === '' ? null : $value;
	}
}
