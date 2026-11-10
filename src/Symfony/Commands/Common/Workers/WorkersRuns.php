<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRun;
use DDD\Domain\Common\Entities\Workers\WorkerMessageRuns;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workers:runs',
    description: 'Lists consumed messages (message runs), newest first: class, transport, process, start, duration, wait, outcome, attempt, error and where the message was dispatched from; filter by outcome, class, process or age'
)]
class WorkersRuns extends WorkersReport
{
    /** Filter value for "caught or thrown" */
    public const string OUTCOME_FAILED = 'FAILED';

    protected function configure(): void
    {
        $this
            ->addArgument('transport', InputArgument::OPTIONAL, 'Only the runs of this transport')
            ->addOption('outcome', null, InputOption::VALUE_REQUIRED, 'HANDLED, CAUGHT, THROWN or FAILED (caught or thrown)')
            ->addOption('messageClass', null, InputOption::VALUE_REQUIRED, 'Only this message class; * is a wildcard ("*ImportMessage")')
            ->addOption('processKey', null, InputOption::VALUE_REQUIRED, 'Only the runs of this worker process')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only runs started within this many minutes')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Most runs to list', (string)self::DEFAULT_LIMIT)
            ->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        try {
            /** @var WorkerMessageRuns $workerMessageRuns */
            $workerMessageRuns = $this->readWithoutRightsRestrictions(fn() => $this->findWorkerMessageRuns($input));
        } catch (BadRequestException $exception) {
            $symfonyStyle->getErrorStyle()->error($exception->getMessage());
            return Command::FAILURE;
        }
        if ($this->isJsonFormat($input)) {
            $this->writeJsonPayload($output, 'workerMessageRuns', $workerMessageRuns);
            return Command::SUCCESS;
        }
        $this->noteWhenMonitoringIsOff($symfonyStyle);
        $symfonyStyle->table(
            ['Started (UTC)', 'Transport', 'Message class', 'Outcome', 'Attempt', 'ms', 'Wait ms', 'Dispatched from', 'Process', 'Error'],
            array_map(
                fn(WorkerMessageRun $workerMessageRun): array => [
                    $this->formatDateTime($workerMessageRun->startedAt ?? null),
                    $workerMessageRun->transport ?? '',
                    $workerMessageRun->messageClass ?? '',
                    ($workerMessageRun->outcome ?? '') . (($workerMessageRun->willRetry ?? null) === true ? ' (retried)' : ''),
                    $workerMessageRun->attempt,
                    $workerMessageRun->durationMs,
                    $workerMessageRun->waitMs ?? '',
                    $workerMessageRun->dispatchedFromRoute ?? $workerMessageRun->dispatchedFromCommand ?? '',
                    $workerMessageRun->processKey ?? '',
                    ($workerMessageRun->errorClass ?? null) !== null
                        ? $workerMessageRun->errorClass . ': ' . mb_strimwidth((string)($workerMessageRun->errorMessage ?? ''), 0, 120, '…')
                        : '',
                ],
                $workerMessageRuns->getElements()
            )
        );
        $symfonyStyle->text(sprintf('%d run(s)', $workerMessageRuns->count()));
        return Command::SUCCESS;
    }

    /**
     * @throws BadRequestException When a filter value is not valid
     */
    protected function findWorkerMessageRuns(InputInterface $input): WorkerMessageRuns
    {
        $conditions = [];
        if (($outcome = $this->getStringOption($input, 'outcome')) !== null) {
            $outcome = strtoupper($outcome);
            $conditions[] = $outcome === self::OUTCOME_FAILED
                ? 'outcome in [' . implode(',', array_map($this->quoteFilterValue(...), WorkerMessageRun::FAILED_OUTCOMES)) . ']'
                : 'outcome eq ' . $this->quoteFilterValue($outcome);
        }
        if (($messageClass = $this->getStringOption($input, 'messageClass')) !== null) {
            $conditions[] = 'messageClass eq ' . $this->quoteFilterValue($messageClass);
        }
        if (($processKey = $this->getStringOption($input, 'processKey')) !== null) {
            $conditions[] = 'processKey eq ' . $this->quoteFilterValue($processKey);
        }
        if ($this->getStringOption($input, 'since') !== null) {
            $conditions[] = 'startedAt ge ' . $this->quoteFilterValue(gmdate('Y-m-d H:i:s', time() - $this->getSinceMinutes($input) * 60));
        }
        $transport = $this->getStringArgument($input, 'transport');
        WorkerMessageRuns::setDefaultQueryOptionsSnapshot();
        try {
            $queryOptions = WorkerMessageRuns::getDefaultQueryOptions();
            $filters = $conditions !== [] ? FiltersOptions::fromString(implode(' and ', $conditions)) : null;
            $queryOptions->setFilters($filters);
            $newestFirst = OrderByOptions::fromString('startedAt desc');
            $queryOptions->setOrderBy($newestFirst);
            $queryOptions->setTop($this->getLimit($input));
            $workerMessageRunsService = WorkerMessageRuns::getService();
            return $transport !== null
                ? $workerMessageRunsService->findWorkerMessageRunsOfTransport($transport)
                : ($workerMessageRunsService->findAll() ?? new WorkerMessageRuns());
        } finally {
            WorkerMessageRuns::restoreDefaultQueryOptionsSnapshot();
        }
    }
}
