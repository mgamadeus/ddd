<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndexes;
use PHPUnit\Framework\TestCase;

/**
 * Composite index names abbreviate each column to three characters; {@see DatabaseIndexes::assignDistinctIndexNames()}
 * keeps the first index's name and makes every later colliding one distinct.
 */
class DistinctIndexNamesTest extends TestCase
{
    public function testTheFirstIndexKeepsItsNameAndALaterCollidingOneGetsASuffix(): void
    {
        $first = new DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['label', 'parentId']);
        $second = new DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['labelCode', 'parentId']);
        $other = new DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['label', 'parentId']);
        $databaseIndexes = new DatabaseIndexes();
        $databaseIndexes->add($first, $second, $other);

        $databaseIndexes->assignDistinctIndexNames();

        self::assertSame('uniq_lab_par', $first->getIndexNameBase());
        self::assertMatchesRegularExpression('/^uniq_lab_par_[0-9a-f]{6}$/', $second->getIndexNameBase());
        // a different index type has its own prefix: no collision, no suffix
        self::assertSame('idx_lab_par', $other->getIndexNameBase());
    }

    public function testTheSuffixIsStableAcrossGenerations(): void
    {
        $names = [];
        for ($generation = 0; $generation < 2; $generation++) {
            $first = new DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['label', 'parentId']);
            $second = new DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['labelCode', 'parentId']);
            $databaseIndexes = new DatabaseIndexes();
            $databaseIndexes->add($first, $second);
            $databaseIndexes->assignDistinctIndexNames();
            $names[] = $second->getIndexNameBase();
        }
        self::assertSame($names[0], $names[1]);
    }

    public function testDistinctNamesStayUnchanged(): void
    {
        $first = new DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['label', 'parentId']);
        $second = new DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['name', 'parentId']);
        $databaseIndexes = new DatabaseIndexes();
        $databaseIndexes->add($first, $second);

        $databaseIndexes->assignDistinctIndexNames();

        self::assertSame('uniq_lab_par', $first->getIndexNameBase());
        self::assertSame('uniq_nam_par', $second->getIndexNameBase());
    }
}
