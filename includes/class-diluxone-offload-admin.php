<?php
/**
 * The plugin's admin screen: one page with a tab bar.
 *
 * Direct $wpdb queries against the plugin's own table (`$wpdb->prefix .
 * 'diluxone_offload_files'`) are used in a few read-only spots to render real-time
 * sync progress; cache layers don't apply because the value would be stale.
 * The table name is derived from $wpdb->prefix and never from user input.
 * These rules are intentionally suppressed file-wide:
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's admin screen.
 */
class Admin {

	/** The menu slug, written once. */
	const MENU = 'diluxone-offload';

	/**
	 * How the plugin introduces itself in the dashboard.
	 *
	 * Written once: the menu, the heading and the browser tab all read it from
	 * here. Spelled out in three places, sooner or later they say three
	 * different things — which is exactly how the heading ended up still
	 * saying "Cloud Storage" long after the plugin stopped being called that.
	 *
	 * @return string
	 */
	public static function plugin_name(): string {
		return (string) \apply_filters( 'diluxone_offload_plugin_name', \__( 'DiluxOne Offload', 'diluxone-offload' ) );
	}

	/**
	 * The tabs, in order: slug => [label, dashicon, aliases, hidden].
	 *
	 * The tab bar, the browser title and the routing all walk this list, so a
	 * tab cannot exist in one and be missing from another. A hidden tab still
	 * answers to its URL and gets its own title, it just is not offered in the
	 * bar.
	 *
	 * @return array<string, array{label: string, icon: string, aliases: string[], hidden: bool}>
	 */
	public static function tabs(): array {
		return array(
			'overview'        => array(
				'label'   => \__( 'Overview', 'diluxone-offload' ),
				'icon'    => 'dashicons-dashboard',
				'aliases' => array(),
				'hidden'  => false,
			),
			'cloud-provider'  => array(
				'label'   => \__( 'Cloud Provider', 'diluxone-offload' ),
				'icon'    => 'dashicons-cloud-upload',
				'aliases' => array(),
				'hidden'  => false,
			),
			'sync-offloading' => array(
				'label'   => \__( 'Sync & Offloading', 'diluxone-offload' ),
				'icon'    => 'dashicons-update',
				'aliases' => array( 'sync' ),
				'hidden'  => false,
			),
			'settings'        => array(
				'label'   => \__( 'Settings', 'diluxone-offload' ),
				'icon'    => 'dashicons-admin-settings',
				'aliases' => array(),
				'hidden'  => false,
			),
			'status'          => array(
				'label'   => \__( 'Status', 'diluxone-offload' ),
				'icon'    => 'dashicons-info',
				'aliases' => array( 'status-tools' ),
				'hidden'  => false,
			),
			'tools'           => array(
				'label'   => \__( 'Tools', 'diluxone-offload' ),
				'icon'    => 'dashicons-admin-tools',
				'aliases' => array(),
				'hidden'  => false,
			),
			'activity'        => array(
				'label'   => \__( 'Activity', 'diluxone-offload' ),
				'icon'    => 'dashicons-chart-line',
				'aliases' => array(),
				'hidden'  => true,
			),
		);
	}

	/**
	 * The name of the tab currently open, resolved through its aliases.
	 *
	 * @param string $tab The raw `tab` parameter.
	 * @return string The canonical tab slug.
	 */
	public static function current_tab( string $tab ): string {
		$tabs = self::tabs();

		if ( isset( $tabs[ $tab ] ) ) {
			return $tab;
		}

		foreach ( $tabs as $slug => $datos ) {
			if ( in_array( $tab, $datos['aliases'], true ) ) {
				return $slug;
			}
		}

		return 'overview';
	}

