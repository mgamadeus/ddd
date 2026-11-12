<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Domain\Base\Repo\Fixtures\DBApplicationChangeHistoryRow;
use DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow;
use PHPUnit\Framework\TestCase;

/**
 * A repository whose entity class is a subclass of the object's class (a module object, the application's
 * repository) refuses the write instead of skipping it without a row and without an error.
 */
class ParentClassEntityPersistTest extends TestCase
{
    public function testUpdateOfAParentClassObjectThrows(): void
    {
        $moduleRow = new ChangeHistoryRow();
        $applicationRepo = new DBApplicationChangeHistoryRow();

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('newInstance()');
        $applicationRepo->update($moduleRow);
    }

    public function testInsertOfAParentClassObjectThrows(): void
    {
        $moduleRow = new ChangeHistoryRow();
        $applicationRepo = new DBApplicationChangeHistoryRow();

        $this->expectException(InternalErrorException::class);
        $applicationRepo->insert($moduleRow);
    }
}
