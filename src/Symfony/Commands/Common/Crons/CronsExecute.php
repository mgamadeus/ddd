<?php

declare (strict_types=1);

namespace DDD\Symfony\Commands\Common\Crons;

use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:crons:execute',
    description: 'Execute all Cron Jobs due',
    hidden: false
)]
class CronsExecute extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $defaultAdminAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        AuthService::instance()->setAccount($defaultAdminAccount);

        putenv('SYMFONY_DEPRECATIONS_HELPER=disabled');
        $output->writeln([
            'Execute Crons:',
            '============',
        ]);
        Crons::getService()->cleanupCronExecutions();
        $crons = Crons::getService()->getCronsScheduledForExecution();
        $cronExecutions = $crons->execute();


        $table = new Table($output);
        $table->setHeaders(
            [
                'Name',
                'Description',
                'Schedule',
                'Command',
                'Execution State',
                'Execution started at',
                'Execution ended at',
                'Next Execution scheduled at',
                'Execution Output'
            ]
        );

        foreach ($cronExecutions->getElements() as $cronExecution) {
            $table->addRow([
                $cronExecution->cron->name,
                $cronExecution->cron->description,
                $cronExecution->cron->schedule,
                $cronExecution->cron->command,
                $cronExecution->executionState,
                $cronExecution->executionStartedAt->format('Y-m-d H:i:s'),
                $cronExecution->executionEndedAt->format('Y-m-d H:i:s'),
                $cronExecution->cron->nextExecutionScheduledAt->format('Y-m-d H:i:s'),
                $cronExecution->output,
            ]);
        }
        $table->render();
        return Command::SUCCESS;
    }
}