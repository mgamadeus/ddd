<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use DDD\Infrastructure\ObjectStorage\S3CompatibleObjectStorage;
use DDD\Tests\Infrastructure\ObjectStorage\Fixtures\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * async-aws retries a failed request itself; with the logger passed to the store, every retry shows up in the log.
 * Against a port nobody listens on, the three retries take about 7 seconds.
 */
class S3CompatibleObjectStorageRetryLoggingTest extends TestCase
{
    public function testTheRetriesOfAFailedRequestAreLogged(): void
    {
        $recordingLogger = new RecordingLogger();
        $objectStorage = new S3CompatibleObjectStorage(new ObjectStorageSettings(
            name: 'retry-test',
            driver: ObjectStorageSettings::DRIVER_S3,
            endpoint: 'http://127.0.0.1:9',
            bucket: 'retry-test',
            accessKey: 'ddd-test',
            secretKey: 'ddd-test-secret',
            pathStyle: true,
        ), $recordingLogger);

        try {
            $objectStorage->getObjectContent('a.txt');
            self::fail('a store nobody listens on cannot answer');
        } catch (InternalErrorException $internalErrorException) {
            self::assertStringContainsString('Object storage "retry-test": get of "a.txt" failed', $internalErrorException->getMessage());
        }

        $retryLines = array_values(array_filter(
            $recordingLogger->loggedLines,
            static fn(string $loggedLine): bool => str_starts_with($loggedLine, 'info Try #')
        ));
        self::assertCount(3, $retryLines);
    }
}
