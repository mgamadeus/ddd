<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Issues;

use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Domain\Common\Services\IssuesTrendService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The error trend from the log backend: gap-free buckets of error events, per environment or summed over all, for one
 * group or all errors — `app:issues:trend --environment=prod --since=24h --format=json`.
 */
#[AsCommand(
    name: 'app:issues:trend',
    description: 'Shows the error trend (error events per time bucket) of an environment or a group',
)]
class IssuesTrend extends IssuesCommand
{
    public const string STEP_AUTO = 'auto';

    protected function configure(): void
    {
        $this
            ->addEnvironmentOption('The environment (default: all configured environments, summed)')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Only this group (error.group_hash)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Window start (30m, 24h, 7d or a date)', '24h')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Window end (default: now)')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Bucket size: auto (1m ≤ 1h, 10m ≤ 6h, 1h ≤ 24h, else 6h), or e.g. 5m, 1h, 1d', self::STEP_AUTO)
            ->addFormatOption();
    }

    protected function executeIssuesCommand(InputInterface $input, OutputInterface $output): int
    {
        /** @var IssuesRegistryJobService $issuesRegistryJobService */
        $issuesRegistryJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        if (!$issuesRegistryJobService->getSettings()->hasQueryBackend()) {
            $this->getErrorOutput($output)->writeln('<error>No query backend configured (ISSUES_QUERY_BACKEND)</error>');
            return Command::FAILURE;
        }
        $from = $this->parseTime((string)$input->getOption('since'));
        $to = $this->parseTime($input->getOption('until')) ?? DateTime::fromTimestamp(time());
        $stepSeconds = $this->parseStep((string)$input->getOption('step'));
        /** @var IssuesTrendService $issuesTrendService */
        $issuesTrendService = DDDService::instance()->getService(IssuesTrendService::class);
        try {
            $issueTrend = $issuesTrendService->getTrend(
                $this->getEnvironmentOption($input),
                $input->getOption('group'),
                $from,
                $to,
                $stepSeconds
            );
        } catch (Throwable $throwable) {
            $this->getErrorOutput($output)->writeln('<error>' . $throwable->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if ($this->isJsonFormat($input)) {
            $bucketPayloads = [];
            foreach ($issueTrend->getElements() as $issueTrendBucket) {
                $bucketPayloads[] = ['start' => $issueTrendBucket->start->format('Y-m-d\TH:i:s\Z'), 'count' => $issueTrendBucket->count];
            }
            $this->writeJson($output, [
                'environment' => $issueTrend->environment,
                'groupHash' => $issueTrend->groupHash,
                'from' => gmdate('Y-m-d\TH:i:s\Z', $from->getTimestamp()),
                'to' => gmdate('Y-m-d\TH:i:s\Z', $to->getTimestamp()),
                'stepSeconds' => $issueTrend->stepSeconds,
                'total' => $issueTrend->getTotal(),
                'buckets' => $bucketPayloads,
            ]);
            return Command::SUCCESS;
        }
        $maximumCount = max(1, ...array_map(static fn($issueTrendBucket): int => $issueTrendBucket->count, $issueTrend->getElements()));
        foreach ($issueTrend->getElements() as $issueTrendBucket) {
            $output->writeln(sprintf(
                '%s  %6d  %s',
                gmdate('Y-m-d H:i', $issueTrendBucket->start->getTimestamp()),
                $issueTrendBucket->count,
                str_repeat('█', (int)ceil(40 * $issueTrendBucket->count / $maximumCount))
            ));
        }
        $output->writeln(sprintf('total %d, step %ds', $issueTrend->getTotal(), $issueTrend->stepSeconds));
        return Command::SUCCESS;
    }

    /**
     * @return int|null Seconds; null = automatic
     * @throws BadRequestException
     */
    protected function parseStep(string $step): ?int
    {
        if ($step === '' || $step === self::STEP_AUTO) {
            return null;
        }
        if (!preg_match('/^(\d+)\s*([smhd])$/i', trim($step), $matches) || (int)$matches[1] === 0) {
            throw new BadRequestException(sprintf('Invalid --step "%s" (auto, or e.g. 30s, 5m, 1h, 1d)', $step));
        }
        return (int)$matches[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($matches[2])];
    }
}
