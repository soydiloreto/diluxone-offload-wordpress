<?php
/**
 * Cloud Stream Wrapper
 *
 * This file IS a PHP stream wrapper implementation. It must operate at the native
 * filesystem layer (fopen/fread/fwrite/fclose/unlink) on temporary files outside
 * of /wp-content/uploads/, so the \WP_Filesystem abstraction cannot be used here.
 * Likewise, trigger_error() is required by the stream wrapper protocol so that
 * callers like fopen() can detect errors. These rules are intentionally suppressed
 * file-wide:
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloud Stream Wrapper
 *
 * Custom stream wrapper that redirects file operations to cloud storage.
 * This is the core component that makes WordPress write directly to cloud.
 */
class CloudStreamWrapper {

	/** @var string Protocol name */
	const PROTOCOL = 'diluxoneoffload';

	/** @var resource Stream context (set by PHP automatically for stream wrappers) */
	public $context;

	/** @var resource|false|null Current file handle (null before stream_open, false on fopen failure, resource otherwise) */
	private $handle = null;

	/** @var string Current file path */
	private $path = '';

	/** @var string Current file content (for writing) */
	private $content = '';

	/** @var int Current position in file */
	private $position = 0;

	/** @var string File mode (r, w, a, etc.) */
	private $mode = '';

	/** @var CloudStorageClientInterface|null */
	private static $cloud_client = null;

	/**
	 * @var array<string, mixed> Statistics cache
	 */
	private static $stat_cache = array();

	/**
	 * @var array<string, mixed> File content cache (like Infinite Uploads)
	 */
	private static $file_cache = array();

	/** @var int Maximum file size to cache (32MB like Infinite Uploads) */
	const CACHE_MAX_BYTES = 33554432; // 32 * 1024 * 1024

	/**
	 * Set when stream_flush() wrote the content to local disk instead of the
	 * cloud because the connection is unhealthy. stream_close() checks it so
	 * the same content is not then pushed to the cloud anyway, which would
	 * defeat the fallback and add one more failure to the health counter.
	 *
	 * @var bool
	 */
	private bool $saved_locally = false;

	/** @var string|null Per-request memo of the cloud_host (host where assets are
	 *  served from). Empty string when the plugin is not configured. */
	private static ?string $cloud_host_cache = null;

	/**
	 * @var array<string, mixed>|null \Iterator for directory listing
	 */
	private $dir_iterator = null;

	/** @var string Current directory path being read */
	private $dir_path = '';

	/** @var string Prefix for directory listing */
	private $dir_prefix = '';

	/**
	 * Register the stream wrapper
	 *
	 * @return bool
	 */
	public static function register() {
		if ( in_array( self::PROTOCOL, stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( self::PROTOCOL );
		}

		$registered = stream_wrapper_register( self::PROTOCOL, __CLASS__ );

		if ( $registered ) {
			Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Registered protocol: ' . self::PROTOCOL . '://' );
		} else {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] Failed to register protocol' );
		}

		return $registered;
	}

	/**
	 * Unregister the stream wrapper
	 *
	 * @return bool
	 */
	public static function unregister() {
		if ( in_array( self::PROTOCOL, stream_get_wrappers(), true ) ) {
			$unregistered = stream_wrapper_unregister( self::PROTOCOL );

			if ( $unregistered ) {
				Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Unregistered protocol: ' . self::PROTOCOL . '://' );
			}

			return $unregistered;
		}

		return true;
	}

	/**
	 * Check if stream wrapper is active
	 *
	 * @return bool
	 */
	public static function is_active() {
		return in_array( self::PROTOCOL, stream_get_wrappers(), true );
	}

	/**
	 * Activate cloud offloading by replacing wp_upload_dir
	 *
	 * @return bool
	 */
	public static function activate_offloading() {
		$state = ConfigManager::get_state();

		// If already active, just ensure filters are registered
		if ( $state === PluginState::OFFLOADING_ACTIVE ) {
			// Ensure stream wrapper is registered
			self::register();

			// Re-register filters (WordPress removes them on some requests)
			remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 10 );

			add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 10, 1 );

			// Re-arm HTTPS upgrade for cloud-host URLs (cheap; idempotent).
			self::register_force_https_filters();

			// Re-setup admin hooks (they might have been removed)
			self::setup_admin_hooks();

