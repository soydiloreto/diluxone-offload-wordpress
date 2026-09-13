<?php
/**
 * Factory that instantiates a cloud-storage provider by name.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload\Factories;

use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use DiluxOneOffload\Providers\AzureProvider;
use DiluxOneOffload\Providers\DiluxOneCloudProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloud Storage Factory
 *
 * Factory class to create cloud storage provider instances
 */
class CloudStorageFactory {

	/**
	 * Create cloud storage client instance
	 *
	 * @param string               $provider Provider name (azure, aws, etc.)
	 * @param array<string, mixed> $config Configuration array
	 * @return CloudStorageClientInterface|null
	 * @throws \Exception When the provider name is not supported or not yet implemented.
	 */
	public static function create( $provider, $config = array() ) {
		switch ( strtolower( $provider ) ) {
			case 'diluxone':
				return new DiluxOneCloudProvider( $config );

			case 'azure':
				return new AzureProvider( $config );

			case 'aws':
				// Future implementation
				throw new \Exception( 'AWS S3 provider not implemented yet' );

			case 'gcp':
				// Future implementation
				throw new \Exception( 'Google Cloud Storage provider not implemented yet' );

			default:
				throw new \Exception( 'Unsupported cloud storage provider: ' . esc_html( $provider ) );
		}
	}

	/**
	 * Get list of supported providers
	 *
	 * @return array<string, mixed>
	 */
	public static function get_supported_providers() {
		return array(
			'diluxone' => array(
				'name'          => 'DiluxOne Cloud (Managed)',
				'implemented'   => true,
				'config_fields' => array(
					'api_key' => 'API Key',
				),
			),
			'azure'    => array(
				'name'          => 'Azure Blob Storage',
				'implemented'   => true,
				'config_fields' => array(
					'storage_account' => 'Storage Account Name',
					'container_name'  => 'Container Name',
					'access_key'      => 'Access Key',
				),
			),
			'aws'      => array(
				'name'          => 'Amazon S3',
				'implemented'   => false,
				'config_fields' => array(
					'bucket_name'       => 'Bucket Name',
					'access_key_id'     => 'Access Key ID',
					'secret_access_key' => 'Secret Access Key',
					'region'            => 'Region',
				),
			),
			'gcp'      => array(
				'name'          => 'Google Cloud Storage',
				'implemented'   => false,
				'config_fields' => array(
					'bucket_name'         => 'Bucket Name',
					'project_id'          => 'Project ID',
					'service_account_key' => 'Service Account Key (JSON)',
				),
			),
		);
	}

	/**
	 * Check if provider is supported and implemented
	 *
	 * @param string $provider
	 * @return bool
	 */
	public static function is_provider_supported( $provider ) {
		$providers = self::get_supported_providers();
		return isset( $providers[ $provider ] ) && $providers[ $provider ]['implemented'];
	}

	/**
	 * Get provider configuration fields
	 *
	 * @param string $provider
	 * @return array<string, mixed>
	 */
	public static function get_provider_config_fields( $provider ) {
		$providers = self::get_supported_providers();
		return $providers[ $provider ]['config_fields'] ?? array();
	}
}
