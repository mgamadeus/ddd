<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\BlobRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\BlobRows\DBBlobRows;
use DDD\Tests\Integration\Fixtures\Services\BlobRowsService;

/**
 * @property BlobRow[] $elements
 * @method BlobRow|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBBlobRows::class)]
class BlobRows extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = BlobRowsService::class;
}
