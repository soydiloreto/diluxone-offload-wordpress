<?php
/**
 * Sync progress snapshot value object.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

use DiluxOneOffload\Enums\SyncStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Progress DTO
 *
 * Immutable value object for synchronization progress tracking
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class SyncProgress {

	/** @var string */
	private string $status;
	/** @var int */
	private int $totalFiles;
	/** @var int */
	private int $processedFiles;
	/** @var int */
	private int $successfulUploads;
	/** @var int */
	private int $failedUploads;
	/** @var int */
	private int $startTime;
	/** @var int */
	private int $lastUpdate;
	/**
	 * @var array<int, string>
	 */
	private array $errors;

	/**
	 * Constructor
	 *
	 * @param string             $status
	 * @param int                $totalFiles
	 * @param int                $processedFiles
	 * @param int                $successfulUploads
	 * @param int                $failedUploads
	 * @param int                $startTime
	 * @param int                $lastUpdate
	 * @param array<int, string> $errors
	 *
	 * @throws \InvalidArgumentException When status is not a valid SyncStatus value or counts are negative.
	 */
	public function __construct(
		string $status = SyncStatus::IDLE,
		int $totalFiles = 0,
		int $processedFiles = 0,
		int $successfulUploads = 0,
		int $failedUploads = 0,
		int $startTime = 0,
		int $lastUpdate = 0,
		array $errors = array()
	) {
		if ( ! SyncStatus::isValid( $status ) ) {
			throw new \InvalidArgumentException( 'Invalid sync status: ' . esc_html( $status ) );
		}

		$this->status            = $status;
		$this->totalFiles        = $totalFiles;
		$this->processedFiles    = $processedFiles;
		$this->successfulUploads = $successfulUploads;
		$this->failedUploads     = $failedUploads;
		$this->startTime         = $startTime ? $startTime : time();
		$this->lastUpdate        = $lastUpdate ? $lastUpdate : time();
		$this->errors            = $errors;
	}

	/**
	 * Create from array
	 *
	 * @param array<string, mixed> $data
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			$data['status'] ?? SyncStatus::IDLE,
			$data['total_files'] ?? 0,
			$data['processed_files'] ?? 0,
			$data['successful_uploads'] ?? 0,
			$data['failed_uploads'] ?? 0,
			$data['start_time'] ?? 0,
			$data['last_update'] ?? 0,
			$data['errors'] ?? array()
		);
	}

	/**
	 * Create initial sync progress
	 *
	 * @param int $totalFiles
	 * @return self
	 */
	public static function start( int $totalFiles ): self {
		return new self(
			SyncStatus::STARTED,
			$totalFiles,
			0,
			0,
			0,
			time(),
			time(),
			array()
		);
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
	 * Get processed files
	 *
	 * @return int
	 */
	public function getProcessedFiles(): int {
		return $this->processedFiles;
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
	 * Get start time
	 *
	 * @return int
	 */
	public function getStartTime(): int {
		return $this->startTime;
	}

	/**
	 * Get last update time
	 *
	 * @return int
	 */
	public function getLastUpdate(): int {
		return $this->lastUpdate;
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
	 * Get completion percentage
	 *
	 * @return float
	 */
	public function getPercentage(): float {
		if ( $this->totalFiles === 0 ) {
			return 0.0;
		}
		return ( $this->processedFiles / $this->totalFiles ) * 100;
	}

	/**
	 * Get elapsed time in seconds
	 *
	 * @return int
	 */
	public function getElapsedTime(): int {
		return time() - $this->startTime;
	}

	/**
	 * Get estimated remaining time in seconds
	 *
	 * @return int|null Returns null if cannot estimate
	 */
	public function getEstimatedRemainingTime(): ?int {
		if ( $this->processedFiles === 0 || $this->totalFiles === 0 ) {
			return null;
		}

		$elapsed        = $this->getElapsedTime();
		$avgTimePerFile = $elapsed / $this->processedFiles;
		$remainingFiles = $this->totalFiles - $this->processedFiles;

		return (int) ( $avgTimePerFile * $remainingFiles );
	}

	/**
	 * Check if sync is in progress
	 *
	 * @return bool
	 */
	public function isInProgress(): bool {
		return SyncStatus::isInProgress( $this->status );
	}

	/**
	 * Check if sync is completed
	 *
	 * @return bool
	 */
	public function isCompleted(): bool {
		return $this->status === SyncStatus::COMPLETED;
	}

	/**
	 * Check if sync is paused
	 *
	 * @return bool
	 */
	public function isPaused(): bool {
		return $this->status === SyncStatus::PAUSED;
	}

	/**
	 * Check if sync has failed
	 *
	 * @return bool
	 */
	public function hasFailed(): bool {
		return $this->status === SyncStatus::FAILED;
	}

	/**
	 * Update status (returns new instance - immutability)
	 *
	 * @param string $status
	 * @return self
	 */
	public function withStatus( string $status ): self {
		return new self(
			$status,
			$this->totalFiles,
			$this->processedFiles,
			$this->successfulUploads,
			$this->failedUploads,
			$this->startTime,
			time(),  // Update last_update
			$this->errors
		);
	}

	/**
	 * Increment processed files (returns new instance - immutability)
	 *
	 * @param bool        $success
	 * @param string|null $error
	 * @return self
	 */
	public function incrementProcessed( bool $success, ?string $error = null ): self {
		$errors = $this->errors;
		if ( ! $success && $error ) {
			$errors[] = $error;
		}

		return new self(
			$this->status,
			$this->totalFiles,
			$this->processedFiles + 1,
			$success ? $this->successfulUploads + 1 : $this->successfulUploads,
			$success ? $this->failedUploads : $this->failedUploads + 1,
			$this->startTime,
			time(),
			$errors
		);
	}

	/**
	 * Convert to legacy array format
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'status'             => $this->status,
			'total_files'        => $this->totalFiles,
			'processed_files'    => $this->processedFiles,
			'successful_uploads' => $this->successfulUploads,
			'failed_uploads'     => $this->failedUploads,
			'start_time'         => $this->startTime,
			'last_update'        => $this->lastUpdate,
			'errors'             => $this->errors,
			// Calculated fields for convenience
			'percentage'         => $this->getPercentage(),
			'elapsed_time'       => $this->getElapsedTime(),
		);
	}
}
