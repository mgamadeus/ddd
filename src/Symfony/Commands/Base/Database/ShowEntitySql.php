<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Base\Database;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\DialectResolver;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatement;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:entity:show-sql',
    description: 'Prints the generated CREATE TABLE + index/foreign-key SQL for an entity (or every entity). The schema is derived from the entity attributes; nothing is executed against the database.',
    hidden: false
)]
class ShowEntitySql extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'entity',
            InputArgument::OPTIONAL,
            'Entity short name (e.g. "Account") or fully-qualified class name. Omit to dump the SQL for every entity.'
        );
        $this->addOption(
            'dialect',
            null,
            InputOption::VALUE_REQUIRED,
            'mysql (default, today\'s MariaDB-targeted SQL) or postgres (dual-dialect architecture plan §6 row 4a: '
            . 'CREATE-path PostgreSQL DDL via PostgresDdlRenderer — requires --create-only, since ALTER/introspection '
            . 'are a later build step).',
            'mysql'
        );
        $this->addOption(
            'create-only',
            null,
            InputOption::VALUE_NONE,
            '--dialect=postgres only: renders the CREATE-only script (every ensure() infra statement, then every '
            . 'entity\'s table/indexes/foreign keys, phase-ordered across the whole set via DdlStatement::sortByPhase() '
            . 'so a forward-referenced foreign key still lands after its target table). No-op for --dialect=mysql, '
            . 'which only ever had a CREATE-path renderer to begin with.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dialectOption = (string)$input->getOption('dialect');
        if (!in_array($dialectOption, ['mysql', 'postgres'], true)) {
            $output->writeln("<error>--dialect must be \"mysql\" or \"postgres\", got \"$dialectOption\".</error>");
            return Command::FAILURE;
        }

        $entityArg = $input->getArgument('entity');
        $entityClassNames = null; // null = every DB-mapped entity
        if ($entityArg !== null && $entityArg !== '') {
            $entityClassName = $this->resolveEntityClass((string)$entityArg, $output);
            if ($entityClassName === null) {
                return Command::FAILURE;
            }
            $entityClassNames = [$entityClassName];
        }

        if ($dialectOption === 'postgres') {
            return $this->executePostgres($entityClassNames, (bool)$input->getOption('create-only'), $output);
        }

        $sql = EntityModelGeneratorService::getDatabaseModels($entityClassNames, filterOverriddenEntities: true)->getSql();
        if ($sql === '') {
            $message = $entityClassNames === null
                ? '-- (no entities found)'
                : "<comment>-- {$entityClassNames[0]} produces no table of its own (e.g. a single-table-inheritance subclass folded into its parent's table).</comment>";
            $output->writeln($message, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }
        $output->writeln($sql, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }

    /** @param string[]|null $entityClassNames */
    protected function executePostgres(?array $entityClassNames, bool $createOnly, OutputInterface $output): int
    {
        if (!$createOnly) {
            $output->writeln(
                '<error>--dialect=postgres currently needs --create-only: PostgresDdlRenderer only has a CREATE '
                . 'path (dual-dialect architecture plan §6 row 4a); ALTER/introspection land in build step 7.</error>'
            );
            return Command::FAILURE;
        }

        $databaseModels = EntityModelGeneratorService::getDatabaseModels($entityClassNames, filterOverriddenEntities: true);
        // One renderer (and one IdentifierPolicy) for the WHOLE set: PostgreSQL index/constraint names are
        // schema-wide, so a duplicate across two different tables must be caught here, across every model, not
        // per-model (dual-dialect architecture plan §4.5).
        $renderer = DialectResolver::forDriver('pdo_pgsql')->ddlRenderer();

        $statements = $renderer->ensure();
        /** @var DatabaseModel $databaseModel */
        foreach ($databaseModels->getElements() as $databaseModel) {
            foreach ($renderer->renderCreate($databaseModel) as $statement) {
                $statements[] = $statement;
            }
        }

        if ($statements === []) {
            $output->writeln('-- (no entities found)', OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        $sql = implode(
            ";\n\n",
            array_map(static fn (DdlStatement $statement): string => $statement->sql, DdlStatement::sortByPhase($statements))
        ) . ";\n";
        $output->writeln($sql, OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }

    /**
     * Resolves an entity short name or fully-qualified class name to a concrete FQN, matched against all DB-mapped
     * entities. Returns null (after writing an error) when the name is unknown or ambiguous.
     */
    protected function resolveEntityClass(string $entityArg, OutputInterface $output): ?string
    {
        $entityClasses = EntityModelGeneratorService::getAllEntityClasses();
        $normalizedArg = ltrim($entityArg, '\\');

        // An argument containing a namespace separator is treated as an explicit FQN.
        if (str_contains($normalizedArg, '\\')) {
            foreach ($entityClasses as $classWithNamespace) {
                if (ltrim($classWithNamespace->getNameWithNamespace(), '\\') === $normalizedArg) {
                    return $classWithNamespace->getNameWithNamespace();
                }
            }
            $output->writeln("<error>No DB-mapped entity found for class '{$entityArg}'.</error>");
            return null;
        }

        // Otherwise match by short class name (case-insensitive).
        $matches = [];
        foreach ($entityClasses as $classWithNamespace) {
            if (strcasecmp($classWithNamespace->name, $normalizedArg) === 0) {
                $matches[] = $classWithNamespace->getNameWithNamespace();
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) === 0) {
            $output->writeln(
                "<error>No DB-mapped entity named '{$entityArg}'. Run app:entity:list to see the available entities.</error>"
            );
            return null;
        }

        $output->writeln(
            "<error>Ambiguous entity name '{$entityArg}' — it matches multiple classes. Pass the fully-qualified class name:</error>"
        );
        foreach ($matches as $match) {
            $output->writeln("  - {$match}");
        }
        return null;
    }
}
