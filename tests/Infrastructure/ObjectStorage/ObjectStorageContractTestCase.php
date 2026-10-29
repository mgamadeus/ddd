<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage;

use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use PHPUnit\Framework\TestCase;

/**
 * The contract every {@see ObjectStorage} adapter fulfils; one subclass per adapter.
 */
abstract class ObjectStorageContractTestCase extends TestCase
{
    abstract protected function createObjectStorage(): ObjectStorage;

    public function testAnObjectIsWrittenReadListedAndDeleted(): void
    {
        $objectStorage = $this->createObjectStorage();
        $this->assertNull($objectStorage->getObjectContent('sourcemaps/r1/missing.js.map'));
        $this->assertFalse($objectStorage->objectExists('sourcemaps/r1/missing.js.map'));

        $objectStorage->putObject('sourcemaps/r1/_next/static/chunks/b.js.map', '{"b":1}', 'application/json');
        $objectStorage->putObject('sourcemaps/r1/_next/static/chunks/a.js.map', '{"a":1}');
        $objectStorage->putObject('sourcemaps/r2/a.js.map', '{"other":1}');
        $objectStorage->putObject('sourcemaps/r1/_next/static/chunks/a.js.map', '{"a":2}');

        $this->assertSame('{"a":2}', $objectStorage->getObjectContent('sourcemaps/r1/_next/static/chunks/a.js.map'), 'a put replaces');
        $this->assertTrue($objectStorage->objectExists('sourcemaps/r1/_next/static/chunks/b.js.map'));
        $objectStorageObjects = $objectStorage->listObjects('sourcemaps/r1/');
        $this->assertSame(
            ['sourcemaps/r1/_next/static/chunks/a.js.map', 'sourcemaps/r1/_next/static/chunks/b.js.map'],
            $objectStorageObjects->getKeys()
        );
        $this->assertSame(7, $objectStorageObjects->first()->size);
        $this->assertNotNull($objectStorageObjects->first()->lastModified);
        $this->assertCount(1, $objectStorage->listObjects('sourcemaps/r1/', limit: 1)->getKeys());
        $this->assertNotSame('', $objectStorage->createTemporaryUrl('sourcemaps/r1/_next/static/chunks/a.js.map', 60));

        $objectStorage->deleteObject('sourcemaps/r1/_next/static/chunks/a.js.map');
        $objectStorage->deleteObject('sourcemaps/r1/_next/static/chunks/a.js.map');
        $this->assertFalse($objectStorage->objectExists('sourcemaps/r1/_next/static/chunks/a.js.map'));
        $this->assertSame(['sourcemaps/r1/_next/static/chunks/b.js.map'], $objectStorage->listObjects('sourcemaps/r1/')->getKeys());
    }
}
