<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\RuntimeErrors;

use DDD\Domain\Common\Services\RuntimeErrorsService;
use DDD\Infrastructure\Logging\Query\Elasticsearch\ElasticsearchIssuesQueryBackend;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Smoke test of the read-only Elasticsearch / OpenSearch access: the error hit count of the window, the top
 * fingerprint groups, the trend buckets, then which grouping works on this index — `multi_terms` (ES ≥ 7.12 /
 * OpenSearch ≥ 2.1) and which field names aggregate (bare or keyword sub-field). Each probe runs on its own; a failing
 * one does not stop the others. An application that defines a command of the same name keeps its own.
 */
#[AsCommand(
    name: self::COMMAND_NAME,
    description: 'Smoke-test the read-only ES client against the Symfony error index; prints hit count + top groups + trend buckets.',
)]
class RuntimeErrorsProbe extends Command
{
    public const string COMMAND_NAME = 'app:runtime-errors:probe';

    protected function configure(): void
    {
        $this
            ->addOption('hours', null, InputOption::VALUE_OPTIONAL, 'Look-back window in hours', '24')
            ->addOption('size', null, InputOption::VALUE_OPTIONAL, 'Number of top fingerprint groups to show', '5')
            ->addOption('compositeSize', null, InputOption::VALUE_OPTIONAL, 'Top N buckets to print for the composite grouping probe', '10')
            ->addOption('index', null, InputOption::VALUE_OPTIONAL, 'Override the index pattern to query')
            ->addOption('full-error-body', null, InputOption::VALUE_NONE, 'Print the whole Elasticsearch error response instead of its first 500 characters');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var RuntimeErrorsService $runtimeErrorsService */
        $runtimeErrorsService = DDDService::instance()->getService(RuntimeErrorsService::class);
        $elasticsearchIssuesQueryBackend = $runtimeErrorsService->getElasticsearchIssuesQueryBackend();
        if (!$input->getOption('full-error-body')) {
            return $this->runProbes($input, $output, $elasticsearchIssuesQueryBackend);
        }
        // the backend is shared: the full body holds for this probe only
        $elasticsearchIssuesQueryBackend->setErrorBodyMaxLength(null);
        try {
            return $this->runProbes($input, $output, $elasticsearchIssuesQueryBackend);
        } finally {
            $elasticsearchIssuesQueryBackend->setErrorBodyMaxLength(ElasticsearchIssuesQueryBackend::DEFAULT_ERROR_BODY_MAX_LENGTH);
        }
    }

    protected function runProbes(InputInterface $input, OutputInterface $output, ElasticsearchIssuesQueryBackend $elasticsearchIssuesQueryBackend): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $lookBackHours = (int)$input->getOption('hours');
        $topGroupsSize = (int)$input->getOption('size');
        $compositeProbeSize = (int)$input->getOption('compositeSize');
        try {
            $indexPattern = $input->getOption('index') ?? $elasticsearchIssuesQueryBackend->getDefaultLogIndexPattern();
        } catch (Throwable $throwable) {
            $symfonyStyle->error($throwable->getMessage());
            return Command::FAILURE;
        }
        $fieldNames = $elasticsearchIssuesQueryBackend->getFieldNames();

        $symfonyStyle->title('Runtime errors ES probe');
        $symfonyStyle->text("Index pattern: <info>{$indexPattern}</info>");
        $symfonyStyle->text("Window: last <info>{$lookBackHours}h</info>, ERROR + CRITICAL");

        try {
            $response = $elasticsearchIssuesQueryBackend->search($indexPattern, [
                'size' => 0,
                'query' => ['bool' => ['filter' => $this->buildSharedFilter($elasticsearchIssuesQueryBackend, $lookBackHours)]],
                'aggs' => [
                    'by_fingerprint' => [
                        'terms' => [
                            'field' => $fieldNames->keyword('error.fingerprint_short'),
                            'size' => $topGroupsSize,
                            'order' => ['_count' => 'desc'],
                        ],
                        'aggs' => ['affected_locations' => ['cardinality' => ['field' => $fieldNames->locationField]]],
                    ],
                    'trend' => ['date_histogram' => ['field' => '@timestamp', 'fixed_interval' => '1h']],
                ],
            ]);
        } catch (Throwable $throwable) {
            $symfonyStyle->error('ES probe failed: ' . $throwable->getMessage());
            $symfonyStyle->note(get_class($throwable));
            return Command::FAILURE;
        }
        $totalHitCount = $response['hits']['total']['value'] ?? null;
        if ($totalHitCount === null) {
            $symfonyStyle->error('Unexpected ES response shape: no hits.total.value present.');
            return Command::FAILURE;
        }
        $fingerprintBuckets = $response['aggregations']['by_fingerprint']['buckets'] ?? [];
        $symfonyStyle->section('Result');
        $symfonyStyle->text("Total ERROR/CRITICAL hits: <info>{$totalHitCount}</info>");
        $symfonyStyle->text('Trend buckets: <info>' . count($response['aggregations']['trend']['buckets'] ?? []) . '</info>');
        if ($fingerprintBuckets !== []) {
            $symfonyStyle->section('Top fingerprint groups');
            $tableRows = [];
            foreach ($fingerprintBuckets as $fingerprintBucket) {
                $tableRows[] = [
                    (string)($fingerprintBucket['key'] ?? '-'),
                    (string)($fingerprintBucket['doc_count'] ?? 0),
                    (string)($fingerprintBucket['affected_locations']['value'] ?? 0),
                ];
            }
            $symfonyStyle->table(['fingerprint_short', 'count', 'affected locations'], $tableRows);
        }
        $this->probeCompositeGrouping($symfonyStyle, $elasticsearchIssuesQueryBackend, $indexPattern, $lookBackHours, $compositeProbeSize);
        $symfonyStyle->success('ES probe returned a live hit count. See the grouping read-off above.');
        return Command::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildSharedFilter(ElasticsearchIssuesQueryBackend $elasticsearchIssuesQueryBackend, int $lookBackHours): array
    {
        return [
            ['range' => ['@timestamp' => ['gte' => 'now-' . $lookBackHours . 'h']]],
            ['terms' => [$elasticsearchIssuesQueryBackend->getFieldNames()->keyword('log.level') => ['ERROR', 'CRITICAL']]],
        ];
    }

