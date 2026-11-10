<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Workers;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Common\Entities\Workers\WorkerProcesses;

class DBWorkerProcesses extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBWorkerProcess::class;
    public const string BASE_ENTITY_SET_CLASS = WorkerProcesses::class;
}
