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

/**
 * Whether the issues pipeline is alive — for a liveness probe or an external monitor: exit 1 when the job is stalled
 * (no successful run within ISSUES_JOB_STALE_MINUTES) or the collector silent (no event within
 * ISSUES_COLLECTOR_SILENT_MINUTES). Without a query backend there is nothing to watch: exit 0.
 */
#[AsCommand(
    name: self::COMMAND_NAME,
    description: 'Checks that the issues registry job runs and the log collector ships events (exit 1 when stalled or silent)',
    aliases: [self::DEPRECATED_COMMAND_NAME],
)]
class IssuesHealth extends IssuesCommand
{
    public const string COMMAND_NAME = 'app:issues:check-registry-job-and-collector-health';

    /** The former name, kept as an alias for running liveness probes — deprecated */
    public const string DEPRECATED_COMMAND_NAME = 'app:issues:health';

    /** Shown when no line arrived for a while: silence alone does not prove a broken pipeline */
    public const string COLLECTOR_SILENT_HINT = 'No line of any level arrived within ISSUES_COLLECTOR_SILENT_MINUTES. '
        . 'A quiet environment can be the reason; prove the path with `php bin/console app:issues:test` '
        . '(its line appears in app:issues:search within a minute), or raise ISSUES_COLLECTOR_SILENT_MINUTES.';

    protected function configure(): void
    {
        $this
            ->addOption('job-only', null, InputOption::VALUE_NONE, 'Exit code from the registry job only (for a liveness probe): a silent collector is reported, not failed')
            ->addFormatOption();
    }

    protected function executeIssuesCommand(InputInterface $input, OutputInterface $output): int
    {
        /** @var IssuesRegistryJobService $issuesRegistryJobService */
        $issuesRegistryJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        $issuesRegistryHealth = $issuesRegistryJobService->getHealth();
        if ($this->isJsonFormat($input)) {
            $payload = $this->toPayload($issuesRegistryHealth);
            $payload['healthy'] = $issuesRegistryHealth->isHealthy();
            if ($issuesRegistryHealth->collectorSilent) {
                $payload['collectorSilentHint'] = self::COLLECTOR_SILENT_HINT;
            }
            $this->writeJson($output, $payload);
        } else {
            $output->writeln([
                'backend: ' . ($issuesRegistryHealth->backendConfigured ? 'configured' : 'none (ISSUES_QUERY_BACKEND)'),
                'last successful run: ' . ($issuesRegistryHealth->lastSuccessfulRunAt?->format('Y-m-d H:i:s') ?? 'never'),
                'newest event seen: ' . ($issuesRegistryHealth->newestEventSeenAt?->format('Y-m-d H:i:s') ?? 'never'),
                'last error: ' . ($issuesRegistryHealth->lastError ?? '-'),
                'job stalled: ' . ($issuesRegistryHealth->jobStalled ? 'YES' : 'no'),
                'collector silent: ' . ($issuesRegistryHealth->collectorSilent ? 'YES — ' . self::COLLECTOR_SILENT_HINT : 'no'),
            ]);
        }
        if ($input->getOption('job-only')) {
            return $issuesRegistryHealth->backendConfigured && $issuesRegistryHealth->jobStalled ? Command::FAILURE : Command::SUCCESS;
        }
        return $issuesRegistryHealth->isHealthy() ? Command::SUCCESS : Command::FAILURE;
    }
}