    protected function probeCompositeGrouping(
        SymfonyStyle $symfonyStyle,
        ElasticsearchIssuesQueryBackend $elasticsearchIssuesQueryBackend,
        string $indexPattern,
        int $lookBackHours,
        int $compositeProbeSize
    ): void {
        $symfonyStyle->section('Composite grouping probe');
        $symfonyStyle->text('Each probe runs in isolation; a FAIL on one field does NOT abort the others.');
        $symfonyStyle->newLine();
        $symfonyStyle->text('<comment>multi_terms availability + composite collapse (needs ES >= 7.12 / OpenSearch >= 2.1):</comment>');
        foreach (['', '.keyword'] as $suffix) {
            $this->runAggregationProbe(
                $symfonyStyle,
                $elasticsearchIssuesQueryBackend,
                $indexPattern,
                $lookBackHours,
                sprintf('multi_terms [error.type%1$s, log.origin.file.name%1$s] (missing "—")', $suffix),
                ['multi_terms' => [
                    'terms' => [
                        ['field' => 'error.type' . $suffix, 'missing' => '—'],
                        ['field' => 'log.origin.file.name' . $suffix, 'missing' => '—'],
                    ],
                    'size' => $compositeProbeSize,
                    'order' => ['_count' => 'desc'],
                ]],
                $compositeProbeSize
            );
        }
        $symfonyStyle->newLine();
        $symfonyStyle->text('<comment>single-field aggregability (which exact field name aggregates):</comment>');
        foreach (['error.type', 'error.type.keyword', 'log.origin.file.name', 'log.origin.file.name.keyword'] as $probeField) {
            $this->runAggregationProbe(
                $symfonyStyle,
                $elasticsearchIssuesQueryBackend,
                $indexPattern,
                $lookBackHours,
                'terms ' . $probeField,
                ['terms' => ['field' => $probeField, 'size' => 5, 'order' => ['_count' => 'desc']]],
                5
            );
        }
        $symfonyStyle->newLine();
        $symfonyStyle->note([
            'Read-off:',
            '• multi_terms OK with buckets => the COARSE (type, file) grouping works on this cluster.',
            '• whichever of error.type / error.type.keyword returns buckets is the field to use:',
            '  bare = ISSUES_ES_KEYWORD_SUFFIX="" (mapped as keyword), .keyword = the default.',
            '• a "—" key in the multi_terms output confirms the missing sentinel retains field-less docs.',
        ]);
    }

    /**
     * @param array<string, mixed> $aggregation
     */
    protected function runAggregationProbe(
        SymfonyStyle $symfonyStyle,
        ElasticsearchIssuesQueryBackend $elasticsearchIssuesQueryBackend,
        string $indexPattern,
        int $lookBackHours,
        string $probeLabel,
        array $aggregation,
        int $sampleSize
    ): void {
        try {
            $response = $elasticsearchIssuesQueryBackend->search($indexPattern, [
                'size' => 0,
                'query' => ['bool' => ['filter' => $this->buildSharedFilter($elasticsearchIssuesQueryBackend, $lookBackHours)]],
                'aggs' => ['probe' => $aggregation],
            ]);
        } catch (Throwable $throwable) {
            $symfonyStyle->writeln(" <fg=red>FAIL</> {$probeLabel}");
            $symfonyStyle->writeln('       ' . get_class($throwable) . ': ' . $throwable->getMessage());
            return;
        }
        $probeBuckets = $response['aggregations']['probe']['buckets'] ?? [];
        $symfonyStyle->writeln(" <fg=green>OK</>   {$probeLabel} — " . count($probeBuckets) . ' bucket(s)');
        foreach (array_slice($probeBuckets, 0, $sampleSize) as $probeBucket) {
            $rawKey = $probeBucket['key'] ?? null;
            $keyDisplay = is_array($rawKey)
                ? implode(' | ', array_map(static fn(mixed $keyPart): string => (string)$keyPart, $rawKey))
                : (string)($rawKey ?? '-');
            $symfonyStyle->writeln('       [' . ($probeBucket['doc_count'] ?? 0) . "] {$keyDisplay}");
        }
    }
}
