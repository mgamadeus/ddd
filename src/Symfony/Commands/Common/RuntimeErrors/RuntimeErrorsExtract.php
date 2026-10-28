<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\RuntimeErrors;

use DDD\Domain\Common\Services\RuntimeErrorsService;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorAggregationQuery;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorGroup;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The distinct runtime errors of an Elasticsearch / OpenSearch log index by criteria, as JSON on stdout (nothing else
 * on stdout; messages go to stderr) — for an agent's fix loop:
 * `app:runtime-errors:extract --logstashEnv=DEVELOPMENT --account=4183511 --hours=720 --limit=5`.
 *
 * Unknown `--logstashEnv` = exit 2 (INVALID), a failed query = exit 1. `--env` is the kernel's, hence `--logstashEnv`.
 * An application that defines a command of the same name keeps its own ({@see RuntimeErrorsCommandsYieldPass}).
 */
#[AsCommand(
    name: self::COMMAND_NAME,
    description: 'Extracts distinct ES runtime errors by criteria (env/account/location/time/level) as structured JSON on stdout.',
)]
class RuntimeErrorsExtract extends Command
{
    public const string COMMAND_NAME = 'app:runtime-errors:extract';

    public const int DEFAULT_LIMIT = 20;

    protected function configure(): void
    {
        $this
            ->addOption(
                'logstashEnv',
                null,
                InputOption::VALUE_OPTIONAL,
                'Logstash environment: PRODUCTION or DEVELOPMENT. (Note: --env is reserved by the Symfony kernel.)',
                RuntimeErrorAggregationQuery::DEFAULT_ENVIRONMENT
            )
            ->addOption('account', null, InputOption::VALUE_OPTIONAL, 'Filter to this account ID (integer, numeric ES field account.id). Omit for all accounts.')
            ->addOption('location', null, InputOption::VALUE_OPTIONAL, 'Filter to this location ID (integer, numeric ES location field). Omit for all locations.')
            ->addOption('hours', null, InputOption::VALUE_OPTIONAL, 'Look-back window in hours.', (string)RuntimeErrorAggregationQuery::DEFAULT_HOURS)
            ->addOption(
                'minutes',
                null,
                InputOption::VALUE_OPTIONAL,
                'Look-back window in MINUTES — OVERRIDES --hours. For tight agentic debugging: errors of the last N minutes right after you ran something (e.g. --minutes=5).'
            )
            ->addOption(
                'workspace',
                null,
                InputOption::VALUE_OPTIONAL,
                'Restrict to ONE dev workspace (workspace keyword term, e.g. mgn). Omit for all. Pair with --logstashEnv=DEVELOPMENT.'
            )
            ->addOption(
                'level',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated log levels to include (e.g. ERROR,CRITICAL).',
                implode(',', RuntimeErrorAggregationQuery::DEFAULT_LEVELS)
            )
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of distinct error groups to return.', (string)self::DEFAULT_LIMIT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $logstashEnvironment = (string)($input->getOption('logstashEnv') ?? RuntimeErrorAggregationQuery::DEFAULT_ENVIRONMENT);
        if (!in_array($logstashEnvironment, RuntimeErrorAggregationQuery::ENVIRONMENTS, true)) {
            $errorOutput->writeln(sprintf(
                '<error>Unknown --logstashEnv value "%s". Valid values: %s</error>',
                $logstashEnvironment,
                implode(', ', RuntimeErrorAggregationQuery::ENVIRONMENTS)
            ));
            return Command::INVALID;
        }
        $accountId = $input->getOption('account') !== null ? (int)$input->getOption('account') : null;
        $locationId = $input->getOption('location') !== null ? (int)$input->getOption('location') : null;
        $lookBackHours = (int)($input->getOption('hours') ?? RuntimeErrorAggregationQuery::DEFAULT_HOURS);
        $lookBackMinutes = $input->getOption('minutes') !== null ? (int)$input->getOption('minutes') : null;
        $logLevels = RuntimeErrorAggregationQuery::parseLevels((string)($input->getOption('level') ?? ''));
        $groupsLimit = (int)($input->getOption('limit') ?? self::DEFAULT_LIMIT);
        $workspaceOption = $input->getOption('workspace');
        $workspace = $workspaceOption !== null && trim((string)$workspaceOption) !== '' ? trim((string)$workspaceOption) : null;

        /** @var RuntimeErrorsService $runtimeErrorsService */
        $runtimeErrorsService = DDDService::instance()->getService(RuntimeErrorsService::class);
        try {
            $runtimeErrorGroups = $runtimeErrorsService->findErrorGroups(
                $lookBackHours,
                $logLevels,
                $groupsLimit,
                RuntimeErrorAggregationQuery::DEFAULT_DIMENSION,
                $logstashEnvironment,
                $accountId,
                $locationId,
                workspace: $workspace,
                minutes: $lookBackMinutes
            );
        } catch (Throwable $throwable) {
            $errorOutput->writeln(sprintf('<error>ES query failed: [%s] %s</error>', get_class($throwable), $throwable->getMessage()));
            return Command::FAILURE;
        }
        $errorPayloads = [];
        foreach ($runtimeErrorGroups->getElements() as $runtimeErrorGroup) {
            $errorPayloads[] = $this->buildErrorGroupPayload($runtimeErrorGroup);
        }
        $output->writeln((string)json_encode([
            'filters' => [
                'environment' => $logstashEnvironment,
                'workspace' => $workspace,
                'accountId' => $accountId,
                'locationId' => $locationId,
                // the effective window: minutes override hours
                'window' => $lookBackMinutes !== null && $lookBackMinutes > 0 ? $lookBackMinutes . 'm' : $lookBackHours . 'h',
                'minutes' => $lookBackMinutes,
                'hours' => $lookBackHours,
                'levels' => $logLevels,
                'limit' => $groupsLimit,
            ],
            'count' => count($errorPayloads),
            'errors' => $errorPayloads,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return Command::SUCCESS;
    }

    /**
     * One group as the JSON payload: `throwSite` = `<file>:<line>`, `fingerprint` = the display fingerprint.
     *
     * @return array<string, mixed> json_encode input only
     */
    protected function buildErrorGroupPayload(RuntimeErrorGroup $runtimeErrorGroup): array
    {
        return [
            'errorType' => $runtimeErrorGroup->errorType,
            'message' => $runtimeErrorGroup->latestMessage,
            'throwSite' => $runtimeErrorGroup->getThrowSite(),
            'route' => $runtimeErrorGroup->route,
            'count' => $runtimeErrorGroup->count,
            'affectedLocationCount' => $runtimeErrorGroup->affectedLocationCount,
            'environment' => $runtimeErrorGroup->environment,
            'errorCode' => $runtimeErrorGroup->errorCode,
            'errorKind' => $runtimeErrorGroup->errorKind,
            'firstSeen' => $runtimeErrorGroup->firstSeenTimestamp,
            'lastSeen' => $runtimeErrorGroup->latestTimestamp,
            'fingerprint' => $runtimeErrorGroup->displayFingerprint,
            'stackTrace' => $runtimeErrorGroup->stackTrace,
        ];
    }
}
