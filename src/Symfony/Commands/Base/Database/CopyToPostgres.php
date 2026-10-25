<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Base\Database;

use DDD\Domain\Base\Repo\DB\Copy\CopyToPostgresService;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Infrastructure\Libs\Config;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Copies one MariaDB database into PostgreSQL using the dual-dialect generator's own DDL
 * (`agentic-plans/backend/2026-10-22-postgres-migration.md` §5.1). Spike scope — see
 * {@see \DDD\Domain\Base\Repo\DB\Copy\CopyToPostgresService}'s class docblock for exactly what is and is not
 * built yet. This class stays a thin shell: connection resolution and refusals live here, every phase's actual
 * work lives in the service so it is exercised the same way from a test and from the CLI.
 */
#[AsCommand(
    name: 'app:db:copy-to-postgres',
    description: 'Copies one MariaDB database into PostgreSQL using the generated PostgreSQL schema (spike scope — see class docblock).',
    hidden: false
)]
class CopyToPostgres extends Command
{
    public const string PHASE_SCHEMA = 'schema';
    public const string PHASE_DATA = 'data';
    public const string PHASE_FINALISE = 'finalise';
    public const string PHASE_VERIFY = 'verify';
    public const string PHASE_ALL = 'all';

    public const array PHASES = [self::PHASE_SCHEMA, self::PHASE_DATA, self::PHASE_FINALISE, self::PHASE_VERIFY];

    public const string DEFAULT_TARGET_DB_ALLOW_PATTERN = '*_sandbox_1';

