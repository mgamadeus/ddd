<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Issues;

use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Services\IssueGroupsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Marks the group fixed. An event after now reopens it as a regression; a replay of older lines does not.
 */
#[AsCommand(
    name: 'app:issues:resolve',
    description: 'Marks an error group resolved: only an event after now reopens it (regression alert)',
)]
class IssuesResolve extends IssuesCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('groupHash', InputArgument::REQUIRED, 'error.group_hash of the group')
            ->addEnvironmentOption()
            ->addFormatOption();
    }

    protected function executeIssuesCommand(InputInterface $input, OutputInterface $output): int
    {
        $issueGroup = $this->getGroupByHash((string)$input->getArgument('groupHash'), $this->getEnvironmentOption($input));
        /** @var IssueGroupsService $issueGroupsService */
        $issueGroupsService = IssueGroup::getService();
        $issueGroup = $issueGroupsService->resolve($issueGroup);
        if ($this->isJsonFormat($input)) {
            $this->writeJson($output, [
                'groupHash' => $issueGroup->groupHash,
                'environment' => $issueGroup->environment,
                'workspace' => $issueGroup->workspace,
                'status' => $issueGroup->status,
            ]);
        } else {
            $output->writeln(sprintf('%s [%s]: %s', $issueGroup->groupHash, $issueGroup->environment ?? '-', $issueGroup->status));
        }
        return Command::SUCCESS;
    }
}
