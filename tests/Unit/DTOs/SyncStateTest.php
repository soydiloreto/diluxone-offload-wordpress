<?php
namespace Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\DTOs\SyncProgress;
use DiluxOneOffload\DTOs\SyncResult;
use DiluxOneOffload\Enums\SyncStatus;

/**
 * Unit tests for the sync state objects.
 *
 * SyncProgress is immutable: every transition returns a new instance. That is
 * the property worth protecting, because the sync loop calls these inside a
 * batch and a mutation leaking into the caller would corrupt a run that can
 * take hours and is resumed from persisted state.
 *
 * Pure logic: no WordPress runtime needed.
 */
class SyncStateTest extends TestCase {

	// ── SyncProgress: percentage ────────────────────────────

	public function test_percentage_is_zero_with_no_files(): void {
		$this->assertSame( 0.0, ( new SyncProgress() )->getPercentage() );
	}

	/** Guards against a division by zero when a sync starts before scanning. */
	public function test_percentage_with_zero_total_does_not_divide_by_zero(): void {
		$p = new SyncProgress( SyncStatus::STARTED, 0, 5 );
		$this->assertSame( 0.0, $p->getPercentage() );
	}

	public function test_percentage_halfway(): void {
		$p = new SyncProgress( SyncStatus::STARTED, 10, 5 );
		$this->assertSame( 50.0, $p->getPercentage() );
	}

	public function test_percentage_complete(): void {
		$p = new SyncProgress( SyncStatus::STARTED, 10, 10 );
		$this->assertSame( 100.0, $p->getPercentage() );
	}

	// ── SyncProgress: status ────────────────────────────────

