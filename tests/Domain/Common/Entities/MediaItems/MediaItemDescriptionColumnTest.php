<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\MediaItems;

use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\MediaItems\MediaItem;
use DDD\Infrastructure\Reflection\ReflectionClass;
use PHPUnit\Framework\TestCase;

/** A media item's description is a TEXT column without index: generated descriptions exceed 255 characters */
class MediaItemDescriptionColumnTest extends TestCase
{
    public function testTheDescriptionIsATextColumnWithoutIndex(): void
    {
        $reflectionClass = ReflectionClass::instance(MediaItem::class);
        $databaseColumn = DatabaseColumn::createFromReflectionProperty(
            $reflectionClass,
            $reflectionClass->getProperty('description')
        );

        self::assertSame(DatabaseColumn::SQL_TYPE_TEXT, $databaseColumn->sqlType);
        $databaseIndex = $reflectionClass->getProperty('description')->getAttributeInstance(DatabaseIndex::class);
        self::assertSame(DatabaseIndex::TYPE_NONE, $databaseIndex->indexType);
    }
}
