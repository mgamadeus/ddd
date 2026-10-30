<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\RuntimeErrors;

use DDD\Domain\Common\Services\RuntimeErrorsService;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorAggregationQuery;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorDetailAggregationQuery;
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
 * `--group=<key>` reproduces ONE group instead (the detail query): `{"filters", "group", "occurrences"}` with the newest
 * `--sampleSize` occurrences, each with frames, causes, route parameters and the worker / console fields.
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

    /** Occurrences printed with `--group` */
    public const int DEFAULT_GROUP_SAMPLE_SIZE = 3;

    public const array GROUPING_DIMENSIONS = [
        RuntimeErrorAggregationQuery::DIMENSION_COARSE,
        RuntimeErrorAggregationQuery::DIMENSION_FINGERPRINT,
        RuntimeErrorAggregationQuery::DIMENSION_GROUP_HASH,
    ];

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
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of distinct error groups to return.', (string)self::DEFAULT_LIMIT)
            ->addOption(
                'group',
                null,
                InputOption::VALUE_OPTIONAL,
                'Reproduce ONE group: its key in --groupingDimension (COARSE: "<error type>|<throw-site file>", FINGERPRINT: error.fingerprint_short, GROUP_HASH: error.group_hash). Prints the group and its newest occurrences; --account, --location, --minutes and --limit do not apply.'
            )
            ->addOption(
                'groupingDimension',
                null,
                InputOption::VALUE_OPTIONAL,
                'Dimension of --group: ' . implode(', ', self::GROUPING_DIMENSIONS) . '.',
                RuntimeErrorAggregationQuery::DEFAULT_DIMENSION
            )
            ->addOption(
                'sampleSize',
                null,
                InputOption::VALUE_OPTIONAL,
                'Occurrences printed with --group (1 … ' . RuntimeErrorDetailAggregationQuery::MAX_SAMPLE_SIZE . ').',
                (string)self::DEFAULT_GROUP_SAMPLE_SIZE
            );
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
        $groupKey = trim((string)($input->getOption('group') ?? ''));
        if ($groupKey !== '') {
            $groupingDimension = strtoupper((string)($input->getOption('groupingDimension') ?? RuntimeErrorAggregationQuery::DEFAULT_DIMENSION));
            if (!in_array($groupingDimension, self::GROUPING_DIMENSIONS, true)) {
                $errorOutput->writeln(sprintf(
                    '<error>Unknown --groupingDimension value "%s". Valid values: %s</error>',
                    $groupingDimension,
                    implode(', ', self::GROUPING_DIMENSIONS)
                ));
                return Command::INVALID;
            }
            $sampleSize = max(1, min(
                RuntimeErrorDetailAggregationQuery::MAX_SAMPLE_SIZE,
                (int)($input->getOption('sampleSize') ?? self::DEFAULT_GROUP_SAMPLE_SIZE)
            ));
            try {
                $runtimeErrorGroupDetail = $runtimeErrorsService->findErrorGroupDetail(
                    $groupKey,
                    $lookBackHours,
                    $logLevels,
                    null,
                    $sampleSize,
                    $groupingDimension,
                    $logstashEnvironment,
                    $workspace
                );
            } catch (Throwable $throwable) {
                $errorOutput->writeln(sprintf('<error>ES query failed: [%s] %s</error>', get_class($throwable), $throwable->getMessage()));
                return Command::FAILURE;
            }
            $occurrencePayloads = [];
            foreach ($runtimeErrorGroupDetail->recentOccurrences->getElements() as $runtimeErrorOccurrence) {
                $occurrencePayloads[] = $runtimeErrorOccurrence->toObject();
            }
            $output->writeln((string)json_encode([
                'filters' => [
                    'environment' => $logstashEnvironment,
                    'workspace' => $workspace,
                    'group' => $groupKey,
                    'groupingDimension' => $groupingDimension,
                    'hours' => $lookBackHours,
                    'levels' => $logLevels,
                    'sampleSize' => $sampleSize,
                ],
                'group' => $runtimeErrorGroupDetail->group->count > 0 ? $this->buildErrorGroupPayload($runtimeErrorGroupDetail->group) : null,
                'occurrences' => $occurrencePayloads,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }
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
