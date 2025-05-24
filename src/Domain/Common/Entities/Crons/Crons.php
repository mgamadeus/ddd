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
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

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
            $cronExecution = $cron->execute($timeoutPerCronInSeconds);
            if ($cronExecution) {
                $cronExecutions->add($cronExecution);
            }
        }
        return $cronExecutions;
    }
}