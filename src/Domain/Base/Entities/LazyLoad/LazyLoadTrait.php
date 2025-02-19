<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\LazyLoad;

use App\Infrastructure\Services\AppService;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\ParentChildrenTrait;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Repo\DatabaseRepoEntitySet;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\RepoEntity;
use DDD\Domain\Base\Repo\Virtual\VirtualEntity;
use DDD\Domain\Common\Services\CacheScopeInvalidationsService;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Traits\AfterConstruct\Attributes\AfterConstruct;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

trait LazyLoadTrait
{
    use SerializerTrait, ParentChildrenTrait;

    /** @var string The lazy load default repository type */
    protected static string $lazyLoadDefaultRepo = LazyLoadRepo::DB;

    /**
     * Checks all Properties of curent instance for defined Lazyloading Attributes
     * Lazyloading is executed based on property $lazyloadDefaultRepo, e.g. ORM
     * If a lazyload Attribute definition with the coresponding Repository exists and the to-be loaded property
     * is not already set/defined, than it will be automatically loaded when the property is accessed
     * In order to be able to do so, the property is unset, so the magic method __get is executed on accessing the property.
     * @throws ReflectionException
     */
    #[AfterConstruct]
    protected function prepareLazyLoad(): void
    {
        $propertiesToLazyLoad = static::getPropertiesToLazyLoad();
        foreach ($propertiesToLazyLoad as $propertyName => $lazyLoadDefinitions) {
            $this->unset($propertyName);
        }
    }

    /**
     * Returns the defined repo class for the given repoType and PropertName
     * either by checking if an individual repositoryClass definition is set in the Lazyload instance
     * or by using ::getRepoClass($repoType) function
     * @param string $repoType
     * @param string $propertyName
     * @return string|null
     */
    public function getRepoClassForProperty(string $repoType, string $propertyName): ?string
    {
        if (isset(StaticRegistry::$repoClassesForProperties[static::class][$propertyName]) &&
            array_key_exists($repoType, StaticRegistry::$repoClassesForProperties[static::class][$propertyName])) {
            $t = StaticRegistry::$repoClassesForProperties;
            return StaticRegistry::$repoClassesForProperties[static::class][$propertyName][$repoType];
        }
        $repoClass = null;
        $propertiesToLazyLoad = static::getPropertiesToLazyLoad();
        $lazyloadAttributeInstance = $propertiesToLazyLoad[$propertyName][$repoType] ?? null;
        if ($lazyloadAttributeInstance && $lazyloadAttributeInstance->repoClass) {
            // first check if repoClass is defined on lazyLoadattribute instance
            $repoClass = $lazyloadAttributeInstance->repoClass;
        } elseif ($lazyloadAttributeInstance) {
            // get property type and try to get repo class from getRepoClass function, if property type has this function
            if ($targetPropertyEntityClassName = $lazyloadAttributeInstance->entityClassName) {
                if ($targetPropertyEntityClassName && method_exists(
                        $targetPropertyEntityClassName,
                        'getRepoClass'
                    )) {
                    /** @var LazyLoadTrait $targetPropertyEntityClassName */
                    $repoClass = $targetPropertyEntityClassName::getRepoClass($repoType);
                }
                return $repoClass;
            }
            $property = new ReflectionProperty($this, $propertyName);
            $propertyType = $property->getType();
            if (
                $propertyType && !$propertyType->isBuiltin(
                ) && $propertyType instanceof ReflectionNamedType && method_exists(
                    $propertyType->getName(),
                    'getRepoClass'
                )
            ) {
                /** @var LazyLoadTrait $lazyLoadPropertyClass */
                $lazyLoadPropertyClass = $propertyType->getName();
                $repoClass = $lazyLoadPropertyClass::getRepoClass($repoType);
            }
        }
        StaticRegistry::$repoClassesForProperties[static::class][$propertyName][$repoType] = $repoClass;
        return $repoClass;
    }

