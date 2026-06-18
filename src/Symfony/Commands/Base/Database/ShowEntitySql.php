<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Base\Database;

use DDD\Domain\Common\Services\EntityModelGeneratorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityArg = $input->getArgument('entity');

        // No argument → full target schema for every DB-mapped entity.
        if ($entityArg === null || $entityArg === '') {
            $sql = EntityModelGeneratorService::getDatabaseModels(null, filterOverriddenEntities: true)->getSql();
            $output->writeln($sql !== '' ? $sql : '-- (no entities found)', OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        $entityClassName = $this->resolveEntityClass((string)$entityArg, $output);
        if ($entityClassName === null) {
            return Command::FAILURE;
        }

        $sql = EntityModelGeneratorService::getDatabaseModels([$entityClassName], filterOverriddenEntities: true)->getSql();
        if ($sql === '') {
            $output->writeln(
                "<comment>-- {$entityClassName} produces no table of its own (e.g. a single-table-inheritance subclass folded into its parent's table).</comment>"
            );
            return Command::SUCCESS;
        }
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
