<?php
namespace Tests\Unit\CloudStorage;

use PHPUnit\Framework\TestCase;
use DiluxOneOffload\Factories\CloudStorageFactory;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;
use DiluxOneOffload\Providers\AzureProvider;
use DiluxOneOffload\Providers\DiluxOneCloudProvider;

/**
 * Unit tests for CloudStorageFactory — provider instantiation, supported
 * provider listing, config-field metadata.
 */
class CloudStorageFactoryTest extends TestCase {

    public function test_create_azure_provider(): void {
        $provider = CloudStorageFactory::create('azure', [
            'storage_account' => 'testacc',
            'container_name'  => 'testcont',
            'access_key'      => 'testkey',
        ]);

        $this->assertInstanceOf(CloudStorageClientInterface::class, $provider);
        $this->assertInstanceOf(AzureProvider::class, $provider);
    }

    public function test_create_diluxone_provider(): void {
        $provider = CloudStorageFactory::create('diluxone', [
            'api_key' => 'test-key',
        ]);

        $this->assertInstanceOf(CloudStorageClientInterface::class, $provider);
        $this->assertInstanceOf(DiluxOneCloudProvider::class, $provider);
    }

    public function test_create_unsupported_provider_throws(): void {
        $this->expectException(\Exception::class);

        CloudStorageFactory::create('aws', []);
    }

    public function test_get_supported_providers(): void {
        $providers = CloudStorageFactory::get_supported_providers();

        $this->assertIsArray($providers);
        $this->assertArrayHasKey('azure', $providers);
        $this->assertArrayHasKey('diluxone', $providers);
    }

    public function test_is_provider_supported(): void {
        $this->assertTrue(CloudStorageFactory::is_provider_supported('azure'));
        $this->assertTrue(CloudStorageFactory::is_provider_supported('diluxone'));
        $this->assertFalse(CloudStorageFactory::is_provider_supported('s3'));
    }

    public function test_get_provider_config_fields(): void {
        $azure_fields = CloudStorageFactory::get_provider_config_fields('azure');
        $diluxone_fields = CloudStorageFactory::get_provider_config_fields('diluxone');

        $this->assertIsArray($azure_fields);
        $this->assertIsArray($diluxone_fields);
        $this->assertNotEmpty($azure_fields);
        $this->assertNotEmpty($diluxone_fields);
    }
}
