<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Common\Entities\Workers\WorkersCheckFinding;
use DDD\Domain\Common\Services\WorkerDefinitionsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workers:check',
    description: 'Checks the worker list against the Messenger configuration (every consumed transport has a consumer entry and vice versa, routing, min/max). Exits 1 on an error — for CI'
)]
class WorkersCheck extends Command
{
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var WorkerDefinitionsService $workerDefinitionsService */
        $workerDefinitionsService = DDDService::instance()->getService(WorkerDefinitionsService::class);
        $workersCheckFindings = $workerDefinitionsService->checkWorkerDefinitions();
        if ($input->getOption('format') === 'json') {
            $output->writeln((string)json_encode($workersCheckFindings->toObject(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $workersCheckFindings->hasErrors() ? Command::FAILURE : Command::SUCCESS;
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        $symfonyStyle->text('Worker list: ' . $workerDefinitionsService->getDefinitionFilePath());
        if ($workersCheckFindings->isEmpty()) {
            $symfonyStyle->success('The worker list matches the Messenger configuration.');
            return Command::SUCCESS;
        }
        $symfonyStyle->table(
            ['Severity', 'Code', 'Transport', 'Finding'],
            array_map(
                static fn(WorkersCheckFinding $workersCheckFinding): array => [
                    $workersCheckFinding->severity,
                    $workersCheckFinding->code,
                    $workersCheckFinding->transport ?? '',
                    $workersCheckFinding->message,
                ],
                $workersCheckFindings->getElements()
            )
        );
        if ($workersCheckFindings->hasErrors()) {
            $symfonyStyle->error('The worker list does not match the Messenger configuration.');
            return Command::FAILURE;
        }
        $symfonyStyle->warning('The worker list matches the Messenger configuration, with warnings.');
        return Command::SUCCESS;
    }
}
