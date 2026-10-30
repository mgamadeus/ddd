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
 * The group never alerts again, also not as a regression; it is still counted.
 */
#[AsCommand(
    name: 'app:issues:ignore',
    description: 'Ignores an error group: it never alerts again',
)]
class IssuesIgnore extends IssuesCommand
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
        $issueGroup = $issueGroupsService->ignore($issueGroup);
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
