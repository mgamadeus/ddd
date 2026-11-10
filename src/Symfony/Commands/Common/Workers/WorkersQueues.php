<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Common\Entities\Workers\WorkerQueueCount;
use DDD\Domain\Common\Services\WorkerQueuesService;
use DDD\Domain\Common\Services\WorkerTransportsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workers:queues',
    description: 'Asks the broker how many messages wait in each consumed transport right now (ready, not yet delivered); writes nothing and works without the worker monitoring'
)]
class WorkersQueues extends WorkersReport
{
    protected function configure(): void
    {
        $this
            ->addArgument('transports', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Only these transports; default every consumed transport')
            ->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var WorkerTransportsService $workerTransportsService */
        $workerTransportsService = DDDService::instance()->getService(WorkerTransportsService::class);
        /** @var WorkerQueuesService $workerQueuesService */
        $workerQueuesService = DDDService::instance()->getService(WorkerQueuesService::class);
        /** @var string[] $transportNames */
        $transportNames = array_values(array_filter((array)$input->getArgument('transports'), 'is_string'));
        if ($transportNames === []) {
            $transportNames = $workerTransportsService->getConsumedTransportNames();
        }
        $workerQueueCounts = $workerQueuesService->countWaitingMessages($transportNames);
        if ($this->isJsonFormat($input)) {
            $this->writeJsonPayload($output, 'workerQueueCounts', $workerQueueCounts);
            return Command::SUCCESS;
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        $symfonyStyle->table(
            ['Transport', 'State', 'Waiting', 'Note'],
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
