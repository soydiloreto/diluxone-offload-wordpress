<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\ScriptedHttp;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * Admin AJAX handlers, second helping: the DiluxOne provider through "test
 * connection" / "save updated credentials", the reset that "cancel sync"
 * performs when nothing is running, and stats refresh for each provider.
 */
class AdminAjaxExtrasTest extends IntegrationTestCase {
    use ScriptedHttp;

    private const API = 'https://api.diluxone.com/cloud-storage-wp/v1';

    private static ?LocalBlobServer $server = null;
    private ?FakeCloudClient $fake = null;
    private int $admin_id = 0;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8774);
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
        $this->admin_id = (int) wp_insert_user(['user_login' => 'adx_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        $this->unhookHttp();
        if ($this->fake) {
            remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
            $this->fake = null;
        }
        delete_transient('diluxone_offload_connection_test_passed_' . $this->admin_id);
        delete_transient('diluxone_offload_stats');
        delete_transient('diluxone_offload_sas_token');
        wp_delete_user($this->admin_id);
        wp_set_current_user(0);
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    public function injectFake($pre) {
        return $this->fake;
    }

    private function call(string $action, array $post = []): array {
        $_POST = $post;
        $_POST['nonce'] = wp_create_nonce('diluxone_offload_admin');
        $_REQUEST = $_POST;
        ob_start();
        try {
            do_action('wp_ajax_' . $action);
        } catch (WPAjaxDieContinueException $e) {
        }
        $raw = (string) ob_get_clean();
        $json = json_decode($raw, true);
        return ['json' => is_array($json) ? $json : null, 'raw' => $raw];
    }

    private static function json(int $code, array $payload): array {
        return self::httpReply($code, (string) json_encode($payload));
    }

    private function configureDiluxOne(string $key = 'dlx_live_0123456789'): void {
        ConfigManager::save_config(['cloud_provider' => 'diluxone', 'provider_config' => ['api_key' => $key, 'cdn_base_url' => 'https://cdn.example.net/c']]);
        ConfigManager::set_state(PluginState::CONFIGURED);
    }

    // ── test_connection: DiluxOne ───────────────────────────

    public function test_diluxone_connection_needs_an_api_key(): void {
        $r = $this->call('diluxone_offload_test_connection', ['provider' => 'diluxone', 'api_key' => '']);
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('API Key', $r['json']['data']['message']);
    }

    public function test_diluxone_connection_success_is_remembered_by_key_prefix(): void {
        $this->scriptHttp(fn() => self::json(200, ['data' => ['plan' => 'pro', 'storageUsedBytes' => 0, 'storageLimitBytes' => 1]]));
        $r = $this->call('diluxone_offload_test_connection', ['provider' => 'diluxone', 'api_key' => 'dlx_live_0123456789abc']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $t = get_transient('diluxone_offload_connection_test_passed_' . $this->admin_id);
        $this->assertSame('diluxone', $t['provider']);
        $this->assertSame('dlx_live_012', $t['api_key_prefix']);
    }

    public function test_diluxone_connection_failure_is_reported(): void {
        $this->scriptHttp(fn() => self::json(401, ['error' => ['message' => 'invalid key']]));
        $r = $this->call('diluxone_offload_test_connection', ['provider' => 'diluxone', 'api_key' => 'dlx_live_bad']);
        $this->assertFalse($r['json']['success']);
        $this->assertSame('invalid key', $r['json']['data']['message']);
    }

    public function test_connection_test_accepts_the_form_nonce_too(): void {
        $_POST = ['_wpnonce' => wp_create_nonce('diluxone_offload_admin'), 'provider' => 'azure'];
        $_REQUEST = $_POST;
        ob_start();
        try {
            do_action('wp_ajax_diluxone_offload_test_connection');
        } catch (WPAjaxDieContinueException $e) {
        }
        $j = json_decode((string) ob_get_clean(), true);
        $this->assertStringContainsString('Missing required fields', $j['data']['message'], 'got past the nonce check');
    }

    public function test_connection_test_without_any_nonce_is_refused(): void {
        $_POST = ['provider' => 'azure'];
        $_REQUEST = $_POST;
        ob_start();
        try {
            do_action('wp_ajax_diluxone_offload_test_connection');
        } catch (WPAjaxDieContinueException $e) {
        }
        $j = json_decode((string) ob_get_clean(), true);
        $this->assertStringContainsString('Security', $j['data']['message']);
    }

    // ── save_updated_credentials ────────────────────────────

    public function test_saving_credentials_requires_a_prior_test(): void {
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'azure']);
        $this->assertStringContainsString('test the connection first', $r['json']['data']['message']);
    }

    public function test_saving_diluxone_credentials_that_differ_from_the_tested_ones_is_refused(): void {
        set_transient('diluxone_offload_connection_test_passed_' . $this->admin_id, ['provider' => 'diluxone', 'api_key_prefix' => 'dlx_live_012', 'timestamp' => time()], 300);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'diluxone', 'api_key' => 'dlx_live_OTHERKEY']);
        $this->assertStringContainsString('do not match', $r['json']['data']['message']);
    }

    public function test_saving_diluxone_credentials_keeps_the_known_cdn_url(): void {
        $this->configureDiluxOne('dlx_live_old');
        set_transient('diluxone_offload_connection_test_passed_' . $this->admin_id, ['provider' => 'diluxone', 'api_key_prefix' => 'dlx_live_012', 'timestamp' => time()], 300);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'diluxone', 'api_key' => 'dlx_live_0123456789new']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $cfg = ConfigManager::get_current_provider_config();
        $this->assertSame('dlx_live_0123456789new', $cfg['api_key']);
        $this->assertSame('https://cdn.example.net/c', $cfg['cdn_base_url']);
        $this->assertFalse(get_transient('diluxone_offload_connection_test_passed_' . $this->admin_id), 'the test token is spent');
    }

    public function test_saving_azure_credentials_that_differ_from_the_tested_ones_is_refused(): void {
        set_transient('diluxone_offload_connection_test_passed_' . $this->admin_id, ['provider' => 'azure', 'account_name' => 'tested', 'container_name' => 'media', 'timestamp' => time()], 300);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'azure', 'account_name' => 'other', 'account_key' => 'k', 'container_name' => 'media']);
        $this->assertStringContainsString('do not match', $r['json']['data']['message']);
    }

    public function test_saving_azure_credentials_keeps_the_custom_domain(): void {
        ConfigManager::save_config(['cloud_provider' => 'azure', 'provider_config' => ['storage_account' => 'tested', 'container_name' => 'media', 'access_key' => 'old', 'custom_domain' => 'https://cdn.example.net']]);
        set_transient('diluxone_offload_connection_test_passed_' . $this->admin_id, ['provider' => 'azure', 'account_name' => 'tested', 'container_name' => 'media', 'timestamp' => time()], 300);
        $key = base64_encode(random_bytes(32));
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'azure', 'account_name' => 'tested', 'account_key' => $key, 'container_name' => 'media']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $cfg = ConfigManager::get_current_provider_config();
        $this->assertSame($key, $cfg['access_key']);
        $this->assertSame('https://cdn.example.net', $cfg['custom_domain']);
    }

    public function test_saving_an_invalid_provider_config_is_an_error_not_a_fatal(): void {
        set_transient('diluxone_offload_connection_test_passed_' . $this->admin_id, ['provider' => 'azure', 'account_name' => '', 'container_name' => '', 'timestamp' => time()], 300);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'azure', 'account_name' => '', 'account_key' => '', 'container_name' => '']);
        $this->assertFalse($r['json']['success'], $r['raw']);
        $this->assertStringContainsString('not saved', $r['json']['data']['message']);
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state(), 'nothing was written');
    }

    public function test_saving_credentials_for_an_unknown_provider_is_an_error(): void {
        set_transient('diluxone_offload_connection_test_passed_' . $this->admin_id, ['provider' => 'azure', 'account_name' => 'tested', 'container_name' => 'media', 'timestamp' => time()], 300);
        $r = $this->call('diluxone_offload_save_updated_credentials', ['provider' => 'dropbox', 'account_name' => 'tested', 'account_key' => 'k', 'container_name' => 'media']);
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('not saved', $r['json']['data']['message']);
    }

    public function test_import_rejects_an_empty_object(): void {
        $r = $this->call('diluxone_offload_import_config', ['config' => '{}']);
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('Invalid configuration data', $r['json']['data']);
    }

    public function test_mark_sync_complete_without_a_sync_manager_says_so(): void {
        $plugin = \DiluxOneOffload\Plugin::get_instance();
        $prop   = new \ReflectionProperty($plugin, 'sync_manager');
        $prop->setAccessible(true);
        $prop->setValue($plugin, null);
        try {
            $r = $this->call('diluxone_offload_mark_sync_complete');
        } finally {
            $prop->setValue($plugin, new \DiluxOneOffload\SyncManager());
        }
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('not available', $r['json']['data']);
    }


    public function test_cancel_with_no_active_sync_resets_to_configured(): void {
        ConfigManager::set_state(PluginState::SYNCED);
        $this->addTestFiles(2);
        update_option('diluxone_offload_failed_files', ['/x.jpg'], false);
        $r = $this->call('diluxone_offload_cancel_sync', ['session_id' => 'me']);
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertStringContainsString('reset to configured', $r['json']['data']['message']);
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
        $this->assertSame(0, $this->getTableRowCount());
        $this->assertFalse(get_option('diluxone_offload_failed_files'));
    }

    // ── refresh_stats per provider ──────────────────────────

    public function test_refresh_stats_for_diluxone_calls_the_api(): void {
        $this->configureDiluxOne();
        $this->scriptHttp(fn() => self::json(200, ['data' => ['fileCount' => 7, 'storageUsedBytes' => 70]]));
        $r = $this->call('diluxone_offload_refresh_stats');
        $this->assertTrue($r['json']['success'], $r['raw']);
        $this->assertSame(7, $r['json']['data']['fileCount']);
        $this->assertSame('healthy', ConfigManager::get_connection_health()['status']);
    }

    public function test_refresh_stats_for_diluxone_reports_an_api_refusal(): void {
        $this->configureDiluxOne();
        $this->scriptHttp(fn() => self::json(503, ['error' => ['message' => 'maintenance']]));
        $r = $this->call('diluxone_offload_refresh_stats');
        $this->assertFalse($r['json']['success']);
        $this->assertSame('maintenance', $r['json']['data']['message']);
    }

    public function test_refresh_stats_with_an_unknown_provider_type_is_an_error(): void {
        $this->fake = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        $r = $this->call('diluxone_offload_refresh_stats');
        $this->assertFalse($r['json']['success']);
        $this->assertStringContainsString('Unknown provider', $r['json']['data']['message']);
    }
}
