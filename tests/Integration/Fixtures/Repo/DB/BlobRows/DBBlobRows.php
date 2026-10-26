<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\BlobRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\BlobRows\BlobRows;

class DBBlobRows extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBBlobRow::class;
    public const string BASE_ENTITY_SET_CLASS = BlobRows::class;
}
