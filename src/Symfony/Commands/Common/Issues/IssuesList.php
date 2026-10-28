<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Issues;

use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssueGroupsFilter;
use DDD\Domain\Common\Services\IssueGroupsService;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the registered error groups, last seen first — the entry point of an agent's fix loop:
 * `app:issues:list --status=OPEN --new-since=24h --format=json`.
 */
#[AsCommand(
    name: 'app:issues:list',
    description: 'Lists the registered error groups (filters: status, new since, release, section, environment)',
)]
class IssuesList extends IssuesCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'OPEN, RESOLVED or IGNORED')
            ->addOption('new-since', null, InputOption::VALUE_REQUIRED, 'Registered since (30m, 24h, 7d or a date)')
            ->addOption('release', null, InputOption::VALUE_REQUIRED, 'First or last seen in this release')
            ->addOption('section', null, InputOption::VALUE_REQUIRED, 'HTTP, CLI, MESSAGE_HANDLER, …')
            ->addOption('environment', null, InputOption::VALUE_REQUIRED, 'The logging environment')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of groups', '50')
            ->addFormatOption();
    }

    protected function executeIssuesCommand(InputInterface $input, OutputInterface $output): int
    {
        $status = $input->getOption('status') !== null ? strtoupper((string)$input->getOption('status')) : null;
        if ($status !== null && !in_array($status, IssueGroup::STATUSES, true)) {
            $this->getErrorOutput($output)->writeln('<error>--status must be one of ' . implode(', ', IssueGroup::STATUSES) . '</error>');
            return Command::INVALID;
        }
        $issueGroupsFilter = new IssueGroupsFilter(
            status: $status,
            newSince: $this->parseTime($input->getOption('new-since')),
            release: $input->getOption('release'),
            section: $input->getOption('section'),
            environment: $input->getOption('environment'),
            limit: (int)$input->getOption('limit'),
        );
        /** @var IssueGroupsService $issueGroupsService */
        $issueGroupsService = IssueGroup::getService();
        $issueGroups = $issueGroupsService->listGroups($issueGroupsFilter);
        $issuesRegistrySettings = IssuesRegistrySettings::fromEnvironment();
        if ($this->isJsonFormat($input)) {
            $groupPayloads = [];
            foreach ($issueGroups->getElements() as $issueGroup) {
                $groupPayload = $this->toPayload($issueGroup);
                $groupPayload['link'] = $issuesRegistrySettings->getAlertLink($issueGroup->groupHash);
                $groupPayloads[] = $groupPayload;
            }
            $this->writeJson($output, ['count' => count($groupPayloads), 'groups' => $groupPayloads]);
            return Command::SUCCESS;
        }
        $table = new Table($output);
        $table->setHeaders(['Group hash', 'Status', 'Exception', 'Message', 'Events', 'Last seen', 'Flags']);
        foreach ($issueGroups->getElements() as $issueGroup) {
            $table->addRow([
                $issueGroup->groupHash,
                $issueGroup->status,
                $issueGroup->exceptionType ?? '',
                mb_strimwidth((string)$issueGroup->sampleMessage, 0, 60, '…'),
                $issueGroup->eventCount,
                $issueGroup->lastSeenAt?->format('Y-m-d H:i:s') ?? '',
                implode(' ', array_filter([
                    $issueGroup->isNew ? 'new' : null,
                    $issueGroup->isRegression ? 'regression' : null,
                    $issueGroup->alertPending ? 'alert-pending' : null,
                ])),
            ]);
        }
        $table->render();
        return Command::SUCCESS;
    }
}
