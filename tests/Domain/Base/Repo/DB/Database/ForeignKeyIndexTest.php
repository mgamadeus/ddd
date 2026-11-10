<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Tests\Domain\Base\Repo\DB\Database\Fixtures\ForeignKeyIndexCompositeRow;
use DDD\Tests\Domain\Base\Repo\DB\Database\Fixtures\ForeignKeyIndexPlainRow;
use DDD\Tests\Domain\Base\Repo\DB\Database\Fixtures\ForeignKeyIndexUniqueClassRow;
use DDD\Tests\Domain\Base\Repo\DB\Database\Fixtures\ForeignKeyIndexUniquePropertyRow;
use PHPUnit\Framework\TestCase;

/**
 * A foreign key column gets an index of its own only when no plain or unique index over exactly this column is
 * declared.
 */
class ForeignKeyIndexTest extends TestCase
{
    protected function setUp(): void
    {
        LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
    }

    public function testAForeignKeyColumnWithoutDeclaredIndexGetsOne(): void
    {
        $this->assertSame(
            [DatabaseIndex::TYPE_INDEX],
            $this->getIndexTypesOfForeignKeyColumn(ForeignKeyIndexPlainRow::class)
        );
    }

    public function testAUniqueIndexOnThePropertyIsTheOnlyIndex(): void
    {
        $this->assertSame(
            [DatabaseIndex::TYPE_UNIQUE],
            $this->getIndexTypesOfForeignKeyColumn(ForeignKeyIndexUniquePropertyRow::class)
        );
    }

    public function testAUniqueIndexOnTheClassIsTheOnlyIndex(): void
    {
        $this->assertSame(
            [DatabaseIndex::TYPE_UNIQUE],
            $this->getIndexTypesOfForeignKeyColumn(ForeignKeyIndexUniqueClassRow::class)
        );
    }

    public function testAnIndexOverSeveralColumnsDoesNotReplaceTheForeignKeyIndex(): void
    {
        $this->assertSame(
            [DatabaseIndex::TYPE_INDEX],
            $this->getIndexTypesOfForeignKeyColumn(ForeignKeyIndexCompositeRow::class)
        );
    }

    /**
     * @param string $entityClassName
     * @return string[] The types of the indexes over exactly the foreign key column
     */
    protected function getIndexTypesOfForeignKeyColumn(string $entityClassName): array
    {
        $indexTypes = [];
        foreach (DatabaseModel::fromEntityClass($entityClassName)->indexes->getElements() as $databaseIndex) {
            if ($databaseIndex->indexColumns === ['tieBreakerParentId']) {
                $indexTypes[] = $databaseIndex->indexType;
            }
        }
        return $indexTypes;
    }
}
