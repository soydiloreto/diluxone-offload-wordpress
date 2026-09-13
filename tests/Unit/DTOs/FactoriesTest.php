<?php
namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\DTOs\PluginSettings;
use DiluxOneOffload\DTOs\ProviderConfig;
use DiluxOneOffload\DTOs\PluginConfig;
use DiluxOneOffload\DTOs\AzureConfig;
use DiluxOneOffload\DTOs\SyncProgress;
use DiluxOneOffload\DTOs\SyncResult;
use DiluxOneOffload\DTOs\SyncFilter;
use DiluxOneOffload\Enums\SyncStatus;

/**
 * Unit tests for the named constructors that build DTOs from outside data.
 *
 * fromPost() is the boundary where a form submission becomes a typed object,
 * so the checkbox semantics matter: an unchecked HTML checkbox sends nothing at
 * all, and reading it with ?? true would turn "off" into "on" on every save.
 *
 * Pure logic: WordPress functions come from tests/stubs/wordpress-stubs.php.
 */
class FactoriesTest extends TestCase {

	// ── PluginSettings::fromPost ────────────────────────────

	public function test_from_post_reads_a_checked_debug_box(): void {
		$s = PluginSettings::fromPost( array( 'enable_debug_logging' => '1' ) );
		$this->assertTrue( $s->isDebugEnabled() );
	}

	/** An unchecked checkbox is absent from $_POST, not present-and-false. */
	public function test_from_post_treats_a_missing_checkbox_as_off(): void {
		$s = PluginSettings::fromPost( array() );
		$this->assertFalse( $s->isDebugEnabled() );
		$this->assertFalse( $s->shouldForceHttpsOnCloud() );
	}

	public function test_from_post_converts_megabytes_to_bytes(): void {
		$s = PluginSettings::fromPost( array( 'max_file_size' => '5' ) );
		$this->assertSame( 5 * 1048576, $s->getMaxFileSize() );
		$this->assertSame( 5.0, $s->getMaxFileSizeMB() );
	}

	public function test_from_post_uses_defaults_when_fields_are_absent(): void {
		$s = PluginSettings::fromPost( array() );
		$this->assertSame( 60, $s->getTimeout() );
		$this->assertSame( '*', $s->getAllowedFileTypes() );
		$this->assertSame( 20 * 1048576, $s->getMaxFileSize() );
	}

	public function test_from_post_reads_the_timeout(): void {
		$this->assertSame( 120, PluginSettings::fromPost( array( 'timeout' => '120' ) )->getTimeout() );
	}

	public function test_from_post_reads_the_allowed_types(): void {
		$this->assertSame( 'jpg,png', PluginSettings::fromPost( array( 'allowed_file_types' => 'jpg,png' ) )->getAllowedFileTypes() );
	}

	// ── ProviderConfig::fromPost ────────────────────────────

	public function test_provider_from_post_requires_a_provider(): void {
		$this->expectException( \InvalidArgumentException::class );
		ProviderConfig::fromPost( array() );
	}

	public function test_azure_from_post_builds_the_config(): void {
		$p = ProviderConfig::fromPost(
			array(
				'cloud_provider' => 'azure',
				'account_name'   => 'acct',
				'account_key'    => 'key',
				'container_name' => 'cont',
			)
		);
		$this->assertSame( 'azure', $p->getCloudProvider() );
		$this->assertSame( 'acct', $p->getStorageAccount() );
		$this->assertSame( 'cont', $p->getContainerName() );
		$this->assertTrue( $p->isConfigured() );
	}

	public function test_azure_from_post_requires_the_storage_account(): void {
		$this->expectException( \InvalidArgumentException::class );
		ProviderConfig::fromPost(
			array(
				'cloud_provider' => 'azure',
				'account_key'    => 'key',
				'container_name' => 'cont',
			)
		);
	}

	public function test_azure_from_post_requires_the_access_key(): void {
		$this->expectException( \InvalidArgumentException::class );
		ProviderConfig::fromPost(
			array(
				'cloud_provider' => 'azure',
				'account_name'   => 'acct',
				'container_name' => 'cont',
			)
		);
	}

	public function test_azure_from_post_requires_the_container(): void {
		$this->expectException( \InvalidArgumentException::class );
		ProviderConfig::fromPost(
			array(
				'cloud_provider' => 'azure',
				'account_name'   => 'acct',
				'account_key'    => 'key',
			)
		);
	}

	public function test_azure_from_post_keeps_an_optional_custom_domain(): void {
		$p = ProviderConfig::fromPost(
			array(
				'cloud_provider' => 'azure',
				'account_name'   => 'acct',
				'account_key'    => 'key',
				'container_name' => 'cont',
				'custom_domain'  => 'cdn.example.com',
			)
		);
		$this->assertSame( 'cdn.example.com', $p->getCustomDomain() );
	}

	// ── SyncProgress::start / fromArray ─────────────────────

	public function test_start_begins_at_zero(): void {
		$p = SyncProgress::start( 50 );
		$this->assertSame( SyncStatus::STARTED, $p->getStatus() );
		$this->assertSame( 50, $p->getTotalFiles() );
		$this->assertSame( 0, $p->getProcessedFiles() );
		$this->assertSame( 0, $p->getSuccessfulUploads() );
		$this->assertSame( 0, $p->getFailedUploads() );
		$this->assertSame( array(), $p->getErrors() );
	}

	public function test_start_is_in_progress(): void {
		$this->assertTrue( SyncProgress::start( 10 )->isInProgress() );
	}