    /**
     * @param string[]|null $entityClassNames Test-only override of entity discovery (EntityModelGeneratorService
     *      never scans tests/ — see ShowEntitySql's own pattern). Null means production discovery: every
     *      DB-mapped entity, exactly like `app:entity:show-sql` with no argument.
     */
    public function __construct(private readonly ?array $entityClassNames = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source-dsn', null, InputOption::VALUE_OPTIONAL, 'mysql://user:pass@host:port/dbname — overrides DB_COPY_SOURCE_* env vars.')
            ->addOption('phase', null, InputOption::VALUE_OPTIONAL, 'schema|data|finalise|verify|all', self::PHASE_ALL)
            ->addOption('table', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Limit to this table (repeatable); default is every DB-mapped entity.')
            ->addOption('chunk-size', null, InputOption::VALUE_OPTIONAL, 'Rows per COPY chunk.', '5000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan; touch nothing.')
            ->addOption(
                'target-db-allow-pattern',
                null,
                InputOption::VALUE_OPTIONAL,
                'fnmatch() pattern the target database name must match (fail-closed refusal of the wrong target); default DB_COPY_TARGET_ALLOW_PATTERN env or ' . self::DEFAULT_TARGET_DB_ALLOW_PATTERN,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $phase = (string)$input->getOption('phase');
        if (!in_array($phase, [...self::PHASES, self::PHASE_ALL], true)) {
            $symfonyStyle->error('--phase must be one of: ' . implode(', ', [...self::PHASES, self::PHASE_ALL]));
            return Command::FAILURE;
        }
        $phasesToRun = $phase === self::PHASE_ALL ? self::PHASES : [$phase];

        $targetConnection = EntityManagerFactory::getInstance()->getConnection();
        $targetDialect = EntityManagerFactory::getDialect();
        if ($targetDialect->name() !== SqlDialect::POSTGRES) {
            $symfonyStyle->error(
                'app:db:copy-to-postgres: the target (the app\'s default-scope connection) must be pdo_pgsql, got "'
                . $targetDialect->name() . '".'
            );
            return Command::FAILURE;
        }

        $targetDbName = (string)$targetConnection->getDatabase();
        $allowPattern = (string)($input->getOption('target-db-allow-pattern')
            ?: (Config::getEnv('DB_COPY_TARGET_ALLOW_PATTERN') ?: self::DEFAULT_TARGET_DB_ALLOW_PATTERN));
        if (!fnmatch($allowPattern, $targetDbName)) {
            $symfonyStyle->error(
                "app:db:copy-to-postgres: target database \"$targetDbName\" does not match the allow pattern "
                . "\"$allowPattern\" — refusing (fail closed)."
            );
            return Command::FAILURE;
        }

        try {
            $sourceConnection = $this->resolveSourceConnection((string)$input->getOption('source-dsn'));
        } catch (Throwable $throwable) {
            $symfonyStyle->error('app:db:copy-to-postgres: could not open the source connection: ' . $throwable->getMessage());
            return Command::FAILURE;
        }

        $tableFilter = $input->getOption('table');
        [$databaseModels, $skippedTables] = $this->resolveDatabaseModels($sourceConnection, $targetDbName, $tableFilter, $symfonyStyle);
        if ($databaseModels === []) {
            $symfonyStyle->error('app:db:copy-to-postgres: no matching entity to copy.');
            return Command::FAILURE;
        }
        if ($skippedTables !== []) {
            $symfonyStyle->note('skipped: no entity for table(s) ' . implode(', ', $skippedTables));
        }

        $chunkSize = max(1, (int)$input->getOption('chunk-size'));
        $dryRun = (bool)$input->getOption('dry-run');
        $service = new CopyToPostgresService($sourceConnection, $targetConnection, $databaseModels, $symfonyStyle, $chunkSize, $dryRun);

        $exitCode = Command::SUCCESS;
        foreach ($phasesToRun as $phaseToRun) {
            $symfonyStyle->section("Phase: $phaseToRun" . ($dryRun ? ' (dry-run)' : ''));
            $phaseResult = match ($phaseToRun) {
                self::PHASE_SCHEMA => $this->renderTimingTable($symfonyStyle, 'Table', 'Seconds', $service->runSchema()),
                self::PHASE_DATA => $this->renderDataTable($symfonyStyle, $service->runData()),
                self::PHASE_FINALISE => $this->renderTimingTable($symfonyStyle, 'Table', 'Seconds', $service->runFinalise()),
                self::PHASE_VERIFY => $this->renderVerifyTable($symfonyStyle, $service->runVerify()),
            };
            if ($phaseToRun === self::PHASE_VERIFY && !$phaseResult) {
                $exitCode = Command::FAILURE;
            }
        }

        if ($exitCode === Command::SUCCESS) {
            $symfonyStyle->success('app:db:copy-to-postgres finished.');
        } else {
            $symfonyStyle->error('app:db:copy-to-postgres: verify found mismatches — see the table above.');
        }
        return $exitCode;
    }

    private function resolveSourceConnection(string $sourceDsn): Connection
    {
        if ($sourceDsn !== '') {
            $parts = parse_url($sourceDsn);
            $params = [
                'driver' => 'pdo_mysql',
                'host' => $parts['host'] ?? '127.0.0.1',
                'port' => isset($parts['port']) ? (int)$parts['port'] : 3306,
                'user' => $parts['user'] ?? 'root',
                'password' => $parts['pass'] ?? '',
                'dbname' => isset($parts['path']) ? ltrim($parts['path'], '/') : '',
                'charset' => 'utf8mb4',
            ];
        } else {
            $params = [
                'driver' => 'pdo_mysql',
                'host' => Config::getEnv('DB_COPY_SOURCE_HOST') ?: '127.0.0.1',
                'port' => (int)(Config::getEnv('DB_COPY_SOURCE_PORT') ?: 3306),
                'user' => Config::getEnv('DB_COPY_SOURCE_USER') ?: 'root',
                'password' => Config::getEnv('DB_COPY_SOURCE_PASSWORD') ?: '',
                'dbname' => Config::getEnv('DB_COPY_SOURCE_NAME') ?: '',
                'charset' => 'utf8mb4',
            ];
        }
        // SELECT-only usage (Mig §5.1 step 1); the tailnet/TLS host refusal is production scope, not yet built here.
        return DriverManager::getConnection($params);
    }

    /**
     * @param string[] $tableFilter
     * @return array{0: DatabaseModel[], 1: string[]} [models to copy, source tables with no matching entity]
     */
    private function resolveDatabaseModels(Connection $source, string $targetDbName, array $tableFilter, SymfonyStyle $io): array
    {
        $entityClassNames = $this->entityClassNames ?? array_map(
            static fn ($classWithNamespace): string => $classWithNamespace->getNameWithNamespace(),
            EntityModelGeneratorService::getAllEntityClasses(filterOverriddenEntities: true)
        );

        $allModels = [];
        foreach ($entityClassNames as $entityClassName) {
            $databaseModel = DatabaseModel::fromEntityClass($entityClassName);
            if ($databaseModel !== null && $databaseModel->parentEntityCLassWithNamespace === null) {
                $allModels[$databaseModel->sqlTableName] = $databaseModel;
            }
        }

        $skipped = [];
        try {
            $sourceDbName = (string)$source->getDatabase();
            $sourceTables = $source->fetchFirstColumn(
                'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\'',
                [$sourceDbName]
            );
            $skipped = array_values(array_diff($sourceTables, array_keys($allModels)));
        } catch (Throwable $throwable) {
            $io->warning('app:db:copy-to-postgres: could not list source tables to report unmatched ones: ' . $throwable->getMessage());
        }

        if ($tableFilter === []) {
            return [array_values($allModels), $skipped];
        }
        $filtered = [];
        foreach ($tableFilter as $table) {
            if (isset($allModels[$table])) {
                $filtered[] = $allModels[$table];
            } else {
                $io->warning("app:db:copy-to-postgres: --table \"$table\" matches no entity; skipped.");
            }
        }
        return [$filtered, $skipped];
    }

    /** @param array<string, array{seconds: float}> $rows */
    private function renderTimingTable(SymfonyStyle $io, string $firstHeader, string $secondHeader, array $rows): bool
    {
        $table = new Table($io);
        $table->setHeaders([$firstHeader, $secondHeader]);
        foreach ($rows as $tableName => $row) {
            $table->addRow([$tableName, number_format($row['seconds'], 3)]);
        }
        $table->render();
        return true;
    }

    /** @param array<string, array{rows: int, seconds: float}> $rows */
    private function renderDataTable(SymfonyStyle $io, array $rows): bool
    {
        $table = new Table($io);
        $table->setHeaders(['Table', 'Rows', 'Seconds']);
        foreach ($rows as $tableName => $row) {
            $table->addRow([$tableName, $row['rows'], number_format($row['seconds'], 3)]);
        }
        $table->render();
        return true;
    }

    /** @param array<string, array{idSetMatches: bool, rowMismatches: string[], identityOk: bool, rows: int}> $rows */
    private function renderVerifyTable(SymfonyStyle $io, array $rows): bool
    {
        $table = new Table($io);
        $table->setHeaders(['Table', 'Rows', 'Id sets', 'Row hashes', 'Identity', 'Result']);
        $allPassed = true;
        foreach ($rows as $tableName => $row) {
            $passed = $row['idSetMatches'] && $row['rowMismatches'] === [] && $row['identityOk'];
            $allPassed = $allPassed && $passed;
            $table->addRow([
                $tableName,
                $row['rows'],
                $row['idSetMatches'] ? 'OK' : 'MISMATCH',
                $row['rowMismatches'] === [] ? 'OK' : ('MISMATCH: ' . implode('; ', array_slice($row['rowMismatches'], 0, 5))),
                $row['identityOk'] ? 'OK' : 'BEHIND',
                $passed ? 'PASS' : 'FAIL',
            ]);
        }
        $table->render();
        return $allPassed;
    }
}