	/**
	 * The browser tab: the plugin's name, then the screen's.
	 *
	 * In a dashboard with twenty plugins, "Status" says nothing about whose
	 * screen it is. "DiluxOne Offload | Status" does.
	 *
	 * @param string $admin_title The title WordPress built.
	 * @param string $title       The screen's own title.
	 * @return string
	 */
	public static function admin_title( string $admin_title, string $title ): string {
		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || false === strpos( (string) $screen->id, self::MENU ) ) {
			return $admin_title;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter, no state change.
		$tab     = isset( $_GET['tab'] ) ? \sanitize_text_field( \wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tabs    = self::tabs();
		$abierta = $tabs[ self::current_tab( $tab ) ]['label'];

		$nuevo = sprintf(
			/* translators: 1: plugin name, 2: name of the screen */
			\_x( '%1$s | %2$s', 'a dashboard screen title', 'diluxone-offload' ),
			self::plugin_name(),
			$abierta
		);

		return str_replace( $title, $nuevo, $admin_title );
	}

	/**
	 * Initialize admin hooks
	 */
	public static function init(): void {
		Logger::debug( '[DiluxOne Offload] Admin::init() called - registering hooks' );
		\add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		\add_filter( 'admin_title', array( __CLASS__, 'admin_title' ), 10, 2 );
		\add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		\add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		\add_action( 'admin_post_diluxone_offload_save_config', array( __CLASS__, 'save_config' ) );
		\add_action( 'admin_post_diluxone_offload_save_offloading', array( __CLASS__, 'save_offloading_config' ) );
		\add_action( 'admin_post_diluxone_offload_remove_provider', array( __CLASS__, 'remove_provider_config' ) );

		// Register AJAX handlers
		\add_action( 'wp_ajax_diluxone_offload_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		\add_action( 'wp_ajax_diluxone_offload_save_updated_credentials', array( __CLASS__, 'ajax_save_updated_credentials' ) );
		\add_action( 'wp_ajax_diluxone_offload_migration_action', array( __CLASS__, 'ajax_migration_action' ) );
		\add_action( 'wp_ajax_diluxone_offload_test_check', array( __CLASS__, 'ajax_test_check' ) );
		\add_action( 'wp_ajax_diluxone_offload_scan_files', array( __CLASS__, 'ajax_scan_files' ) );
		// DISABLED: ajax_diluxone_offload_start_sync now handled by Plugin::ajax_cs_start_sync in class-diluxone-offload-plugin-enhanced.php (legacy handler removed).
		\add_action( 'wp_ajax_diluxone_offload_cancel_sync', array( __CLASS__, 'ajax_cancel_sync' ) );
		\add_action( 'wp_ajax_diluxone_offload_mark_sync_complete', array( __CLASS__, 'ajax_mark_sync_complete' ) );
		\add_action( 'wp_ajax_diluxone_offload_delete_local_files', array( __CLASS__, 'ajax_delete_local_files' ) );
		\add_action( 'wp_ajax_diluxone_offload_retry_failed', array( __CLASS__, 'ajax_retry_failed' ) );
		\add_action( 'wp_ajax_diluxone_offload_clear_failed', array( __CLASS__, 'ajax_clear_failed' ) );
		\add_action( 'wp_ajax_diluxone_offload_resync_all', array( __CLASS__, 'ajax_resync_all' ) );
		\add_action( 'wp_ajax_diluxone_offload_ajax_remove_provider', array( __CLASS__, 'ajax_remove_provider' ) );
		\add_action( 'wp_ajax_diluxone_offload_import_config', array( __CLASS__, 'ajax_import_config' ) );
		\add_action( 'wp_ajax_diluxone_offload_refresh_stats', array( __CLASS__, 'ajax_refresh_stats' ) );
		Logger::debug( '[DiluxOne Offload] Admin hooks registered successfully' );
	}

	/**
	 * Register the top-level admin menu.
	 *
	 * Standalone menu — no shared "DiluxOne" parent.
	 *
	 * @return void
	 */
	public static function add_admin_menu() {
		\add_menu_page(
			self::plugin_name(),                     // Page title.
			self::plugin_name(),                     // Menu label.
			'manage_options',                        // Capability.
			self::MENU,                              // Menu slug.
			array( __CLASS__, 'render_admin_page' ), // Callback.
			'dashicons-cloud',                       // Icon.
			81                                       // Position (below the Settings block).
		);
	}

	/**
	 * Render the admin page (called dynamically by DiluxOne Core)
	 */
	public static function render_admin_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter, no state change.
		$pedida      = isset( $_GET['tab'] ) ? \sanitize_text_field( \wp_unslash( $_GET['tab'] ) ) : 'overview';
		$current_tab = self::current_tab( $pedida );

		// Check configuration states
		$is_configured         = ConfigManager::is_configured();
		$current_state         = ConfigManager::get_state();
		$is_synced             = ( $current_state === 'synced' || $current_state === 'offloading_active' );
		$is_offloading_enabled = ( $current_state === 'offloading_active' );

		?>
		<div class="wrap diluxone-offload-admin">
			<h1>
				<span class="dashicons dashicons-cloud"></span>
				<?php echo \esc_html( self::plugin_name() ); ?>
			</h1>

			<!-- Tabs Navigation -->
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::tabs() as $slug => $datos ) : ?>
					<?php
					if ( $datos['hidden'] ) {
						continue; }
					?>
					<a href="
					<?php
					echo \esc_url(
						\add_query_arg(
							array(
								'page' => self::MENU,
								'tab'  => $slug,
							),
							\admin_url( 'admin.php' )
						)
					);
					?>
								"
						class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons <?php echo \esc_attr( $datos['icon'] ); ?>"></span>
						<?php echo \esc_html( $datos['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<!-- Tab Content -->
			<div class="tab-content" style="margin-top: 20px;">
				<?php
				// Connection health check (5-min TTL)
				$health = ConfigManager::check_connection_health();
				if ( $health['status'] === 'unhealthy' ) {
					self::render_connection_health_banner( $health );
				}
				?>
				<?php self::render_tab_content( $current_tab ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Register settings
	 */
	public static function register_settings(): void {
		\register_setting(
			'diluxone_offload_cloud_storage',
			'diluxone_offload_cloud_storage_config',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings_option' ),
				'default'           => array(),
			)
		);

		if ( \is_multisite() ) {
			\register_setting(
				'diluxone_offload_cloud_storage_network',
				'diluxone_offload_cloud_storage_network_config',
				array(
					'type'              => 'array',
					'sanitize_callback' => array( __CLASS__, 'sanitize_settings_option' ),
					'default'           => array(),
				)
			);
		}
	}

	/**
	 * Sanitize the diluxone_offload_cloud_storage{,_network}_config option.
	 *
	 * Production save paths go through ConfigManager (DTO validation +
	 * credential encryption). This callback satisfies the Settings API
	 * contract and hardens anything submitted directly through it.
	 *
	 * Sanitization is per field, not blanket. Running sanitize_text_field()
	 * over everything would corrupt the values that carry credentials:
	 * Azure account keys and DiluxOne API keys arrive base64-encoded (or
	 * already wrapped by Crypto), and a GCP service-account key is a JSON
	 * blob. sanitize_text_field() strips percent-encoded octets and collapses
	 * whitespace and newlines, so it silently mangles all three and the
	 * provider then fails to authenticate with no visible cause.
	 *
	 * @param mixed $value
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings_option( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $k => $v ) {
			$key = \sanitize_key( (string) $k );

			switch ( $key ) {
				case 'cloud_provider':
					$clean[ $key ] = \sanitize_key( (string) $v );
					break;

				case 'provider_config':
					$clean[ $key ] = self::sanitize_provider_config( $v );
					break;

				case 'debug_enabled':
				case 'keep_local_files':
				case 'auto_activate_offloading':
				case 'force_https_on_cloud':
					$clean[ $key ] = (bool) $v;
					break;

				case 'timeout':
				case 'max_file_size':
					$clean[ $key ] = \absint( $v );
					break;

				default:
					if ( is_array( $v ) ) {
						$clean[ $key ] = self::sanitize_settings_option( $v );
					} elseif ( is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
						$clean[ $key ] = $v;
					} else {
						$clean[ $key ] = \sanitize_text_field( (string) $v );
					}
					break;
			}
		}

		return $clean;
	}

	/**
	 * Sanitize the provider_config sub-array.
	 *
	 * Credential fields are preserved byte for byte apart from surrounding
	 * whitespace — see sanitize_settings_option() for why. They are validated
	 * on use (a bad key fails Test Connection) and encrypted at rest by
	 * ConfigManager, so passing them through untouched here is not a leak.
	 *
	 * @param mixed $value
	 * @return array<string, mixed>
	 */
	private static function sanitize_provider_config( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $k => $v ) {
			$key = \sanitize_key( (string) $k );

			if ( in_array( $key, ConfigManager::ENCRYPTED_FIELDS, true ) ) {
				$clean[ $key ] = is_scalar( $v ) ? trim( (string) $v ) : '';
				continue;
			}

			switch ( $key ) {
				case 'custom_domain':
				case 'cdn_base_url':
					$clean[ $key ] = \esc_url_raw( (string) $v );
					break;

				default:
					$clean[ $key ] = is_scalar( $v ) ? \sanitize_text_field( (string) $v ) : '';
					break;
			}
		}

		return $clean;
	}

	/**
	 * Single source of truth for the plugin version: the `Version:` line of
	 * the main plugin file. Cached per request.
	 */
	public static function get_plugin_version(): string {
		static $version = null;
		if ( $version !== null ) {
			return $version;
		}
		if ( ! \function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data    = \get_plugin_data( DILUXONE_OFFLOAD_FILE, false, false );
		$version = $data['Version'] !== '' ? (string) $data['Version'] : ( defined( 'DILUXONE_OFFLOAD_VERSION' ) ? DILUXONE_OFFLOAD_VERSION : '' );
		return $version;
	}

	/**
	 * Enqueue admin assets (CSS/JS)
	 *
	 * @param mixed $hook_suffix
	 */
	public static function enqueue_admin_assets( $hook_suffix ): void {
		// Only load on our plugin pages
		if ( strpos( $hook_suffix, 'diluxone-offload' ) === false ) {
			return;
		}

		// Enqueue CSS
		wp_enqueue_style(
			'diluxone-offload-admin',
			DILUXONE_OFFLOAD_URL . 'assets/css/admin.css',
			array(),
			DILUXONE_OFFLOAD_VERSION
		);

		// Enqueue JS
		wp_enqueue_script(
			'diluxone-offload-admin',
			DILUXONE_OFFLOAD_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			DILUXONE_OFFLOAD_VERSION,
			true
		);

		self::enqueue_tab_assets();

		// Localize script with AJAX data
		wp_localize_script(
			'diluxone-offload-admin',
			'diluxOneOffloadAdmin',
			array(
				'nonce'           => wp_create_nonce( 'diluxone_offload_admin' ),
				// Offloading activate/deactivate verify a different action; see
				// Plugin::ajax_activate_offloading().
				'offloadingNonce' => wp_create_nonce( 'diluxone_offload_admin_nonce' ),
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'autoRefresh'     => true,
				'strings'         => array(
					'testing_connection'   => __( 'Testing connection...', 'diluxone-offload' ),
					'connection_success'   => __( 'Connection successful!', 'diluxone-offload' ),
					'connection_failed'    => __( 'Connection failed:', 'diluxone-offload' ),
					'scanning_media'       => __( 'Scanning media library...', 'diluxone-offload' ),
					'migrating_files'      => __( 'Migrating files to cloud...', 'diluxone-offload' ),
					'deleting_local'       => __( 'Deleting local files...', 'diluxone-offload' ),
					'downloading_files'    => __( 'Downloading files from cloud...', 'diluxone-offload' ),
					'confirm_migrate'      => __( 'Are you sure you want to migrate all files to cloud storage? This cannot be undone without using the rollback feature.', 'diluxone-offload' ),
					'confirm_delete_local' => __( 'Are you sure you want to delete all local files? Make sure your migration was successful first.', 'diluxone-offload' ),
					'confirm_rollback'     => __( 'Are you sure you want to download all files from cloud and revert URLs? This may take a long time.', 'diluxone-offload' ),
				),
			)
		);
	}

	/**
	 * Per-tab stylesheet and script, by tab slug.
	 *
	 * Each admin tab used to carry its own <style>/<script> block inline in its
	 * template. They now live in assets/ and are registered here so WordPress
	 * can cache, version, defer and dequeue them like any other asset — and so
	 * a page only pays for the tab it is showing.
	 *
	 * @return array<string, string> Tab slug => asset basename in assets/{css,js}/.
	 */
	private static function tab_assets(): array {
		return array(
			'overview'        => 'admin-overview',
			'cloud-provider'  => 'admin-cloud-provider',
			'sync-offloading' => 'admin-sync',
			'settings'        => 'admin-settings',
			'status'          => 'admin-status-tools',
			'tools'           => 'admin-status-tools',
			'activity'        => 'admin-activity',
		);
	}

	/**
	 * Build the strings and data the current tab's script needs.
	 *
	 * Templates used to interpolate both directly into an inline <script>.
	 * Keeping them here means the JS files are static and cacheable, the
	 * strings stay in the .pot, and nothing is echoed into a script tag.
	 *
	 * @param string               $tab           Resolved tab slug.
	 * @param array<string, mixed> $template_data Data the tab's template renders with, if known yet.
	 * @return array{payload: array<string, mixed>, object: string, handle: string}|null
	 */
	private static function tab_payload( string $tab, array $template_data = array() ): ?array {
		$payload = null;
		$object  = '';
		$handle  = '';

		switch ( $tab ) {
			case 'activity':
				$payload = array(
					'i18n' => array(
						'uploads'                  => __( 'Uploads', 'diluxone-offload' ),
						'deletions'                => __( 'Deletions', 'diluxone-offload' ),
						'hide_details'             => __( 'Hide details', 'diluxone-offload' ),
						'show_details'             => __( 'Show details', 'diluxone-offload' ),
						'hide'                     => __( 'Hide', 'diluxone-offload' ),
						'view'                     => __( 'View', 'diluxone-offload' ),
						'full_path'                => __( 'Full path', 'diluxone-offload' ),
						'short_name'               => __( 'Short name', 'diluxone-offload' ),
						'export_failed'            => __( 'Export failed:', 'diluxone-offload' ),
						'are_you_sure_you_want_to' => __( 'Are you sure you want to delete activity logs older than 30 days? This action cannot be undone.', 'diluxone-offload' ),
						'clear_failed'             => __( 'Clear failed:', 'diluxone-offload' ),
					),
					'data' => array(
						'chart_data'    => $template_data['chart_data'] ?? null,
						'activity_type' => $template_data['activity_type'] ?? null,
						'date_from'     => $template_data['date_from'] ?? null,
						'date_to'       => $template_data['date_to'] ?? null,
					),
				);
				$object  = 'DiluxOneOffloadActivity';
				$handle  = 'diluxone-offload-admin-activity';
				break;

			case 'status':
			case 'tools':
				$payload = array(
					'i18n' => array(
						'exported'                        => __( 'Exported!', 'diluxone-offload' ),
						'please_paste_configuration_json_first' => __( 'Please paste configuration JSON first.', 'diluxone-offload' ),
						'invalid_json_format'             => __( 'Invalid JSON format:', 'diluxone-offload' ),
						'warning_this_will_overwrite_your_current' => __( '⚠️ WARNING: This will overwrite your current configuration!\\n\\nAre you sure you want to continue?', 'diluxone-offload' ),
						'importing'                       => __( 'Importing...', 'diluxone-offload' ),
						'configuration_imported_successfully_reloading_page' => __( 'Configuration imported successfully! Reloading page...', 'diluxone-offload' ),
						'import_failed'                   => __( 'Import failed.', 'diluxone-offload' ),
						'request_failed_please_try_again' => __( 'Request failed. Please try again.', 'diluxone-offload' ),
					),
					'data' => array(
						'config_data_json_pretty_print' => $template_data['config_data, JSON_PRETTY_PRINT'] ?? null,
						'get_bloginfo_version'          => get_bloginfo( 'version' ),
						'admin_get_plugin_version'      => self::get_plugin_version(),
						'get_site_url'                  => get_site_url(),
					),
				);
				$object  = 'DiluxOneOffloadStatus';
				$handle  = 'diluxone-offload-admin-status-tools';
				break;

			case 'cloud-provider':
				$payload = array(
					'i18n' => array(
						'connection_failed'               => __( 'Connection Failed', 'diluxone-offload' ),
						'please_enter_the_api_key'        => __( 'Please enter the API Key.', 'diluxone-offload' ),
						'please_fill_in_all_required_fields' => __( 'Please fill in all required fields.', 'diluxone-offload' ),
						'testing'                         => __( 'Testing...', 'diluxone-offload' ),
						'test_connection'                 => __( 'Test Connection', 'diluxone-offload' ),
						'connection_successful'           => __( 'Connection Successful', 'diluxone-offload' ),
						'storage_account_name_must_be_3'  => __( 'Storage Account Name must be 3-24 characters long and contain only lowercase letters and numbers.', 'diluxone-offload' ),
						'container_name_must_contain_only_lowercase' => __( 'Container Name must contain only lowercase letters, numbers, and hyphens.', 'diluxone-offload' ),
						'deleting_configuration'          => __( 'Deleting configuration...', 'diluxone-offload' ),
						'yes_delete_configuration'        => __( 'Yes, Delete Configuration', 'diluxone-offload' ),
						'please_enter_the_new_api_key'    => __( 'Please enter the new API Key.', 'diluxone-offload' ),
						'please_enter_the_new_access_key' => __( 'Please enter the new access key.', 'diluxone-offload' ),
						'saving'                          => __( 'Saving...', 'diluxone-offload' ),
						'save'                            => __( 'Save', 'diluxone-offload' ),
					),
					'data' => array(
						'config_cloud_provider' => $template_data['config']['cloud_provider'] ?? '',
					),
				);
				$object  = 'DiluxOneOffloadProvider';
				$handle  = 'diluxone-offload-admin-cloud-provider';
				break;

			case 'overview':
				$payload = array(
					'i18n' => array(
						'images'       => __( 'Images', 'diluxone-offload' ),
						'videos'       => __( 'Videos', 'diluxone-offload' ),
						'audio'        => __( 'Audio', 'diluxone-offload' ),
						'other'        => __( 'Other', 'diluxone-offload' ),
						'last_updated' => __( 'Last updated:', 'diluxone-offload' ),
						'just_now'     => __( 'just now', 'diluxone-offload' ),
						'storage'      => __( 'Storage', 'diluxone-offload' ),
						'total_files'  => __( 'Total Files', 'diluxone-offload' ),
						'error_please_update_your_credentials' => __( 'ERROR: please update your credentials', 'diluxone-offload' ),
						'request_timed_out_try_again_later' => __( 'Request timed out. Try again later.', 'diluxone-offload' ),
					),
					'data' => array(),
				);
				$object  = 'DiluxOneOffloadOverview';
				$handle  = 'diluxone-offload-admin-overview';
				break;

			case 'sync-offloading':
				$payload = array(
					'i18n' => array(
						'cancelling_sync'                  => __( 'Cancelling sync...', 'diluxone-offload' ),
						'close'                            => __( 'Close', 'diluxone-offload' ),
						'upload_summary'                   => __( 'Upload Summary', 'diluxone-offload' ),
						'total_files'                      => __( 'Total files:', 'diluxone-offload' ),
						'already_uploaded'                 => __( 'Already uploaded:', 'diluxone-offload' ),
						'new_files'                        => __( 'New files:', 'diluxone-offload' ),
						'pending'                          => __( 'Pending:', 'diluxone-offload' ),
						'upload_performance'               => __( 'Upload Performance:', 'diluxone-offload' ),
						'balanced_5_parallel'              => __( 'Balanced (5 parallel)', 'diluxone-offload' ),
						'fast_20_parallel'                 => __( 'Fast (20 parallel)', 'diluxone-offload' ),
						'intensive_40_parallel'            => __( 'Intensive (40 parallel)', 'diluxone-offload' ),
						'continue_upload'                  => __( 'Continue Upload', 'diluxone-offload' ),
						'upload_from_scratch'              => __( 'Upload from Scratch', 'diluxone-offload' ),
						'scan_and_complete_sync'           => __( 'Scan and Complete Sync', 'diluxone-offload' ),
						'sync_files_to_cloud'              => __( 'Sync Files to Cloud', 'diluxone-offload' ),
						'error_processing_batch'           => __( 'Error processing batch:', 'diluxone-offload' ),
						'max_retries_exceeded_sync_stopped_please' => __( '⚠️ Max retries exceeded. Sync stopped. Please check logs and try again.', 'diluxone-offload' ),
						'sync_completed_successfully'      => __( 'Sync Completed Successfully!', 'diluxone-offload' ),
						'all_files_have_been_synced_to'    => __( 'All files have been synced to cloud storage.', 'diluxone-offload' ),
						'sync_completed_with_errors'       => __( 'Sync Completed with Errors', 'diluxone-offload' ),
						'some_files_could_not_be_synced'   => __( 'Some files could not be synced.', 'diluxone-offload' ),
						'sync_failed'                      => __( 'Sync Failed', 'diluxone-offload' ),
						'unknown_error'                    => __( 'Unknown error', 'diluxone-offload' ),
						'successful'                       => __( 'Successful:', 'diluxone-offload' ),
						'failed'                           => __( 'Failed:', 'diluxone-offload' ),
						'enable_offloading'                => __( 'Enable Offloading', 'diluxone-offload' ),
						'later'                            => __( 'Later', 'diluxone-offload' ),
						'accept'                           => __( 'Accept', 'diluxone-offload' ),
						'cannot_enable_offloading'         => __( 'Cannot enable offloading: ', 'diluxone-offload' ),
						'failed_files'                     => __( 'failed files', 'diluxone-offload' ),
						'and'                              => __( 'and', 'diluxone-offload' ),
						'pending_files'                    => __( 'pending files', 'diluxone-offload' ),
						'please_resolve_errors_first_using_clear' => __( 'Please resolve errors first using "Clear Failed & Enable" or retry failed files.', 'diluxone-offload' ),
						'enabling_cloud_storage_offloading' => __( 'Enabling cloud storage offloading...', 'diluxone-offload' ),
						'enabling'                         => __( 'Enabling...', 'diluxone-offload' ),
						'offloading_enabled_successfully'  => __( 'Offloading enabled successfully!', 'diluxone-offload' ),
						'connection_error'                 => __( 'Connection error', 'diluxone-offload' ),
						'cancelling'                       => __( 'Cancelling...', 'diluxone-offload' ),
						'cancelling_sync_please_wait'      => __( 'Cancelling sync... Please wait.', 'diluxone-offload' ),
						'sync_cancelled_refreshing'        => __( 'Sync cancelled. Refreshing...', 'diluxone-offload' ),
						'retry_failed_files'               => __( 'Retry Failed Files', 'diluxone-offload' ),
						'failed_files_to_retry'            => __( 'Failed files to retry:', 'diluxone-offload' ),
						'previously_failed'                => __( 'Previously failed:', 'diluxone-offload' ),
						'new_files_found'                  => __( 'New files found:', 'diluxone-offload' ),
						'performance_level'                => __( 'Performance Level:', 'diluxone-offload' ),
						'balanced_5_parallel_recommended'  => __( 'Balanced (5 parallel - Recommended)', 'diluxone-offload' ),
						'fast_20_parallel_more_resources'  => __( 'Fast (20 parallel - More resources)', 'diluxone-offload' ),
						'intensive_40_parallel_maximum_speed' => __( 'Intensive (40 parallel - Maximum speed)', 'diluxone-offload' ),
						'higher_values_faster_upload_but_more' => __( 'Higher values = faster upload but more server resources. Start with Balanced if unsure.', 'diluxone-offload' ),
						'retry_upload'                     => __( 'Retry Upload', 'diluxone-offload' ),
						'cancel'                           => __( 'Cancel', 'diluxone-offload' ),
						'error_calculating_failed_files'   => __( 'Error calculating failed files', 'diluxone-offload' ),
						'confirm_complete_resync'          => __( 'Confirm Complete Resync', 'diluxone-offload' ),
						'are_you_sure_you_want_to'         => __( 'Are you sure you want to resynchronize all files?', 'diluxone-offload' ),
						'all_sync_history_will_be_cleared' => __( 'All sync history will be cleared', 'diluxone-offload' ),
						'files_will_be_scanned_from_scratch' => __( 'Files will be scanned from scratch', 'diluxone-offload' ),
						'already_synced_files_will_be_detected' => __( 'Already synced files will be detected and skipped', 'diluxone-offload' ),
						'this_action_cannot_be_undone'     => __( 'This action cannot be undone.', 'diluxone-offload' ),
						'yes_resync_all_files'             => __( 'Yes, Resync All Files', 'diluxone-offload' ),
						'processing'                       => __( 'Processing...', 'diluxone-offload' ),
						'clearing_sync_data'               => __( 'Clearing sync data...', 'diluxone-offload' ),
						'sync_data_cleared'                => __( 'Sync Data Cleared', 'diluxone-offload' ),
						'reloading_page'                   => __( 'Reloading page...', 'diluxone-offload' ),
						'error_preparing_resync'           => __( 'Error preparing resync', 'diluxone-offload' ),
						'are_you_sure_you_want_to_2'       => __( 'Are you sure you want to clear the failed files list?', 'diluxone-offload' ),
						'clearing'                         => __( 'Clearing...', 'diluxone-offload' ),
						'clear_list'                       => __( 'Clear List', 'diluxone-offload' ),
						'files_discarded_but_failed_to_enable' => __( 'Files discarded but failed to enable offloading', 'diluxone-offload' ),
						'connection_error_while_enabling_offloading' => __( 'Connection error while enabling offloading', 'diluxone-offload' ),
						'failed_to_discard_files'          => __( 'Failed to discard files', 'diluxone-offload' ),
						'sync_cancelled_and_reset_to_configured' => __( 'Sync cancelled and reset to configured state', 'diluxone-offload' ),
						'failed_to_cancel_sync'            => __( 'Failed to cancel sync', 'diluxone-offload' ),
						'connection_error_while_cancelling_sync' => __( 'Connection error while cancelling sync', 'diluxone-offload' ),
						'connection_error_deletion_interrupted' => __( 'Connection error. Deletion interrupted.', 'diluxone-offload' ),
						'deletion_completed_successfully'  => __( 'Deletion Completed Successfully!', 'diluxone-offload' ),
						'all_local_files_have_been_deleted' => __( 'All local files have been deleted.', 'diluxone-offload' ),
						'deletion_completed_with_errors'   => __( 'Deletion Completed with Errors', 'diluxone-offload' ),
						'some_files_could_not_be_deleted'  => __( 'Some files could not be deleted.', 'diluxone-offload' ),
						'deleted'                          => __( 'Deleted:', 'diluxone-offload' ),
						'deactivating_offloading'          => __( 'Deactivating Offloading...', 'diluxone-offload' ),
						'files_already_exist_locally'      => __( 'files already exist locally.', 'diluxone-offload' ),
						'files_are_already_local_but_failed' => __( 'Files are already local but failed to disable offloading. Please disable manually.', 'diluxone-offload' ),
						'total_files_in_cloud'             => __( 'Total files in cloud:', 'diluxone-offload' ),
						'already_local'                    => __( 'Already local:', 'diluxone-offload' ),
						'pending_download'                 => __( 'Pending download:', 'diluxone-offload' ),
						'disconnecting'                    => __( 'Disconnecting...', 'diluxone-offload' ),
						'disconnected_reloading'           => __( 'Disconnected. Reloading...', 'diluxone-offload' ),
						'force_disconnect_without_sync'    => __( 'Force Disconnect Without Sync', 'diluxone-offload' ),
						'failed_to_disconnect'             => __( 'Failed to disconnect', 'diluxone-offload' ),
						'cancelling_download'              => __( 'Cancelling download...', 'diluxone-offload' ),
						'download_completed_with_errors'   => __( 'Download Completed with Errors', 'diluxone-offload' ),
						'some_files_could_not_be_downloaded' => __( 'Some files could not be downloaded.', 'diluxone-offload' ),
						'downloaded'                       => __( 'Downloaded:', 'diluxone-offload' ),
						'skipped'                          => __( 'Skipped:', 'diluxone-offload' ),
						'files_downloaded_but_failed_to_disable' => __( 'Files downloaded but failed to disable offloading. Please disable manually.', 'diluxone-offload' ),
						'download_performance_level'       => __( 'Download Performance Level:', 'diluxone-offload' ),
						'balanced_is_recommended_for_most_cases' => __( 'Balanced is recommended for most cases. Fast and Intensive require more server resources.', 'diluxone-offload' ),
						'connection_error_please_try_again' => __( 'Connection error. Please try again.', 'diluxone-offload' ),
						'max_retries_exceeded_please_try_again' => __( 'Max retries exceeded. Please try again later.', 'diluxone-offload' ),
						'dev_mode_enable_offloading_without_syncing' => __( 'DEV MODE: Enable offloading without syncing files? This assumes cloud already has all files.', 'diluxone-offload' ),
						'dev_mode_offloading_enabled_without_sync' => __( 'DEV MODE: Offloading enabled without sync!', 'diluxone-offload' ),
						'dev_mode_disconnect_without_downloading_files' => __( 'DEV MODE: Disconnect without downloading files? This assumes local already has all files.', 'diluxone-offload' ),
						'dev_mode_offloading_disabled_without_sync' => __( 'DEV MODE: Offloading disabled without sync!', 'diluxone-offload' ),
					),
					'data' => array(
						'current_state' => $template_data['current_state'] ?? null,
					),
				);
				$object  = 'DiluxOneOffloadSync';
				$handle  = 'diluxone-offload-admin-sync';
				break;
		}

		if ( $payload === null ) {
			return null;
		}

		return array(
			'payload' => $payload,
			'object'  => $object,
			'handle'  => $handle,
		);
	}

	/**
	 * Enqueue the stylesheet and script belonging to the tab being rendered.
	 *
	 * @return void
	 */
	private static function enqueue_tab_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter, no state change.
		$pedida = isset( $_GET['tab'] ) ? \sanitize_text_field( \wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tab    = self::current_tab( $pedida );

		$assets = self::tab_assets();
		if ( ! isset( $assets[ $tab ] ) ) {
			return;
		}

		$base   = $assets[ $tab ];
		$handle = 'diluxone-offload-' . $base;

		if ( file_exists( DILUXONE_OFFLOAD_DIR . 'assets/css/' . $base . '.css' ) ) {
			wp_enqueue_style(
				$handle,
				DILUXONE_OFFLOAD_URL . 'assets/css/' . $base . '.css',
				array( 'diluxone-offload-admin' ),
				DILUXONE_OFFLOAD_VERSION
			);
		}

		if ( file_exists( DILUXONE_OFFLOAD_DIR . 'assets/js/' . $base . '.js' ) ) {
			wp_enqueue_script(
				$handle,
				DILUXONE_OFFLOAD_URL . 'assets/js/' . $base . '.js',
				array( 'jquery', 'diluxone-offload-admin' ),
				DILUXONE_OFFLOAD_VERSION,
				true
			);

			// Localize the strings here, not at render time: some tabs return
			// early before rendering their template, and the script is enqueued
			// either way. Localizing here guarantees the object always exists.
			$bag = self::tab_payload( $tab );
			if ( $bag !== null ) {
				wp_localize_script( $bag['handle'], $bag['object'], $bag['payload'] );
			}
		}
	}

	/**
	 * Merge the tab's render-time data into its already-localized object.
	 *
	 * @param string               $tab           Resolved tab slug.
	 * @param array<string, mixed> $template_data Data the tab's template renders with.
	 * @return void
	 */
	private static function merge_tab_data( string $tab, array $template_data ): void {
		$bag = self::tab_payload( $tab, $template_data );

		if ( $bag === null || empty( $bag['payload']['data'] ) || ! wp_script_is( $bag['handle'], 'enqueued' ) ) {
			return;
		}

		wp_add_inline_script(
			$bag['handle'],
			sprintf(
				'Object.assign( %s.data, %s );',
				$bag['object'],
				wp_json_encode( $bag['payload']['data'] )
			),
			'before'
		);
	}

	/**
	 * Render tab content based on current tab
	 *
	 * @param mixed $current_tab
	 */
	private static function render_tab_content( $current_tab ): void {
		$template_path = '';
		$template_data = array();

		switch ( $current_tab ) {
			case 'overview':
				$template_path           = 'admin-overview.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();

				// Fetch cloud stats for overview display
				$current_state_ov = ConfigManager::get_state();
				$is_configured_ov = ! in_array( $current_state_ov, array( 'not_configured', '' ), true );
				$cloud_stats_ov   = null;

				if ( $is_configured_ov ) {
					try {
						$client = ConfigManager::get_cloud_client();
						if ( $client instanceof \DiluxOneOffload\Providers\DiluxOneCloudProvider ) {
							$cloud_stats_ov = $client->get_stats();
						} elseif ( $client instanceof \DiluxOneOffload\Providers\AzureProvider ) {
							$cloud_stats_ov = $client->get_container_stats();
						}
					} catch ( \Exception $e ) {
						$cloud_stats_ov = array(
							'success' => false,
							'message' => $e->getMessage(),
						);
					}
				}

				$template_data = array(
					'config'          => $config,
					'cloud_stats'     => $cloud_stats_ov,
					'stats'           => self::get_basic_stats(),
					'recent_activity' => array(),
				);
				break;

			case 'settings':
				$template_path           = 'admin-settings.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();
				$template_data           = array(
					'config' => $config,
				);
				break;

			case 'cloud-provider':
				$template_path           = 'admin-cloud-provider.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();

				// Prepare cloud stats and DB data for template (no business logic in templates)
				$current_state_cp   = ConfigManager::get_state();
				$is_configured_cp   = ! in_array( $current_state_cp, array( 'not_configured', '' ), true );
				$cloud_stats_cp     = null;
				$has_files_in_db_cp = false;

				if ( $is_configured_cp ) {
					// Fetch cloud stats
					try {
						$client = ConfigManager::get_cloud_client();
						if ( $client instanceof \DiluxOneOffload\Providers\DiluxOneCloudProvider ) {
							$cloud_stats_cp = $client->get_stats();
						} elseif ( $client instanceof \DiluxOneOffload\Providers\AzureProvider ) {
							$cloud_stats_cp = $client->get_container_stats();
						}
					} catch ( \Exception $e ) {
						$cloud_stats_cp = array(
							'success' => false,
							'message' => $e->getMessage(),
						);
					}

					// Check files in DB (table name from trusted source, no user input)
					require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
					global $wpdb;
					$table_name_cp = DiluxOneOffloadDB::get_table_name();
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted DiluxOneOffloadDB::get_table_name()
					$has_files_in_db_cp = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name_cp}" ) > 0;
				}

				$template_data = array(
					'config'          => $config,
					'current_state'   => $current_state_cp,
					'is_configured'   => $is_configured_cp,
					'cloud_stats'     => $cloud_stats_cp,
					'has_files_in_db' => $has_files_in_db_cp,
				);
				break;

			case 'sync':
			case 'sync-offloading':
				// Check if sync tab is accessible
				$is_configured = ConfigManager::is_configured();
				if ( ! $is_configured ) {
					// Distinguish "credentials cannot be decrypted" from
					// "never configured": the recovery path is different
					// (re-enter creds vs initial setup) and the existing
					// "Steps to Enable Sync" copy misleads the user when the
					// real problem is unreadable credentials.
					$health             = ConfigManager::get_connection_health();
					$is_decrypt_failure = $health['status'] === 'unhealthy'
						&& $health['error_code'] === 'decrypt_failed';

					if ( $is_decrypt_failure ) {
						?>
						<div class="wrap">
							<div class="notice notice-error">
								<h3><?php \esc_html_e( 'Stored Credentials Unreadable', 'diluxone-offload' ); ?></h3>
								<p><?php \esc_html_e( 'Sync is paused because the saved cloud credentials cannot be decrypted. This is not the same as "never configured" — the cloud provider details are still in the database, but the WordPress salts changed since they were saved (commonly after restoring a database from a different environment).', 'diluxone-offload' ); ?></p>
								<p><?php \esc_html_e( 'Re-enter the credentials in the Cloud Provider tab. Everything else (provider selection, container name, sync state) is preserved.', 'diluxone-offload' ); ?></p>
								<p>
									<a href="?page=diluxone-offload&tab=cloud-provider" class="button button-primary">
										<?php \esc_html_e( 'Re-enter Credentials', 'diluxone-offload' ); ?>
									</a>
								</p>
							</div>
						</div>
						<?php
						return;
					}
					?>
					<div class="wrap">
						<div class="notice notice-warning is-dismissible">
							<h3><?php esc_html_e( 'Sync & Offloading Not Available', 'diluxone-offload' ); ?></h3>
							<p><?php esc_html_e( 'Please configure a cloud provider in the Settings tab first.', 'diluxone-offload' ); ?></p>
							<p>
								<a href="?page=diluxone-offload&tab=settings" class="button button-primary">
									<?php esc_html_e( 'Go to Settings', 'diluxone-offload' ); ?>
								</a>
							</p>
						</div>

						<div class="card" style="max-width: 600px; margin-top: 20px;">
							<h2><?php esc_html_e( 'Steps to Enable Sync', 'diluxone-offload' ); ?></h2>
							<ol>
								<li><?php esc_html_e( 'Go to the Settings tab', 'diluxone-offload' ); ?></li>
								<li><?php esc_html_e( 'Configure your cloud storage provider', 'diluxone-offload' ); ?></li>
								<li><?php esc_html_e( 'Test the connection', 'diluxone-offload' ); ?></li>
								<li><?php esc_html_e( 'Save your configuration', 'diluxone-offload' ); ?></li>
								<li><?php esc_html_e( 'Return to this tab to sync files', 'diluxone-offload' ); ?></li>
							</ol>
						</div>
					</div>
					<?php
					return;
				}

				$template_path           = 'admin-sync.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();

				$current_state_sync = ConfigManager::get_state();

				// Smart cleanup: reset stale syncing state (no heartbeat for >90s)
				if ( $current_state_sync === 'syncing' ) {
					$sync_meta            = get_option( 'diluxone_offload_sync_meta', array() );
					$last_heartbeat       = $sync_meta['last_heartbeat'] ?? 0;
					$time_since_heartbeat = time() - $last_heartbeat;

					if ( $time_since_heartbeat > 90 ) {
						ConfigManager::set_state( \DiluxOneOffload\Enums\PluginState::SYNCED );
						ConfigManager::clear_sync_progress();
						$current_state_sync = 'synced';
						Logger::info( '[DiluxOne Offload Admin] Auto-reset state from syncing to SYNCED (inactive for ' . $time_since_heartbeat . 's)' );
					}
				}

				// Get failed files from DB
				require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
				$failed_files_sync = DiluxOneOffloadDB::get_failed_files();

				// Get file counts from DB for continuation detection (table name from trusted source)
				global $wpdb;
				$table_name_sync = DiluxOneOffloadDB::get_table_name();
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted DiluxOneOffloadDB::get_table_name()
				$has_files_in_db_sync = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name_sync}" ) > 0;
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$synced_count_sync = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name_sync} WHERE synced=1" );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pending_count_sync = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name_sync} WHERE synced=0 AND deleted=0" );

				$template_data = array(
					'config'          => $config,
					'current_state'   => $current_state_sync,
					'sync_progress'   => ConfigManager::get_sync_progress(),
					'stats'           => self::get_basic_stats(),
					'failed_files'    => $failed_files_sync,
					'failed_count'    => count( $failed_files_sync ),
					'has_files_in_db' => $has_files_in_db_sync,
					'synced_count'    => $synced_count_sync,
					'pending_count'   => $pending_count_sync,
				);
				break;

			case 'activity':
				$template_path = 'admin-activity.php';
				$template_data = array(
					'activity_log'   => array(),
					'activity_stats' => self::get_basic_activity_stats(),
					'chart_data'     => array(
						'labels'    => array(),
						'uploads'   => array(),
						'deletions' => array(),
					),
				);
				break;

			case 'status':
			case 'tools':
			case 'status-tools':  // legacy alias — keep for old bookmarked URLs
				// Status and Tools share a single template that renders one
				// section at a time, picked via $section. The legacy
				// 'status-tools' URL falls back to 'status'.
				$template_path           = 'admin-status-tools.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();
				$template_data           = array(
					'config'        => $config,
					'health_status' => self::get_basic_health_status(),
					'status_checks' => self::get_basic_status_checks(),
					'storage_stats' => self::get_basic_stats(),
					'section'       => ( $current_tab === 'tools' ) ? 'tools' : 'status',
				);
				break;

			default:
				$template_path           = 'admin-overview.php';
				$config                  = ConfigManager::get_config();
				$config['is_configured'] = ConfigManager::is_configured();
				$template_data           = array(
					'config'          => $config,
					'stats'           => self::get_basic_stats(),
					'recent_activity' => array(),
				);
		}

