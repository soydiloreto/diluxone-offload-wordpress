<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use DiluxOneOffload\Plugin;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\DiluxOneOffloadDB;

/**
 * Integration tests for multisite behaviour.
 *
 * The readme promises "per-site or network-level configuration". These tests
 * are what stands behind that sentence: every site gets its own tracking
 * table, sites added after activation get one too, and one site's provider
 * config never leaks into another.
 *
 * They only run when the tests environment is a network; `make env-multisite`
 * converts it. On a single site they are skipped, not silently passed.
 */
class MultisiteTest extends IntegrationTestCase {

    /** @var int[] Sites created by a test, removed in tearDown. */
    private array $created_sites = [];

    protected function setUp(): void {
        if (!is_multisite()) {
            $this->markTestSkipped('Needs a multisite tests environment (make env-multisite).');
        }
        parent::setUp();
    }

    protected function tearDown(): void {
        foreach ($this->created_sites as $id) {
            wp_delete_site($id);
        }
        $this->created_sites = [];
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────

    private function tableExistsOn(int $site_id): bool {
        // get_table_name() reads $wpdb->prefix live, so switching blogs is
        // enough to make the DB layer look at that site's table.
        switch_to_blog($site_id);
        $found = DiluxOneOffloadDB::table_exists();
        restore_current_blog();
        return $found;
    }

    private function createSite(string $slug): int {
        $id = wp_insert_site([
            'domain' => (string) parse_url(network_home_url(), PHP_URL_HOST),
            'path'   => '/' . $slug . '/',
            'title'  => $slug,
        ]);
        $this->assertIsInt($id, 'site creation must succeed');
        $this->created_sites[] = $id;
        return $id;
    }

    // ── Tables per site ─────────────────────────────────────

    public function test_network_activation_creates_a_table_on_every_site(): void {
        $site = $this->createSite('ms-activation-' . uniqid());

        // Drop what wp_initialize_site created, so this asserts activation alone.
        global $wpdb;
        switch_to_blog($site);
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}diluxone_offload_files`");
        restore_current_blog();
        $this->assertFalse($this->tableExistsOn($site), 'precondition: table gone');

        Plugin::activate(true);

        $this->assertTrue($this->tableExistsOn(get_main_site_id()), 'main site');
        $this->assertTrue($this->tableExistsOn($site), 'secondary site');
    }

    public function test_single_site_activation_touches_only_the_current_site(): void {
        $site = $this->createSite('ms-single-' . uniqid());
        global $wpdb;
        switch_to_blog($site);
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}diluxone_offload_files`");
        restore_current_blog();

        Plugin::activate(false);

        $this->assertFalse($this->tableExistsOn($site), 'a non-network activation must not reach other sites');
    }

    public function test_a_site_created_after_activation_gets_its_table(): void {
        // wp_insert_site fires wp_initialize_site, which the plugin listens to.
        $site = $this->createSite('ms-new-' . uniqid());
        $this->assertTrue($this->tableExistsOn($site));
    }

    // ── Config isolation ────────────────────────────────────

    public function test_provider_config_does_not_leak_between_sites(): void {
        $site = $this->createSite('ms-config-' . uniqid());

        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'acct-main',
                'container_name'  => 'main',
                'access_key'      => base64_encode(random_bytes(32)),
            ],
        ]);
        $this->assertSame('azure', ConfigManager::get_config()['cloud_provider'], 'main site sees its config');

        switch_to_blog($site);
        $other = ConfigManager::get_config();
        $other_state = ConfigManager::get_state();
        restore_current_blog();

        $this->assertSame('', $other['cloud_provider'], 'the other site must start unconfigured');
        $this->assertSame('not_configured', $other_state);
    }

    public function test_tracking_rows_do_not_leak_between_sites(): void {
        $site = $this->createSite('ms-rows-' . uniqid());

        DiluxOneOffloadDB::add_file('2026/01/main-only.jpg', 100);
        $this->assertSame(1, (int) DiluxOneOffloadDB::get_stats()['total_files'], 'main site has its row');

        switch_to_blog($site);
        $count = (int) DiluxOneOffloadDB::get_stats()['total_files'];
        restore_current_blog();

        $this->assertSame(0, $count, 'the other site must not see rows from the main site');
    }
}
