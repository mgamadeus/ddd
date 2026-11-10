<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Workers;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRuns;

class DBWorkerMessageRuns extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBWorkerMessageRun::class;
    public const string BASE_ENTITY_SET_CLASS = WorkerMessageRuns::class;
}
