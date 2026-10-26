<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Doctrine;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineBoundSql;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRowModel;
use DDD\Tests\Integration\Fixtures\Repo\DB\SpatialRows\DBSpatialRowModel;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRowModel;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;

/**
 * The batch upsert builder: per-column forms as in the single-row upsert, a row writes only its initialized fields,
 * rows of one shape form one statement and a statement's ON DUPLICATE KEY UPDATE list names only its own columns.
 * The entity manager never connects — DBAL connects lazily and the builder runs no query.
 */
class UpsertMultipleStatementsTest extends TestCase
{
    protected function entityManager(): DoctrineEntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([], true);
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'dbname' => 'never', 'serverVersion' => '10.6.0-MariaDB'],
            $configuration
        );
        return new DoctrineEntityManager($connection, $configuration);
    }

    protected function assertPlaceholdersAreAligned(DoctrineBoundSql $statement): void
    {
        $this->assertSame(substr_count($statement->sql, '?'), count($statement->values), $statement->sql);
        $this->assertSame(count($statement->values), count($statement->types), $statement->sql);
    }

    public function testANullGeometryKeepsTheFollowingBindTypesAligned(): void
    {
        $spatialRowModel = new DBSpatialRowModel();
        $spatialRowModel->label = 'no location';
        $spatialRowModel->location = null;
        $spatialRowModel->embedding = null;
        $spatialRowModel->flag = true;
        $spatialRowModel->payload = ['color' => 'red'];
        $spatialRowModel->amount = 5;
        $models = [$spatialRowModel];

        $statement = $this->entityManager()->buildUpsertMultipleStatements($models)->first();

        $this->assertPlaceholdersAreAligned($statement);
        $this->assertSame(['string', 'string', 'boolean', 'json', 'integer'], $statement->types);
        $this->assertSame(['no location', null, true, ['color' => 'red'], 5], $statement->values);
    }

    public function testAVectorAndAGeometryRenderTheirSqlFunctions(): void
    {
        $spatialRowModel = new DBSpatialRowModel();
        $spatialRowModel->location = 'POINT(13.4 52.5)';
        $spatialRowModel->embedding = [0.1, 0.2, 0.3];
        $models = [$spatialRowModel];

        $statement = $this->entityManager()->buildUpsertMultipleStatements($models)->first();

        $this->assertStringContainsString('(ST_GeomFromText(?), VEC_FromText(?)', $statement->sql);
        $this->assertSame('[0.1,0.2,0.3]', $statement->values[1]);
        $this->assertPlaceholdersAreAligned($statement);
    }

    public function testAColumnToReplaceRendersBareValues(): void
    {
        $translatedRowModel = new DBTranslatedRowModel();
        $translatedRowModel->name = ['en' => 'Bread'];
        $translatedRowModel->columnsToReplaceInsteadOfMerge = ['name'];
        $models = [$translatedRowModel];

        $statement = $this->entityManager()->buildUpsertMultipleStatements($models)->first();

        $this->assertStringContainsString('`name` = VALUES(`name`)', $statement->sql);
        $this->assertStringNotContainsString('JSON_MERGE_PATCH(COALESCE(`name`', $statement->sql);
    }

    public function testAMergedJsonColumnKeepsTheStoredValueOnNull(): void
    {
        $translatedRowModel = new DBTranslatedRowModel();
        $translatedRowModel->name = ['en' => 'Bread'];
        $models = [$translatedRowModel];

        $statement = $this->entityManager()->buildUpsertMultipleStatements($models)->first();

        $this->assertStringContainsString("`name` = JSON_MERGE_PATCH(COALESCE(`name`,'{}'), COALESCE(VALUES(`name`),'{}'))", $statement->sql);
    }

    public function testRowsOfDifferentShapesFormContiguousRunsWithTheirOwnColumns(): void
    {
        $withAmount = new DBCounterRowModel();
        $withAmount->businessKey = 'a';
        $withAmount->amount = 1;
        $withAmountToo = new DBCounterRowModel();
        $withAmountToo->businessKey = 'b';
        $withAmountToo->amount = 2;
        $withId = new DBCounterRowModel();
        $withId->id = 7;
        $withId->label = 'by id';
        $withAmountAgain = new DBCounterRowModel();
        $withAmountAgain->businessKey = 'c';
        $withAmountAgain->amount = 3;
        $models = [$withAmount, $withAmountToo, $withId, $withAmountAgain];

        $statements = $this->entityManager()->buildUpsertMultipleStatements($models)->getElements();

        $this->assertCount(3, $statements);
        $this->assertSame(2, substr_count($statements[0]->sql, '), (') + 1, 'the first two rows share one statement');
        $this->assertStringNotContainsString('`id`,', $statements[0]->sql, 'rows without an id leave the id column out');
        $this->assertStringContainsString('`amount` = VALUES(`amount`)', $statements[0]->sql);
        $this->assertStringNotContainsString('`label`', $statements[0]->sql, 'a run updates only its own columns');
        $this->assertStringNotContainsString('`displayOrder`', $statements[0]->sql, 'an uninitialized field is not written');
        $this->assertStringContainsString('`id` = LAST_INSERT_ID(`id`)', $statements[0]->sql);
        $this->assertStringContainsString('`label` = VALUES(`label`)', $statements[1]->sql);
        $this->assertStringNotContainsString('`amount`', $statements[1]->sql);
        foreach ($statements as $statement) {
            $this->assertPlaceholdersAreAligned($statement);
        }
    }

    public function testInsertIgnoreHasNoUpdateList(): void
    {
        $counterRowModel = new DBCounterRowModel();
        $counterRowModel->businessKey = 'a';
        $models = [$counterRowModel];

        $statement = $this->entityManager()->buildUpsertMultipleStatements($models, true)->first();

        $this->assertStringStartsWith('INSERT IGNORE INTO', $statement->sql);
        $this->assertStringNotContainsString('ON DUPLICATE KEY UPDATE', $statement->sql);
    }
}