    /**
     * Especially covers lazyloading, see definition of prepareLazyLoad()
     * @throws ReflectionException
     */
    public function __get(string $propertyName)
    {
        if (LazyLoad::$disableLazyLoadGlobally) {
            return null;
        }
        $propertiesToLazyLoad = static::getPropertiesToLazyLoad();
        if ($lazyloadAttributeInstance = $propertiesToLazyLoad[$propertyName]['lazyInstance'] ?? null) {
            $property = new ReflectionProperty($this, $propertyName);
            $propertyType = $property->getType();
            if (
                $propertyType && !$propertyType->isBuiltin() &&
                $propertyType instanceof ReflectionNamedType
            ) {
                $lazyInstancePropertyClass = $propertyType->getName();
                $instance = new $lazyInstancePropertyClass();
                $this->$propertyName = $instance;
                //set parent / child relationsship
                if ($lazyloadAttributeInstance->addAsChild) {
                    $this->addChildren($instance);
                }
                // lazyInstance does not make sens as parent
                return $this->$propertyName;
            }
        }
        if (isset($propertiesToLazyLoad[$propertyName])) {
            // we can have multiple Repos to load the entity, we execute loading on each repo
            /** @var Entity[]|EntitySet[] $loadedInstances */
            $loadedInstances = [];
            foreach ($propertiesToLazyLoad[$propertyName] as $repoType => $lazyloadAttributeInstance) {
                $lazyloadAttributeInstance = $propertiesToLazyLoad[$propertyName][$repoType];
                $repoFunction = $lazyloadAttributeInstance->loadMethod;

                // For simple lazyloading using class methods, the repoClass instance is $this
                if ($lazyloadAttributeInstance->repoType == LazyLoadRepo::CLASS_METHOD) {
                    $repoClassInstance = $this;
                } else {
                    //we need to determine the Repo Class associated with the entity class of the property to be lazyloaded
                    $repoClass = $this->getRepoClassForProperty($repoType, $propertyName);
                    $repoClassInstance = new $repoClass();

                    // handling in case of Argus Repo Entities => we do not perform lazyloading on them but instead lazy instance them
                    // this is done in order to be able to perform all loading operations at once at the end
                    if ($repoType == LazyLoadRepo::ARGUS && property_exists($this, 'isArgusEntity')) {
                        $this->$propertyName = $repoClassInstance;
                        //set parent / child relationsship
                        $this->addChildren($repoClassInstance);
                        return $this->$propertyName;
                    }
                    $propertyContainingId = $lazyloadAttributeInstance->getPropertyContainingId();
                    if ($propertyContainingId && !isset($this->$propertyContainingId)) {
                        return null;
                    }
                    // determine if caching is applicable
                    $repoClassReflection = ReflectionClass::instance($repoClass);
                    if (DDDService::instance()::$noCache) {
                        $lazyloadAttributeInstance->useCache = false;
                    } elseif ($lazyloadAttributeInstance->useCache && $entityCacheAttributeInstance = $repoClassReflection->getAttributeInstance(
                            EntityCache::class
                        )) {
                        /** @var EntityCache $entityCacheAttributeInstance */
                        if ($entityCacheAttributeInstance->cacheScopes) {
                            /** @var CacheScopeInvalidationsService $cacheScopeServiceClass */
                            $cacheScopeServiceClass = DDDService::instance()->getService(
                                CacheScopeInvalidationsService::class
                            );
                            $lazyloadAttributeInstance->useCache = $cacheScopeServiceClass::canUseCachingForScopesAndLazyloadInitiatingEntity(
                                $entityCacheAttributeInstance->cacheScopes,
                                $this
                            );
                        }
                    }
                }

                /** @var Entity $lazyLoadedEntity */
                if ($lazyloadAttributeInstance->repoType == LazyLoadRepo::CLASS_METHOD) {
                    $lazyLoadedEntity = $this->$repoFunction();
                } elseif ($repoClassInstance instanceof VirtualEntity) {
                    // in case of virtual repos, we use a precalling method that is handling caching
                    $lazyLoadedEntity = $repoClassInstance->callLazyLoadMethod(
                        $repoFunction,
                        $this,
                        $lazyloadAttributeInstance
                    );
                } else {
                    $lazyLoadedEntity = $repoClassInstance->$repoFunction($this, $lazyloadAttributeInstance);
                }
                if ($lazyLoadedEntity) {
                    $loadedInstances[] = [$lazyloadAttributeInstance, $lazyLoadedEntity];
                }
            }
            $lazyLoadedEntity = null;
            $lazyloadAttributeInstance = null;
            foreach ($loadedInstances as [$currentLazyloadAttributeInstance, $currentLazyLoadedEntity]) {
                /** @var Entity|EntitySet $currentLazyLoadedEntity */
                /** @var LazyLoad $currentLazyloadAttributeInstance */
                if (!$lazyLoadedEntity) {
                    $lazyLoadedEntity = $currentLazyLoadedEntity;
                    $lazyloadAttributeInstance = $currentLazyloadAttributeInstance;
                    continue;
                }
                // in case of entitySets, we add merge properties and elements of current loaded instance to first loaded instance
                if (is_a($currentLazyLoadedEntity, EntitySet::class, true)) {
                    $lazyLoadedEntity->mergeFromOtherSet($currentLazyLoadedEntity);
                }
            }
            if ($lazyLoadedEntity) {
                $this->$propertyName = $lazyLoadedEntity;
                //set parent / child relationsship
                if ($lazyLoadedEntity instanceof DefaultObject) {
                    if ($lazyloadAttributeInstance->addAsChild) {
                        $this->addChildren($lazyLoadedEntity);
                    }
                    // adding current instance as child of $lazyLoadedEntity will automatically
                    // set $lazyLoadedEntity as parent of current instance
                    if ($lazyloadAttributeInstance->addAsParent) {
                        // we want to add current instance to parent if parent has a property of current type
                        // e.g. when we load (parent) OrmAccount from OrmProject we expect to have project also in account
                        // check if lazyloaded entities has current current entity as property
                        $instanceAddedToParent = false;
                        if ($propertyOfCurrentType = $lazyLoadedEntity->getPropertyOfType($this::class)) {
                            $propertyOfCurrentTypeName = $propertyOfCurrentType->getName();
                            // check if $propertyOfCurrentTypeName is not equal to propertyName
                            // e.g. parentWorld, cause this way we mss up references, parent World would have as parentWorld put the Child World
                            if ($propertyOfCurrentTypeName != $propertyName) {
                                if (!isset($lazyLoadedEntity->$propertyOfCurrentTypeName) || is_null(
                                        $lazyLoadedEntity->$propertyOfCurrentTypeName
                                    )) {
                                    $instanceAddedToParent = true;
                                    $lazyLoadedEntity->$propertyOfCurrentTypeName = $this;
                                    $lazyLoadedEntity->addChildren($this);
                                }
                            }
                        }
                        // check if lazyloaded entities has current current entity within a EntitySet as property
                        if (!$instanceAddedToParent && DefaultObject::isEntity($this)) {
                            /** @var Entity $this */
                            if ($entitySetClass = $this::getEntitySetClass()) {
                                if ($propertyOfCurrentSetType = $lazyLoadedEntity->getPropertyOfType(
                                    $entitySetClass
                                )) {
                                    $propertyOfCurrentSetTypeName = $propertyOfCurrentSetType->getName();
                                    // property of current EntitySet type exists but is not instantiated
                                    if (!isset($lazyLoadedEntity->$propertyOfCurrentSetTypeName) || is_null(
                                            $lazyLoadedEntity->$propertyOfCurrentSetTypeName
                                        )) {
                                        /** @var EntitySet $entitySetInstance */
                                        $entitySetInstance = new $entitySetClass();
                                        $lazyLoadedEntity->$propertyOfCurrentSetTypeName = $entitySetInstance;
                                        $lazyLoadedEntity->addChildren($entitySetInstance);
                                        $entitySetInstance->add($this);
                                        $instanceAddedToParent = true;
                                    } // property of current EntitySet type exists and is already instantiated
                                    else {
                                        /** @var EntitySet $entitySetInstance */
                                        $entitySetInstance = $lazyLoadedEntity->$propertyOfCurrentSetTypeName;
                                        $entitySetInstance->add($this);
                                    }
                                }
                            }
                        }
                    }
                }
                return $this->$propertyName;
            }
        }
        return null;
    }

