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
 * Back to OPEN, without an alert.
 */
#[AsCommand(
    name: 'app:issues:reopen',
    description: 'Reopens a resolved or ignored error group',
)]
class IssuesReopen extends IssuesCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('groupHash', InputArgument::REQUIRED, 'error.group_hash of the group')
            ->addFormatOption();
    }

    protected function executeIssuesCommand(InputInterface $input, OutputInterface $output): int
    {
        $issueGroup = $this->getGroupByHash((string)$input->getArgument('groupHash'));
        /** @var IssueGroupsService $issueGroupsService */
        $issueGroupsService = IssueGroup::getService();
        $issueGroup = $issueGroupsService->reopen($issueGroup);
        if ($this->isJsonFormat($input)) {
            $this->writeJson($output, ['groupHash' => $issueGroup->groupHash, 'status' => $issueGroup->status]);
        } else {
            $output->writeln($issueGroup->groupHash . ': ' . $issueGroup->status);
        }
        return Command::SUCCESS;
    }
}