	public function test_start_stamps_the_times(): void {
		$p = SyncProgress::start( 10 );
		$this->assertGreaterThan( 0, $p->getStartTime() );
		$this->assertGreaterThan( 0, $p->getLastUpdate() );
	}

	public function test_progress_round_trips_through_array(): void {
		$a = new SyncProgress( SyncStatus::STARTED, 10, 4, 3, 1, 1700000000, 1700000100, array( 'e' ) );
		$b = SyncProgress::fromArray( $a->toArray() );
		$this->assertSame( $a->getStatus(), $b->getStatus() );
		$this->assertSame( $a->getTotalFiles(), $b->getTotalFiles() );
		$this->assertSame( $a->getProcessedFiles(), $b->getProcessedFiles() );
		$this->assertSame( $a->getSuccessfulUploads(), $b->getSuccessfulUploads() );
		$this->assertSame( $a->getFailedUploads(), $b->getFailedUploads() );
		$this->assertSame( $a->getErrors(), $b->getErrors() );
	}

	public function test_progress_to_array_has_the_persisted_keys(): void {
		$a = SyncProgress::start( 10 )->toArray();
		foreach ( array( 'status', 'total_files', 'processed_files' ) as $k ) {
			$this->assertArrayHasKey( $k, $a );
		}
	}

	// ── SyncResult factories ────────────────────────────────

	public function test_result_success_marks_the_rest_as_skipped(): void {
		$r = SyncResult::success( 10, 8, 30 );
		$this->assertTrue( $r->isSuccess() );
		$this->assertSame( SyncStatus::COMPLETED, $r->getStatus() );
		$this->assertSame( 8, $r->getSuccessfulUploads() );
		$this->assertSame( 0, $r->getFailedUploads() );
		$this->assertSame( 2, $r->getSkippedFiles() );
		$this->assertSame( 30, $r->getDuration() );
	}

	public function test_result_failure_records_the_error(): void {
		$r = SyncResult::failure( 'credentials rejected', 10, 2, 8, 15 );
		$this->assertTrue( $r->isFailure() );
		$this->assertSame( SyncStatus::FAILED, $r->getStatus() );
		$this->assertContains( 'credentials rejected', $r->getErrors() );
		$this->assertSame( 8, $r->getFailedUploads() );
	}

	public function test_result_from_a_clean_completed_progress_is_a_success(): void {
		$p = new SyncProgress( SyncStatus::COMPLETED, 10, 10, 10, 0 );
		$this->assertTrue( SyncResult::fromProgress( $p )->isSuccess() );
	}

	/** Completed with failures is not a success — the distinction drives the UI. */
	public function test_result_from_a_completed_progress_with_failures_is_not_a_success(): void {
		$p = new SyncProgress( SyncStatus::COMPLETED, 10, 10, 9, 1 );
		$this->assertFalse( SyncResult::fromProgress( $p )->isSuccess() );
	}

	public function test_result_from_an_unfinished_progress_is_not_a_success(): void {
		$p = new SyncProgress( SyncStatus::STARTED, 10, 5, 5, 0 );
		$this->assertFalse( SyncResult::fromProgress( $p )->isSuccess() );
	}

	public function test_result_from_progress_counts_unprocessed_as_skipped(): void {
		$p = new SyncProgress( SyncStatus::COMPLETED, 10, 7, 7, 0 );
		$this->assertSame( 3, SyncResult::fromProgress( $p )->getSkippedFiles() );
	}

	public function test_result_summary_is_not_empty(): void {
		$this->assertNotSame( '', SyncResult::success( 5, 5, 10 )->getSummary() );
		$this->assertNotSame( '', SyncResult::failure( 'boom' )->getSummary() );
	}

	public function test_result_statistics_pass_through(): void {
		$r = SyncResult::success( 1, 1, 0, array( 'bytes' => 123 ) );
		$this->assertSame( array( 'bytes' => 123 ), $r->getStatistics() );
	}

	public function test_result_to_array_has_the_reported_keys(): void {
		$a = SyncResult::success( 10, 10, 5 )->toArray();
		foreach ( array( 'success', 'status', 'total_files' ) as $k ) {
			$this->assertArrayHasKey( $k, $a );
		}
	}

	// ── Remaining accessors ─────────────────────────────────

	public function test_azure_accessors(): void {
		$c = new AzureConfig( 'acct', 'cont', 'key' );
		$this->assertSame( 'acct', $c->getStorageAccount() );
		$this->assertSame( 'cont', $c->getContainerName() );
		$this->assertSame( 'key', $c->getAccessKey() );
	}

	public function test_plugin_config_exposes_its_parts(): void {
		$provider = new ProviderConfig( 'azure', array( 'storage_account' => 'a' ) );
		$settings = new PluginSettings( false, false, false, true, 60, 1048576, '*' );
		$c        = new PluginConfig( $provider, $settings );

		$this->assertSame( $provider, $c->getProvider() );
		$this->assertSame( $settings, $c->getSettings() );
		$this->assertSame( array( 'storage_account' => 'a' ), $c->getProviderConfig() );
		$this->assertFalse( $c->shouldKeepLocalFiles() );
		$this->assertFalse( $c->shouldAutoActivateOffloading() );
		$this->assertSame( 1048576, $c->getMaxFileSize() );
	}

	public function test_sync_filter_reports_its_excluded_paths(): void {
		$f = new SyncFilter( '*', 0, array( 'a/', 'b/' ) );
		$this->assertSame( array( 'a/', 'b/' ), $f->getExcludedPaths() );
	}
}