    /**
     * Returns LazyLoad Properties and Lazyload Definitions
     * @return LazyLoad[][]
     */
    public static function getPropertiesToLazyLoad(): array
    {
        $currentClassName = DDDService::instance()->getContainerServiceClassNameForClass(static::class);

        $propertiesToLazyLoad = StaticRegistry::$propertiesToLazyLoadForClasses[$currentClassName] ?? null;
        if ($propertiesToLazyLoad) {
            return $propertiesToLazyLoad;
        }
        StaticRegistry::$propertiesToLazyLoadForClasses[$currentClassName] = [];
        $reflectionClass = ReflectionClass::instance($currentClassName);
        $properties = $reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC);
        foreach ($properties as $property) {
            $propertyName = $property->getName();
            // LazyLoad preparation
            foreach ($property->getAttributes(LazyLoad::class) as $attribute) {
                /** @var LazyLoad $lazyloadAttributeInstance */
                $lazyloadAttributeInstance = $attribute->newInstance();
                if (!isset(StaticRegistry::$propertiesToLazyLoadForClasses[$currentClassName][$propertyName])) {
                    StaticRegistry::$propertiesToLazyLoadForClasses[$currentClassName][$propertyName] = [];
                }

                // if we have a repository defined that matches the Autoloading Definition, and the property is not already set,
                // we unset the property name in the current instance so magic method __get access to the property can execute the
                // lazyload defined function
                if (!($property->hasDefaultValue() && $property->getDefaultValue() !== null)) {
                    if ($lazyloadAttributeInstance->createInstanceWithoutLoading) {
                        // we dont need a repository, as we only create an instance in a lazy fashion
                        StaticRegistry::$propertiesToLazyLoadForClasses[$currentClassName][$propertyName]['lazyInstance'] = $lazyloadAttributeInstance;
                    } else {
                        StaticRegistry::$propertiesToLazyLoadForClasses[$currentClassName][$propertyName][$lazyloadAttributeInstance->repoType] = $lazyloadAttributeInstance;
                    }
                }
            }
        }
        return StaticRegistry::$propertiesToLazyLoadForClasses[$currentClassName];
    }

    /**
     * Returns Repository Class name for repository type given, e.g. ORM
     * Repo Classes are defined by LazyLoadRepo Attributes set on class and stored statically
     * @param string $repoType
     * @return string|null
     */
    public static function getRepoClass(
        string $repoType = null
    ): ?string
    {
        $currentClassName = static::class;
        $defaultRepoType = LazyLoadRepo::getDafaultRepoType();
        if (!isset(StaticRegistry::$repoTypesForClasses[$currentClassName])) {
            $defaultRepoTypeForClass = null;
            $hasRepoMatchingTheDefaultRepoTyoe = false;
            $availableDBRepos = [];

            StaticRegistry::$repoTypesForClasses[$currentClassName] = [];
            // get_called_class returns the class name where the static call was executed (late static binding, PHP 5.3+)
            foreach (
                (ReflectionClass::instance($currentClassName))->getAttributes(LazyLoadRepo::class) as $attribute
            ) {
                /** @var LazyLoadRepo $lazyLoadRepo */
                $lazyLoadRepo = $attribute->newInstance();
                StaticRegistry::$repoTypesForClasses[$currentClassName][$lazyLoadRepo->repoType] = $lazyLoadRepo;
                if ($lazyLoadRepo->isDefault) {
                    $defaultRepoTypeForClass = $lazyLoadRepo->repoType;
                }
                if ($lazyLoadRepo->repoType == $defaultRepoType) {
                    $hasRepoMatchingTheDefaultRepoTyoe = true;
                }
                if (in_array($lazyLoadRepo->repoType, LazyLoadRepo::DATABASE_REPOS)) {
                    $availableDBRepos[] = $lazyLoadRepo->repoType;
                }
            }

            if ($defaultRepoTypeForClass) {
                // if we have a default repo set as parameter, we use this
                StaticRegistry::$defaultRepoTypesForClasses[$currentClassName] = $defaultRepoTypeForClass;
            } elseif ($hasRepoMatchingTheDefaultRepoTyoe) {
                // if there is a repo matching the default repo, we take this
                StaticRegistry::$defaultRepoTypesForClasses[$currentClassName] = $defaultRepoType;
            } elseif (count($availableDBRepos)) {
                // if there is another DB repo, we take this
                $firstDBRepo = $availableDBRepos[0];
                StaticRegistry::$defaultRepoTypesForClasses[$currentClassName] = $firstDBRepo;
            }
        }
        $repoType = $repoType ?? (StaticRegistry::$defaultRepoTypesForClasses[$currentClassName] ?? null);
        if (!$repoType) {
            return null;
        }
        if (!isset(StaticRegistry::$repoTypesForClasses[$currentClassName][$repoType])) {
            return null;
        }
        return DDDService::instance()->getContainerServiceClassNameForClass(
            StaticRegistry::$repoTypesForClasses[$currentClassName][$repoType]->repoClass
        );
    }

    /**
     * Returns a instance of the RepoClass
     * @param string|null $repoType
     * @return RepoEntity|DatabaseRepoEntity|null
     */
    public static function getRepoClassInstance(string $repoType = null
    ): RepoEntity|DatabaseRepoEntity|DatabaseRepoEntitySet|null
    {
        $repoClass = self::getRepoClass($repoType);
        if ($repoClass) {
            return new $repoClass();
        }
        return null;
    }

    /**
     * @return string[] Returns Repo classes refering to database related Repos, if existent
     */
    public static function getDatabaseRelatedRepoClasses(): array
    {
        $repoClasses = [];
        foreach (LazyLoadRepo::DATABASE_REPOS as $repoType) {
            $repoClass = static::getRepoClass($repoType);
            if ($repoClass) {
                $repoClasses[] = $repoClass;
            }
        }
        return $repoClasses;
    }

    /**
     * All non argus properties are loaded instantly by accessing them (recursively)
     * properties with argus load repo, are loaded at once, if possible
     * @param PropertyToBeLoaded[] $propertiesToBeLoaded
     * @param array $callStack
     * @return void
     * @throws ReflectionException
     */
    public function lazyLoadProperties(
        array $propertiesToBeLoaded,
        array $callStack = []
    ): void
    {
        if (isset($callStack[spl_object_id($this)])) {
            return;
        }
        if (!$propertiesToBeLoaded || !count($propertiesToBeLoaded)) {
            return;
        }
        $callStack[spl_object_id($this)] = true;

        /** @var LazyLoadTrait $currentClassName */
        $currentClassName = DDDService::instance()->getContainerServiceClassNameForClass(static::class);

        /** @var string[] $propertiesToBeArgusLoaded */
        $propertiesToBeArgusLoaded = [];
        /** @var PropertyToBeLoaded[] $propertiesToBeLoadedIteratively */
        $propertiesToBeLoadedIteratively = [];

        // if current class has a Argus Repo Class, all desendent classes should be Argus loadable
        $argusClass = $currentClassName::getRepoClass(LazyLoadRepo::ARGUS);

        foreach ($propertiesToBeLoaded as $propertyToBeLoaded) {
            $reflectioncClass = ReflectionClass::instance($propertyToBeLoaded->className);
            $propertyName = $propertyToBeLoaded->propertyName;

            $reflectioncProperty = $reflectioncClass->getProperty($propertyToBeLoaded->propertyName);
            if (!$reflectioncProperty) {
                // class property combination does not exist, we ignore it
                continue;
            }

            foreach ($reflectioncProperty->getAttributes(LazyLoad::class) as $lazyLoadAttribute) {
                /** @var LazyLoad $lazyLoadAttributeInstance */
                $lazyLoadAttributeInstance = $lazyLoadAttribute->newInstance();
                if ($lazyLoadAttributeInstance->repoType != LazyLoadRepo::ARGUS) {
                    // we have property that is loaded iteratively
                    if ($propertyToBeLoaded->className == $currentClassName) {
                        // property is from current class, try to laod it instant
                        if (!isset($this->$propertyName)) {
                            $this->$propertyName;
                        }
                    } else {
                        // put property to recursive stack
                        $propertiesToBeLoadedIteratively[] = $propertyToBeLoaded;
                    }
                } elseif ($propertyToBeLoaded->className == $currentClassName) {
                    // we have a class with an argus repo and property is to be argus loaded
                    if ($argusClass) {
                        // load later with argus call
                        /** @var LazyLoadTrait $propertyName */
                        $propertyName = $reflectioncProperty->getType()->getName();
                        $argusRepoClass = $propertyName::getRepoClass(LazyLoadRepo::ARGUS);
                        $propertiesToBeArgusLoaded[] = $argusRepoClass;
                    } elseif (!isset($this->$propertyName)) {
                        // as current class has no argus repo, we need to load property instantly
                        $this->$propertyName;
                    }
                } elseif ($argusClass) {
                    // as current class supports argus loading, we cann load argus properties later at once
                    /** @var LazyLoadTrait $propertyName */
                    $propertyName = $reflectioncProperty->getType()->getName();
                    $argusRepoClass = $propertyName::getRepoClass(LazyLoadRepo::ARGUS);
                    $propertiesToBeArgusLoaded[] = $argusRepoClass;
                } else {
                    // current class does not support argus loading, we need to pass argus properties to children
                    $propertiesToBeLoadedIteratively[] = $propertyToBeLoaded;
                }
            }
        }

        // recursively pass parameter to children and load non argus properties instantly
        if ($propertiesToBeLoadedIteratively) {
            foreach ($this->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $propertyName = $property->getName();
                if (isset($this->$propertyName) && is_object($this->$propertyName) && method_exists(
                        $this->$propertyName,
                        'lazyLoadProperties'
                    )) {
                    $this->$propertyName->lazyLoadProperties($propertiesToBeLoadedIteratively, $callStack);
                }
            }
        }

        if ($propertiesToBeArgusLoaded) {
            $argusRepoClass = $currentClassName::getRepoClass(LazyLoadRepo::ARGUS);
            /** var ArgusTrait $argusRepo */
            if ($argusRepoClass) {
                $argusRepo = new $argusRepoClass();
                if (method_exists($argusRepo, 'fromEntity')) {
                    $argusRepo->fromEntity($this);
                    $argusRepo->setPropertiesToLoad(...$propertiesToBeArgusLoaded);
                    $argusRepo->argusLoad(autoloadCurrentObject: false);
                    $argusRepo->toEntity(entityInstance: $this);
                }
            }
        }
    }
}
