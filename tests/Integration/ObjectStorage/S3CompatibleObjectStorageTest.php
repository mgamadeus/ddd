<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\ObjectStorage;

use AsyncAws\S3\S3Client;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use DDD\Infrastructure\ObjectStorage\S3CompatibleObjectStorage;
use DDD\Tests\Infrastructure\ObjectStorage\ObjectStorageContractTestCase;

/**
 * The ObjectStorage contract against a real S3-compatible endpoint (the harness's SeaweedFS, signature V4, path
 * style): DDD_TEST_S3_ENDPOINT, DDD_TEST_S3_ACCESS_KEY, DDD_TEST_S3_SECRET_KEY. Skipped without them.
 */
class S3CompatibleObjectStorageTest extends ObjectStorageContractTestCase
{
    public const string BUCKET = 'ddd-contract-test';

    protected ObjectStorageSettings $objectStorageSettings;

    protected function setUp(): void
    {
        $endpoint = getenv('DDD_TEST_S3_ENDPOINT') ?: null;
        if ($endpoint === null) {
            self::markTestSkipped('DDD_TEST_S3_ENDPOINT not set');
        }
        $this->objectStorageSettings = new ObjectStorageSettings(
            name: 'contract-test',
            driver: ObjectStorageSettings::DRIVER_S3,
            endpoint: $endpoint,
            // one bucket (a bucket is a volume collection in SeaweedFS), one prefix per test
            bucket: self::BUCKET,
            accessKey: getenv('DDD_TEST_S3_ACCESS_KEY') ?: 'ddd-test',
            secretKey: getenv('DDD_TEST_S3_SECRET_KEY') ?: 'ddd-test-secret',
            pathStyle: true,
            prefix: 'run-' . bin2hex(random_bytes(4)) . '/',
        );
        try {
            $this->createS3Client()->createBucket(['Bucket' => self::BUCKET])->resolve();
        } catch (\AsyncAws\S3\Exception\BucketAlreadyOwnedByYouException|\AsyncAws\S3\Exception\BucketAlreadyExistsException) {
        }
    }

    protected function createS3Client(): S3Client
    {
        return new S3Client([
            'endpoint' => $this->objectStorageSettings->endpoint,
            'region' => $this->objectStorageSettings->region,
            'accessKeyId' => $this->objectStorageSettings->accessKey,
            'accessKeySecret' => $this->objectStorageSettings->secretKey,
            'pathStyleEndpoint' => 'true',
        ]);
    }

    protected function createObjectStorage(): ObjectStorage
    {
        return new S3CompatibleObjectStorage($this->objectStorageSettings);
    }

    public function testThePresignedUrlReadsTheObjectWithoutCredentials(): void
    {
        $objectStorage = $this->createObjectStorage();
        $objectStorage->putObject('presign/a.txt', 'hello');

        $content = @file_get_contents($objectStorage->createTemporaryUrl('presign/a.txt', 60));

        $this->assertSame('hello', $content);
    }

    public function testWrongCredentialsFailWithTheStoreName(): void
    {
        $objectStorage = new S3CompatibleObjectStorage(new ObjectStorageSettings(
            name: 'contract-test',
            driver: ObjectStorageSettings::DRIVER_S3,
            endpoint: $this->objectStorageSettings->endpoint,
            bucket: $this->objectStorageSettings->bucket,
            accessKey: 'ddd-test',
            secretKey: 'wrong',
            pathStyle: true,
        ));

        $this->expectExceptionMessageMatches('/Object storage "contract-test": put of "x" failed/');
        $objectStorage->putObject('x', 'y');
    }
}
