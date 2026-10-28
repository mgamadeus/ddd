<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Issues;

use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\Query\IssueEvent;
use DDD\Infrastructure\Logging\Query\IssueEventSearch;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Searches the events in the log backend directly (also those the registry does not group: warnings, other levels) —
 * e.g. right after an agent ran something on workspace mgn: `app:issues:search --workspace=mgn --since=5m --format=json`.
 * `--environment` picks one environment's selector (default: every configured environment, merged by time);
 * `--workspace` filters on the lines' `service.workspace`, also where no environment is configured for that workspace.
 */
#[AsCommand(
    name: 'app:issues:search',
    description: 'Searches issue events in the log backend (text, level, route, account, group, issue, time window)',
)]
class IssuesSearch extends IssuesCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('text', null, InputOption::VALUE_REQUIRED, 'Case-insensitive text in the line')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Comma-separated levels (default: error and above or uncaught)')
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Route name')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account id')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'error.group_hash')
            ->addOption('issue', null, InputOption::VALUE_REQUIRED, 'error.issue_hash')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Window start (30m, 24h, 7d or a date)', '1h')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Window end (default: now)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of events', '20')
            ->addOption('oldest-first', null, InputOption::VALUE_NONE, 'Oldest events first')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Only lines of this workspace (service.workspace, e.g. mgn)')
            ->addEnvironmentOption('Search this registry environment\'s selector only (default: all configured environments)')
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
        $levels = array_values(array_filter(array_map('trim', explode(',', (string)$input->getOption('level')))));
        $issueEventSearch = new IssueEventSearch(
            $this->parseTime((string)$input->getOption('since')),
            $this->parseTime($input->getOption('until')) ?? DateTime::fromTimestamp(time()),
            text: $input->getOption('text'),
            levels: $levels,
            routeName: $input->getOption('route'),
            accountId: $input->getOption('account'),
            groupHash: $input->getOption('group'),
            issueHash: $input->getOption('issue'),
            limit: max(1, (int)$input->getOption('limit')),
            oldestFirst: (bool)$input->getOption('oldest-first'),
            workspace: $input->getOption('workspace'),
        );
        $environment = $this->getEnvironmentOption($input);
        $environments = $environment !== null ? [$environment] : $issuesRegistryJobService->getEnvironmentNames();
        /** @var array<int, array{0: string, 1: IssueEvent}> $environmentEvents method-local: environment and event */
        $environmentEvents = [];
        try {
            foreach ($environments as $searchedEnvironment) {
                foreach ($issuesRegistryJobService->getQueryBackend($searchedEnvironment)->searchEvents($issueEventSearch)->getElements() as $issueEvent) {
                    $environmentEvents[] = [$searchedEnvironment, $issueEvent];
                }
            }
        } catch (Throwable $throwable) {
            $this->getErrorOutput($output)->writeln('<error>' . $throwable->getMessage() . '</error>');
            return Command::FAILURE;
        }
        // several environments: merged by time, the limit over all of them
        usort($environmentEvents, static fn(array $left, array $right): int => $issueEventSearch->oldestFirst
            ? ($left[1]->timestamp?->getTimestamp() ?? 0) <=> ($right[1]->timestamp?->getTimestamp() ?? 0)
            : ($right[1]->timestamp?->getTimestamp() ?? 0) <=> ($left[1]->timestamp?->getTimestamp() ?? 0));
        $environmentEvents = array_slice($environmentEvents, 0, $issueEventSearch->limit);
        if ($this->isJsonFormat($input)) {
            $eventPayloads = [];
            foreach ($environmentEvents as [$searchedEnvironment, $issueEvent]) {
                $eventPayloads[] = $this->toEventPayload($issueEvent, $searchedEnvironment);
            }
            $this->writeJson($output, [
                'environments' => $environments,
                'workspace' => $issueEventSearch->workspace,
                'count' => count($eventPayloads),
                'events' => $eventPayloads,
            ]);
            return Command::SUCCESS;
        }
        foreach ($environmentEvents as [$searchedEnvironment, $issueEvent]) {
            $output->writeln(sprintf(
                '%s  %-8s  %-8s  %-8s  %s  %s  %s',
                $issueEvent->timestamp?->format('Y-m-d H:i:s') ?? '-',
                $searchedEnvironment,
                $issueEvent->workspace ?? '-',
                $issueEvent->level ?? '-',
                $issueEvent->groupHash ?? '-',
                $issueEvent->entryPoint ?? '-',
                mb_strimwidth((string)$issueEvent->message, 0, 120, '…')
            ));
        }
        return Command::SUCCESS;
    }
}
