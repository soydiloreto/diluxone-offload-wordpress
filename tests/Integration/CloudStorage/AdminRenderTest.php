<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\Admin;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * Integration tests for the Admin class: hooks, tab routing, asset
 * enqueuing and — above all — rendering every tab in every plugin state,
 * including each flavour of the connection-health banner.
 *
 * Rendering is asserted on structure (the wrapper, the tab, the banner) and
 * on the absence of PHP notices in the output, because that is what a user
 * sees: a page, or a page with a warning splattered across it.
 */
class AdminRenderTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;
    private ?FakeCloudClient $fake = null;
    private int $admin_id = 0;

    private const TABS = ['overview', 'cloud-provider', 'sync-offloading', 'settings', 'status', 'tools', 'activity'];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8768);
    }

    public static function tearDownAfterClass(): void {
        if (self::$server) {
            self::$server->stop();
            self::$server = null;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        parent::setUp();
        $id = wp_insert_user([
            'user_login' => 'render_' . wp_generate_password(8, false),
            'user_pass'  => wp_generate_password(12),
            'role'       => 'administrator',
        ]);
        $this->admin_id = (int) $id;
        wp_set_current_user($this->admin_id);
        if (!class_exists('WP_Screen')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        set_current_screen('toplevel_page_diluxone-offload');
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        if ($this->fake) {
            remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
            $this->fake = null;
        }
        delete_transient('diluxone_offload_azure_stats');
        if ($this->admin_id) {
            wp_delete_user($this->admin_id);
        }
        wp_set_current_user(0);
        $_GET = [];
        parent::tearDown();
    }

    public function injectFake($pre) {
        return $this->fake;
    }

    private function useFakeClient(): FakeCloudClient {
        $this->fake = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        return $this->fake;
    }

    private function configure(string $state = PluginState::CONFIGURED): void {
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'renderacct',
                'container_name'  => 'media',
                'access_key'      => base64_encode(random_bytes(32)),
            ],
        ]);
        ConfigManager::set_state($state);
    }

    /**
     * Renders a tab and fails on any PHP warning/notice/deprecation raised
     * while doing so — an undefined array key in a template is a bug even
     * when display_errors is off.
     */
    private function render(string $tab): string {
        $_GET['tab'] = $tab;
        $GLOBALS['wp_scripts'] = null;
        $GLOBALS['wp_styles'] = null;
        $problems = [];
        set_error_handler(function (int $no, string $str, string $file, int $line) use (&$problems): bool {
            if (strpos($file, '/diluxone-offload') !== false) {
                $problems[] = "$str in $file:$line";
            }
            return true;
        });
        ob_start();
        try {
            Admin::enqueue_admin_assets('toplevel_page_diluxone-offload');
            Admin::render_admin_page();
        } finally {
            $html = (string) ob_get_clean();
            restore_error_handler();
        }
        $this->assertSame([], $problems, "PHP notices while rendering tab $tab");
        $this->assertStringContainsString('diluxone-offload-admin', $html, "wrapper missing in tab $tab");
        return $html;
    }

    // ── Identity and routing ────────────────────────────────

    public function test_plugin_name_and_version(): void {
        $this->assertSame('DiluxOne Offload', Admin::plugin_name());
        $this->assertSame('1.0.0', Admin::get_plugin_version());
    }

    public function test_init_registers_the_menu_and_the_post_handlers(): void {
        Admin::init();
        $this->assertNotFalse(has_action('admin_menu', [Admin::class, 'add_admin_menu']));
        $this->assertNotFalse(has_action('admin_post_diluxone_offload_save_config', [Admin::class, 'save_config']));
        $this->assertNotFalse(has_action('admin_post_diluxone_offload_remove_provider', [Admin::class, 'remove_provider_config']));
        $this->assertNotFalse(has_filter('admin_title', [Admin::class, 'admin_title']));
    }

    public function test_admin_title_swaps_the_menu_label_for_the_plugin_name(): void {
        $this->assertStringContainsString('DiluxOne Offload', Admin::admin_title('Foo ‹ Site — WordPress', 'Foo'));
        $this->assertSame('Bar ‹ Site', Admin::admin_title('Bar ‹ Site', 'Other'));
        set_current_screen('dashboard');
        $this->assertSame('Dashboard ‹ Site', Admin::admin_title('Dashboard ‹ Site', 'Dashboard'), 'other screens are left alone');
    }

    public function test_sanitize_keeps_non_string_scalars_as_they_are(): void {
        $clean = Admin::sanitize_settings_option(['some_flag' => 5, 'provider_config' => ['retries' => 3]]);
        $this->assertSame(5, $clean['some_flag']);
        $this->assertEquals(3, $clean['provider_config']['retries']);
    }

    public function test_tabs_and_aliases(): void {
        $tabs = Admin::tabs();
        foreach (['overview', 'cloud-provider', 'sync-offloading', 'settings', 'status', 'tools', 'activity'] as $t) {
            $this->assertArrayHasKey($t, $tabs);
        }
        $this->assertSame('sync-offloading', Admin::current_tab('sync'), 'legacy alias');
        $this->assertSame('status', Admin::current_tab('status-tools'), 'legacy alias');
        $this->assertSame('overview', Admin::current_tab('nope'), 'unknown falls back');
    }

    public function test_add_admin_menu_registers_the_top_level_page(): void {
        global $menu;
        $menu = [];
        Admin::add_admin_menu();
        $found = false;
        foreach ((array) $menu as $item) {
            if (isset($item[2]) && $item[2] === 'diluxone-offload') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'menu slug diluxone-offload registered');
    }

    public function test_register_settings_declares_the_config_options(): void {
        Admin::register_settings();
        $registered = get_registered_settings();
        $this->assertArrayHasKey('diluxone_offload_cloud_storage_config', $registered);
        $this->assertSame([Admin::class, 'sanitize_settings_option'], $registered['diluxone_offload_cloud_storage_config']['sanitize_callback']);
    }

    public function test_sanitize_settings_option_shape(): void {
        $clean = Admin::sanitize_settings_option([
            'cloud_provider'  => 'AZURE<script>',
            'provider_config' => ['access_key' => ' KEY==%20 ', 'custom_domain' => 'https://cdn.example.com/x', 'storage_account' => '<b>acct</b>'],
            'debug_enabled'   => '1',
            'timeout'         => '-5',
            'unknown'         => ['nested' => '<i>x</i>'],
        ]);
        $this->assertSame('azurescript', $clean['cloud_provider']);
        $this->assertSame('KEY==%20', $clean['provider_config']['access_key'], 'credentials are only trimmed');
        $this->assertSame('https://cdn.example.com/x', $clean['provider_config']['custom_domain']);
        $this->assertSame('acct', $clean['provider_config']['storage_account']);
        $this->assertTrue($clean['debug_enabled']);
        $this->assertSame(5, $clean['timeout']);
        $this->assertSame('x', $clean['unknown']['nested']);
        $this->assertSame([], Admin::sanitize_settings_option('not an array'));
    }

    // ── Rendering: every tab, every state ───────────────────

    public function test_all_tabs_render_when_not_configured(): void {
        foreach (self::TABS as $tab) {
            $html = $this->render($tab);
            $this->assertNotSame('', $html);
        }
    }

    public function test_all_tabs_render_when_configured(): void {
        $this->configure(PluginState::CONFIGURED);
        $this->useFakeClient();
        foreach (self::TABS as $tab) {
            $this->render($tab);
        }
    }

    public function test_all_tabs_render_when_synced(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        $this->addTestFiles(3);
        foreach (self::TABS as $tab) {
            $this->render($tab);
        }
    }

    public function test_all_tabs_render_when_offloading_is_active(): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->useFakeClient();
        $this->addTestFiles(2);
        \DiluxOneOffload\DiluxOneOffloadDB::mark_synced('/2024/01/test-file-1.jpg');
        foreach (self::TABS as $tab) {
            $this->render($tab);
        }
    }

    public function test_overview_uses_cached_stats_when_present(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        set_transient('diluxone_offload_azure_stats', ['fileCount' => 42, 'storageUsedBytes' => 1024, 'storageLimitBytes' => null, 'plan' => null, 'bandwidthUsedBytes' => null, 'storageCheckedAt' => gmdate('c'), 'quotaExceeded' => false, 'filesByType' => ['images' => 40, 'videos' => 0, 'audio' => 0, 'other' => 2]], 300);
        $html = $this->render('overview');
        $this->assertStringContainsString('42', $html);
        $this->assertStringNotContainsString('diluxone-offload-stats-wrap diluxone-offload-loading', $html, 'warm cache: not in loading state');
        $this->assertMatchesRegularExpression('/id="stats-loading"[^>]*display: none/', $html, 'warm cache: overlay hidden');
    }

    public function test_overview_paints_the_loading_overlay_on_a_cold_cache(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        delete_transient('diluxone_offload_azure_stats');
        $html = $this->render('overview');
        $this->assertStringContainsString('diluxone-offload-stats-wrap diluxone-offload-loading', $html);
        $this->assertDoesNotMatchRegularExpression('/id="stats-loading"[^>]*display: none/', $html, 'cold cache: overlay visible');
    }

    public function test_sync_tab_explains_unreadable_credentials(): void {
        $this->configure(PluginState::CONFIGURED);
        // Corrupt the stored key so decryption fails, then the health records it.
        $cfg = get_option('diluxone_offload_config');
        $cfg['provider_config']['access_key'] = 'DILUXONEOFFLOADENC1:not-base64!!';
        update_option('diluxone_offload_config', $cfg);
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => 'decrypt_failed', 'error_message' => 'x', 'consecutive_failures' => 1, 'last_check' => time(), 'last_success' => 0, 'error_source' => 'crypto']);
        $html = $this->render('sync-offloading');
        $this->assertStringContainsString('Unreadable', $html);
    }

    /** @dataProvider healthErrors */
    public function test_health_banner_for_each_error_code(string $code, string $expect): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => $code, 'error_message' => 'boom', 'consecutive_failures' => 3, 'last_check' => time(), 'last_success' => 0, 'error_source' => 'azure']);
        $html = $this->render('overview');
        $this->assertStringContainsString($expect, strtolower($html));
        $this->assertNotSame('', Admin::pause_reason_short($code));
    }

    /** @return array<string, array{string,string}> */
    public function healthErrors(): array {
        return [
            'decrypt' => ['decrypt_failed', 'credential'],
            '401'     => ['401', 'permission'],
            '403'     => ['403', 'permission'],
            '404'     => ['404', 'not found'],
            'network' => ['exception', 'connect'],
            'other'   => ['500', 'paused'],
        ];
    }

    /** @dataProvider lastSuccessAges */
    public function test_health_banner_says_when_the_cloud_last_answered(int $age, string $expect): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->useFakeClient();
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => '403', 'error_message' => 'x', 'consecutive_failures' => 3, 'last_check' => time(), 'last_success' => time() - $age, 'error_source' => 'azure']);
        $html = $this->render('overview');
        if ($expect === '') {
            $this->assertStringNotContainsString('Last successful connection', $html);
        } else {
            $this->assertStringContainsString($expect, $html);
        }
        $this->assertStringContainsString('falling back to local storage', $html, 'offloading is on: uploads fall back');
    }

    /** @return array<string, array{int,string}> */
    public function lastSuccessAges(): array {
        return [
            'just now' => [60, ''],
            'minutes'  => [600, '10 minutes ago'],
            'hours'    => [7200, '2 hours ago'],
            'days'     => [3 * 86400, '3 days ago'],
        ];
    }

    public function test_sync_tab_resets_a_sync_whose_tab_went_away(): void {
        $this->configure(PluginState::SYNCING);
        $this->useFakeClient();
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 'gone', 'last_heartbeat' => time() - 600], false);
        $this->render('sync-offloading');
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state(), 'stale syncing state is healed on render');
    }

    public function test_sync_tab_keeps_a_live_sync(): void {
        $this->configure(PluginState::SYNCING);
        $this->useFakeClient();
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 'live', 'last_heartbeat' => time()], false);
        $this->render('sync');
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state());
    }

    public function test_pause_reason_short_has_a_fallback(): void {
        $this->assertNotSame('', Admin::pause_reason_short('something-new'));
    }

    // ── Assets ──────────────────────────────────────────────

    public function test_assets_are_enqueued_only_on_our_page(): void {
        $GLOBALS['wp_scripts'] = null;
        Admin::enqueue_admin_assets('edit.php');
        $this->assertFalse(wp_script_is('diluxone-offload-admin', 'enqueued'));
        Admin::enqueue_admin_assets('toplevel_page_diluxone-offload');
        $this->assertTrue(wp_script_is('diluxone-offload-admin', 'enqueued'));
        $this->assertTrue(wp_style_is('diluxone-offload-admin', 'enqueued'));
    }

    public function test_each_tab_gets_its_own_script_and_localized_strings(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        $with_js = ['overview' => 'DiluxOneOffloadOverview', 'cloud-provider' => 'DiluxOneOffloadProvider', 'sync-offloading' => 'DiluxOneOffloadSync', 'status' => 'DiluxOneOffloadStatus', 'tools' => 'DiluxOneOffloadStatus', 'activity' => 'DiluxOneOffloadActivity'];
        foreach ($with_js as $tab => $object) {
            $this->render($tab);
            $handles = array_filter(wp_scripts()->queue, fn($h) => strpos($h, 'diluxone-offload-admin-') === 0);
            $this->assertCount(1, $handles, "tab $tab enqueues exactly one tab script");
            $data = wp_scripts()->get_data(reset($handles), 'data');
            $this->assertStringContainsString($object, (string) $data, "tab $tab localizes $object");
        }
        $this->render('settings');
        $this->assertCount(0, array_filter(wp_scripts()->queue, fn($h) => strpos($h, 'diluxone-offload-admin-') === 0), 'settings has css only');
    }
}