			return true;
		}

		if ( ! PluginState::can_activate_offloading( $state ) ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] Cannot activate offloading in state: ' . $state );
			return false;
		}

		// Register stream wrapper
		if ( ! self::register() ) {
			return false;
		}

		// Hook into WordPress upload directory
		add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 10, 1 );

		// Re-upgrade cloud-host URLs to https:// when WP core's set_url_scheme()
		// downgrades them on plain-HTTP local installs.
		self::register_force_https_filters();

		// Setup admin hooks for plugin/theme/core installation/updates
		self::setup_admin_hooks();

		// Update state
		ConfigManager::set_state( PluginState::OFFLOADING_ACTIVE );

		// Drop the metadata of a finished forward sync.
		// It is of no use once offloading is on, and leaving it behind makes
		// validate_multi_tab() read a dead heartbeat and drop the plugin back
		// to CONFIGURED.
		delete_option( 'diluxone_offload_sync_meta' );
		Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Cleared completed sync metadata' );

		Logger::info( '[DiluxOne Offload CloudStreamWrapper] Offloading activated' );

		return true;
	}

	/**
	 * Deactivate cloud offloading
	 *
	 * @return bool
	 */
	public static function deactivate_offloading() {
		// Remove WordPress hooks
		remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 10 );
		self::unregister_force_https_filters();

		// Unregister stream wrapper
		self::unregister();

		// Update state
		ConfigManager::set_state( PluginState::SYNCED );

		Logger::info( '[DiluxOne Offload CloudStreamWrapper] Offloading deactivated' );

		return true;
	}

	/**
	 * Setup admin hooks to disable cloud offloading during plugin/theme/core installation/updates
	 *
	 * WordPress needs to use wp-content/upgrade/ directory for these operations,
	 * not uploads/. We temporarily remove the upload_dir filter to allow this.
	 *
	 * Inspired by Infinite Uploads but improved to cover more cases.
	 */
	private static function setup_admin_hooks(): void {
		// Disable cloud offloading during WordPress core, plugin, and theme updates/installations
		add_action( 'load-update.php', array( __CLASS__, 'tear_down' ) );           // General updates page
		add_action( 'load-update-core.php', array( __CLASS__, 'tear_down' ) );      // WordPress core updates
		add_action( 'load-plugin-install.php', array( __CLASS__, 'tear_down' ) );   // Plugin installation
		add_action( 'load-theme-install.php', array( __CLASS__, 'tear_down' ) );    // Theme installation
	}

	/**
	 * Temporarily disable cloud offloading for plugin/theme/core operations
	 *
	 * This removes the upload_dir filter so WordPress can use wp-content/upgrade/
	 * directory instead of uploads/ for plugin/theme/core files.
	 *
	 * Called automatically by load-* hooks during admin operations.
	 */
	public static function tear_down(): void {
		remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );
		Logger::warning( '[DiluxOne Offload CloudStreamWrapper] Temporarily disabled upload_dir filter for plugin/theme/core operation' );
	}

	/**
	 * Filter WordPress upload directory to use cloud protocol
	 *
	 * Double protection: this method should not be called during plugin/theme/core
	 * operations due to tear_down() hooks, but we add a path check as extra safety.
	 *
	 * @param array<string, mixed> $upload_dir
	 * @return array<string, mixed>
	 */
	public static function filter_upload_dir( $upload_dir ) {
		// Extra safety: Skip filtering if path contains 'upgrade' directory
		// This handles edge cases where tear_down() hooks might not fire
		if ( isset( $upload_dir['path'] ) && strpos( $upload_dir['path'], 'wp-content/upgrade' ) !== false ) {
			Logger::warning( '[DiluxOne Offload CloudStreamWrapper] Skipping upload_dir filter - detected upgrade directory' );
			return $upload_dir;
		}

		if ( isset( $upload_dir['basedir'] ) && strpos( $upload_dir['basedir'], 'wp-content/upgrade' ) !== false ) {
			Logger::warning( '[DiluxOne Offload CloudStreamWrapper] Skipping upload_dir filter - detected upgrade directory in basedir' );
			return $upload_dir;
		}

		$original_path    = $upload_dir['path'];
		$original_basedir = $upload_dir['basedir'];

		// Convert local paths to cloud protocol
		$upload_dir['path'] = str_replace(
			WP_CONTENT_DIR . '/uploads',
			self::PROTOCOL . '://uploads',
			$original_path
		);

		$upload_dir['basedir'] = str_replace(
			WP_CONTENT_DIR . '/uploads',
			self::PROTOCOL . '://uploads',
			$original_basedir
		);

		// URLs should point to cloud storage
		$cloud_client = self::get_cloud_client();
		if ( $cloud_client ) {
			try {
				// Extract relative path from uploads directory, keeping 'uploads/' prefix
				$relative_path     = str_replace( WP_CONTENT_DIR . '/', '', $original_path );
				$upload_dir['url'] = $cloud_client->get_file_url( $relative_path );

				// For baseurl, use just 'uploads'
				$upload_dir['baseurl'] = $cloud_client->get_file_url( 'uploads' );
			} catch ( \Exception $e ) {
				Logger::error( '[DiluxOne Offload CloudStreamWrapper] filter_upload_dir exception: ' . $e->getMessage() );
				// Fall back to original URLs on error
			}
		}

		return $upload_dir;
	}

	// ------------------------------------------------------------------
	// Force-HTTPS for cloud-host URLs
	//
	// WHY: WP core's set_url_scheme() downgrades https:// to http:// when the
	// request comes in over plain HTTP (is_ssl() === false). That's harmless
	// for local URLs, but Azure Blob Storage rejects HTTP with a 400, breaking
	// the front-end whenever offloading is on and the WP site URL is plain
	// http (typical for local dev: http://localhost:8090).
	//
	// The fix is small and local: a filter on the URL-emitting hooks that
	// re-applies https:// only when the URL points at the cloud_host. We do
	// not touch URLs at any other host (including the WP site itself), so
	// the rest of WordPress keeps working as configured.
	// ------------------------------------------------------------------

	/**
	 * Register the URL filters that re-upgrade cloud-host URLs to https://.
	 * Idempotent — duplicate registration is removed first.
	 */
	public static function register_force_https_filters(): void {
		self::unregister_force_https_filters();
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'force_https_on_url' ), 99, 1 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'force_https_on_srcset' ), 99, 1 );
		add_filter( 'style_loader_src', array( __CLASS__, 'force_https_on_url' ), 99, 1 );
		add_filter( 'script_loader_src', array( __CLASS__, 'force_https_on_url' ), 99, 1 );
	}

	/**
	 * Remove the URL filters registered by register_force_https_filters().
	 */
	public static function unregister_force_https_filters(): void {
		remove_filter( 'wp_get_attachment_url', array( __CLASS__, 'force_https_on_url' ), 99 );
		remove_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'force_https_on_srcset' ), 99 );
		remove_filter( 'style_loader_src', array( __CLASS__, 'force_https_on_url' ), 99 );
		remove_filter( 'script_loader_src', array( __CLASS__, 'force_https_on_url' ), 99 );
	}

	/**
	 * Filter callback. Returns $url unchanged unless it starts with
	 * "http://<cloud_host>", in which case it is reissued as
	 * "https://<cloud_host>...".
	 *
	 * @param mixed $url
	 * @return mixed
	 */
	public static function force_https_on_url( $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return $url;
		}
		$cloud_host = self::get_cloud_host();
		if ( $cloud_host === '' || ! self::is_force_https_enabled() ) {
			return $url;
		}
		$needle = 'http://' . $cloud_host;
		if ( stripos( $url, $needle ) === 0 ) {
			return 'https://' . substr( $url, strlen( 'http://' ) );
		}
		return $url;
	}

	/**
	 * Adapter for wp_calculate_image_srcset, which returns an array keyed by
	 * image width with each entry containing a 'url' field.
	 *
	 * @param mixed $sources
	 * @return mixed
	 */
	public static function force_https_on_srcset( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $key => $source ) {
			if ( is_array( $source ) && isset( $source['url'] ) && is_string( $source['url'] ) ) {
				$sources[ $key ]['url'] = self::force_https_on_url( $source['url'] );
			}
		}
		return $sources;
	}

	/**
	 * Read the force_https_on_cloud setting from the plugin config.
	 * Defaults to true so the fix is on out-of-the-box.
	 */
	private static function is_force_https_enabled(): bool {
		$config = ConfigManager::get_config();
		return ! empty( $config['force_https_on_cloud'] );
	}

	/**
	 * Lower-cased host where the cloud-storage plugin serves assets from.
	 * Returns '' when the plugin is not configured. Memoised per-request.
	 */
	private static function get_cloud_host(): string {
		if ( self::$cloud_host_cache !== null ) {
			return self::$cloud_host_cache;
		}
		$config = ConfigManager::get_config();
		if ( empty( $config['cloud_provider'] ) ) {
			self::$cloud_host_cache = '';
			return self::$cloud_host_cache;
		}
		$provider = $config['cloud_provider'];
		$pc       = $config['provider_config'] ?? array();

		$host = '';
		if ( $provider === 'azure' && ! empty( $pc['storage_account'] ) ) {
			$custom = $pc['custom_domain'] ?? '';
			if ( is_string( $custom ) && $custom !== '' ) {
				$parsed = wp_parse_url( $custom, PHP_URL_HOST );
				$host   = is_string( $parsed ) && $parsed !== '' ? $parsed : $custom;
			} else {
				$host = $pc['storage_account'] . '.blob.core.windows.net';
			}
		} elseif ( $provider === 'diluxone' && ! empty( $pc['cdn_host'] ) ) {
			$host = (string) $pc['cdn_host'];
		}

		self::$cloud_host_cache = strtolower( $host );
		return self::$cloud_host_cache;
	}


	/**
	 * Stream wrapper: Open file
	 *
	 * @param string $path
	 * @param string $mode
	 * @param int    $options
	 * @param string &$opened_path
	 * @return bool
	 */
	public function stream_open( $path, $mode, $options, &$opened_path ) {
		$this->path          = $this->parse_path( $path );
		$this->mode          = $mode;
		$this->position      = 0;
		$this->content       = '';
		$this->saved_locally = false;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Opening: ' . $this->path . ' (mode: ' . $mode . ')' );
		}

		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] Cloud client not available' );
			return false;
		}

		// Read/write: keep the blob in the buffer so writes reach the cloud on close.
		if ( strpos( $mode, 'r' ) !== false && strpos( $mode, '+' ) !== false ) {
			$cached_content = $this->cache_get( $this->path );
			if ( $cached_content === null ) {
				$temp_file = wp_tempnam( $this->path );
				try {
					$result = $cloud_client->download_file( $this->path, $temp_file );
				} catch ( \Exception $e ) {
					Logger::error( '[DiluxOne Offload CloudStreamWrapper] stream_open read/write exception: ' . $this->path . ' - ' . $e->getMessage() );
					@unlink( $temp_file );
					return false;
				}
				if ( ! $result['success'] ) {
					@unlink( $temp_file );
					return false;
				}
				$cached_content = (string) file_get_contents( $temp_file );
				unlink( $temp_file );
			}
			$this->content = $cached_content;
			return true;
		}

		// Handle different modes
		if ( strpos( $mode, 'r' ) !== false ) {
			// Read mode - check cache first (like Infinite Uploads)
			$cached_content = $this->cache_get( $this->path );

			if ( $cached_content !== null ) {
				// ✅ Cache HIT - use cached content
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Cache HIT: ' . $this->path );
				}

				// Write cached content to temp file
				$temp_file = wp_tempnam( $this->path );
				file_put_contents( $temp_file, $cached_content );
				$this->handle = fopen( $temp_file, $mode );
				return $this->handle !== false;
			}

			// ❌ Cache MISS - download from Azure
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Cache MISS: ' . $this->path );
			}

			$temp_file = wp_tempnam( $this->path );

			try {
				$result = $cloud_client->download_file( $this->path, $temp_file );
			} catch ( \Exception $e ) {
				Logger::error( '[DiluxOne Offload CloudStreamWrapper] stream_open read exception: ' . $this->path . ' - ' . $e->getMessage() );
				@unlink( $temp_file );
				return false;
			}

			if ( $result['success'] ) {
				// Cache the downloaded content for future reads
				$content = file_get_contents( $temp_file );
				if ( $content !== false ) {
					$this->cache_set( $this->path, $content );
				}

				$this->handle = fopen( $temp_file, $mode );
				return $this->handle !== false;
			}

			return false;
		} elseif ( strpos( $mode, 'w' ) !== false || strpos( $mode, 'a' ) !== false ) {
			// Write/Append mode - prepare for writing
			if ( strpos( $mode, 'a' ) !== false ) {
				// Append: try to download first; a 404 simply means a new file.
				$temp_file = wp_tempnam( $this->path );
				try {
					$result = $cloud_client->download_file( $this->path, $temp_file );

					if ( $result['success'] ) {
						$buffer = file_get_contents( $temp_file );
						if ( $buffer !== false ) {
							$this->content = $buffer;
						}
						unlink( $temp_file );
					}
				} catch ( \Exception $e ) {
					Logger::error( '[DiluxOne Offload CloudStreamWrapper] stream_open append exception: ' . $this->path . ' - ' . $e->getMessage() );
					@unlink( $temp_file );
				}
				// On failure $this->content stays empty, i.e. a new file.
			}
			return true;
		}

		return false;
	}

	/**
	 * Stream wrapper: Read from file
	 *
	 * @param int $count
	 * @return string|false
	 */
	public function stream_read( $count ) {
		if ( $this->handle ) {
			return $count > 0 ? fread( $this->handle, $count ) : '';
		}

		// Read from content buffer
		$data            = substr( $this->content, $this->position, $count );
		$this->position += strlen( $data );

		return $data;
	}

	/**
	 * Stream wrapper: Write to file
	 *
	 * @param string $data
	 * @return int
	 */
	public function stream_write( $data ) {
		if ( $this->handle ) {
			$written = fwrite( $this->handle, $data );
			return $written === false ? 0 : $written;
		}

		// Write to content buffer
		$data_length = strlen( $data );

		// Handle different write positions
		if ( $this->mode === 'a' || $this->mode === 'a+' ) {
			// Append mode
			$this->content .= $data;
			$this->position = strlen( $this->content );
		} else {
			// Overwrite mode
			$this->content   = substr( $this->content, 0, $this->position ) .
							$data .
							substr( $this->content, $this->position + $data_length );
			$this->position += $data_length;
		}

		return $data_length;
	}

	/**
	 * Stream wrapper: Flush buffer to cloud
	 *
	 * Implements stream_flush() to support file_put_contents() which calls:
	 * fopen() -> fwrite() -> fflush() -> fclose()
	 *
	 * This is CRITICAL for Elementor, Astra, and Spectra CSS/JS file generation.
	 * Without this method, file_put_contents() fails silently.
	 *
	 * Copied from Infinite Uploads best practices (lines 538-615)
	 * Optimizations:
	 * - Caches stat after upload (avoids HEAD requests)
	 * - Caches file content (avoids re-downloads)
	 * - Reuses Azure client (no re-authentication)
	 *
	 * @return bool
	 */
	public function stream_flush() {
		// Read-only streams cannot be flushed (like Infinite Uploads line 539)
		if ( strpos( $this->mode, 'r' ) === 0 && strpos( $this->mode, '+' ) === false ) {
			return false;
		}

		// If using temp file handle, flush it
		if ( $this->handle ) {
			return fflush( $this->handle );
		}

		// If content buffer is empty, nothing to flush
		if ( empty( $this->content ) ) {
			return true;
		}

		// Pre-upload health check: if 3+ consecutive failures, fallback to local
		$health = \DiluxOneOffload\ConfigManager::get_connection_health();
		if ( ( $health['consecutive_failures'] ?? 0 ) >= 3 ) {
			$relative_path = $this->path;
			// Strip 'uploads/' prefix if present to get relative path within uploads dir
			if ( strpos( $relative_path, 'uploads/' ) === 0 ) {
				$relative_path = substr( $relative_path, 8 );
			}
			$local_path = WP_CONTENT_DIR . '/uploads/' . $relative_path;
			$local_dir  = dirname( $local_path );
			if ( ! is_dir( $local_dir ) ) {
				wp_mkdir_p( $local_dir );
			}
			$written = @file_put_contents( $local_path, $this->content );
			if ( $written !== false ) {
				$this->saved_locally = true;
				Logger::warning( '[DiluxOne Offload CloudStreamWrapper] FALLBACK: Saved locally due to unhealthy connection (' . $health['consecutive_failures'] . ' failures): ' . $this->path );
				// Track fallback for admin notification
				$fallbacks = get_transient( 'diluxone_offload_fallback_uploads' );
				if ( ! is_array( $fallbacks ) ) {
					$fallbacks = array();
				}
				$fallbacks[] = array(
					'path' => $this->path,
					'time' => time(),
				);
				if ( count( $fallbacks ) > 100 ) {
					$fallbacks = array_slice( $fallbacks, -100 );
				}
				set_transient( 'diluxone_offload_fallback_uploads', $fallbacks, DAY_IN_SECONDS );
				return true;
			}
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] FALLBACK FAILED: Could not write to local path: ' . $local_path );
		}

		// Upload content to cloud
		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::error( '[DiluxOne Offload CloudStreamWrapper] stream_flush: Cloud client not available' );
			}
			return false;
		}

		// Create temporary file with buffered content
		$temp_file = wp_tempnam( $this->path );
		file_put_contents( $temp_file, $this->content );

		// Upload to cloud (single PUT request)
		// ⭐ CRITICAL: Pass destination path as 3rd parameter so provider can detect MIME type from extension
		// Temp files have no extension, so get_mime_type() would return 'application/octet-stream'
		try {
			$result = $cloud_client->upload_file( $temp_file, $this->path, array( 'mime_type_from_path' => $this->path ) );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] stream_flush exception: ' . $this->path . ' - ' . $e->getMessage() );
			\DiluxOneOffload\ConfigManager::record_connection_failure( 'exception', $e->getMessage(), 'upload' );
			@unlink( $temp_file );
			return false;
		}

		// Clean up temp file
		unlink( $temp_file );

		if ( ! $result['success'] ) {
			$error_msg = $result['error'] ?? 'Unknown upload error';
			Logger::info( '[DiluxOne Offload CloudStreamWrapper] stream_flush failed: ' . $this->path . ' - ' . $error_msg );
			$error_code = '';
			if ( preg_match( '/(\d{3})/', $error_msg, $matches ) ) {
				$error_code = $matches[1];
			}
			\DiluxOneOffload\ConfigManager::record_connection_failure( $error_code, $error_msg, 'upload' );
			return false;
		}

		// Auto-recovery: if was unhealthy and upload succeeded, mark healthy
		$health = \DiluxOneOffload\ConfigManager::get_connection_health();
		if ( $health['status'] === 'unhealthy' ) {
			\DiluxOneOffload\ConfigManager::record_connection_success();
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::debug( '[DiluxOne Offload CloudStreamWrapper] stream_flush: Uploaded ' . $this->path . ' (' . strlen( $this->content ) . ' bytes)' );
		}

		// ✅ OPTIMIZATION 1: Cache stat to avoid immediate HEAD request
		// (Like Infinite Uploads lines 589-596)
		self::$stat_cache[ $this->path ] = array(
			0         => 0,
			'dev'     => 0,
			1         => 0,
			'ino'     => 0,
			2         => 33188,
			'mode'    => 33188,  // Regular file with 0644 permissions
			3         => 1,
			'nlink'   => 1,
			4         => 0,
			'uid'     => 0,
			5         => 0,
			'gid'     => 0,
			6         => 0,
			'rdev'    => 0,
			7         => strlen( $this->content ),
			'size'    => strlen( $this->content ),
			8         => time(),
			'atime'   => time(),
			9         => time(),
			'mtime'   => time(),
			10        => time(),
			'ctime'   => time(),
			11        => -1,
			'blksize' => -1,
			12        => -1,
			'blocks'  => -1,
		);

		// ✅ OPTIMIZATION 2: Cache file content for immediate reads
		// (Like Infinite Uploads lines 515-527)
		$this->cache_set( $this->path, $this->content );

		return true;
	}

	/**
	 * Stream wrapper: Close file
	 *
	 * @return bool
	 */
	public function stream_close() {
		if ( $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}

		// ⚠️ IMPORTANT: Don't upload here if already flushed
		// file_put_contents() calls fflush() before fclose()
		// So content is already uploaded by stream_flush()
		// Only upload if flush was never called (direct fclose() without fflush())
		if ( ! $this->saved_locally && ( strpos( $this->mode, 'w' ) !== false || strpos( $this->mode, 'a' ) !== false || strpos( $this->mode, '+' ) !== false ) ) {
			if ( ! empty( $this->content ) ) {
				// Check if file is already in cache (uploaded by flush)
				$cached_content = $this->cache_get( $this->path );
				if ( $cached_content !== $this->content ) {
					// Content changed after flush, upload again
					$this->upload_content_to_cloud();
				}
			}
		}

		return true;
	}

	/**
	 * Stream wrapper: Get file statistics
	 *
	 * @return array<int|string, mixed>|false
	 */
	public function stream_stat() {
		if ( $this->handle ) {
			return fstat( $this->handle );
		}

		// No handle (in-memory buffer mode): synthesise a stat-like array
		// for the in-memory content buffer using format_url_stat() which
		// emits the same numeric/named-key shape PHP expects.
		return $this->format_url_stat( array( 'size' => strlen( $this->content ) ) );
	}

	/**
	 * Stream wrapper: Check if end of file
	 *
	 * @return bool
	 */
	public function stream_eof() {
		if ( $this->handle ) {
			return feof( $this->handle );
		}

		// For write mode (content buffer): we are at EOF when the cursor has
		// reached the end of the buffered content.
		return $this->position >= strlen( $this->content );
	}

	/**
	 * Stream wrapper: Seek to position
	 *
	 * @param int $offset
	 * @param int $whence
	 * @return bool
	 */
	public function stream_seek( $offset, $whence = SEEK_SET ) {
		if ( $this->handle ) {
			return fseek( $this->handle, $offset, $whence ) === 0;
		}

		// For write mode (content buffer)
		switch ( $whence ) {
			case SEEK_SET:
				$this->position = $offset;
				break;
			case SEEK_CUR:
				$this->position += $offset;
				break;
			case SEEK_END:
				$this->position = strlen( $this->content ) + $offset;
				break;
			default:
				return false;
		}

		return true;
	}

	/**
	 * Stream wrapper: Get current position
	 *
	 * @return int
	 */
	public function stream_tell() {
		if ( $this->handle ) {
			$pos = ftell( $this->handle );
			return $pos === false ? 0 : $pos;
		}

		// For write mode (content buffer): the cursor we tracked.
		return $this->position;
	}

	/**
	 * Stream wrapper: Change file metadata
	 *
	 * Called when chmod(), touch(), chown(), chgrp() are used on cloud files.
	 * Azure Blob Storage doesn't support Unix-style permissions, so we simulate
	 * success to prevent warnings.
	 *
	 * Copied from Infinite Uploads approach (line 642)
	 *
	 * @param string $path The file path
	 * @param int    $option STREAM_META_TOUCH, STREAM_META_OWNER_NAME, etc.
	 * @param mixed  $value The metadata value
	 * @return bool
	 */
	public function stream_metadata( $path, $option, $value ) {
		// Azure doesn't have Unix permissions concept
		// Return true to silently succeed (like Infinite Uploads does for Imagify)
		// This prevents warnings when WordPress tries to chmod files
		return true;
	}

	/**
	 * Stream wrapper: Get URL statistics
	 *
	 * Copied exactly from Infinite Uploads approach
	 *
	 * @param string $path
	 * @param int    $flags
	 * @return array<int|string, mixed>|false
	 */
	public function url_stat( $path, $flags ) {
		$parsed_path = $this->parse_path( $path );

		$extension = pathinfo( $parsed_path, PATHINFO_EXTENSION );

		/**
		 * If the file is actually just a path to a directory
		 * then return it as always existing. This is to work
		 * around wp_upload_dir doing file_exists checks on
		 * the uploads directory on every page load.
		 */
		if ( ! $extension ) {
			return array(
				0         => 0,
				'dev'     => 0,
				1         => 0,
				'ino'     => 0,
				2         => 16895,
				'mode'    => 16895,
				3         => 0,
				'nlink'   => 0,
				4         => 0,
				'uid'     => 0,
				5         => 0,
				'gid'     => 0,
				6         => -1,
				'rdev'    => -1,
				7         => 0,
				'size'    => 0,
				8         => 0,
				'atime'   => 0,
				9         => 0,
				'mtime'   => 0,
				10        => 0,
				'ctime'   => 0,
				11        => -1,
				'blksize' => -1,
				12        => -1,
				'blocks'  => -1,
			);
		}

		// Check if this path is in the url_stat cache
		if ( isset( self::$stat_cache[ $parsed_path ] ) ) {
			return self::$stat_cache[ $parsed_path ];
		}

		// Create stat (with HEAD request)
		$stat = $this->create_stat( $parsed_path, $flags );

		// Cache result (even if false)
		self::$stat_cache[ $parsed_path ] = $stat;

		return $stat;
	}

	/**
	 * Create stat by checking file existence in Azure
	 * Copied from Infinite Uploads create_stat() logic
	 *
	 * @param string $path
	 * @param int    $flags
	 * @return array<int|string, mixed>|false
	 */
	private function create_stat( $path, $flags ) {
		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			return $this->trigger_error_internal( 'Cloud client not available', $flags );
		}

		// Try to check if file exists in Azure (HEAD request)
		// Note: file_exists() returns boolean (true/false)
		try {
			$exists = $cloud_client->file_exists( $path );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] create_stat exception: ' . $path . ' - ' . $e->getMessage() );
			return $this->trigger_error_internal( 'Cloud error: ' . $e->getMessage(), $flags );
		}

		if ( ! $exists ) {
			// File doesn't exist - trigger error (returns false)
			return $this->trigger_error_internal( 'File or directory not found: ' . $path, $flags );
		}

		// File exists - return stat array
		return $this->format_url_stat( array() );
	}

	/**
	 * Trigger error based on flags (copied from Infinite Uploads)
	 *
	 * @param string   $error
	 * @param int|null $flags
	 * @return false|array<int|string, mixed>
	 */
	private function trigger_error_internal( $error, $flags = null ) {
		// This is triggered with things like file_exists()
		if ( $flags & STREAM_URL_STAT_QUIET ) {
			return $flags & STREAM_URL_STAT_LINK
				? $this->format_url_stat( false )
				: false;
		}

		// This is triggered when doing things like lstat() or stat().
		// PHP stream wrapper protocol expects errors to be raised via trigger_error()
		// so callers like fopen() / file_exists() can detect them. We control the message
		// ourselves (it never contains user input), but cast to string for safety.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Required by PHP stream wrapper protocol; message is internal.
		trigger_error( (string) $error, E_USER_WARNING );

		return false;
	}

	/**
	 * Prepare a url_stat result array (copied from Infinite Uploads)
	 *
	 * @param mixed $result
	 * @return array<int|string, int>
	 */
	private function format_url_stat( $result = null ) {
		$stat = array(
			0         => 0,
			'dev'     => 0,
			1         => 0,
			'ino'     => 0,
			2         => 0,
			'mode'    => 0,
			3         => 0,
			'nlink'   => 0,
			4         => 0,
			'uid'     => 0,
			5         => 0,
			'gid'     => 0,
			6         => -1,
			'rdev'    => -1,
			7         => 0,
			'size'    => 0,
			8         => 0,
			'atime'   => 0,
			9         => 0,
			'mtime'   => 0,
			10        => 0,
			'ctime'   => 0,
			11        => -1,
			'blksize' => -1,
			12        => -1,
			'blocks'  => -1,
		);

		switch ( gettype( $result ) ) {
			case 'NULL':
			case 'string':
				// Directory with 0777 access
				$stat[2]      = 0040777;
				$stat['mode'] = 0040777;
				break;
			case 'array':
				// Regular file with 0777 access
				$stat[2]      = 0100777;
				$stat['mode'] = 0100777;
				// Apply caller-provided size when present (e.g. in-memory buffer
				// mode passes the buffered length here so fstat()/filesize()
				// don't always report 0 bytes for cloud-backed streams).
				if ( isset( $result['size'] ) && is_int( $result['size'] ) ) {
					$stat[7]      = $result['size'];
					$stat['size'] = $result['size'];
				}
				break;
		}

		return $stat;
	}

	/**
	 * Stream wrapper: Check if file exists
	 *
	 * Assumes the file is there instead of paying for a HEAD request: the
	 * caller only asks about paths the plugin itself wrote.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function stream_exists( $path ) {
		// Always true: if something asked for this path, treat it as present.
		// Evita HEAD request innecesario
		return true;
	}

	/**
	 * Stream wrapper: Delete file
	 *
	 * ⭐ IMPORTANT: Always succeeds even if file doesn't exist (following Infinite Uploads pattern)
	 * Plugins often delete temp files during regeneration - Azure latency can cause 404s
	 * Goal is achieved (file doesn't exist) whether delete succeeds or file was already gone
	 *
	 * Implementation copied from Infinite Uploads unlink() pattern (lines 351-365)
	 *
	 * @param string $path
	 * @return bool
	 */
	public function unlink( $path ) {
		$parsed_path = $this->parse_path( $path );

		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			return false;
		}

		// Delete from cloud (ignore result - file might not exist, that's OK)
		try {
			$result = $cloud_client->delete_file( $parsed_path );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] unlink exception (non-critical): ' . $parsed_path . ' - ' . $e->getMessage() );
			$result = array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		// ⭐ ALWAYS clear cache and return success (like Infinite Uploads does)
		// Whether delete succeeded or file didn't exist, the goal is achieved
		unset( self::$stat_cache[ $parsed_path ] );
		unset( self::$file_cache[ $parsed_path ] );

		// Cache as "deleted" to prevent future stat checks (like Infinite Uploads line 359)
		self::$stat_cache[ $parsed_path ] = false;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( $result['success'] ) {
				Logger::info( '[DiluxOne Offload CloudStreamWrapper] Deleted: ' . $parsed_path );
			} else {
				// Log but don't fail (file might already be deleted, that's fine)
				Logger::error( '[DiluxOne Offload CloudStreamWrapper] Delete result (non-critical): ' . $parsed_path . ' - ' . $result['error'] );
			}
		}

		// ⭐ ALWAYS return true (goal achieved: file doesn't exist)
		return true;
	}

	/**
	 * Stream wrapper: Rename/move file
	 *
	 * This is CRITICAL for plugins like Astra that use atomic writes:
	 * 1. Write to temp file: file_put_contents('temp.css', $content)
	 * 2. Atomic rename: rename('temp.css', 'final.css')
	 *
	 * Implementation uses Azure Copy Blob API for efficient server-side copy:
	 * - Copy source blob to destination (server-side, no download/upload)
	 * - Delete source blob
	 * - Update cache accordingly
	 *
	 * @param string $path_from Source path
	 * @param string $path_to Destination path
	 * @return bool True on success, false on failure
	 */
	public function rename( $path_from, $path_to ) {
		$parsed_from = $this->parse_path( $path_from );
		$parsed_to   = $this->parse_path( $path_to );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::info( '[DiluxOne Offload CloudStreamWrapper] rename: ' . $parsed_from . ' -> ' . $parsed_to );
		}

		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] rename failed: Cloud client not available' );
			return false;
		}

		// Step 1: Copy blob from source to destination (server-side)
		try {
			$copy_result = $cloud_client->copy_blob( $parsed_from, $parsed_to );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] rename copy exception: ' . $parsed_from . ' -> ' . $parsed_to . ' - ' . $e->getMessage() );
			return false;
		}

		if ( ! $copy_result['success'] ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::warning( '[DiluxOne Offload CloudStreamWrapper] rename failed at copy: ' . ( $copy_result['error'] ?? 'Unknown error' ) );
			}
			return false;
		}

		// Step 2: Delete source blob
		try {
			$delete_result = $cloud_client->delete_file( $parsed_from );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] rename delete exception (non-critical): ' . $parsed_from . ' - ' . $e->getMessage() );
			$delete_result = array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		// Note: We don't check delete result strictly because:
		// - Copy succeeded, so destination file exists ✓
		// - If delete fails, source file still exists (not ideal but not critical)
		// - Goal of rename is achieved: file exists at destination
		if ( ! $delete_result['success'] ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::warning( '[DiluxOne Offload CloudStreamWrapper] rename: copy succeeded but delete failed (non-critical): ' . ( $delete_result['error'] ?? 'Unknown error' ) );
			}
		}

		// Step 3: Update cache
		// Transfer cache from source to destination
		$cached_content = $this->cache_get( $parsed_from );
		if ( $cached_content !== null ) {
			$this->cache_set( $parsed_to, $cached_content );
		}

		// Transfer stat cache from source to destination
		if ( isset( self::$stat_cache[ $parsed_from ] ) ) {
			self::$stat_cache[ $parsed_to ] = self::$stat_cache[ $parsed_from ];
		}

		// Clear source from cache (mark as deleted)
		unset( self::$file_cache[ $parsed_from ] );
		unset( self::$stat_cache[ $parsed_from ] );
		self::$stat_cache[ $parsed_from ] = false; // Mark as deleted

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::info( '[DiluxOne Offload CloudStreamWrapper] rename successful: ' . $parsed_from . ' -> ' . $parsed_to );
		}

		return true;
	}

	/**
	 * Stream wrapper: Create directory
	 *
	 * @param string $path
	 * @param int    $mode
	 * @param int    $options
	 * @return bool
	 */
	public function mkdir( $path, $mode, $options ) {
		// Cloud storage doesn't need directory creation
		// Just return true to let WordPress think it succeeded
		return true;
	}

	/**
	 * Stream wrapper: Remove directory
	 *
	 * @param string $path
	 * @param int    $options
	 * @return bool
	 */
	public function rmdir( $path, $options ) {
		// Cloud storage doesn't have explicit directories
		return true;
	}

	/**
	 * Parse stream wrapper path to get relative cloud path
	 *
	 * @param string $path
	 * @return string
	 */
	private function parse_path( $path ) {
		// Remove protocol and normalize path
		$path = str_replace( self::PROTOCOL . '://', '', $path );
		return ltrim( $path, '/' );
	}

	/**
	 * Upload content buffer to cloud storage
	 *
	 * @return bool
	 */
	private function upload_content_to_cloud() {
		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			return false;
		}

		// Create temporary file with content
		$temp_file = wp_tempnam( $this->path );
		file_put_contents( $temp_file, $this->content );

		// Direct PUT to cloud (no validation)
		// ⭐ CRITICAL: Pass destination path so Azure can detect MIME type from extension
		try {
			$result = $cloud_client->upload_file( $temp_file, $this->path, array( 'mime_type_from_path' => $this->path ) );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] upload_content_to_cloud exception: ' . $this->path . ' - ' . $e->getMessage() );
			@unlink( $temp_file );
			return false;
		}

		// Clean up temp file
		unlink( $temp_file );

		if ( $result['success'] ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::info( '[DiluxOne Offload CloudStreamWrapper] Uploaded: ' . $this->path );
			}

			// Cache stat AFTER upload to avoid immediate HEAD requests
			self::$stat_cache[ $this->path ] = array(
				0         => 0,
				'dev'     => 0,
				1         => 0,
				'ino'     => 0,
				2         => 33188,
				'mode'    => 33188,
				3         => 1,
				'nlink'   => 1,
				4         => 0,
				'uid'     => 0,
				5         => 0,
				'gid'     => 0,
				6         => 0,
				'rdev'    => 0,
				7         => strlen( $this->content ),
				'size'    => strlen( $this->content ),
				8         => time(),
				'atime'   => time(),
				9         => time(),
				'mtime'   => time(),
				10        => time(),
				'ctime'   => time(),
				11        => -1,
				'blksize' => -1,
				12        => -1,
				'blocks'  => -1,
			);

			return true;
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::error( '[DiluxOne Offload CloudStreamWrapper] Failed to upload: ' . $this->path . ' - ' . $result['error'] );
			}
			return false;
		}
	}

	/**
	 * Get cloud client instance
	 *
	 * @return CloudStorageClientInterface|null
	 */
	private static function get_cloud_client() {
		if ( self::$cloud_client === null ) {
			self::$cloud_client = ConfigManager::get_cloud_client();
		}

		return self::$cloud_client;
	}

	/**
	 * Clear stat cache
	 */
	public static function clear_stat_cache(): void {
		self::$stat_cache = array();
	}

	/**
	 * File Cache Methods (Copied from Infinite Uploads approach)
	 *
	 * These methods implement in-memory caching of downloaded files to avoid
	 * repeated downloads during thumbnail generation.
	 * Infinite Uploads implements these in lines 462-527
	 */

	/**
	 * Get cached file content
	 *
	 * @param string $path File path
	 * @return string|null File content or null if not cached
	 */
	private function cache_get( $path ) {
		if ( isset( self::$file_cache[ $path ] ) ) {
			return self::$file_cache[ $path ];
		}
		return null;
	}

	/**
	 * Cache file content
	 *
	 * Only caches files smaller than CACHE_MAX_BYTES (32MB)
	 * to avoid memory exhaustion.
	 *
	 * @param string $path File path
	 * @param string $content File content
	 */
	private function cache_set( $path, $content ): void {
		// Don't cache files that are too big (like Infinite Uploads)
		if ( strlen( $content ) > self::CACHE_MAX_BYTES ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::info( '[DiluxOne Offload CloudStreamWrapper] File too large to cache: ' . $path . ' (' . strlen( $content ) . ' bytes)' );
			}
			return;
		}

		// Only keep most recent file (like Infinite Uploads does)
		self::$file_cache          = array();
		self::$file_cache[ $path ] = $content;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::info( '[DiluxOne Offload CloudStreamWrapper] Cached file: ' . $path . ' (' . strlen( $content ) . ' bytes)' );
		}
	}

	/**
	 * Clear file cache
	 */
	public static function clear_file_cache(): void {
		self::$file_cache = array();
	}

	/**
	 * Directory Methods (Copied from Infinite Uploads approach)
	 *
	 * These methods enable directory listing functionality like opendir(), readdir()
	 * Infinite Uploads implements these in lines 1032-1175
	 */

	/**
	 * Open directory handle
	 *
	 * Called by opendir() - lists blobs with given prefix
	 * Based on Infinite Uploads dir_opendir() (line 1068)
	 *
	 * @param string   $path Directory path
	 * @param int|null $options Options (null when called via dir_rewinddir)
	 * @return bool
	 */
	public function dir_opendir( $path, $options ) {
		$this->dir_path   = $this->parse_path( $path );
		$this->dir_prefix = rtrim( $this->dir_path, '/' ) . '/';

		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] dir_opendir: Cloud client not available' );
			return false;
		}

		// List blobs with prefix (simulate directory listing)
		// Note: Azure doesn't have native "listBlobs with prefix" in our client
		// For now, we'll create an empty iterator to prevent errors
		// This is a simplified version - full implementation would require Azure Blob list API
		$this->dir_iterator = array();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::info( '[DiluxOne Offload CloudStreamWrapper] dir_opendir: ' . $this->dir_path );
		}

		return true;
	}

	/**
	 * Read directory entry
	 *
	 * Called by readdir() - returns next file/directory name
	 * Based on Infinite Uploads dir_readdir() (line 1133)
	 *
	 * @return string|false
	 */
	public function dir_readdir() {
		// Check if iterator is valid
		if ( ! is_array( $this->dir_iterator ) || empty( $this->dir_iterator ) ) {
			return false;
		}

		// Get current item and advance
		$current = array_shift( $this->dir_iterator );

		if ( $current === null ) {
			return false;
		}

		// Remove prefix to return relative path (like Infinite Uploads does)
		if ( $this->dir_prefix && strpos( $current, $this->dir_prefix ) === 0 ) {
			return substr( $current, strlen( $this->dir_prefix ) );
		}

		return $current;
	}

	/**
	 * Close directory handle
	 *
	 * Called by closedir()
	 * Based on Infinite Uploads dir_closedir() (line 1032)
	 *
	 * @return bool
	 */
	public function dir_closedir() {
		$this->dir_iterator = null;
		$this->dir_path     = '';
		$this->dir_prefix   = '';

		// Force garbage collection like Infinite Uploads does
		gc_collect_cycles();

		return true;
	}

	/**
	 * Rewind directory handle
	 *
	 * Called by rewinddir() - resets directory pointer
	 * Based on Infinite Uploads dir_rewinddir() (line 1044)
	 *
	 * @return bool
	 */
	public function dir_rewinddir() {
		// Reset by re-opening the directory
		$this->dir_iterator = null;
		return $this->dir_opendir( $this->dir_path, null );
	}
}
