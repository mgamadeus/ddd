<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModels;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Libs\ClassFinder;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\DBAL\Exception;
use ReflectionException;

/**
 * Service for generation of SQL table definitions and Doctrine models based on Entites
 */
class EntityModelGeneratorService
{
    protected static DatabaseModels $databaseModels;

    public static function getDatabaseModels(?array $entityClasses = null): DatabaseModels
    {
        if (!isset(self::$databaseModels)) {
            self::$databaseModels = new DatabaseModels();
            $entityClassesWithNamespace = self::getAllEntityClasses();
            foreach ($entityClassesWithNamespace as $entityClassWithNamespace) {
                $databaseModel = DatabaseModel::fromEntityClass($entityClassWithNamespace->getNameWithNamespace());
                if ($databaseModel) {
                    self::$databaseModels->add($databaseModel);
                }
            }
        }
        if (!$entityClasses) {
            return self::$databaseModels;
        }
        $databaseModels = new DatabaseModels();
        foreach ($entityClasses as $entityClass) {
            if ($databaseModel = self::$databaseModels->getModelByEntityClass($entityClass)) {
                $databaseModels->add($databaseModel);
            }
        }
        return $databaseModels;
    }

    /**
     * Creates Tables for given entity class names. If null is passed, it searches for all Entities in Project
     * @param string[]|null $entityClasses
     * @return string
     * @throws Exception
     * @throws ReflectionException
     */
    public function createOrUpdateDatabaseTablesForEntities(?array $entityClasses = null): string
    {
        if (!$entityClasses) {
            $entityClasses = $this->getAllEntityClasses();
        } else {
            $entityClasses = [];
            foreach ($entityClasses as $entityClassName) {
                $entityClassWithNamespace = new ClassWithNamespace($entityClassName);
                $entityClasses[] = $entityClassWithNamespace;
            }
        }

        $databaseModels = self::getDatabaseModels($entityClasses);
        $sql = $databaseModels->getSql();
        $entityManager = EntityManagerFactory::getInstance();
        $entityManager->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0;');
        //$entityManager->getConnection()->executeStatement($sql);
        $entityManager->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1;');
        return $sql;
    }

    /**
     * Generates Doctrine model class files based on all entities found
     * @return void
     */
    public function generateDoctrineModelsEntities(): void
    {
        foreach (self::getDatabaseModels()->getElements() as $databaseModel) {
            $this->generateModelClassFile($databaseModel);
        }
    }

    /**
     * Generates Doctrine model class file based on Entity class name without namespace
     * @param string $entityClassNameWithoutNamespace
     * @return void
     */
    public function generateDoctrineModelForEntityClassWithoutNameSpace(string $entityClassNameWithoutNamespace): void
    {
        $classes = ClassFinder::getClassesInDirectory(APP_ROOT_DIR . '/src/Domain');
        foreach ($classes as $classWithNamespace) {
            if ($classWithNamespace->name == $entityClassNameWithoutNamespace) {
                $this->generateDoctrineModelForEntityClass($classWithNamespace->getNameWithNamespace());
            }
        }
    }

    /**
     * Generates Doctrine model php class file based on Entity class name
     *  It uses config doctrine.models.baseDirectory as root directory
     * @param string $entityClass
     * @param bool $generateClass
     * @param bool $generateEmptyClass if true, generates only empty class (first run, so the class can be referenced in other files)
     * @return string
     */
    public function generateDoctrineModelForEntityClass(
        string $entityClass,
        bool $generateClass = false,
        bool $generateEmptyClass = true
    ): string {
        $databaseModels = self::getDatabaseModels([$entityClass]);
        if ($firstModel = $databaseModels->first()) {
            if ($generateClass) {
                $this->generateModelClassFile($firstModel);
                return '';
            } else {
                return $firstModel->getDoctrineModelCode();
            }
        }
        return '';
    }

    /**
     * Generates Doctrine model php class file based on DatabseModel
     * It uses config doctrine.models.baseDirectory as root directory
     * @param DatabaseModel $databaseModel
     * @return void
     */
    protected function generateModelClassFile(DatabaseModel &$databaseModel): void
    {
        $modelCode = $databaseModel->getDoctrineModelCode();
        if ($modelCode) {
            $filename = $databaseModel->modelClassWithNamespace->filename;
            // Extract the directory path from the filename
            $directory = dirname($filename);

            // Check if the directory exists, if not, create it
            if (!file_exists($directory)) {
                // The third parameter 'true' allows the creation of nested directories as required
                mkdir($directory, 0777, true);
            }

            // Now, it's safe to open the file as the directory structure exists
            $fp = fopen($filename, 'w');
            if ($fp === false) {
                throw new Exception("Cannot open file ($filename)");
            }

            fwrite($fp, $modelCode);
            fclose($fp);
        }
    }

    /**
     * Returns all Entity classes in Domain
     * If $restrictToClasesWithLazyloadRepoType is a string, then ony classes that have LazyLoadRepo
     * attribute with corresponding repoType = $restrictToClasesWithLazyloadRepoType will be considered
     * @param string|null $restrictToClasesWithLazyloadRepoType
     * @return ClassWithNamespace[]
     */
    public static function getAllEntityClasses(?string $restrictToClasesWithLazyloadRepoType = LazyLoadRepo::DB): array
    {
        DDDService::instance()->deactivateCaches();
        $classesFromFramework = ClassFinder::getClassesInDirectory(DDDService::instance()->getFrameworkRootDir() . '/Domain');
        $classesFromApplication = ClassFinder::getClassesInDirectory(DDDService::instance()->getRootDir() . '/src/Domain');
        $allClasses = array_merge($classesFromFramework, $classesFromApplication);
        //header('content-type:application/json');
        //echo json_encode($allClasses);die();
        /** @var ClassWithNamespace[] $entityClasses */
        $entityClasses = [];
        foreach ($allClasses as $class) {
            // ignore repo classes
            if (strpos($class->namespace, '\\Repo\\') !== false) {
                continue;
            }
            if (strpos($class->namespace,'Entities') === false)
                continue;
            if (DefaultObject::isEntity($class->getNameWithNamespace())) {
                if ($restrictToClasesWithLazyloadRepoType) {
                    $reflectionClass = ReflectionClass::instance($class->getNameWithNamespace());
                    if ($lazyloadRepoAttributes = $reflectionClass->getAttributes(LazyLoadRepo::class)) {
                        $hasDBRepo = false;
                        $hasLegacyDBRepo = false;
                        $forceDBEntityModelCreation = false;
                        foreach ($lazyloadRepoAttributes as $lazyloadRepoAttribute) {
                            /** @var LazyLoadRepo $lazyloadRepoAttributeInstance */
                            $lazyloadRepoAttributeInstance = $lazyloadRepoAttribute->newInstance();
                            $forceDBEntityModelCreation = $lazyloadRepoAttributeInstance->forceDBEntityModelCreation;
                            if ($lazyloadRepoAttributeInstance->repoType == $restrictToClasesWithLazyloadRepoType) {
                                $hasDBRepo = true;
                            }
                            if ($lazyloadRepoAttributeInstance->repoType == LazyLoadRepo::LEGACY_DB) {
                                $hasLegacyDBRepo = true;
                            }
                        }
                        if ($hasDBRepo && (!$hasLegacyDBRepo || $forceDBEntityModelCreation)) {
                            $entityClasses[] = $class;
                        }
                    }
                } else {
                    $entityClasses[] = $class;
                }
            }
        }
        DDDService::instance()->restoreCachesSnapshot();
        return $entityClasses;
    }
}