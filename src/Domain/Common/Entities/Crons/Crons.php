<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Crons\DBCrons;
use DDD\Domain\Common\Services\CronsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LogLevel;
use ReflectionException;
use Throwable;

/**
 * @property Cron[] $elements;
 * @method Cron getByUniqueKey(string $uniqueKey)
 * @method Cron first()
 * @method Cron[] getElements()
 * @method static CronsService getService()
 * @method static DBCrons getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBCrons::class)]
#[QueryOptions(top: 10)]
class Crons extends EntitySet
{
    use QueryOptionsTrait;

    /**
     * Executes all Crons and returns CronExecutions
     * @param int $timeoutPerCronInSeconds
     * @return CronExecutions
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function execute(int $timeoutPerCronInSeconds = 60): CronExecutions
    {
        $cronExecutions = new CronExecutions();
        foreach ($this->getElements() as $cron) {
            // one failing Cron must never cost the other due Crons of this tick their run
            try {
                $cronExecution = $cron->execute($timeoutPerCronInSeconds);
            } catch (Throwable $throwable) {
                $this->logCronExecutionFailure($cron, $throwable);
                continue;
            }
            if ($cronExecution) {
                $cronExecutions->add($cronExecution);
            }
        }
        return $cronExecutions;
    }

    /**
     * Records a Cron whose execution threw before it could be closed (Cron::execute() closes timeouts and failing
     * commands itself; what reaches here is bookkeeping that failed). Never throws — logging must not stop the tick.
     * @param Cron $cron
     * @param Throwable $throwable
     * @return void
     */
    protected function logCronExecutionFailure(Cron $cron, Throwable $throwable): void
    {
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable(
                $throwable,
                LogLevel::ERROR,
                ['cronName' => $cron->name ?? null, 'cronId' => $cron->id ?? null],
                false,
                IssuesLogService::LOG_MESSAGE_SECTION_CLI
            );
        } catch (Throwable) {
            error_log('Cron ' . ($cron->name ?? '?') . ' failed: ' . $throwable->getMessage());
        }
    }
}