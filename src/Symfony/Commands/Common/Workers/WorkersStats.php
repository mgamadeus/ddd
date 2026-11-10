<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Common\Entities\Workers\WorkerStatBucket;
use DDD\Domain\Common\Entities\Workers\WorkerStatBuckets;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workers:stats',
    description: 'Shows what the workers handled per transport and message class in a time window (worker monitoring): counts, failures, duration, wait'
)]
class WorkersStats extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Only this transport')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Window in minutes back from now', '60')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sinceMinutes = max(1, (int)$input->getOption('since'));
        $transport = $input->getOption('transport');
        $transport = is_string($transport) && $transport !== '' ? $transport : null;
        $now = time();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $workerStatBuckets = WorkerStatBuckets::getService()->findWorkerStatBucketsInWindow(
                DateTime::fromTimestamp($now - $sinceMinutes * 60),
                DateTime::fromTimestamp($now),
                $transport
            );
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        /** @var WorkerStatBucket[] $totalsByTransportAndClass */
        $totalsByTransportAndClass = [];
        foreach ($workerStatBuckets->getElements() as $workerStatBucket) {
            $totalKey = $workerStatBucket->transport . '|' . $workerStatBucket->messageClass;
            if (!isset($totalsByTransportAndClass[$totalKey])) {
                $total = new WorkerStatBucket();
                $total->transport = $workerStatBucket->transport;
                $total->messageClass = $workerStatBucket->messageClass;
                $totalsByTransportAndClass[$totalKey] = $total;
            }
            $total = $totalsByTransportAndClass[$totalKey];
            $total->handledCount += $workerStatBucket->handledCount;
            $total->thrownCount += $workerStatBucket->thrownCount;
            $total->caughtCount += $workerStatBucket->caughtCount;
            $total->redeliveredCount += $workerStatBucket->redeliveredCount;
            $total->durationTotalMs += $workerStatBucket->durationTotalMs;
            $total->durationMaxMs = max($total->durationMaxMs, $workerStatBucket->durationMaxMs);
            $total->waitCount += $workerStatBucket->waitCount;
            $total->waitTotalMs += $workerStatBucket->waitTotalMs;
            $total->waitMaxMs = max($total->waitMaxMs, $workerStatBucket->waitMaxMs);
        }
        ksort($totalsByTransportAndClass);
        $totals = new WorkerStatBuckets();
        foreach ($totalsByTransportAndClass as $total) {
            $totals->add($total);
        }
        if ($input->getOption('format') === 'json') {
            $output->writeln((string)json_encode($totals->toObject(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        $symfonyStyle->text(sprintf('Last %d minute(s)%s', $sinceMinutes, $transport !== null ? ', transport ' . $transport : ''));
        $symfonyStyle->table(
            ['Transport', 'Message class', 'Handled', 'Thrown', 'Caught', 'Redelivered', 'Avg ms', 'Max ms', 'Avg wait ms', 'Max wait ms'],
            array_map(
                static fn(WorkerStatBucket $total): array => [
                    $total->transport,
                    $total->messageClass,
                    $total->handledCount,
                    $total->thrownCount,
                    $total->caughtCount,
                    $total->redeliveredCount,
                    $total->handledCount > 0 ? intdiv($total->durationTotalMs, $total->handledCount) : '',
                    $total->durationMaxMs,
                    $total->waitCount > 0 ? intdiv($total->waitTotalMs, $total->waitCount) : '',
                    $total->waitCount > 0 ? $total->waitMaxMs : '',
                ],
                $totals->getElements()
            )
        );
        return Command::SUCCESS;
    }
}
