<?php
/**
 * Sync operation result envelope.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

use DiluxOneOffload\Enums\SyncStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Result DTO
 *
 * Immutable value object for complete synchronization result
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class SyncResult {

	/** @var bool */
	private bool $success;
	/** @var string */
	private string $status;
	/** @var int */
	private int $totalFiles;
	/** @var int */
	private int $successfulUploads;
	/** @var int */
	private int $failedUploads;
	/** @var int */
	private int $skippedFiles;
	/** @var int */
	private int $duration;
	/**
	 * @var array<int, string>
	 */
	private array $errors;
	/**
	 * @var array<string, mixed>
	 */
	private array $statistics;

	/**
	 * Constructor
	 *
	 * @param bool                 $success
	 * @param string               $status
	 * @param int                  $totalFiles
	 * @param int                  $successfulUploads
	 * @param int                  $failedUploads
	 * @param int                  $skippedFiles
	 * @param int                  $duration
	 * @param array<int, string>   $errors
	 * @param array<string, mixed> $statistics
	 */
	public function __construct(
		bool $success,
		string $status,
		int $totalFiles,
		int $successfulUploads,
		int $failedUploads,
		int $skippedFiles = 0,
		int $duration = 0,
		array $errors = array(),
		array $statistics = array()
	) {
		$this->success           = $success;
		$this->status            = $status;
		$this->totalFiles        = $totalFiles;
		$this->successfulUploads = $successfulUploads;
		$this->failedUploads     = $failedUploads;
		$this->skippedFiles      = $skippedFiles;
		$this->duration          = $duration;
		$this->errors            = $errors;
		$this->statistics        = $statistics;
	}

	/**
	 * Create successful result
	 *
	 * @param int                  $totalFiles
	 * @param int                  $successfulUploads
	 * @param int                  $duration
	 * @param array<string, mixed> $statistics
	 * @return self
	 */
	public static function success( int $totalFiles, int $successfulUploads, int $duration = 0, array $statistics = array() ): self {
		return new self(
			true,
			SyncStatus::COMPLETED,
			$totalFiles,
			$successfulUploads,
			0,
			$totalFiles - $successfulUploads,  // skipped
			$duration,
			array(),
			$statistics
		);
	}

	/**
	 * Create failed result
	 *
	 * @param string $error
	 * @param int    $totalFiles
	 * @param int    $successfulUploads
	 * @param int    $failedUploads
	 * @param int    $duration
	 * @return self
	 */
	public static function failure( string $error, int $totalFiles = 0, int $successfulUploads = 0, int $failedUploads = 0, int $duration = 0 ): self {
		return new self(
			false,
			SyncStatus::FAILED,
			$totalFiles,
			$successfulUploads,
			$failedUploads,
			0,
			$duration,
			array( $error ),
			array()
		);
	}

	/**
	 * Create from SyncProgress
	 *
	 * @param SyncProgress $progress
	 * @return self
	 */
	public static function fromProgress( SyncProgress $progress ): self {
		$success = $progress->isCompleted() && $progress->getFailedUploads() === 0;
		$skipped = $progress->getTotalFiles() - $progress->getProcessedFiles();

		return new self(
			$success,
			$progress->getStatus(),
			$progress->getTotalFiles(),
			$progress->getSuccessfulUploads(),
			$progress->getFailedUploads(),
			$skipped,
			$progress->getElapsedTime(),
			$progress->getErrors(),
			array()
		);
	}

	/**
	 * Check if sync was successful
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * Check if sync failed
	 *
	 * @return bool
	 */
	public function isFailure(): bool {
		return ! $this->success;
	}

	/**
	 * Get status
	 *
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}

	/**
	 * Get total files
	 *
	 * @return int
	 */
	public function getTotalFiles(): int {
		return $this->totalFiles;
	}

	/**
	 * Get successful uploads
	 *
	 * @return int
	 */
	public function getSuccessfulUploads(): int {
		return $this->successfulUploads;
	}

	/**
	 * Get failed uploads
	 *
	 * @return int
	 */
	public function getFailedUploads(): int {
		return $this->failedUploads;
	}

	/**
	 * Get skipped files
	 *
	 * @return int
	 */
	public function getSkippedFiles(): int {
		return $this->skippedFiles;
	}

	/**
	 * Get duration in seconds
	 *
	 * @return int
	 */
	public function getDuration(): int {
		return $this->duration;
	}

	/**
	 * Get errors
	 *
	 * @return array<int, string>
	 */
	public function getErrors(): array {
		return $this->errors;
	}

	/**
	 * Get statistics
	 *
	 * @return array<string, mixed>
	 */
	public function getStatistics(): array {
		return $this->statistics;
	}

	/**
	 * Get success rate percentage
	 *
	 * @return float
	 */
	public function getSuccessRate(): float {
		if ( $this->totalFiles === 0 ) {
			return 0.0;
		}
		return ( $this->successfulUploads / $this->totalFiles ) * 100;
	}

	/**
	 * Get formatted duration
	 *
	 * @return string
	 */
	public function getFormattedDuration(): string {
		if ( $this->duration < 60 ) {
			return $this->duration . ' seconds';
		} elseif ( $this->duration < 3600 ) {
			return round( $this->duration / 60, 1 ) . ' minutes';
		} else {
			return round( $this->duration / 3600, 1 ) . ' hours';
		}
	}

	/**
	 * Get summary message
	 *
	 * @return string
	 */
	public function getSummary(): string {
		if ( $this->success ) {
			return sprintf(
				'Sync completed successfully: %d/%d files uploaded in %s',
				$this->successfulUploads,
				$this->totalFiles,
				$this->getFormattedDuration()
			);
		} else {
			return sprintf(
				'Sync failed: %d successful, %d failed out of %d total files',
				$this->successfulUploads,
				$this->failedUploads,
				$this->totalFiles
			);
		}
	}

	/**
	 * Convert to array
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'success'            => $this->success,
			'status'             => $this->status,
			'total_files'        => $this->totalFiles,
			'successful_uploads' => $this->successfulUploads,
			'failed_uploads'     => $this->failedUploads,
			'skipped_files'      => $this->skippedFiles,
			'duration'           => $this->duration,
			'errors'             => $this->errors,
			'statistics'         => $this->statistics,
			// Calculated fields
			'success_rate'       => $this->getSuccessRate(),
			'formatted_duration' => $this->getFormattedDuration(),
			'summary'            => $this->getSummary(),
		);
	}
}
