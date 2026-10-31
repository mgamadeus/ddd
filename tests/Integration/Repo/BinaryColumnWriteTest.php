<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Repo;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatement;
use DDD\Tests\Integration\Fixtures\Entities\BlobRows\BlobRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\BlobRows\DBBlobRowModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * BLOB columns written through the model upsert path round-trip arbitrary bytes on both engines. PostgreSQL
 * renders them as `bytea`; the Doctrine field type stays `string`, so the PostgreSQL upsert builder must bind them
 * as binary. Real failure: every track upload of a consuming app (a gzip blob column) failed with "invalid byte
 * sequence for encoding UTF8: 0x8b". {@see BlobRow} is not part of {@see IntegrationTestCase::FIXTURE_ENTITY_CLASSES}
 * (would change the golden fixture DDL), so this class creates its table itself.
 */
class BinaryColumnWriteTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        $connection = static::connection();
        $databaseModel = DatabaseModel::fromEntityClass(BlobRow::class);
        $connection->executeStatement('DROP TABLE IF EXISTS ' . static::quoteIdentifier($databaseModel->sqlTableName));
        if (static::currentDriver() === 'pdo_pgsql') {
            foreach (static::dialect()->ddlRenderer()->renderCreate($databaseModel)->sortedByPhase() as $statement) {
                $connection->executeStatement($statement->sql);
            }
            return;
        }
        foreach (static::splitSqlStatements($databaseModel->getSql()) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            static::connection()->executeStatement(
                'DROP TABLE IF EXISTS ' . static::quoteIdentifier(DatabaseModel::fromEntityClass(BlobRow::class)->sqlTableName)
            );
        }
        parent::tearDownAfterClass();
    }

    public function testGzipBytesRoundTripThroughInsertAndUpdate(): void
    {
        $gzipBytes = gzencode(str_repeat("lat;lng;ts\n", 50), 9);
        $this->assertFalse(mb_check_encoding($gzipBytes, 'UTF-8'), 'fixture must contain invalid UTF-8 bytes');

        // The model, not the entity: the entity-to-model mapping of a raw binary string is app-side (e.g.
        // writes `TrackLocations::toString()`); the upsert of the model is the path that failed on PostgreSQL.
        $blobRowModel = new DBBlobRowModel();
        $blobRowModel->label = 'track';
        $blobRowModel->payload = $gzipBytes;
        $id = static::entityManager()->upsert($blobRowModel);
        $this->assertNotEmpty($id);
        $this->assertSame($gzipBytes, $this->readPayload((int)$id));

        $updatedBytes = gzencode('updated', 9);
        $blobRowModel->id = (int)$id;
        $blobRowModel->payload = $updatedBytes;
        static::entityManager()->upsert($blobRowModel);
        $this->assertSame($updatedBytes, $this->readPayload((int)$id));
    }

    protected function readPayload(int $id): string
    {
        $payload = $this->fetchRow('BlobRows', $id)['payload'];

        return is_resource($payload) ? stream_get_contents($payload) : (string)$payload;
    }
}
