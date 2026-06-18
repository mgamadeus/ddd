<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Base\Database;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:entity:list',
    description: 'Lists all DB-mapped entities with their SQL table name and fully-qualified class name. Optionally filter by a name substring.',
    hidden: false
)]
class ListEntities extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'filter',
            InputArgument::OPTIONAL,
            'Only list entities whose class name, table name, or FQN contains this (case-insensitive) substring.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filterArg = $input->getArgument('filter');
        $filter = is_string($filterArg) && $filterArg !== '' ? mb_strtolower($filterArg) : null;

        $models = EntityModelGeneratorService::getDatabaseModels(null, filterOverriddenEntities: true)->getElements();

        $rows = [];
        foreach ($models as $model) {
            if (!$model instanceof DatabaseModel) {
                continue;
            }
            $name = $model->entityClassWithNamespace?->name ?? '?';
            $fqn = $model->entityClassWithNamespace?->getNameWithNamespace() ?? '?';
            // Single-table-inheritance subclasses fold into the parent's table (no own table).
            $tableName = $model->parentEntityCLassWithNamespace !== null
                ? $model->parentEntityCLassWithNamespace->name . ' (STI parent table)'
                : ($model->sqlTableName ?? '-');

            if ($filter !== null
                && !str_contains(mb_strtolower($name), $filter)
                && !str_contains(mb_strtolower($tableName), $filter)
                && !str_contains(mb_strtolower($fqn), $filter)) {
                continue;
            }
            $rows[] = [$name, $tableName, $fqn];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));

        $tableHelper = new Table($output);
        $tableHelper->setHeaders(['Entity', 'Table', 'Class']);
        $tableHelper->setRows($rows);
        $tableHelper->render();

        $count = count($rows);
        $output->writeln($count . ' ' . ($count === 1 ? 'entity' : 'entities') . '.');

        return Command::SUCCESS;
    }
}
