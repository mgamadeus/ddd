<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Issues;

use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs the issues registry job ({@see IssuesRegistryJobService}): once (a cron every minute) or with `--loop` as a
 * long-running worker. One instance at a time (a database lock); a failed run is caught up by the next one.
 */
#[AsCommand(
    name: self::COMMAND_NAME,
    description: 'Runs the issues registry job: reads the log backend, registers new error groups, counts events, sends alerts',
    aliases: [self::DEPRECATED_COMMAND_NAME],
)]
class IssuesSync extends IssuesCommand
{
    public const string COMMAND_NAME = 'app:issues:sync-registry-from-log-backend';

    /** The former name, kept as an alias for running deployments — deprecated */
    public const string DEPRECATED_COMMAND_NAME = 'app:issues:sync';

    public const int DEFAULT_INTERVAL_SECONDS = 60;

    protected function configure(): void
    {
        $this
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Run forever, one run per interval')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between two runs with --loop', (string)self::DEFAULT_INTERVAL_SECONDS)
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Seed mode for this many hours from now: register groups without alerts')
            ->addFormatOption();
    }

    protected function executeIssuesCommand(InputInterface $input, OutputInterface $output): int
    {
        /** @var IssuesRegistryJobService $issuesRegistryJobService */
        $issuesRegistryJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        $seedHours = $input->getOption('seed') !== null ? max(0, (int)$input->getOption('seed')) : null;
        if (!$input->getOption('loop')) {
            return $this->runOnce($issuesRegistryJobService, $seedHours, $input, $output);
        }
        $intervalSeconds = max(5, (int)$input->getOption('interval'));
        while (true) {
            $startedAt = time();
            $this->runOnce($issuesRegistryJobService, $seedHours, $input, $output);
            $seedHours = null;
            sleep(max(1, $intervalSeconds - (time() - $startedAt)));
        }
    }

    protected function runOnce(
        IssuesRegistryJobService $issuesRegistryJobService,
        ?int $seedHours,
        InputInterface $input,
        OutputInterface $output
    ): int {
        try {
            $issuesRegistryJobResult = $issuesRegistryJobService->run(seedHours: $seedHours);
        } catch (Throwable $throwable) {
            $this->getErrorOutput($output)->writeln('<error>issues sync failed: ' . $throwable->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if ($this->isJsonFormat($input)) {
            $this->writeJson($output, $this->toPayload($issuesRegistryJobResult));
            return Command::SUCCESS;
        }
        if ($issuesRegistryJobResult->skippedReason !== null) {
            $output->writeln('skipped: ' . $issuesRegistryJobResult->skippedReason);
            return Command::SUCCESS;
        }
        $output->writeln(sprintf(
            '%s groups new, %d issues new, %d regressions, %d events counted, %d alerts sent, %d failed%s%s',
            $issuesRegistryJobResult->groupsCreated,
            $issuesRegistryJobResult->issuesCreated,
            $issuesRegistryJobResult->regressions,
            $issuesRegistryJobResult->eventsCounted,
            $issuesRegistryJobResult->alertsSent,
            $issuesRegistryJobResult->alertsFailed,
            $issuesRegistryJobResult->seedMode ? ' (seed mode)' : '',
            $issuesRegistryJobResult->explosion ? ' (explosion: new groups not alerted one by one)' : ''
        ));
        return Command::SUCCESS;
    }
}
