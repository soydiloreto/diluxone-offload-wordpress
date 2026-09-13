<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\Admin;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB as DB;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * The admin_post form handlers: saving settings, saving provider
 * credentials, and the "remove configuration" reset. Each ends in wp_safe_redirect() + exit, so the test
 * hooks the `wp_redirect` filter and throws to capture the destination
 * before exit() can run.
 */
class AdminPostTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;
    private ?FakeCloudClient $fake = null;
    private int $admin_id = 0;
    private int $subscriber_id = 0;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8771);
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
        $this->admin_id = (int) wp_insert_user(['user_login' => 'post_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'administrator']);
        $this->subscriber_id = (int) wp_insert_user(['user_login' => 'sub_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(12), 'role' => 'subscriber']);
        wp_set_current_user($this->admin_id);
        add_filter('wp_redirect', [$this, 'captureRedirect']);
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        remove_filter('wp_redirect', [$this, 'captureRedirect']);
        if ($this->fake) {
            remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
            $this->fake = null;
        }
        wp_delete_user($this->admin_id);
        wp_delete_user($this->subscriber_id);
        wp_set_current_user(0);
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    public function captureRedirect(string $location) {
        throw new RedirectCaptured($location);
    }

    public function injectFake($pre) {
        return $this->fake;
    }

    private function useFakeClient(): FakeCloudClient {
        $this->fake = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        return $this->fake;
    }

    /** Runs a handler with a valid nonce and returns the parsed redirect query. */
    private function submit(callable $handler, string $nonce_action, array $post): array {
        $_POST = $post + ['_wpnonce' => wp_create_nonce($nonce_action)];
        $_REQUEST = $_POST;
        try {
            $handler();
        } catch (RedirectCaptured $e) {
            $query = [];
            parse_str((string) parse_url($e->location, PHP_URL_QUERY), $query);
            $this->assertStringStartsWith(admin_url('admin.php'), $e->location);
            return $query;
        }
        $this->fail('handler did not redirect');
    }

    // ── guards ──────────────────────────────────────────────

    public function test_both_handlers_refuse_a_bad_nonce(): void {
        foreach (['save_config', 'remove_provider_config'] as $h) {
            $_POST = ['_wpnonce' => 'nope'];
            try {
                Admin::$h();
                $this->fail("$h ran without a nonce");
            } catch (WPAjaxDieContinueException $e) {
                $this->assertStringContainsString('Security', $e->getMessage(), $h);
            }
        }
    }

    public function test_both_handlers_refuse_a_non_admin(): void {
        wp_set_current_user($this->subscriber_id);
        foreach (['save_config' => 'diluxone_offload_save_config', 'remove_provider_config' => 'diluxone_offload_remove_provider'] as $h => $action) {
            $_POST = ['_wpnonce' => wp_create_nonce($action)];
            try {
                Admin::$h();
                $this->fail("$h ran for a subscriber");
            } catch (WPAjaxDieContinueException $e) {
                $this->assertStringContainsString('permissions', $e->getMessage(), $h);
            }
        }
    }

    // ── save_config: settings tab ───────────────────────────

    public function test_settings_tab_saves_the_plugin_settings(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'redirect_tab'         => 'settings',
            'max_file_size'        => '256',
            'timeout'              => '45',
            'allowed_file_types'   => 'jpg, png',
            'keep_local_files'     => '1',
            'enable_debug_logging' => '1',
            'force_https_on_cloud' => '1',
        ]);
        $this->assertSame('settings', $q['tab']);
        $this->assertArrayHasKey('success', $q);
        $cfg = ConfigManager::get_config();
        $this->assertSame(256 * MB_IN_BYTES, (int) $cfg['max_file_size'], 'stored in bytes');
        $this->assertSame(45, (int) $cfg['timeout']);
        $this->assertTrue((bool) $cfg['debug_enabled']);
    }

    public function keepOldOption($value, $old) {
        return $old;
    }

    public function test_settings_tab_reports_a_refused_write(): void {
        add_filter('pre_update_option_diluxone_offload_config', [$this, 'keepOldOption'], 10, 2);
        try {
            $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['redirect_tab' => 'settings', 'timeout' => '77']);
        } finally {
            remove_filter('pre_update_option_diluxone_offload_config', [$this, 'keepOldOption'], 10);
        }
        $this->assertSame('settings', $q['tab']);
        $this->assertStringContainsString('Failed to save settings', $q['error']);
    }

    public function test_provider_tab_reports_a_refused_write(): void {
        $this->useFakeClient();
        add_filter('pre_update_option_diluxone_offload_config', [$this, 'keepOldOption'], 10, 2);
        try {
            $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
                'redirect_tab'   => 'cloud-provider',
                'cloud_provider' => 'azure',
                'account_name'   => 'refusedacct',
                'account_key'    => base64_encode(random_bytes(32)),
                'container_name' => 'media',
            ]);
        } finally {
            remove_filter('pre_update_option_diluxone_offload_config', [$this, 'keepOldOption'], 10);
        }
        $this->assertStringContainsString('Failed to save configuration', $q['error']);
    }

    // ── save_config: cloud-provider tab ─────────────────────

    public function test_provider_tab_saves_azure_credentials_and_moves_to_configured(): void {
        $this->useFakeClient();
        $key = base64_encode(random_bytes(32));
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'redirect_tab'   => 'cloud-provider',
            'cloud_provider' => 'azure',
            'account_name'   => 'posttestacct',
            'account_key'    => $key,
            'container_name' => 'media',
            'custom_domain'  => '',
        ]);
        $this->assertSame('cloud-provider', $q['tab']);
        $this->assertArrayHasKey('success', $q, print_r($q, true));
        $cfg = ConfigManager::get_config();
        $this->assertSame('azure', $cfg['cloud_provider']);
        $this->assertSame('posttestacct', $cfg['provider_config']['storage_account']);
        $this->assertSame($key, ConfigManager::get_current_provider_config()['access_key'], 'key round-trips through encryption');
        $this->assertNotSame($key, get_option('diluxone_offload_config')['provider_config']['access_key'], 'key is not stored in clear');
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    public function test_provider_tab_rejects_an_invalid_storage_account_name(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'redirect_tab'   => 'cloud-provider',
            'cloud_provider' => 'azure',
            'account_name'   => 'Has Spaces',
            'account_key'    => 'k',
            'container_name' => 'media',
        ]);
        $this->assertStringContainsString('3-24 lowercase', $q['error']);
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state());
    }

    public function test_provider_tab_rejects_missing_fields(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'redirect_tab'   => 'cloud-provider',
            'cloud_provider' => 'azure',
            'account_name'   => 'acct',
            'account_key'    => '',
            'container_name' => 'media',
        ]);
        $this->assertStringContainsString('Access Key is required', $q['error']);
    }

    public function test_provider_tab_saves_even_when_the_account_is_unreachable_and_health_records_it(): void {
        $this->useFakeClient()->connection_ok = false;
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', [
            'redirect_tab'   => 'cloud-provider',
            'cloud_provider' => 'azure',
            'account_name'   => 'unreachable',
            'account_key'    => base64_encode(random_bytes(32)),
            'container_name' => 'media',
        ]);
        $this->assertArrayHasKey('success', $q, 'credentials are stored; reachability is the health check\'s job');
        delete_option('diluxone_offload_connection_health');
        $this->assertSame('unhealthy', ConfigManager::check_connection_health()['status']);
    }

    public function test_provider_tab_without_credentials_just_returns_to_the_tab(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['redirect_tab' => 'cloud-provider']);
        $this->assertSame('cloud-provider', $q['tab']);
        $this->assertArrayNotHasKey('error', $q);
        $this->assertArrayNotHasKey('success', $q);
    }

    public function test_unknown_tab_is_an_invalid_save_request(): void {
        $q = $this->submit([Admin::class, 'save_config'], 'diluxone_offload_save_config', ['redirect_tab' => 'tools']);
        $this->assertSame('overview', $q['tab']);
        $this->assertStringContainsString('Invalid save request', $q['error']);
    }

    // ── remove_provider_config ──────────────────────────────

    public function test_remove_provider_wipes_options_and_the_file_table(): void {
        ConfigManager::save_config(['cloud_provider' => 'azure', 'provider_config' => ['storage_account' => 'gone', 'container_name' => 'media', 'access_key' => 'k']]);
        ConfigManager::set_state(PluginState::SYNCED);
        update_option('diluxone_offload_sync_meta', ['status' => 'completed']);
        update_option('diluxone_offload_connection_health', ['status' => 'healthy']);
        $this->addTestFiles(3);

        $q = $this->submit([Admin::class, 'remove_provider_config'], 'diluxone_offload_remove_provider', []);

        $this->assertSame('settings', $q['tab']);
        $this->assertArrayHasKey('success', $q);
        $this->assertFalse(get_option('diluxone_offload_config'));
        $this->assertFalse(get_option('diluxone_offload_sync_meta'));
        $this->assertFalse(get_option('diluxone_offload_connection_health'));
        $this->assertSame(PluginState::NOT_CONFIGURED, ConfigManager::get_state());
        $this->assertSame(0, DB::get_total_count());
    }
}

/** Thrown by the wp_redirect filter so the handler's exit() is never reached. */
class RedirectCaptured extends \Exception {
    public string $location;
    public function __construct(string $location) {
        parent::__construct('redirect to ' . $location);
        $this->location = $location;
    }
}
