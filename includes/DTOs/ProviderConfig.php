<?php
/**
 * Cloud-provider configuration value object.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\DTOs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider Configuration DTO
 *
 * Immutable value object for cloud provider settings and credentials
 *
 * @package DiluxOneOffload\DTOs
 * @since 1.0.0
 */
class ProviderConfig {

	/** @var string */
	private string $cloudProvider;
	/**
	 * @var array<string, mixed>
	 */
	private array $providerConfig;

	/**
	 * Constructor
	 *
	 * @param string               $cloudProvider Provider name (azure, aws, gcp)
	 * @param array<string, mixed> $providerConfig Provider-specific configuration
	 */
	public function __construct(
		string $cloudProvider = '',
		array $providerConfig = array()
	) {
		$this->cloudProvider  = $cloudProvider;
		$this->providerConfig = $providerConfig;
	}

	/**
	 * Create from array configuration
	 *
	 * @param array<string, mixed> $config
	 * @return self
	 * @throws \InvalidArgumentException When provider_config is not an array.
	 */
	public static function fromArray( array $config ): self {
		$provider_config = $config['provider_config'] ?? array();
		if ( ! is_array( $provider_config ) ) {
			throw new \InvalidArgumentException( 'provider_config must be an array' );
		}
		return new self(
			(string) ( $config['cloud_provider'] ?? '' ),
			$provider_config
		);
	}

	/**
	 * Create from POST data
	 *
	 * @param array<string, mixed> $post POST data from form
	 * @return self
	 * @throws \InvalidArgumentException When required POST fields are missing or invalid.
	 */
	public static function fromPost( array $post ): self {
		$cloud_provider = sanitize_text_field( wp_unslash( $post['cloud_provider'] ?? '' ) );

		if ( empty( $cloud_provider ) ) {
			throw new \InvalidArgumentException( 'Cloud provider is required' );
		}

		// Build provider-specific configuration
		$provider_config = array();

		switch ( $cloud_provider ) {
			case 'azure':
				$provider_config = array(
					'storage_account' => sanitize_text_field( wp_unslash( $post['account_name'] ?? '' ) ),
					'access_key'      => sanitize_text_field( wp_unslash( $post['account_key'] ?? '' ) ),
					'container_name'  => sanitize_text_field( wp_unslash( $post['container_name'] ?? '' ) ),
					'custom_domain'   => sanitize_text_field( wp_unslash( $post['custom_domain'] ?? '' ) ),
					// NOTE: use_https removed - HTTPS is always enforced in provider
				);

				// Validate required fields
				if ( empty( $provider_config['storage_account'] ) ) {
					throw new \InvalidArgumentException( 'Storage Account Name is required' );
				}
				if ( empty( $provider_config['access_key'] ) ) {
					throw new \InvalidArgumentException( 'Access Key is required' );
				}
				if ( empty( $provider_config['container_name'] ) ) {
					throw new \InvalidArgumentException( 'Container Name is required' );
				}

				// Validate formats
				if ( ! preg_match( '/^[a-z0-9]{3,24}$/', $provider_config['storage_account'] ) ) {
					throw new \InvalidArgumentException( 'Storage Account Name must be 3-24 lowercase letters and numbers only' );
				}
				if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9]|[-](?![.])){1,61}[a-z0-9]$/', $provider_config['container_name'] ) ) {
					throw new \InvalidArgumentException( 'Container Name must be lowercase letters, numbers, and hyphens only (3-63 characters)' );
				}
				break;

			// Future providers: aws, gcp
			default:
				throw new \InvalidArgumentException( 'Unsupported cloud provider: ' . esc_html( $cloud_provider ) );
		}

		return new self( $cloud_provider, $provider_config );
	}

	/**
	 * Get cloud provider name
	 *
	 * @return string
	 */
	public function getCloudProvider(): string {
		return $this->cloudProvider;
	}

	/**
	 * Get provider-specific configuration
	 *
	 * @return array<string, mixed>
	 */
	public function getProviderConfig(): array {
		// NOTE: use_https enforcement removed - HTTPS is now hardcoded in provider
		return $this->providerConfig;
	}

	/**
	 * Check if provider is configured
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return ! empty( $this->cloudProvider ) && ! empty( $this->providerConfig );
	}

	/**
	 * Get storage account name (Azure specific)
	 *
	 * @return string
	 */
	public function getStorageAccount(): string {
		return $this->providerConfig['storage_account'] ?? '';
	}

	/**
	 * Get container name (Azure specific)
	 *
	 * @return string
	 */
	public function getContainerName(): string {
		return $this->providerConfig['container_name'] ?? '';
	}

	/**
	 * Get custom domain
	 *
	 * @return string
	 */
	public function getCustomDomain(): string {
		return $this->providerConfig['custom_domain'] ?? '';
	}

	/**
	 * Convert to array format
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'cloud_provider'  => $this->cloudProvider,
			'provider_config' => $this->providerConfig,
		);
	}

	/**
	 * Merge with another provider config (update fields)
	 *
	 * @param array<string, mixed> $updates Array of fields to update
	 * @return self New instance with updated fields
	 */
	public function merge( array $updates ): self {
		$current = $this->toArray();
		$merged  = array_merge( $current, $updates );

		// Handle nested provider_config merge
		if ( isset( $updates['provider_config'] ) ) {
			$merged['provider_config'] = array_merge(
				$current['provider_config'] ?? array(),
				$updates['provider_config']
			);
		}

		return self::fromArray( $merged );
	}
}
