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
 * PostgreSQL only: installs what the framework's SQL relies on — creates the extensions (pg_trgm, unaccent, postgis,
 * vector, vectorscale, pg_stat_statements) and the `ddd_ci_ai` collation if missing, and creates or replaces the
 * `ddd_*` SQL functions (`ddd_json_merge_patch`, `ddd_fold`, `ddd_unaccent`, `ddd_translatable_text`, `ddd_now`, …).
 * Idempotent (`IF NOT EXISTS`, `CREATE OR REPLACE`, one transaction): run it after a framework update whose changelog
 * names a changed function — the schema diff compares tables, not these. `--dry-run` prints the statements. On
 * MariaDB it does nothing.
 */
#[AsCommand(
    name: self::COMMAND_NAME,
    description: 'PostgreSQL only, idempotent: creates the extensions and the ddd_ci_ai collation if missing, creates or replaces the ddd_* SQL functions',
)]
class InstallPostgresExtensionsCollationFunctions extends Command
{
    public const string COMMAND_NAME = 'app:db:postgres:install-extensions-collation-functions';

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the statements instead of running them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        if ($sqlDialect->name() !== SqlDialect::POSTGRES) {
            $output->writeln('Nothing to do: PostgreSQL only (this database is ' . $sqlDialect->name() . ').');
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
        $output->writeln(sprintf('%d statements applied: extensions and ddd_ci_ai collation (if missing), ddd_* functions (created or replaced).', $statements->count()));
        return Command::SUCCESS;
    }
}
