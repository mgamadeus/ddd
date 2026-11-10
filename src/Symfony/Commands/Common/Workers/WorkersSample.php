<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Common\Entities\Workers\WorkerQueueCount;
use DDD\Domain\Common\Services\WorkerMonitoringService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:workers:sample',
    description: 'Samples the waiting messages and live worker processes of every consumed Messenger transport into the worker monitoring and prunes it. Run every minute (Cron)'
)]
class WorkersSample extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        /** @var WorkerMonitoringService $workerMonitoringService */
        $workerMonitoringService = DDDService::instance()->getService(WorkerMonitoringService::class);
        if (!$workerMonitoringService->getSettings()->enabled) {
            $symfonyStyle->text('The worker monitoring is switched off (Common.Workers enabled / WORKERS_ENABLED): nothing sampled.');
            return Command::SUCCESS;
        }
        try {
            $workerQueueCounts = $workerMonitoringService->sampleAllTransportsAndPrune();
        } catch (Throwable $throwable) {
            $symfonyStyle->getErrorStyle()->error('Sampling failed: ' . $throwable->getMessage());
            return Command::FAILURE;
        }
        $symfonyStyle->table(
            ['Transport', 'State', 'Waiting', 'Remark'],
            array_map(
                static fn(WorkerQueueCount $workerQueueCount): array => [
                    $workerQueueCount->transport,
                    $workerQueueCount->state,
                    $workerQueueCount->waiting ?? '',
                    $workerQueueCount->error ?? '',
                ],
                $workerQueueCounts->getElements()
            )
        );
        return Command::SUCCESS;
    }
}
