<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\BlobRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\BlobRows\BlobRow;

class DBBlobRow extends DBEntity
{
    public const string BASE_ENTITY_CLASS = BlobRow::class;
    public const string BASE_ORM_MODEL = DBBlobRowModel::class;
}
