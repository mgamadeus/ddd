<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Workers;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Common\Entities\Workers\WorkerStatBuckets;

class DBWorkerStatBuckets extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBWorkerStatBucket::class;
    public const string BASE_ENTITY_SET_CLASS = WorkerStatBuckets::class;
}
