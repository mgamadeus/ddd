<?php

declare(strict_types=1);

namespace DDD\Symfony\Messenger;

/**
 * A service that puts back process state one consumed Messenger job may leave behind for the next job of the same
 * worker: a static flag, a process memo, an open stack of a shared service. The
 * {@see \DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware} runs EVERY service implementing this interface
 * before every consumed job, whatever base class the message's handler extends — so a module or an application adds
 * its own state to the reset by implementing the interface, without owning the handler base class.
 *
 * Collected by interface autoconfiguration ({@see \DDD\Symfony\Kernels\DDDKernel::build()}, tag {@see self::TAG}),
 * which also reaches module services. Order: the `priority` attribute of the tag (higher first, default 0); a
 * resetter needing an explicit priority tags itself in the application's services configuration.
 *
 * The reset of the message's handler class ({@see \DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler::resetWorkerStateForNewJob()}:
 * connections, unit of work, entity registries) runs FIRST; a handler that opts out of the fresh state
 * ({@see \DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler::RESET_WORKER_STATE_BEFORE_JOB} false) skips the
 * resetters too. A resetter that throws stops neither the other resetters nor the job.
 */
interface WorkerStateResetterInterface
{
    /** @var string The container tag every implementation carries (interface autoconfiguration) */
    public const string TAG = 'ddd.worker_state_resetter';

    /**
     * Puts back the state this resetter owns to what a fresh worker process has.
     *
     * @return void
     */
    public function resetWorkerStateForNewJob(): void;
}
