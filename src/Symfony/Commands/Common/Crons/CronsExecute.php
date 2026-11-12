<?php

declare (strict_types=1);

namespace DDD\Symfony\Commands\Common\Crons;

use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;

#[AsCommand(
    name: 'app:crons:execute',
    description: 'Execute all Cron Jobs due',
    hidden: false
)]
class CronsExecute extends Command implements SignalableCommandInterface
{
    /**
     * A runner told to stop (SIGTERM on a rollout, SIGINT, SIGHUP) closes the execution it runs instead of leaving it
     * RUNNING, which would keep its Cron from running until the abandoned row is closed.
     *
     * @return int[]
     */
    public function getSubscribedSignals(): array
    {
        // without pcntl the console cannot handle signals: nothing subscribed, the abandoned row is closed by a later tick
        if (!SignalRegistry::isSupported()) {
            return [];
        }
        return array_values(array_filter(
            [defined('SIGTERM') ? SIGTERM : null, defined('SIGINT') ? SIGINT : null, defined('SIGHUP') ? SIGHUP : null]
        ));
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        Cron::closeCronExecutionInProgressOnRunnerStop($signal);
        // the shell convention for a process ended by a signal
        return 128 + $signal;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $defaultAdminAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        AuthService::instance()->setAccount($defaultAdminAccount);

        putenv('SYMFONY_DEPRECATIONS_HELPER=disabled');
        $output->writeln([
            'Execute Crons:',
            '============',
        ]);
        $cronsService = Crons::getService();
        $cronsService->cleanupCronExecutions();
        $abandonedCronExecutionsClosed = $cronsService->closeAbandonedCronExecutions(Crons::TIMEOUT_PER_CRON_IN_SECONDS);
        if ($abandonedCronExecutionsClosed > 0) {
            $output->writeln("Closed $abandonedCronExecutionsClosed abandoned execution(s) as FAILED.");
        }
        $crons = $cronsService->getCronsScheduledForExecution();

        $cronExecutions = $crons->execute(Crons::TIMEOUT_PER_CRON_IN_SECONDS);


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