<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource;

use DDD\Symfony\Messenger\WorkerStateResetterInterface;

/** A module service tagged by interface autoconfiguration */
class ModuleWorkerStateResetter implements WorkerStateResetterInterface
{
    public function resetWorkerStateForNewJob(): void
    {
    }
}
