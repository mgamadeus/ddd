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
    name: 'app:crons:list',
    description: 'Lists all Cron Jobs',
    hidden: false
)]
class CronsList extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $defaultAdminAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        AuthService::instance()->setAccount($defaultAdminAccount);
        putenv('SYMFONY_DEPRECATIONS_HELPER=disabled');
        $output->writeln([
            'Crons available:',
            '============',
        ]);
        $crons = Crons::getService()->list();


        $table = new Table($output);
        $table->setHeaders(
            [
                'Name',
                'Description',
                'Schedule',
                'Command',
                'Last Execution started at',
                'Next Execution scheduled at',
                'Created',
                'Updated'
            ]
        );

        foreach ($crons->getElements() as $cron) {
            $table->addRow([
                $cron->name,
                $cron->description,
                $cron->schedule,
                $cron->command,
                isset($cron->lastExecutionStartedAt) ? $cron->lastExecutionStartedAt->format('Y-m-d H:i:s') : '',
                isset($cron->nextExecutionScheduledAt) ? $cron->nextExecutionScheduledAt->format('Y-m-d H:i:s') : '',
                $cron->getCreatedTime()?->format('Y-m-d H:i:s'),
                $cron->getModifiedTime()?->format('Y-m-d H:i:s'),
            ]);
        }
        $table->render();
        return Command::SUCCESS;
    }
}