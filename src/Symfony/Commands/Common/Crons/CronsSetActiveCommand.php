<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Crons;

use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Shared body of `app:crons:activate` / `app:crons:deactivate`: switches one or several Crons, addressed by their
 * unique names, on or off — nothing else. Schedule, command and description stay as they are (that is
 * `app:crons:upsert`). The switch goes through {@see Cron::activate()} / {@see Cron::deactivate()}, so a Cron paused
 * across its slot is re-anchored at its NEXT slot instead of firing at the next tick.
 *
 * Exit code: SUCCESS when every named Cron was switched or already in the target state; FAILURE when any name was not
 * found or threw. Deliberately no `--all`: switching every Cron at once is not a one-liner anybody should have.
 */
abstract class CronsSetActiveCommand extends Command
{
    /** @var bool The state the concrete command sets */
    protected const bool TARGET_ACTIVE = true;

    protected function configure(): void
    {
        $this
            ->addArgument(
                'names',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'The unique name(s) of the Cron(s) to ' . $this->getVerb() . ' (quote names with spaces)'
            )
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Show the resulting Cron(s) without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(120);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $this->setDefaultAdminAccountAsAuthContext();
        putenv('SYMFONY_DEPRECATIONS_HELPER=disabled');

        $cronNames = array_values(array_filter(array_map(
            static fn (mixed $cronName): string => trim((string)$cronName),
            (array)$input->getArgument('names')
        )));
        if ($cronNames === []) {
            $symfonyStyle->error('At least one Cron name is required.');
            return Command::FAILURE;
        }
        $dryRun = (bool)$input->getOption('dryRun');
        $verb = $this->getVerb();

        $cronsService = Crons::getService();
        $cronsService->throwErrors = true;
        $tableRows = [];
        $failureCount = 0;
        foreach ($cronNames as $cronName) {
            try {
                $cron = $cronsService->findByName($cronName);
                if (!$cron instanceof Cron) {
                    $tableRows[] = [$cronName, '-', '-', '-', 'NOT FOUND'];
                    $failureCount++;
                    continue;
                }
                $activeBefore = $this->formatActive($cron);
                if ($cron->isActive === static::TARGET_ACTIVE) {
                    $tableRows[] = [
                        $cronName, $activeBefore, $activeBefore, $this->formatNextExecutionScheduledAt($cron),
                        'unchanged (already ' . (static::TARGET_ACTIVE ? 'active' : 'inactive') . ')',
                    ];
                    continue;
                }
                static::TARGET_ACTIVE ? $cron->activate() : $cron->deactivate();
                if (!$dryRun) {
                    $cron = $cron->update();
                }
                $tableRows[] = [
                    $cronName, $activeBefore, $this->formatActive($cron), $this->formatNextExecutionScheduledAt($cron),
                    $dryRun ? 'DRY RUN — would ' . $verb : $verb . 'd',
                ];
            } catch (Throwable $throwable) {
                $tableRows[] = [$cronName, '-', '-', '-', 'ERROR: ' . get_class($throwable) . ': ' . $throwable->getMessage()];
                $failureCount++;
            }
        }
        $symfonyStyle->table(['Cron', 'Active before', 'Active after', 'Next execution', 'Result'], $tableRows);
        if ($failureCount > 0) {
            $symfonyStyle->error($failureCount . ' of ' . count($cronNames) . ' Cron(s) could not be ' . $verb . 'd.');
            return Command::FAILURE;
        }
        $symfonyStyle->success(
            ($dryRun ? 'Dry run: ' : '') . count($cronNames) . ' Cron(s) ' . $verb . 'd' . ($dryRun ? ' (nothing written).' : '.')
        );
        return Command::SUCCESS;
    }

    /** Cron rows are admin-only for update ({@see Cron}'s RolesRequiredForUpdate), so the switch runs as the CLI admin. */
    protected function setDefaultAdminAccountAsAuthContext(): void
    {
        $defaultAdminAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        if ($defaultAdminAccount) {
            AuthService::instance()->setAccount($defaultAdminAccount);
        }
    }

    /** @return string `activate` or `deactivate`, after {@see self::TARGET_ACTIVE} */
    protected function getVerb(): string
    {
        return static::TARGET_ACTIVE ? 'activate' : 'deactivate';
    }

    protected function formatActive(Cron $cron): string
    {
        return $cron->isActive ? 'yes' : 'no';
    }

    protected function formatNextExecutionScheduledAt(Cron $cron): string
    {
        return isset($cron->nextExecutionScheduledAt) ? $cron->nextExecutionScheduledAt->format('Y-m-d H:i:s') : '-';
    }
}
