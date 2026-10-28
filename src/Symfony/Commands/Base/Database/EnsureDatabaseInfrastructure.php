<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Base\Database;

use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Creates or updates the database infrastructure the framework's SQL relies on — on PostgreSQL the extensions, the
 * `ddd_ci_ai` collation and the `ddd_*` functions (`ddd_json_merge_patch`, `ddd_fold`, …); MariaDB needs none.
 * Idempotent (`IF NOT EXISTS`, `CREATE OR REPLACE`): run it after a framework update whose changelog names a changed
 * function — the schema diff compares tables, not these functions. `--dry-run` prints the statements.
 */
#[AsCommand(
    name: 'app:db:ensure',
    description: 'Creates or updates the PostgreSQL infrastructure of the framework (extensions, collation, ddd_* functions); idempotent',
)]
class EnsureDatabaseInfrastructure extends Command
{
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the statements instead of running them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        if ($sqlDialect->name() !== SqlDialect::POSTGRES) {
            $output->writeln('Nothing to do: ' . $sqlDialect->name() . ' needs no framework infrastructure.');
            return Command::SUCCESS;
        }
        $statements = $sqlDialect->ddlRenderer()->ensure()->sortedByPhase();
        if ($input->getOption('dry-run')) {
            foreach ($statements->getElements() as $statement) {
                $output->writeln(rtrim($statement->sql, "; \n") . ';');
            }
            return Command::SUCCESS;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        try {
            $connection->transactional(static function () use ($connection, $statements): void {
                foreach ($statements->getElements() as $statement) {
                    $connection->executeStatement($statement->sql);
                }
            });
        } catch (Throwable $throwable) {
            $output->writeln('<error>' . $throwable->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('%d statements applied (extensions, collation, ddd_* functions).', $statements->count()));
        return Command::SUCCESS;
    }
}
