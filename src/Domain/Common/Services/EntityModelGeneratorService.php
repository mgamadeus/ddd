<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModels;
use DDD\Domain\Base\Repo\DB\Database\SubclassIndicator;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Libs\ClassFinder;
use DDD\Infrastructure\Modules\DDDModule;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\CompilerPasses\ModuleCompilerPass;
use Doctrine\DBAL\Exception;
use ReflectionAttribute;
use ReflectionException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Service for generation of SQL table definitions and Doctrine models based on Entites
 */
class EntityModelGeneratorService
{
    protected static ?DatabaseModels $databaseModels = null;

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

        // Include entity classes from registered DDD modules
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.project_dir', DDDService::instance()->getRootDir());
        foreach (ModuleCompilerPass::discoverModules($containerBuilder) as $moduleClass) {
            /** @var DDDModule $moduleClass */
            $moduleDomainPath = $moduleClass::getSourcePath() . '/Domain';
            if (is_dir($moduleDomainPath)) {
                $classesFromModule = ClassFinder::getClassesInDirectory($moduleDomainPath);
                $allClasses = array_merge($allClasses, $classesFromModule);
            }
        }
        //header('content-type:application/json');
        //echo json_encode($allClasses);die();
        /** @var ClassWithNamespace[] $entityClasses */
        $entityClasses = [];
        foreach ($allClasses as $class) {
            // ignore repo classes
            if (strpos($class->namespace, '\\Repo\\') !== false) {
                continue;
            }
            if (strpos($class->namespace, 'Entities') === false) {
                continue;
            }
            if (DefaultObject::isEntity($class->getNameWithNamespace())) {
                if ($restrictToClasesWithLazyloadRepoType) {
                    $reflectionClass = ReflectionClass::instance($class->getNameWithNamespace());
                    if ($lazyloadRepoAttributes = $reflectionClass->getAttributes(LazyLoadRepo::class, ReflectionAttribute::IS_INSTANCEOF)) {
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
        $entityClasses = self::filterOutOverriddenEntities($entityClasses);
        DDDService::instance()->restoreCachesSnapshot();
        return $entityClasses;
    }

    /**
     * Removes entity classes that are overridden by application-side entities present in the same
     * set. Two override conventions are recognised:
     *
     *  1. **Inheritance override** — a descendant entity extends an ancestor entity with the same
     *     short class name (e.g. App\…\Account extends DDD\…\Account). The ancestor is dropped
     *     because the descendant owns the same SQL table (table name is derived from the EntitySet's
     *     short name, which stays identical across overrides).
     *  2. **Sibling override** — two unrelated entity classes share the same short class name but
     *     live in different namespaces (e.g. App\…\CacheScopeInvalidation and DDD\…\CacheScopeInvalidation,
     *     both extending Entity directly). Without override resolution both would produce a
     *     DatabaseModel for the same `sqlTableName`, and the second one's `add()` to the DatabaseModels
     *     ObjectSet would be silently dropped by content-equality dedup — usually the app's variant,
     *     because the vendor-side class loads first under composer's autoload order. The app-side
     *     class wins by convention; "app-side" is detected via the absence of `/vendor/` in the
     *     class's source-file path.
     *
     * Single Table Inheritance hierarchies are preserved: an ancestor carrying #[SubclassIndicator]
     * is never marked as overridden, and the inheritance walk stops there so STI siblings above it
     * are kept too.
     *
     * @param ClassWithNamespace[] $entityClasses
     * @return ClassWithNamespace[]
     * @throws ReflectionException
     */
    protected static function filterOutOverriddenEntities(array $entityClasses): array
    {
        /** @var array<string, ClassWithNamespace> $byClassName */
        $byClassName = [];
        foreach ($entityClasses as $classWithNamespace) {
            $byClassName[$classWithNamespace->getNameWithNamespace()] = $classWithNamespace;
        }

        $overriddenClassNames = [];

        // ── Convention 1: inheritance overrides (App\X extends DDD\X) ──────────────────────────
        foreach ($entityClasses as $classWithNamespace) {
            $reflectionClass = ReflectionClass::instance($classWithNamespace->getNameWithNamespace());
            $shortName = $classWithNamespace->name;
            $parentClass = $reflectionClass->getParentClass();
            while ($parentClass) {
                if ($parentClass->isAbstract()) {
                    break;
                }
                $parentClassName = $parentClass->getName();
                if (!DefaultObject::isEntity($parentClassName)) {
                    break;
                }
                // STI base classes (carrying #[SubclassIndicator]) are legitimate co-existing tables -- never exclude.
                if ($parentClass->getAttributes(SubclassIndicator::class, ReflectionAttribute::IS_INSTANCEOF)) {
                    break;
                }
                // Only treat as an override when the short class name matches (App\Foo extends DDD\Foo convention).
                // A different short name indicates a specialization (e.g. AdminUser extends User), not an override.
                if ($parentClass->getShortName() !== $shortName) {
                    break;
                }
                if (isset($byClassName[$parentClassName])) {
                    $overriddenClassNames[$parentClassName] = true;
                }
                $parentClass = $parentClass->getParentClass() ?: null;
            }
        }

        // ── Convention 2: sibling overrides (App\X and DDD\X both extend Entity) ───────────────
        // Group by short class name. For any short name with multiple classes where at least one
        // lives outside vendor/, drop the vendor-resident classes from the set.
        /** @var array<string, ClassWithNamespace[]> $byShortName */
        $byShortName = [];
        foreach ($entityClasses as $classWithNamespace) {
            $byShortName[$classWithNamespace->name][] = $classWithNamespace;
        }
        foreach ($byShortName as $shortName => $group) {
            if (count($group) < 2) {
                continue;
            }
            $hasAppSide = false;
            foreach ($group as $cwn) {
                if (!self::classLivesInVendor($cwn->getNameWithNamespace())) {
                    $hasAppSide = true;
                    break;
                }
            }
            if (!$hasAppSide) {
                // All variants are in vendor (e.g. two DDD packages with name collision). Nothing
                // to disambiguate by the app-wins rule — leave the inheritance pass's decision
                // (or lack thereof) untouched.
                continue;
            }
            foreach ($group as $cwn) {
                if (self::classLivesInVendor($cwn->getNameWithNamespace())) {
                    $overriddenClassNames[$cwn->getNameWithNamespace()] = true;
                }
            }
        }

        if (!$overriddenClassNames) {
            return $entityClasses;
        }

        return array_values(
            array_filter(
                $entityClasses,
                static fn(ClassWithNamespace $cwn) => !isset($overriddenClassNames[$cwn->getNameWithNamespace()])
            )
        );
    }

    /**
     * True when the class's source file is loaded from `vendor/` — used to disambiguate sibling
     * entity overrides (app-side beats vendor-side, see {@see self::filterOutOverriddenEntities()}).
     * Reflection-based so it works regardless of namespace convention or composer.json structure.
     *
     * @throws ReflectionException
     */
    protected static function classLivesInVendor(string $fqcn): bool
    {
        $file = ReflectionClass::instance($fqcn)->getFileName();
        if ($file === false) {
            return false; // Built-in / eval'd class — treat as app-side.
        }
        // Normalise to forward slashes so the substring check works identically on Windows.
        $normalised = str_replace('\\', '/', $file);
        return str_contains($normalised, '/vendor/');
    }

    /**
     * Clears the per-process static cache of generated {@see DatabaseModels}. The cache is keyed
     * by nothing — it's a single instance reused for the lifetime of the PHP process. In long-
     * running workers (Symfony Messenger, PHP-FPM with high MaxRequestsPerChild) where entity PHP
     * files can hot-reload between requests, the static cache otherwise yields a stale schema
     * snapshot that diverges from the freshly-introspected live DB. Callers that need a guaranteed-
     * fresh recompute (e.g. {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService::applyDiffs()}
     * inside its advisory-lock body) MUST call this alongside
     * {@see \DDD\Domain\Common\Services\DatabaseSchemaIntrospectionService::invalidateCache()}.
     */
    public static function invalidateCache(): void
    {
        self::$databaseModels = null;
    }

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
}