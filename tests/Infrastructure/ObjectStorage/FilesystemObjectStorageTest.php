<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use DDD\Infrastructure\Services\ObjectStorageService;

class FilesystemObjectStorageTest extends ObjectStorageContractTestCase
{
    protected string $rootDirectory;

    protected function setUp(): void
    {
        $this->rootDirectory = sys_get_temp_dir() . '/ddd-object-storage-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->rootDirectory)) {
            exec('rm -r ' . escapeshellarg($this->rootDirectory));
        }
        foreach (['OBJECT_STORAGE_TEST_EXPORTS_DRIVER', 'OBJECT_STORAGE_TEST_EXPORTS_PATH', 'OBJECT_STORAGE_TEST_EXPORTS_PREFIX'] as $variable) {
            unset($_ENV[$variable]);
        }
    }

    protected function createObjectStorage(): ObjectStorage
    {
        return new FilesystemObjectStorage($this->rootDirectory, 'tenant/');
    }

    public function testAKeyCannotLeaveTheRoot(): void
    {
        $this->expectException(InternalErrorException::class);
        $this->createObjectStorage()->putObject('sourcemaps/../../etc/passwd', 'x');
    }

    public function testAStoreIsConfiguredByNameFromTheEnvironment(): void
    {
        $objectStorageService = new ObjectStorageService();
        $this->assertNull($objectStorageService->getObjectStorage('test-exports'), 'without a driver the store is not configured');

        $_ENV['OBJECT_STORAGE_TEST_EXPORTS_DRIVER'] = 'filesystem';
        $_ENV['OBJECT_STORAGE_TEST_EXPORTS_PATH'] = $this->rootDirectory;
        $_ENV['OBJECT_STORAGE_TEST_EXPORTS_PREFIX'] = '/exports/';
        $this->assertSame('OBJECT_STORAGE_TEST_EXPORTS_DRIVER', ObjectStorageSettings::getEnvName('test-exports', ObjectStorageSettings::SETTING_DRIVER));
        $objectStorage = $objectStorageService->getObjectStorage('test-exports');

        $this->assertInstanceOf(FilesystemObjectStorage::class, $objectStorage);
        $objectStorage->putObject('2026/report.pdf', 'pdf');
        $this->assertFileExists($this->rootDirectory . '/exports/2026/report.pdf');
    }
}