	public function test_invalid_status_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new SyncProgress( 'not-a-status' );
	}

	public function test_every_valid_status_is_accepted(): void {
		foreach ( array( SyncStatus::IDLE, SyncStatus::STARTED, SyncStatus::PAUSED, SyncStatus::COMPLETED, SyncStatus::FAILED, SyncStatus::CANCELLED ) as $s ) {
			$this->assertSame( $s, ( new SyncProgress( $s ) )->getStatus() );
		}
	}

	public function test_completed_predicate(): void {
		$this->assertTrue( ( new SyncProgress( SyncStatus::COMPLETED ) )->isCompleted() );
		$this->assertFalse( ( new SyncProgress( SyncStatus::STARTED ) )->isCompleted() );
	}

	public function test_paused_predicate(): void {
		$this->assertTrue( ( new SyncProgress( SyncStatus::PAUSED ) )->isPaused() );
		$this->assertFalse( ( new SyncProgress( SyncStatus::IDLE ) )->isPaused() );
	}

	public function test_failed_predicate(): void {
		$this->assertTrue( ( new SyncProgress( SyncStatus::FAILED ) )->hasFailed() );
		$this->assertFalse( ( new SyncProgress( SyncStatus::COMPLETED ) )->hasFailed() );
	}

	// ── SyncProgress: immutability ──────────────────────────

	public function test_with_status_returns_a_new_instance(): void {
		$a = new SyncProgress( SyncStatus::STARTED, 10, 3 );
		$b = $a->withStatus( SyncStatus::PAUSED );
		$this->assertNotSame( $a, $b );
		$this->assertSame( SyncStatus::STARTED, $a->getStatus(), 'the original must not change' );
		$this->assertSame( SyncStatus::PAUSED, $b->getStatus() );
	}

	public function test_with_status_keeps_the_counters(): void {
		$a = new SyncProgress( SyncStatus::STARTED, 10, 3, 2, 1 );
		$b = $a->withStatus( SyncStatus::PAUSED );
		$this->assertSame( 10, $b->getTotalFiles() );
		$this->assertSame( 3, $b->getProcessedFiles() );
		$this->assertSame( 2, $b->getSuccessfulUploads() );
		$this->assertSame( 1, $b->getFailedUploads() );
	}

	public function test_increment_processed_on_success(): void {
		$a = new SyncProgress( SyncStatus::STARTED, 10, 0, 0, 0 );
		$b = $a->incrementProcessed( true );
		$this->assertSame( 1, $b->getProcessedFiles() );
		$this->assertSame( 1, $b->getSuccessfulUploads() );
		$this->assertSame( 0, $b->getFailedUploads() );
		$this->assertSame( 0, $a->getProcessedFiles(), 'the original must not change' );
	}

	public function test_increment_processed_on_failure(): void {
		$b = ( new SyncProgress( SyncStatus::STARTED, 10 ) )->incrementProcessed( false, 'upload failed' );
		$this->assertSame( 1, $b->getProcessedFiles() );
		$this->assertSame( 0, $b->getSuccessfulUploads() );
		$this->assertSame( 1, $b->getFailedUploads() );
		$this->assertContains( 'upload failed', $b->getErrors() );
	}

	public function test_a_successful_increment_records_no_error(): void {
		$b = ( new SyncProgress( SyncStatus::STARTED, 10 ) )->incrementProcessed( true, 'ignored' );
		$this->assertSame( array(), $b->getErrors() );
	}

	public function test_a_failure_without_a_message_records_no_error(): void {
		$b = ( new SyncProgress( SyncStatus::STARTED, 10 ) )->incrementProcessed( false );
		$this->assertSame( array(), $b->getErrors() );
		$this->assertSame( 1, $b->getFailedUploads() );
	}

	public function test_errors_accumulate_across_increments(): void {
		$p = ( new SyncProgress( SyncStatus::STARTED, 10 ) )
			->incrementProcessed( false, 'first' )
			->incrementProcessed( false, 'second' );
		$this->assertSame( array( 'first', 'second' ), $p->getErrors() );
		$this->assertSame( 2, $p->getProcessedFiles() );
	}

	// ── SyncProgress: time estimates ────────────────────────

	public function test_remaining_time_is_unknown_before_any_file_is_processed(): void {
		$this->assertNull( ( new SyncProgress( SyncStatus::STARTED, 100, 0 ) )->getEstimatedRemainingTime() );
	}

	public function test_remaining_time_is_unknown_with_no_total(): void {
		$this->assertNull( ( new SyncProgress( SyncStatus::STARTED, 0, 5 ) )->getEstimatedRemainingTime() );
	}

	public function test_remaining_time_is_a_number_once_work_has_started(): void {
		// startTime 60s ago, 10 of 100 done -> roughly 6s/file, 540s left.
		$p = new SyncProgress( SyncStatus::STARTED, 100, 10, 10, 0, time() - 60 );
		$this->assertIsInt( $p->getEstimatedRemainingTime() );
		$this->assertGreaterThan( 0, $p->getEstimatedRemainingTime() );
	}

	public function test_elapsed_time_is_not_negative(): void {
		$this->assertGreaterThanOrEqual( 0, ( new SyncProgress() )->getElapsedTime() );
	}

	// ── SyncResult ──────────────────────────────────────────

	public function test_success_rate_is_zero_with_no_files(): void {
		$r = new SyncResult( true, SyncStatus::COMPLETED, 0, 0, 0 );
		$this->assertSame( 0.0, $r->getSuccessRate() );
	}

	public function test_success_rate_all_uploaded(): void {
		$r = new SyncResult( true, SyncStatus::COMPLETED, 10, 10, 0 );
		$this->assertSame( 100.0, $r->getSuccessRate() );
	}

	public function test_success_rate_partial(): void {
		$r = new SyncResult( true, SyncStatus::COMPLETED, 10, 7, 3 );
		$this->assertSame( 70.0, $r->getSuccessRate() );
	}

	public function test_duration_under_a_minute_reads_in_seconds(): void {
		$r = new SyncResult( true, SyncStatus::COMPLETED, 1, 1, 0, 0, 45 );
		$this->assertStringContainsString( 'second', $r->getFormattedDuration() );
	}

	public function test_duration_under_an_hour_reads_in_minutes(): void {
		$r = new SyncResult( true, SyncStatus::COMPLETED, 1, 1, 0, 0, 600 );
		$this->assertStringContainsString( 'minute', $r->getFormattedDuration() );
	}

	public function test_long_duration_reads_in_hours(): void {
		$r = new SyncResult( true, SyncStatus::COMPLETED, 1, 1, 0, 0, 7200 );
		$this->assertStringContainsString( 'hour', $r->getFormattedDuration() );
	}

	public function test_failure_predicates_are_opposites(): void {
		$r = new SyncResult( false, SyncStatus::FAILED, 5, 0, 5 );
		$this->assertTrue( $r->isFailure() );
		$this->assertFalse( $r->isSuccess() );
	}

	public function test_counters_are_reported_back(): void {
		$r = new SyncResult( true, SyncStatus::COMPLETED, 10, 7, 2, 1, 30, array( 'e' ) );
		$this->assertSame( 10, $r->getTotalFiles() );
		$this->assertSame( 7, $r->getSuccessfulUploads() );
		$this->assertSame( 2, $r->getFailedUploads() );
		$this->assertSame( 1, $r->getSkippedFiles() );
		$this->assertSame( 30, $r->getDuration() );
		$this->assertSame( array( 'e' ), $r->getErrors() );
	}

	// ── SyncStatus ──────────────────────────────────────────

	public function test_status_validity(): void {
		$this->assertTrue( SyncStatus::isValid( SyncStatus::COMPLETED ) );
		$this->assertFalse( SyncStatus::isValid( 'nonsense' ) );
		$this->assertFalse( SyncStatus::isValid( '' ) );
	}

	public function test_in_progress_only_for_running_states(): void {
		$this->assertTrue( SyncStatus::isInProgress( SyncStatus::STARTED ) );
		$this->assertFalse( SyncStatus::isInProgress( SyncStatus::COMPLETED ) );
		$this->assertFalse( SyncStatus::isInProgress( SyncStatus::IDLE ) );
	}
}
