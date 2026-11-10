<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Workers;

use DDD\Domain\Common\Services\WorkerDefinitionsService;
use DDD\Domain\Common\Services\WorkerTransportsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workers:list',
    description: 'Lists the worker definitions (consumers per Messenger transport, loops) of the worker list'
)]
class WorkersList extends Command
{
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var WorkerDefinitionsService $workerDefinitionsService */
        $workerDefinitionsService = DDDService::instance()->getService(WorkerDefinitionsService::class);
        /** @var WorkerTransportsService $workerTransportsService */
        $workerTransportsService = DDDService::instance()->getService(WorkerTransportsService::class);
        $workerDefinitions = $workerDefinitionsService->getWorkerDefinitions();
        if ($input->getOption('format') === 'json') {
            $output->writeln((string)json_encode($workerDefinitions->toObject(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }
        $symfonyStyle = new SymfonyStyle($input, $output);
        $symfonyStyle->text('Worker list: ' . $workerDefinitions->definitionFile . ($workerDefinitions->definitionFileExists ? '' : ' (missing)'));
        $consumerRows = [];
        foreach ($workerDefinitions->consumers->getElements() as $workerConsumerDefinition) {
            $consumerRows[] = [
                $workerConsumerDefinition->transport,
                $workerConsumerDefinition->isAutoscaled() ? 'autoscaled' : 'fixed',
                $workerConsumerDefinition->getEffectiveMin(),
                $workerConsumerDefinition->getEffectiveMax(),
                $workerConsumerDefinition->limit ?? '',
                $workerConsumerDefinition->memoryLimit ?? '',
                $workerDefinitionsService->getMessageLogModeForTransport($workerConsumerDefinition->transport),
                $workerTransportsService->hasReceiver($workerConsumerDefinition->transport) ? 'yes' : 'NO',
            ];
        }
        foreach ($workerTransportsService->getConsumedTransportNames() as $consumedTransportName) {
            if ($workerDefinitions->consumers->getByTransport($consumedTransportName) === null) {
                $consumerRows[] = [$consumedTransportName, 'NOT DEFINED', '', '', '', '', '', 'yes'];
            }
        }
        $symfonyStyle->section('Consumers');
        $symfonyStyle->table(['Transport', 'Scaling', 'Min', 'Max', 'Limit', 'Memory limit', 'Message log', 'Transport exists'], $consumerRows);
        $loopRows = [];
        foreach ($workerDefinitions->loops->getElements() as $workerLoopDefinition) {
            $loopRows[] = [$workerLoopDefinition->name, $workerLoopDefinition->args, $workerLoopDefinition->sleepSeconds ?? '', $workerLoopDefinition->prodOnly ? 'yes' : ''];
        }
        if ($loopRows !== []) {
            $symfonyStyle->section('Loops');
            $symfonyStyle->table(['Name', 'Arguments', 'Sleep seconds', 'Production only'], $loopRows);
        }
        return Command::SUCCESS;
    }
}
