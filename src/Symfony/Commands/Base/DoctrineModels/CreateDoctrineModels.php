<?php

declare(strict_types=1);
// src/Security/AccessDeniedHandler.php
namespace DDD\Symfony\Commands\Base\DoctrineModels;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Infrastructure\Services\AppService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:generate-doctrine-models-for-entities',
    description: 'Creates Doctrine models based on entities',
    hidden: false
)]
class CreateDoctrineModels extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        putenv('SYMFONY_DEPRECATIONS_HELPER=disabled');
        $output->writeln([
            'Doctrine Model Generator (generates Doctrine Models based on Entities)',
            '============',
        ]);
        //echo AppService::instance()->getContainerServiceClassNameForClass(\DDD\Symfony\Commands\Common\Crons\CronsExecute::class);
        //die();
        $entityModelGeneratorService = new EntityModelGeneratorService();
        $classes = $entityModelGeneratorService->getAllEntityClasses();
        foreach ($classes as $classWithNamespace){
            $output->writeln("Generating Doctrine model for {$classWithNamespace->name}");
            $entityModelGeneratorService->generateDoctrineModelForEntityClass($classWithNamespace->getNameWithNamespace(),true);
        }
        return Command::SUCCESS;
    }

}