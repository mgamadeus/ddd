<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumns;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndexes;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Database\UpsertConflictKey;
use DDD\Domain\Base\Repo\DB\Dialect\Write\ConflictTargetResolver;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use PHPUnit\Framework\TestCase;

/**
 * `#[UpsertConflictKey]` on an entity with several nullable UNIQUE keys (ddd-common-political's Country: slug, tld,
 * shortCode, isoCode): the declared key decides a row that carries it; a row without it falls to the nullable
 * fallback instead of an error; a name matching no key of the entity is still an error.
 */
class ConflictTargetResolverDeclaredKeyTest extends TestCase
{
    protected function createCountryLikeModel(string $entityClass): DatabaseModel
    {
        $databaseModel = new DatabaseModel();
        $databaseModel->sqlTableName = 'Countries';
        $databaseModel->entityClassWithNamespace = new ClassWithNamespace($entityClass);
        $databaseModel->columns = new DatabaseColumns();
        $databaseModel->indexes = new DatabaseIndexes();
        foreach (['slug', 'tld', 'shortCode', 'isoCode'] as $columnName) {
            $databaseColumn = new DatabaseColumn();
            $databaseColumn->name = $columnName;
            $databaseColumn->allowsNull = true;
            $databaseModel->columns->add($databaseColumn);
            $databaseIndex = new DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE, indexColumns: [$columnName]);
            $databaseModel->indexes->add($databaseIndex);
        }
        return $databaseModel;
    }

    public function testTheDeclaredKeyDecidesARowThatCarriesIt(): void
    {
        $resolution = ConflictTargetResolver::resolve($this->createCountryLikeModel(DeclaredShortCodeEntity::class), ['slug', 'tld', 'shortCode', 'name'], false);

        self::assertFalse($resolution->needsNullableFallback);
        self::assertSame(['shortCode'], $resolution->columns);
    }

    public function testARowWithoutTheDeclaredKeyFallsBackInsteadOfFailing(): void
    {
        $resolution = ConflictTargetResolver::resolve($this->createCountryLikeModel(DeclaredShortCodeEntity::class), ['slug', 'tld', 'name'], false);

        self::assertTrue($resolution->needsNullableFallback);
    }

    public function testADeclaredKeyThatIsNoKeyOfTheEntityIsAnError(): void
    {
        $this->expectException(InternalErrorException::class);
        ConflictTargetResolver::resolve($this->createCountryLikeModel(DeclaredTypoEntity::class), ['slug', 'tld', 'name'], false);
    }
}

#[UpsertConflictKey(columns: ['shortCode'])]
class DeclaredShortCodeEntity
{
}

#[UpsertConflictKey(columns: ['shortcode'])]
class DeclaredTypoEntity
{
}
