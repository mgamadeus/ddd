<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Issues;

use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\Query\IssueEvent;
use DDD\Infrastructure\Logging\Query\IssueEventSearch;
use DDD\Infrastructure\Logging\SourceMaps\ResolvedFrame;
use DDD\Infrastructure\Logging\SourceMaps\SourceMapResolver;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * One error group: the registry row, its issues (one per entry point) and the newest events from the log backend with
 * their full context (stack trace, request) — what an agent needs to fix the cause.
 */
#[AsCommand(
    name: 'app:issues:show',
    description: 'Shows one error group: registry data, issues per entry point, newest events with full context',
)]
class IssuesShow extends IssuesCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('groupHash', InputArgument::REQUIRED, 'error.group_hash of the group')
            ->addEnvironmentOption()
            ->addOption('events', null, InputOption::VALUE_REQUIRED, 'Newest events to fetch from the log backend', '3')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Event search window (30m, 24h, 7d or a date)', '7d')
            ->addOption('resolve', null, InputOption::VALUE_NONE, 'Client errors: resolve the minified frames through the source maps of the event\'s release (resolvedFrames, resolvedStackTrace)')
            ->addFormatOption();
    }

    protected function executeIssuesCommand(InputInterface $input, OutputInterface $output): int
    {
        $issueGroup = $this->getGroupByHash((string)$input->getArgument('groupHash'), $this->getEnvironmentOption($input));
        /** @var IssuesRegistryJobService $issuesRegistryJobService */
        $issuesRegistryJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        $eventPayloads = [];
        $eventsError = null;
        $eventLimit = max(0, (int)$input->getOption('events'));
        if ($eventLimit > 0 && $issuesRegistryJobService->getSettings()->hasQueryBackend()) {
            try {
                // the events of the group's environment, from that environment's selector
                $issueEvents = $issuesRegistryJobService->getQueryBackend($issueGroup->environment)->searchEvents(new IssueEventSearch(
                    $this->parseTime((string)$input->getOption('since')),
                    DateTime::fromTimestamp(time()),
                    groupHash: $issueGroup->groupHash,
                    limit: $eventLimit,
                ));
                foreach ($issueEvents->getElements() as $issueEvent) {
                    $eventPayload = $this->toEventPayload($issueEvent, $issueGroup->environment);
                    if ($input->getOption('resolve')) {
                        $eventPayload += $this->toResolvedFramesPayload($issueEvent);
                    }
                    $eventPayloads[] = $eventPayload;
                }
            } catch (Throwable $throwable) {
                $eventsError = $throwable->getMessage();
            }
        }
        $issuePayloads = [];
        foreach ($issueGroup->issues->getElements() as $issue) {
            $issuePayloads[] = $this->toPayload($issue);
        }
        $groupPayload = $this->toGroupPayload($issueGroup);
        $groupPayload['link'] = $issuesRegistryJobService->getSettings()->getAlertLink($issueGroup->groupHash, $issueGroup->environment);
        if ($this->isJsonFormat($input)) {
            $this->writeJson($output, [
                'environment' => $issueGroup->environment,
                'workspace' => $issueGroup->workspace,
                'group' => $groupPayload,
                'issues' => $issuePayloads,
                'events' => $eventPayloads,
                'eventsError' => $eventsError,
            ]);
            return Command::SUCCESS;
        }
        $output->writeln((string)json_encode($groupPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $output->writeln('issues:');
        foreach ($issueGroup->issues->getElements() as $issue) {
            $output->writeln(sprintf('  %s  %s  %d events', $issue->issueHash, $issue->entryPoint ?? '-', $issue->eventCount));
        }
        $output->writeln('newest events:');
        foreach ($eventPayloads as $eventPayload) {
            $resolvedStackTrace = $eventPayload['resolvedStackTrace'] ?? null;
            unset($eventPayload['resolvedStackTrace'], $eventPayload['resolvedFrames']);
            $output->writeln((string)json_encode($eventPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($resolvedStackTrace !== null) {
                $output->writeln('resolved stack trace:');
                $output->writeln($resolvedStackTrace);
            }
        }
        if ($eventsError !== null) {
            $this->getErrorOutput($output)->writeln('<error>event search failed: ' . $eventsError . '</error>');
        }
        return Command::SUCCESS;
    }

    /**
     * The source-map resolution of a client event: `resolvedFrames` (one object per frame) and `resolvedStackTrace`;
     * nothing for a backend event.
     *
     * @return array<string, mixed> json_encode input only
     */
    protected function toResolvedFramesPayload(IssueEvent $issueEvent): array
    {
        $clientIssueFrames = $issueEvent->getClientFrames();
        if ($clientIssueFrames->count() === 0) {
            return [];
        }
        /** @var SourceMapResolver $sourceMapResolver */
        $sourceMapResolver = DDDService::instance()->getService(SourceMapResolver::class);
        $resolvedFrames = $sourceMapResolver->resolveFrames((string)$issueEvent->release, $clientIssueFrames);
        return [
            'resolvedFrames' => array_map(fn(ResolvedFrame $resolvedFrame): array => $this->toPayload($resolvedFrame), array_values($resolvedFrames->getElements())),
            'resolvedStackTrace' => implode("\n", $resolvedFrames->toStackTraceLines()),
        ];
    }
}