		$full_template_path = DILUXONE_OFFLOAD_DIR . 'templates/' . $template_path;

		// The strings were localized when the script was enqueued, so the object
		// exists even on the early-return branches below. Only the data depends
		// on $template_data, so merge that in now. Footer scripts have not been
		// printed yet at this point, so this still reaches the browser.
		self::merge_tab_data( $current_tab, $template_data );

		if ( file_exists( $full_template_path ) ) {
			// Templates expect each value of $template_data to be available as
			// a local variable. The keys are static (set in this method) and
			// never derived from user input, so the documented extract() risk
			// (variable shadowing from untrusted keys) does not apply here.
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Keys are static and trusted; templates depend on this contract.
			extract( $template_data );
			include $full_template_path;
		} else {
			echo '<p>' . \esc_html(
				/* translators: %s: relative template file path */
				\sprintf( \__( 'Template not found: %s', 'diluxone-offload' ), $template_path )
			) . '</p>';
		}
	}

	/**
	 * Short, single-line reason for a health pause. Used inline in the
	 * Status tab cards as a sub-label so each card explains *why* it is
	 * showing "paused" instead of its normal state.
	 *
	 * Mirrors the error_code branches in health_banner_copy() — keep them
	 * in sync if a new error_code is added.
	 *
	 * @param string $error_code Connection-health error_code (e.g. 'decrypt_failed', '403')
	 * @return string Short human label (already translated)
	 */
	public static function pause_reason_short( string $error_code ): string {
		switch ( $error_code ) {
			case 'decrypt_failed':
				return __( 'credentials unreadable', 'diluxone-offload' );
			case '401':
			case '403':
				return __( 'permission denied', 'diluxone-offload' );
			case '404':
				return __( 'container not found', 'diluxone-offload' );
			case 'exception':
				return __( 'connection error', 'diluxone-offload' );
			default:
				return __( 'cloud unreachable', 'diluxone-offload' );
		}
	}

	/**
	 * Build the title / detail / CTA copy for the health banner based on the
	 * recorded `error_code`. Each branch maps a known failure mode to a
	 * tailored message so the user knows exactly what to do.
	 *
	 * Returns an array with keys: title, detail, cta_label.
	 *
	 * @param string $error_code    Code from connection_health (e.g. 'decrypt_failed', '403', 'exception')
	 * @param string $error_message Human-readable message from the failure source
	 * @return array{title:string,detail:string,cta_label:string}
	 */
	private static function health_banner_copy( string $error_code, string $error_message ): array {
		switch ( $error_code ) {
			case 'decrypt_failed':
				return array(
					'title'     => __( 'Stored Credentials Unreadable', 'diluxone-offload' ),
					'detail'    => __( 'Your saved cloud credentials cannot be decrypted. This usually means the WordPress salts (AUTH_KEY / SECURE_AUTH_KEY) changed since these credentials were saved — for example after restoring a database from a different environment. Re-enter your credentials to fix this.', 'diluxone-offload' ),
					'cta_label' => __( 'Re-enter Credentials', 'diluxone-offload' ),
				);

			case '401':
			case '403':
				return array(
					'title'     => __( 'Cloud Permission Denied', 'diluxone-offload' ),
					'detail'    => __( 'The cloud provider rejected the credentials. The access key may have been rotated, the SAS token may have expired, or the role assignment is missing. Verify the credentials and re-enter them.', 'diluxone-offload' ),
					'cta_label' => __( 'Update Credentials', 'diluxone-offload' ),
				);

			case '404':
				return array(
					'title'     => __( 'Container Not Found', 'diluxone-offload' ),
					'detail'    => __( 'The configured container or bucket does not exist on the cloud provider. Check that the name is spelled correctly and that it has been created.', 'diluxone-offload' ),
					'cta_label' => __( 'Open Cloud Provider Settings', 'diluxone-offload' ),
				);

			case 'exception':
				return array(
					'title'     => __( 'Cloud Connection Error', 'diluxone-offload' ),
					'detail'    => $error_message !== ''
						? $error_message
						: __( 'An unexpected error occurred while talking to the cloud provider.', 'diluxone-offload' ),
					'cta_label' => __( 'Update your credentials in the Cloud Provider tab', 'diluxone-offload' ),
				);

			default:
				// Unknown / generic — preserve the existing copy.
				return array(
					'title'     => __( 'Cloud Connection Error', 'diluxone-offload' ),
					'detail'    => $error_message,
					'cta_label' => __( 'Update your credentials in the Cloud Provider tab', 'diluxone-offload' ),
				);
		}
	}

	/**
	 * Render the connection health error banner.
	 *
	 * @param array<string, mixed> $health Connection health data from ConfigManager
	 */
	private static function render_connection_health_banner( array $health ): void {
		$current_state = ConfigManager::get_state();
		$is_offloading = ( $current_state === 'offloading_active' );
		$copy          = self::health_banner_copy(
			(string) ( $health['error_code'] ?? '' ),
			(string) ( $health['error_message'] ?? '' )
		);

		// Calculate time since last success (only show if > 5 min to avoid
		// confusing "2 minutes ago" when the break just happened)
		$last_success_text = '';
		if ( $health['last_success'] > 0 ) {
			$diff = time() - $health['last_success'];
			if ( $diff < 300 ) {
				// Too recent — skip showing it (just broke, not useful context)
				$last_success_text = '';
			} elseif ( $diff < 3600 ) {
				$minutes = (int) ( $diff / 60 );
				/* translators: %d: number of minutes */
				$last_success_text = sprintf( \_n( '%d minute ago', '%d minutes ago', $minutes, 'diluxone-offload' ), $minutes );
			} elseif ( $diff < 86400 ) {
				$hours = (int) ( $diff / 3600 );
				/* translators: %d: number of hours */
				$last_success_text = sprintf( \_n( '%d hour ago', '%d hours ago', $hours, 'diluxone-offload' ), $hours );
			} else {
				$days = (int) ( $diff / 86400 );
				/* translators: %d: number of days */
				$last_success_text = sprintf( \_n( '%d day ago', '%d days ago', $days, 'diluxone-offload' ), $days );
			}
		}
		?>
		<div class="diluxone-offload-health-banner" style="background: #fef0f0; border-left: 4px solid #d63638; padding: 16px 20px; margin-bottom: 20px; border-radius: 4px;">
			<div style="display: flex; align-items: flex-start; gap: 12px;">
				<span class="dashicons dashicons-warning" style="color: #d63638; font-size: 24px; flex-shrink: 0; margin-top: 2px;"></span>
				<div>
					<strong style="color: #721c24; font-size: 15px;">
						<?php echo \esc_html( $copy['title'] ); ?>
					</strong>
					<p style="margin: 8px 0 0; color: #721c24;">
						<?php echo \esc_html( $copy['detail'] ); ?>
					</p>
					<?php if ( $last_success_text ) : ?>
					<p style="margin: 4px 0 0; color: #856404; font-size: 13px;">
						<?php
						/* translators: %s: human-readable time, e.g. "5 minutes ago" */
						printf( \esc_html__( 'Last successful connection: %s', 'diluxone-offload' ), \esc_html( $last_success_text ) );
						?>
					</p>
					<?php endif; ?>
					<?php if ( $is_offloading ) : ?>
					<p style="margin: 8px 0 0; color: #721c24; font-weight: 600;">
						<?php \esc_html_e( 'File uploads are falling back to local storage.', 'diluxone-offload' ); ?>
					</p>
					<?php endif; ?>
					<p style="margin: 8px 0 0; font-size: 13px;">
						<a href="<?php echo \esc_url( \admin_url( 'admin.php?page=diluxone-offload&tab=cloud-provider' ) ); ?>" style="color: #721c24; text-decoration: underline;">
							<?php echo \esc_html( $copy['cta_label'] ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get basic stats for templates
	 *
	 * @return array<string, mixed>
	 */
	private static function get_basic_stats(): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'diluxone_offload_files';

		// Get deletable files count (synced but not deleted locally)
		$deletable_stats = $wpdb->get_row(
			"SELECT
                COUNT(*) as total_files,
                COALESCE(SUM(size), 0) as total_size
             FROM {$table_name}
             WHERE synced = 1 AND deleted = 0",
			ARRAY_A
		);

		$deletable_files = $deletable_stats ? (int) $deletable_stats['total_files'] : 0;
		$deletable_size  = $deletable_stats ? (int) $deletable_stats['total_size'] : 0;

		return array(
			'total_files'     => 0,
			'total_size'      => 0,
			'cloud_files'     => 0,
			'cloud_size'      => 0,
			'local_files'     => 0,
			'local_size'      => 0,
			'files_today'     => 0,
			'size_today'      => 0,
			'deletable_files' => $deletable_files,
			'deletable_size'  => $deletable_size,
		);
	}

	/**
	 * Get basic activity stats for templates
	 *
	 * @return array<string, mixed>
	 */
	private static function get_basic_activity_stats(): array {
		return array(
			'total_today'    => 0,
			'total_week'     => 0,
			'total_month'    => 0,
			'errors_count'   => 0,
			'trend_today'    => 0,
			'week_uploads'   => 0,
			'week_deletions' => 0,
			'month_size'     => 0,
			'total_entries'  => 0,
		);
	}

	/**
	 * Get basic health status for templates
	 *
	 * @return array<string, mixed>
	 */
	private static function get_basic_health_status(): array {
		return array(
			'overall'         => 50,
			'checks_passed'   => 3,
			'warnings'        => 2,
			'critical_issues' => 1,
		);
	}

	/**
	 * Get basic status checks for templates
	 *
	 * @return array<string, mixed>
	 */
	private static function get_basic_status_checks(): array {
		return array(
			'azure_connection'      => array(
				'status'  => 'warning',
				'message' => \__( 'Not configured yet', 'diluxone-offload' ),
				'details' => array(),
			),
			'stream_wrapper'        => array(
				'status'  => 'success',
				'message' => \__( 'Stream wrapper registered', 'diluxone-offload' ),
				'details' => array(
					'protocol'   => 'diluxone-offload://',
					'registered' => true,
				),
			),
			'file_operations'       => array(
				'status'  => 'warning',
				'message' => \__( 'Not tested yet', 'diluxone-offload' ),
				'details' => array(
					'write'  => false,
					'read'   => false,
					'delete' => false,
				),
			),
			'performance'           => array(
				'status'  => 'warning',
				'message' => \__( 'Not tested yet', 'diluxone-offload' ),
				'details' => array(
					'upload_speed'   => '0 MB/s',
					'download_speed' => '0 MB/s',
					'latency'        => '0 ms',
				),
			),
			'wordpress_integration' => array(
				'status'  => 'success',
				'message' => \__( 'WordPress integration active', 'diluxone-offload' ),
				'details' => array(
					'media_library' => true,
					'url_rewriting' => true,
					'multisite'     => \is_multisite(),
				),
			),
			'security'              => array(
				'status'  => 'success',
				'message' => \__( 'Security features active', 'diluxone-offload' ),
				'details' => array(
					'ssl'            => true,
					'encryption'     => true,
					'access_control' => true,
				),
			),
		);
	}

	/**
	 * Handle configuration save
	 *
	 * @throws \Exception When PluginSettings/ProviderConfig validation fails inside
	 *                   the inner try blocks (caught and converted to error notices).
	 */
	public static function save_config(): void {
		// Check nonce for security
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'diluxone_offload_save_config' ) ) {
			wp_die( esc_html__( 'Security check failed', 'diluxone-offload' ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		// Get redirect tab from form (cloud-provider or settings)
		$redirect_tab = sanitize_text_field( wp_unslash( $_POST['redirect_tab'] ?? 'settings' ) );

		// ========================================================================
		// NEW ARCHITECTURE: Detect what's being saved based on tab + fields
		// ========================================================================
		// Key insight: disabled fields are NOT sent in POST, so we use redirect_tab
		// to determine intent, then check which fields were actually sent

		$is_from_settings_tab       = ( $redirect_tab === 'settings' );
		$is_from_cloud_provider_tab = ( $redirect_tab === 'cloud-provider' );

		if ( $is_from_settings_tab ) {
			// Settings-only save: Use PluginSettings::fromPost()
			try {
				$settings = \DiluxOneOffload\DTOs\PluginSettings::fromPost( $_POST );

				$result = ConfigManager::save_plugin_settings( $settings );

				if ( $result ) {
					$redirect_url = add_query_arg(
						array(
							'page'    => 'diluxone-offload',
							'tab'     => $redirect_tab,
							'success' => rawurlencode( 'Settings saved successfully!' ),
						),
						admin_url( 'admin.php' )
					);
				} else {
					throw new \Exception( 'Failed to save settings to database' );
				}
			} catch ( \Exception $e ) {
				$redirect_url = add_query_arg(
					array(
						'page'  => 'diluxone-offload',
						'tab'   => $redirect_tab,
						'error' => rawurlencode( 'Failed to save settings: ' . $e->getMessage() ),
					),
					admin_url( 'admin.php' )
				);
			}

			wp_safe_redirect( $redirect_url );
			exit;

		} elseif ( $is_from_cloud_provider_tab ) {
			// Cloud Provider tab: Could be full provider save OR custom domain only

			// Check if provider credentials were sent (not disabled)
			$has_provider_credentials = isset( $_POST['cloud_provider'] ) &&
				( isset( $_POST['account_name'] ) || isset( $_POST['api_key'] ) );

			if ( $has_provider_credentials ) {
				// Full provider save: credentials + custom domain
				try {
					$provider = \DiluxOneOffload\DTOs\ProviderConfig::fromPost( $_POST );

					$result = ConfigManager::save_provider_config( $provider );

					if ( $result ) {
						$redirect_url = add_query_arg(
							array(
								'page'    => 'diluxone-offload',
								'tab'     => $redirect_tab,
								'success' => rawurlencode( 'Provider configuration saved successfully!' ),
							),
							admin_url( 'admin.php' )
						);
					} else {
						throw new \Exception( 'Failed to save provider configuration to database' );
					}
				} catch ( \InvalidArgumentException $e ) {
					// Validation error from ProviderConfig::fromPost()
					$redirect_url = add_query_arg(
						array(
							'page'  => 'diluxone-offload',
							'tab'   => $redirect_tab,
							'error' => rawurlencode( $e->getMessage() ),
						),
						admin_url( 'admin.php' )
					);
				} catch ( \Exception $e ) {
					$redirect_url = add_query_arg(
						array(
							'page'  => 'diluxone-offload',
							'tab'   => $redirect_tab,
							'error' => rawurlencode( 'Failed to save configuration: ' . $e->getMessage() ),
						),
						admin_url( 'admin.php' )
					);
				}
			} else {
				// No credentials sent (fields were disabled) — nothing to save
				$redirect_url = add_query_arg(
					array(
						'page' => 'diluxone-offload',
						'tab'  => $redirect_tab,
					),
					admin_url( 'admin.php' )
				);
			}

			wp_safe_redirect( $redirect_url );
			exit;

		} else {
			// Unknown tab - shouldn't happen
			$redirect_url = add_query_arg(
				array(
					'page'  => 'diluxone-offload',
					'tab'   => 'overview',
					'error' => rawurlencode( 'Invalid save request' ),
				),
				admin_url( 'admin.php' )
			);

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * AJAX handler for testing connection
	 */
	public static function ajax_test_connection(): void {

		// Check nonce for security - try different nonce field names
		$nonce_verified = false;
		if ( isset( $_POST['nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			$nonce_verified = true;
		} elseif ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			$nonce_verified = true;
		}

		if ( ! $nonce_verified ) {
			Logger::error( '[DiluxOne Offload] ajax_test_connection: nonce verification failed.' );
			wp_send_json_error( array( 'message' => esc_html__( 'Security check failed', 'diluxone-offload' ) ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions', 'diluxone-offload' ) ) );
		}

		// Detect provider type
		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? 'azure' ) );

		$api_key        = '';
		$account_name   = '';
		$container_name = '';

		try {
			if ( $provider === 'diluxone' ) {
				$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
				if ( empty( $api_key ) ) {
					wp_send_json_error( array( 'message' => esc_html__( 'API Key is required', 'diluxone-offload' ) ) );
				}
				$client = \DiluxOneOffload\Factories\CloudStorageFactory::create(
					'diluxone',
					array(
						'api_key' => $api_key,
					)
				);
			} else {
				$account_name   = sanitize_text_field( wp_unslash( $_POST['account_name'] ?? '' ) );
				$account_key    = sanitize_text_field( wp_unslash( $_POST['account_key'] ?? '' ) );
				$container_name = sanitize_text_field( wp_unslash( $_POST['container_name'] ?? '' ) );

				if ( empty( $account_name ) || empty( $account_key ) || empty( $container_name ) ) {
					wp_send_json_error( array( 'message' => esc_html__( 'Missing required fields', 'diluxone-offload' ) ) );
				}
				$client = \DiluxOneOffload\Factories\CloudStorageFactory::create(
					'azure',
					array(
						'storage_account' => $account_name,
						'access_key'      => $account_key,
						'container_name'  => $container_name,
					)
				);
			}

			if ( $client === null ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Could not instantiate cloud client for the selected provider.', 'diluxone-offload' ) ) );
			}

			$result = $client->test_connection();

			if ( $result['success'] ) {
				Logger::info( '[DiluxOne Offload] Connection successful for provider: ' . $provider );
				ConfigManager::record_connection_success();

				// Save provider-aware transient for validation when saving
				if ( $provider === 'diluxone' ) {
					$transient_data = array(
						'provider'       => 'diluxone',
						'api_key_prefix' => substr( $api_key, 0, 12 ),
						'timestamp'      => time(),
					);
				} else {
					$transient_data = array(
						'provider'       => 'azure',
						'account_name'   => $account_name,
						'container_name' => $container_name,
						'timestamp'      => time(),
					);
				}

				set_transient(
					'diluxone_offload_connection_test_passed_' . get_current_user_id(),
					$transient_data,
					300
				);

				\wp_send_json_success(
					array(
						'message'     => $result['message'] ?? 'Connection successful! You can now save.',
						'test_passed' => true,
					)
				);
			} else {
				Logger::info( '[DiluxOne Offload] Connection failed: ' . $result['message'] );
				\wp_send_json_error(
					array(
						'message' => $result['message'] ?? 'Connection failed',
					)
				);
			}
		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload] Connection error: ' . $e->getMessage() );
			\wp_send_json_error(
				array(
					'message' => 'Connection error: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX handler for refreshing cloud storage statistics
	 *
	 * Calls provider-specific stats method with force_refresh=true.
	 * Uses instanceof to detect which method to call (get_stats for DiluxOne,
	 * get_container_stats for Azure) since these methods are not in the interface.
	 */
	public static function ajax_refresh_stats(): void {
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions', 'diluxone-offload' ) ) );
		}

		$client = ConfigManager::get_cloud_client();
		if ( ! $client ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Cloud client not available', 'diluxone-offload' ) ) );
		}

		try {
			$stats = null;
			if ( $client instanceof \DiluxOneOffload\Providers\DiluxOneCloudProvider ) {
				$stats = $client->get_stats( true );
			} elseif ( $client instanceof \DiluxOneOffload\Providers\AzureProvider ) {
				$stats = $client->get_container_stats( true );
			} else {
				wp_send_json_error( array( 'message' => esc_html__( 'Unknown provider type', 'diluxone-offload' ) ) );
			}

			if ( $stats['success'] ) {
				ConfigManager::record_connection_success();
				wp_send_json_success( $stats['data'] ?? array() );
			} else {
				wp_send_json_error( array( 'message' => $stats['message'] ?? 'Failed to fetch stats' ) );
			}
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX handler for saving updated credentials (Update Credentials modal)
	 */
	public static function ajax_save_updated_credentials(): void {
		// Check nonce
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized', 'diluxone-offload' ) ) );
		}

		// Validate that connection test passed
		$test_data = get_transient( 'diluxone_offload_connection_test_passed_' . get_current_user_id() );
		if ( ! $test_data ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You must test the connection first', 'diluxone-offload' ) ) );
		}

		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) );

		// Build provider_data based on provider type
		if ( $provider === 'diluxone' ) {
			$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

			// Validate transient matches diluxone test
			if ( ( $test_data['provider'] ?? '' ) !== 'diluxone' ||
				( $test_data['api_key_prefix'] ?? '' ) !== substr( $api_key, 0, 12 ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Credentials do not match tested values. Please test again.', 'diluxone-offload' ) ) );
			}

			// Preserve cdn_base_url from existing config
			$current_config = ConfigManager::get_provider_config();
			$provider_data  = array(
				'cloud_provider'  => 'diluxone',
				'provider_config' => array(
					'api_key'      => $api_key,
					'cdn_base_url' => $current_config['provider_config']['cdn_base_url'] ?? '',
				),
			);
		} else {
			$account_name   = sanitize_text_field( wp_unslash( $_POST['account_name'] ?? '' ) );
			$account_key    = sanitize_text_field( wp_unslash( $_POST['account_key'] ?? '' ) );
			$container_name = sanitize_text_field( wp_unslash( $_POST['container_name'] ?? '' ) );

			// Validate transient matches azure test
			if ( ( $test_data['account_name'] ?? '' ) !== $account_name ||
				( $test_data['container_name'] ?? '' ) !== $container_name ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Credentials do not match tested values. Please test again.', 'diluxone-offload' ) ) );
			}

			$provider_data = array(
				'cloud_provider'  => $provider,
				'provider_config' => array(
					'storage_account' => $account_name,
					'access_key'      => $account_key,
					'container_name'  => $container_name,
				),
			);

			// Preserve custom_domain if exists
			$current_config = ConfigManager::get_provider_config();
			if ( ! empty( $current_config['provider_config']['custom_domain'] ) ) {
				$provider_data['provider_config']['custom_domain'] =
					$current_config['provider_config']['custom_domain'];
			}
		}

		try {
			$provider_config = \DiluxOneOffload\DTOs\ProviderConfig::fromArray( $provider_data );
			ConfigManager::save_provider_config( $provider_config );

			// Clear transient
			delete_transient( 'diluxone_offload_connection_test_passed_' . get_current_user_id() );

			// Log for audit
			Logger::info( '[DiluxOne Offload] Credentials updated by user ID: ' . get_current_user_id() );

			wp_send_json_success(
				array(
					'message' => 'Credentials updated successfully',
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'message' => 'Error saving: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Handle offloading configuration save
	 */
	public static function save_offloading_config(): void {
		// Check nonce for security
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'diluxone_offload_save_offloading' ) ) {
			wp_die( esc_html__( 'Security check failed', 'diluxone-offload' ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		// Sanitize and validate input
		$offloading_enabled  = isset( $_POST['offloading_enabled'] );
		$offloading_strategy = sanitize_text_field( wp_unslash( $_POST['offloading_strategy'] ?? 'new_uploads_only' ) );
		$delete_local_files  = isset( $_POST['delete_local_files'] );

		// Save offloading configuration
		try {
			$config                        = ConfigManager::get_config();
			$config['offloading_enabled']  = $offloading_enabled;
			$config['offloading_strategy'] = $offloading_strategy;
			$config['delete_local_files']  = $delete_local_files;

			// Set activation timestamp for new uploads strategy
			if ( $offloading_enabled && $offloading_strategy === 'new_uploads_only' ) {
				if ( empty( $config['diluxone_offload_activation_timestamp'] ) ) {
					update_option( 'diluxone_offload_activation_timestamp', time() );
				}
			}

			ConfigManager::save_config( $config );

			$redirect_url = add_query_arg(
				array(
					'page'    => 'diluxone-offload',
					'tab'     => 'offloading',
					'success' => rawurlencode( 'Offloading configuration saved successfully!' ),
				),
				admin_url( 'admin.php' )
			);

		} catch ( \Exception $e ) {
			$redirect_url = add_query_arg(
				array(
					'page'  => 'diluxone-offload',
					'tab'   => 'offloading',
					'error' => rawurlencode( 'Failed to save offloading configuration: ' . $e->getMessage() ),
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * AJAX handler for migration actions
	 */
	public static function ajax_migration_action(): void {
		// Check nonce for security
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Security check failed', 'diluxone-offload' ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		$action = sanitize_text_field( wp_unslash( $_POST['migration_action'] ?? '' ) );

		if ( empty( $action ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No action specified', 'diluxone-offload' ) ) );
		}

		// Legacy endpoint - MigrationTools class was removed.
		// Migration is now handled via the sync flow in class-diluxone-offload-plugin-enhanced.php
		wp_send_json_error(
			array(
				'message' => 'This migration endpoint is deprecated. Please use the Sync & Offloading tab instead.',
			)
		);
	}

	/**
	 * AJAX handler for individual status checks
	 */
	public static function ajax_test_check(): void {
		// Check nonce for security
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Security check failed', 'diluxone-offload' ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		$check_type = sanitize_text_field( wp_unslash( $_POST['check_type'] ?? '' ) );

		if ( empty( $check_type ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No check type specified', 'diluxone-offload' ) ) );
		}

		try {
			switch ( $check_type ) {
				case 'azure_connection':
					// Use new architecture: ConfigManager::get_cloud_client()
					$cloud_client = ConfigManager::get_cloud_client();

					if ( ! $cloud_client ) {
						$result = array(
							'success' => false,
							'message' => 'Cloud provider not configured',
						);
					} else {
						$result = $cloud_client->test_connection();
					}
					break;

				case 'stream_wrapper':
					$is_registered = in_array( 'diluxoneoffload', stream_get_wrappers(), true );
					$result        = array(
						'success' => $is_registered,
						'message' => $is_registered
							? 'Stream wrapper diluxoneoffload:// is registered'
							: 'Stream wrapper diluxoneoffload:// is NOT registered',
					);
					break;

				case 'upload_test':
				case 'download_test':
					// These legacy tests are no longer needed - stream wrapper handles uploads/downloads
					$result = array(
						'success' => true,
						'message' => 'Test deprecated - functionality handled by stream wrapper',
					);
					break;

				default:
					/* translators: %s: requested check type identifier */
					wp_send_json_error( array( 'message' => sprintf( esc_html__( 'Invalid check type: %s', 'diluxone-offload' ), $check_type ) ) );
			}

			wp_send_json_success(
				array(
					'status'      => $result['success'] ? 'passed' : 'failed',
					'status_text' => $result['success'] ? 'Passed' : 'Failed',
					'message'     => $result['message'] ?? ( $result['success'] ? 'Check passed' : 'Check failed' ),
				)
			);

		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'status'      => 'failed',
					'status_text' => 'Failed',
					'message'     => 'Check error: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Remove provider configuration
	 */
	public static function remove_provider_config(): void {
		// Check nonce for security
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'diluxone_offload_remove_provider' ) ) {
			wp_die( esc_html__( 'Security check failed', 'diluxone-offload' ) );
		}

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		try {
			Logger::info( '[DiluxOne Offload] Removing provider configuration...' );

			// ⭐ COMPLETE DELETION: Delete all wp_options entries
			delete_option( 'diluxone_offload_config' );         // Main config (credentials + settings)
			delete_option( 'diluxone_offload_plugin_state' );   // Plugin state (NOT_CONFIGURED, CONFIGURED, SYNCING, etc.)
			delete_option( 'diluxone_offload_sync_meta' );      // Sync metadata (total_files, start_time, concurrency, etc.)
			delete_option( 'diluxone_offload_sync_progress' );  // Legacy sync progress option (may not exist)
			delete_option( 'diluxone_offload_failed_files' );   // Array of files that failed to sync
			delete_option( 'diluxone_offload_connection_health' ); // Connection health status

			// Clear the MySQL table: wp_diluxone_offload_files (tracks all files for sync)
			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
			DiluxOneOffloadDB::clear_table();

			Logger::info( '[DiluxOne Offload] Provider configuration removed successfully - all credentials, state, and tracking data deleted' );

			$redirect_url = add_query_arg(
				array(
					'page'    => 'diluxone-offload',
					'tab'     => 'settings',
					'success' => rawurlencode( __( 'Cloud storage configuration removed successfully. The plugin has been reset.', 'diluxone-offload' ) ),
				),
				admin_url( 'admin.php' )
			);

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload] Error removing provider configuration: ' . $e->getMessage() );

			$redirect_url = add_query_arg(
				array(
					'page'  => 'diluxone-offload',
					'tab'   => 'settings',
					'error' => rawurlencode(
						sprintf(
						/* translators: %s is the error message. */
							__( 'Failed to remove configuration: %s', 'diluxone-offload' ),
							$e->getMessage()
						)
					),
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * AJAX: Scan files for sync
	 */
	public static function ajax_scan_files(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'diluxone-offload' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		try {
			// Get upload directory
			$upload_dir = wp_upload_dir();
			$files      = array();
			$total_size = 0;

			Logger::log( '[DiluxOne Offload Admin] Scanning directory (ajax): ' . $upload_dir['basedir'], 'info', true );

			// Scan files recursively
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $upload_dir['basedir'] )
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$files[]     = $file->getPathname();
					$total_size += $file->getSize();
				}
			}

			wp_send_json_success(
				array(
					'total_files'          => count( $files ),
					'total_size'           => $total_size,
					'total_size_formatted' => size_format( $total_size ),
					/* translators: 1: number of files, 2: human-readable total size */
					'message'              => sprintf( __( 'Found %1$d files (%2$s)', 'diluxone-offload' ), count( $files ), size_format( $total_size ) ),
				)
			);

		} catch ( \Exception $e ) {
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error scanning files: %s', 'diluxone-offload' ), $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Start sync process
	 */
	public static function ajax_start_sync(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'diluxone-offload' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		try {
			// Start sync using SyncManager
			$sync_manager = new \DiluxOneOffload\SyncManager();
			$result       = $sync_manager->start_sync();

			if ( ! empty( $result['success'] ) ) {
				// Update state to syncing
				ConfigManager::set_state( 'syncing' );

				wp_send_json_success(
					array(
						'message'  => __( 'Sync started successfully', 'diluxone-offload' ),
						'redirect' => true,
					)
				);
			} else {
				$msg = $result['message'] !== '' ? (string) $result['message'] : __( 'Failed to start sync', 'diluxone-offload' );
				wp_send_json_error( esc_html( $msg ) );
			}
		} catch ( \Exception $e ) {
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error starting sync: %s', 'diluxone-offload' ), $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Delete local files after sync
	 */
	public static function ajax_delete_local_files(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'diluxone-offload' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		try {
			// Check that offloading is active - only allow deletion if offloading is ON
			$current_state = ConfigManager::get_state();
			if ( $current_state !== 'offloading_active' ) {
				wp_send_json_error(
					array(
						'message' => __( 'Offloading must be active before deleting local files', 'diluxone-offload' ),
					)
				);
			}

			// Get all synced files from DB
			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

			$synced_files = \DiluxOneOffload\DiluxOneOffloadDB::get_synced_files();

			if ( empty( $synced_files ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'No synced files found to delete', 'diluxone-offload' ),
					)
				);
			}

			// Get upload directory base path
			$upload_dir = wp_upload_dir();
			$base_path  = $upload_dir['basedir'];

			$deleted_count    = 0;
			$failed_count     = 0;
			$total_size_freed = 0;
			$errors           = array();

			foreach ( $synced_files as $file ) {
				$file_path = $base_path . $file['file'];

				// Safety check: ensure file is within uploads directory.
				// realpath() returns false for non-existent paths; treat that as "outside".
				$real_file = realpath( $file_path );
				$real_base = realpath( $base_path );
				if ( $real_file === false || $real_base === false || strpos( $real_file, $real_base ) !== 0 ) {
					$errors[] = 'Skipped file outside uploads directory: ' . $file['file'];
					continue;
				}

				// Check if file exists
				if ( ! file_exists( $file_path ) ) {
					continue; // Already deleted or doesn't exist
				}

				// Get file size before deletion
				$file_size = filesize( $file_path );

				// Delete the file. wp_delete_file() returns void, so we re-check existence.
				wp_delete_file( $file_path );
				clearstatcache( true, $file_path );
				if ( ! file_exists( $file_path ) ) {
					++$deleted_count;
					$total_size_freed += $file_size;
				} else {
					++$failed_count;
					$errors[] = 'Failed to delete: ' . $file['file'];
				}
			}

			// Clean up empty directories
			self::cleanup_empty_directories( $base_path );

			wp_send_json_success(
				array(
					'message'       => sprintf(
						/* translators: 1: number of deleted files, 2: total MB freed (decimal), 3: number of failures */
						__( 'Deleted %1$d files (%2$.2f MB freed). %3$d failures.', 'diluxone-offload' ),
						$deleted_count,
						$total_size_freed / 1048576,
						$failed_count
					),
					'deleted_count' => $deleted_count,
					'failed_count'  => $failed_count,
					'size_freed'    => $total_size_freed,
					'errors'        => $errors,
				)
			);

		} catch ( \Exception $e ) {
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error deleting local files: %s', 'diluxone-offload' ), $e->getMessage() ) );
		}
	}

	/**
	 * Recursively cleanup empty directories
	 *
	 * @param mixed $path
	 */
	private static function cleanup_empty_directories( $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = scandir( $path );
		if ( $entries === false ) {
			return;
		}
		$entries = array_diff( $entries, array( '.', '..' ) );

		foreach ( $entries as $entry ) {
			$full_path = $path . '/' . $entry;
			if ( is_dir( $full_path ) ) {
				self::cleanup_empty_directories( $full_path );
			}
		}

		// Check again after recursive cleanup
		$entries = scandir( $path );
		if ( $entries === false ) {
			return;
		}
		$entries = array_diff( $entries, array( '.', '..' ) );

		// Only delete if empty and not the base uploads directory.
		// WP_Filesystem doesn't expose a portable rmdir() that works without
		// re-initialising file ownership credentials in admin context, so we
		// use the native call here, restricted to verified-empty subdirectories
		// under wp-content/uploads/.
		$upload_dir = wp_upload_dir();
		if ( empty( $entries ) && realpath( $path ) !== realpath( $upload_dir['basedir'] ) ) {
			// Best-effort rmdir on an already-empty directory inside the
			// uploads tree. The @ silencing matches the WP-core pattern
			// for idempotent cleanup — a "directory not empty" race
			// during plugin uninstall is harmless.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Idempotent cleanup of already-verified-empty directory.
			@rmdir( $path );
		}
	}

	/**
	 * AJAX: Cancel sync process OR reset plugin to configured state
	 *
	 * This endpoint handles two scenarios:
	 * 1. Active sync: Cancel the sync and reset to CONFIGURED
	 * 2. Synced state: Reset everything (DB + metadata + state) back to CONFIGURED
	 */
	public static function ajax_cancel_sync(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'diluxone-offload' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		// ⭐ DOUBLE VALIDATION: Validate that this operation is safe to execute
		$session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
		$validation = \DiluxOneOffload\ValidationHelper::validate_sync_operation(
			$session_id,
			'cancel_sync' // Validate that no other tab is syncing
		);

		if ( ! $validation['passed'] ) {
			Logger::warning( '[DiluxOne Offload] Cancel sync BLOCKED by validation: ' . $validation['reason'] );
			wp_send_json_error(
				array(
					'validation_failed' => true,
					'reason'            => $validation['reason'],
					'details'           => $validation['details'],
					'message'           => __( 'Cannot cancel sync: Another tab is currently syncing', 'diluxone-offload' ),
				)
			);
		}

		try {
			$current_state = ConfigManager::get_state();

			// Try to cancel sync using SyncManager
			$sync_manager = new \DiluxOneOffload\SyncManager();
			$cancelled    = $sync_manager->cancel_sync();

			if ( $cancelled ) {
				// Sync was active and got cancelled
				wp_send_json_success(
					array(
						'message' => __( 'Sync cancelled successfully', 'diluxone-offload' ),
					)
				);
			} else {
				// No active sync - perform FULL RESET (for "Cancel Sync & Reset" button)
				// This allows user to discard a completed sync and start fresh

				Logger::info( '[DiluxOne Offload] No active sync - performing full reset to CONFIGURED state' );

				// Clear DB table
				require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
				\DiluxOneOffload\DiluxOneOffloadDB::clear_table();

				// Clear sync metadata
				delete_option( 'diluxone_offload_sync_meta' );
				delete_option( 'diluxone_offload_failed_files' );

				// Reset state to CONFIGURED (preserves credentials)
				ConfigManager::set_state( \DiluxOneOffload\Enums\PluginState::CONFIGURED );

				wp_send_json_success(
					array(
						'message' => __( 'Plugin reset to configured state successfully', 'diluxone-offload' ),
					)
				);
			}
		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload] Error in cancel_sync: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error: %s', 'diluxone-offload' ), $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Mark sync as complete (sets state to SYNCED)
	 */
	public static function ajax_mark_sync_complete(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'diluxone-offload' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		try {
			$plugin       = Plugin::get_instance();
			$sync_manager = $plugin->get_sync_manager();

			if ( $sync_manager ) {
				$sync_meta = get_option( 'diluxone_offload_sync_meta', array() );

				if ( ! empty( $sync_meta ) ) {
					// ⭐ FIX: Clear DB table (keep synced files for stats, but clear pending)
					require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

					// Get final stats before clearing
					$stats = \DiluxOneOffload\DiluxOneOffloadDB::get_stats();
					Logger::info( '[DiluxOne Offload Admin] Final sync stats: ' . wp_json_encode( $stats ) );

					// Clear only pending files (keep synced files for history/stats)
					// Actually, we can keep the table as-is for audit purposes
					// DiluxOneOffloadDB::clear_table(); // Don't clear, just mark as completed

					// Update state to SYNCED
					ConfigManager::set_state( \DiluxOneOffload\Enums\PluginState::SYNCED );

					// Mark sync meta as completed (don't delete, update status)
					$sync_meta['status']   = 'completed';
					$sync_meta['end_time'] = time();
					update_option( 'diluxone_offload_sync_meta', $sync_meta, false );

					Logger::info( '[DiluxOne Offload Admin] Sync marked as complete - state set to SYNCED' );
					wp_send_json_success( 'Sync completed' );
				} else {
					wp_send_json_error( esc_html__( 'No sync metadata found', 'diluxone-offload' ) );
				}
			} else {
				wp_send_json_error( esc_html__( 'Sync manager not available', 'diluxone-offload' ) );
			}
		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Admin] Error completing sync: ' . $e->getMessage() );
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error completing sync: %s', 'diluxone-offload' ), $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Retry failed files (deprecated — kept as a stub).
	 *
	 * Old method removed in favor of the unified sync flow. Retry is now
	 * handled by Plugin::ajax_cs_start_sync with retry_failed=1.
	 */
	public static function ajax_retry_failed(): void {
		wp_send_json_error( esc_html__( 'This endpoint is deprecated. Use ajax_cs_start_sync with retry_failed parameter instead.', 'diluxone-offload' ) );
	}

	/**
	 * AJAX: Clear failed files list
	 */
	public static function ajax_clear_failed(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'diluxone-offload' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		try {
			ConfigManager::clear_failed_files();

			wp_send_json_success( 'Failed files list cleared successfully' );

		} catch ( \Exception $e ) {
			/* translators: %s: error message */
			wp_send_json_error( sprintf( esc_html__( 'Error clearing failed files: %s', 'diluxone-offload' ), $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Resync all files (re-scan and upload missing files)
	 */
	public static function ajax_resync_all(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'diluxone_offload_admin' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'diluxone-offload' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'diluxone-offload' ) );
		}

		try {
			// Clear table and start sync from scratch
			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';

			\DiluxOneOffload\DiluxOneOffloadDB::clear_table();

			// Start sync using SyncManager
			$sync_manager = new \DiluxOneOffload\SyncManager();
			$result       = $sync_manager->start_sync();

			if ( $result['success'] ) {
				ConfigManager::set_state( \DiluxOneOffload\Enums\PluginState::SYNCING );

				wp_send_json_success(
					array(
						'message'     => __( 'Resync started - scanning files...', 'diluxone-offload' ),
						'total_files' => $result['total_files'] ?? 0,
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to start resync', 'diluxone-offload' ),
					)
				);
			}
		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload Admin] Resync error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Error starting resync: ', 'diluxone-offload' ) . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX handler to remove provider configuration
	 */
	public static function ajax_remove_provider(): void {
		// Check nonce
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Insufficient permissions', 'diluxone-offload' ) ) );
		}

		try {
			Logger::info( '[DiluxOne Offload] AJAX: Removing provider configuration...' );

			// Deactivate stream wrapper before removing config (prevents inconsistent state)
			CloudStreamWrapper::deactivate_offloading();
			Logger::info( '[DiluxOne Offload] AJAX: Stream wrapper deactivated during provider removal' );

			// ⭐ COMPLETE DELETION: Delete all wp_options entries
			delete_option( 'diluxone_offload_config' );
			delete_option( 'diluxone_offload_plugin_state' );
			delete_option( 'diluxone_offload_sync_meta' );
			delete_option( 'diluxone_offload_sync_progress' );
			delete_option( 'diluxone_offload_failed_files' );
			delete_option( 'diluxone_offload_connection_health' );

			// Clear the MySQL table
			require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
			DiluxOneOffloadDB::clear_table();

			// Clean up all transients
			delete_transient( 'diluxone_offload_azure_stats' );
			delete_transient( 'diluxone_offload_stats' );
			delete_transient( 'diluxone_offload_sas_token' );
			delete_transient( 'diluxone_offload_connection_test_passed_' . get_current_user_id() );

			Logger::info( '[DiluxOne Offload] AJAX: Provider configuration removed successfully' );

			wp_send_json_success(
				array(
					'message' => 'Cloud storage configuration deleted successfully. Reloading page...',
				)
			);

		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload] AJAX: Error removing provider: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => 'Error deleting configuration: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX handler to import configuration from JSON
	 */
	public static function ajax_import_config(): void {
		// Check nonce
		check_ajax_referer( 'diluxone_offload_admin', 'nonce' );

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'diluxone-offload' ) ) );
		}

		try {
			// Get config JSON from request. The body is a JSON blob that
			// we json_decode + structurally validate below; passing it through
			// sanitize_text_field would corrupt the structure. Nonce was already
			// verified at the top of this AJAX handler.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload validated by json_decode below.
			$config_json = isset( $_POST['config'] ) ? wp_unslash( (string) $_POST['config'] ) : '';

			if ( empty( $config_json ) ) {
				wp_send_json_error( __( 'No configuration data provided', 'diluxone-offload' ) );
			}

			// Decode JSON
			$config_data = json_decode( $config_json, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				wp_send_json_error( __( 'Invalid JSON format: ', 'diluxone-offload' ) . json_last_error_msg() );
			}

			if ( empty( $config_data ) || ! is_array( $config_data ) ) {
				wp_send_json_error( __( 'Invalid configuration data', 'diluxone-offload' ) );
			}

			Logger::info( '[DiluxOne Offload] Importing configuration with ' . count( $config_data ) . ' options' );

			// Validate that we have diluxone_offload_ options
			$diluxone_offload_options_count = 0;
			foreach ( $config_data as $option_name => $option_value ) {
				if ( strpos( $option_name, 'diluxone_offload_' ) === 0 ) {
					++$diluxone_offload_options_count;
				}
			}

			if ( $diluxone_offload_options_count === 0 ) {
				wp_send_json_error( __( 'No valid DiluxOne Offload options found in import data', 'diluxone-offload' ) );
			}

			// Import each option
			$imported_count = 0;
			$errors         = array();

			foreach ( $config_data as $option_name => $option_value ) {
				// Only import diluxone_offload_ options
				if ( strpos( $option_name, 'diluxone_offload_' ) !== 0 ) {
					continue;
				}

				try {
					// update_option() automatically serializes arrays/objects
					// So we just pass the value directly - no manual serialization needed
					update_option( $option_name, $option_value );
					++$imported_count;

					Logger::info( "[DiluxOne Offload] Imported option: {$option_name}" );

				} catch ( \Exception $e ) {
					$errors[] = "Error importing {$option_name}: " . $e->getMessage();
					Logger::info( "[DiluxOne Offload] Error importing {$option_name}: " . $e->getMessage() );
				}
			}

			if ( $imported_count > 0 ) {
				$message = sprintf(
					/* translators: %d: number of imported configuration options */
					__( 'Successfully imported %d configuration options.', 'diluxone-offload' ),
					$imported_count
				);

				if ( ! empty( $errors ) ) {
					/* translators: %s: comma-separated list of error messages */
					$message .= ' ' . sprintf( __( 'Errors: %s', 'diluxone-offload' ), implode( ', ', $errors ) );
				}

				Logger::info( "[DiluxOne Offload] Configuration import completed: {$imported_count} options imported" );

				wp_send_json_success( $message );
			} else {
				wp_send_json_error( __( 'No options were imported', 'diluxone-offload' ) );
			}
		} catch ( \Exception $e ) {
			Logger::info( '[DiluxOne Offload] AJAX: Error importing configuration: ' . $e->getMessage() );
			wp_send_json_error( __( 'Error importing configuration: ', 'diluxone-offload' ) . $e->getMessage() );
		}
	}
}

// Initialize admin
Admin::init();
