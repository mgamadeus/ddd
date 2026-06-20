<?php

namespace DDD\Domain\Base\Entities\Traits;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;

trait EntityTrait
{
    use DefaultObjectTrait;

    public const bool IS_ENTITY = true;

    /** @var string|null The internal identifier of the entity */
    public int|string|null $id = null;

    /**
     * returns the class of the corresponding EntitySet if existent
     * * uses configured number if present in static::$entitySetClass otherwise it uses inflector to pluralize
     * *
     * * In case of entities extending other specific entities, this will return the parents Entity's EntitySet class
     * * e.g. Event extends Post, will return Posts for Event as EntitySet class
     * @return string|null
     * @throws ReflectionException
     */
    public static function getEntitySetClass(): ?string
    {
        $currentClassName = static::class;
        if (isset(StaticRegistry::$entitySetClasses[$currentClassName])) {
            return StaticRegistry::$entitySetClasses[$currentClassName];
        }
        // if Entity has as parent class another specific Entity
        // we return the EntitySet class of the parent Entity class
        /** @var EntityTrait $parentEntityClassName */
        if ($parentEntityClassName = static::getParentEntityClassName()) {
            $entitySetClass = $parentEntityClassName::getEntitySetClass();
            StaticRegistry::$entitySetClasses[$currentClassName] = $entitySetClass;
            return $entitySetClass;
        }
        /** @var DefaultObject $currentClassName */
        $classWithNamespace = $currentClassName::getClassWithNamespace();
        $inflector = InflectorFactory::create()->build();
        $pluralClassName = $inflector->pluralize($classWithNamespace->name);
        $classWithNamespacePlural = new ClassWithNamespace($pluralClassName, $classWithNamespace->namespace);
        if (class_exists($classWithNamespacePlural->getNameWithNamespace())) {
            StaticRegistry::$entitySetClasses[$currentClassName] = $classWithNamespacePlural->getNameWithNamespace();
            return StaticRegistry::$entitySetClasses[$currentClassName];
        }
        // Last-resort fallback for cross-namespace inheritance: when an entity is
        // explicitly marked with #[ReuseParentEntitySet] and we've reached this point
        // without finding a same-namespace plural, fall through to the parent class's
        // EntitySet across the namespace boundary (e.g. App\Foo -> DDD\Foos). The
        // default guard in getParentEntityClassName() is intentional — most App
        // subclasses need their own App EntitySet — but constants-only subclasses are
        // a common-enough exception to warrant an explicit opt-in attribute.
        $reflectionClass = $currentClassName::getReflectionClass();
        if ($reflectionClass->hasAttribute(\DDD\Domain\Base\Entities\Attributes\ReuseParentEntitySet::class)) {
            $crossNamespaceParent = static::getParentEntityClassName(considerOnlyClassesFromSameRootNamespace: false);
            if ($crossNamespaceParent) {
                /** @var EntityTrait $crossNamespaceParent */
                $entitySetClass = $crossNamespaceParent::getEntitySetClass();
                if ($entitySetClass) {
                    StaticRegistry::$entitySetClasses[$currentClassName] = $entitySetClass;
                    return $entitySetClass;
                }
            }
        }
        return null;
    }

    /**
     *  If this Entity extends another specific Entity, returns the parent Entity class name, else null
     *  Important: if partent class is abstract, it wont be considered
     * @param bool $considerOnlyClassesFromSameRootNamespace if true, e.g. App classes extending framework classes (DDD) do not return framework class
     * @return string|null
     * @throws ReflectionException
     */
    public static function getParentEntityClassName(bool $considerOnlyClassesFromSameRootNamespace = true): ?string
    {
        /** @var EntityTrait $currentClassName */
        $currentClassName = static::class;
        $reflectionClass = $currentClassName::getReflectionClass();
        $parentClass = $reflectionClass->getParentClass();
        $parentClassName = $reflectionClass->getParentClass()->getName();
        /** @var string $currentClassName */
        /** @var string $parentClassName */
        if (
            DefaultObject::isEntity($parentClassName) && !$parentClass->isAbstract() && $parentClassName != Entity::class
        ) {
            if ($considerOnlyClassesFromSameRootNamespace) {
                if (
                    (str_starts_with($currentClassName, DDDService::APP_ROOT_NAMESPACE) && str_starts_with(
                            $parentClassName,
                            DDDService::APP_ROOT_NAMESPACE
                        )) || (str_starts_with(
                            $currentClassName,
                            DDDService::FRAMEWORK_ROOT_NAMESPACE
                        ) && str_starts_with(
                            $parentClassName,
                            DDDService::FRAMEWORK_ROOT_NAMESPACE
                        ))
                ) {
                    return $parentClassName;
                } else {
                    return null;
                }
            } else {
                return $parentClassName;
            }
        }
        return null;
    }

    /**
     * returns an individual uniqu key for current entity
     * @return string
     */
    public function uniqueKey(): string
    {
        if (isset($this->id)) {
            return static::uniqueKeyStatic($this->id ?? null);
        }
        return parent::uniqueKey();
    }

    /**
     * @return Entity|null Persists Entity
     * @return static|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws ORMException
     * @throws NonUniqueResultException
     * @throws OptimisticLockException
     */
    public function update(int $depth = DatabaseRepoEntity::UPDATE_DEFAULT_RECURSIVE_DEPTH): ?static
    {
        $service = static::getService();
        if (!$service) {
            return null;
        }
        $classWithNamespace = new ClassWithNamespace(static::class);
        $updateMethod = 'update';
        if (method_exists($service, $updateMethod)) {
            $updatedEntity = $service->$updateMethod($this, $depth);
            $this->overwritePropertiesFromOtherObject($updatedEntity);
            return $this;
        }
        $updateMethod = 'update' . $classWithNamespace->name;
        if (method_exists($service, $updateMethod)) {
            $updatedEntity = $service->$updateMethod($this, $depth);
            $this->overwritePropertiesFromOtherObject($updatedEntity);
            return $this;
        }
        if ($parentEntityClassName = static::getParentEntityClassName()) {
            $updateMethod = 'update' . $parentEntityClassName;
            if (method_exists($service, $updateMethod)) {
                $updatedEntity = $service->$updateMethod($this, $depth);
                $this->overwritePropertiesFromOtherObject($updatedEntity);
                return $this;
            }
        }
        // generic update function
        $repoClassInstance = static::getRepoClassInstance();
        if ($repoClassInstance && method_exists($repoClassInstance, 'update')) {
            return $repoClassInstance->update($this, $depth);
        }
        return null;
    }

    /**
     * Persist ONLY the named properties' columns of THIS already-persisted entity — a targeted, rights-bypassing,
     * high-concurrency PARTIAL update that writes no other column and, unlike {@see update()}, does NOT reload/detach
     * this instance. Use for competing single-column writes (run-state claim/heartbeat, async-computed
     * summary/embedding/verdict) where a full-row update would clobber a concurrently-written column. No-op if this
     * entity has no id. Delegates to {@see \DDD\Domain\Base\Repo\DB\DBEntity::updatePartialIgnoringRights()}.
     *
     * @param string ...$propertyNames Entity property names whose columns are the ONLY ones written.
     */
    public function updatePartialIgnoringRights(string ...$propertyNames): static
    {
        $repoClassInstance = static::getRepoClassInstance(LazyLoadRepo::DB);
        if ($repoClassInstance instanceof DBEntity) {
            $repoClassInstance->updatePartialIgnoringRights($this, ...$propertyNames);
        }
        return $this;
    }

    /**
     * Returns Entity by id
     * @param string|int $id
     * @return static|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public static function byId(string|int|null $id): ?static
    {
        $service = static::getService();
        if (!$service) {
            /** @var DatabaseRepoEntity|null $repoClassInstance */
            $repoClassInstance = self::getRepoClassInstance();
            if (!$repoClassInstance) {
                return null;
            }
            return $repoClassInstance->find($id);
        }
        $classWithNamespace = new ClassWithNamespace(static::class);
        $findMethod = 'find';
        if (method_exists($service, $findMethod)) {
            return $service->$findMethod($id);
        }
        $findMethod = 'find' . $classWithNamespace->name;
        if (method_exists($service, $findMethod)) {
            return $service->$findMethod($id);
        }
        if ($parentEntityClassName = static::getParentEntityClassName()) {
            $findMethod = 'find' . $parentEntityClassName;
            if (method_exists($service, $findMethod)) {
                return $service->$findMethod($id);
            }
        }
        return null;
    }

    /**
     * @return void Delete Entity
     * @throws BadRequestException
     * @throws ReflectionException
     * @throws ORMException
     * @throws NonUniqueResultException
     * @throws OptimisticLockException
     */
    public function delete(): void
    {
        $service = static::getService();
        if (!$service) {
            return;
        }
        $deleteMethod = 'delete';
        if (method_exists($service, $deleteMethod)) {
            $service->$deleteMethod($this);
            return;
        }
        $classWithNamespace = new ClassWithNamespace(static::class);
        $deleteMethod = 'delete' . $classWithNamespace->name;
        if (method_exists($service, $deleteMethod)) {
            $service->$deleteMethod($this);
            return;
        }

        /** @var Entity $parentEntityClassName */
        if ($parentEntityClassName = static::getParentEntityClassName()) {
            $updateMethod = 'delete' . $parentEntityClassName;
            if (method_exists($service, $updateMethod)) {
                $service->$deleteMethod($this);
                return;
            }
        }
        // generic delete function
        $repoClassInstance = static::getRepoClassInstance();
        if ($repoClassInstance && method_exists($repoClassInstance, 'delete')) {
            $repoClassInstance->delete($this);
        }
        return;
    }

    /**
     * @return Service|null Returns the Service for this Entity
     */
    public static function getService(): ?Service
    {
        /** @var EntitySet $entitySetClass */
        $entitySetClass = self::getEntitySetClass();
        if (!$entitySetClass) {
            return null;
        }
        return $entitySetClass::getService();
    }

    /**
     * Returns true, if the current entity depends on the given Entity or Set
     * * An Entity depends on another, when contains an id of the other, e.g. Project depends on Account when it has an accountId and LazyLoad on the Account
     * @param DefaultObject $entityOrSet
     * @return bool
     * @throws ReflectionException
     */
    public static function dependsOn(DefaultObject &$entityOrSet): bool
    {
        if (isset(StaticRegistry::$entityDependsOnEntity[static::class][$entityOrSet::class])) {
            return StaticRegistry::$entityDependsOnEntity[static::class][$entityOrSet::class];
        }
        $depdensOn = false;
        foreach (self::getReflectionClass()->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            $types = [];
            $reflectionType = $reflectionProperty->getType();
            if ($reflectionType instanceof ReflectionNamedType) {
                $types[] = $reflectionType->getName();
            } elseif ($reflectionType instanceof ReflectionUnionType) {
                foreach ($reflectionType->getTypes() as $reflectionType) {
                    $types[] = $reflectionType->getName();
                }
            }
            foreach ($types as $typeName) {
                if ($typeName == $entityOrSet::class) {
                    $depdensOn = true;
                    StaticRegistry::$entityDependsOnEntity[static::class][$entityOrSet::class] = $depdensOn;
                    return $depdensOn;
                }
            }
        }
        StaticRegistry::$entityDependsOnEntity[static::class][$entityOrSet::class] = $depdensOn;
        return $depdensOn;
    }

    /**
     * Returns the property name for the property containing the id of the parent Entity given. If not found, returns false.
     * E.g. returns when called on AdSet with a Campaign (parent Entity) given, it will return campaignId
     * @param DefaultObject $entityOrSet
     * @param string $propertyName
     * @return string|bool
     */
    public static function getPropertyContainingIdForParentEntity(
        DefaultObject &$entityOrSet
    ): string|bool {
        if (!static::dependsOn($entityOrSet)) {
            return false;
        }
        $propertiesToLazyLoad = self::getPropertiesToLazyLoad();
        $reflectionClass = self::getReflectionClass();
        // Collect ALL DB-repo lazyload properties whose type matches the parent entity. With a SINGLE match this is the
        // historical behaviour (most parent FKs do not set addAsParent). With MORE THAN ONE match — two relations typed
        // with the same class, e.g. AIConversationMessage::$delegatedConversation (forward FK to a CHILD) and
        // $aiConversation (the real parent, addAsParent) — TYPE alone cannot disambiguate, and returning the first by
        // declaration order stamps the WRONG FK column (the conversation #298 self-edge: a message's own conversation
        // id written into delegatedConversationId). So when ambiguous, the property explicitly marked addAsParent wins;
        // a non-addAsParent forward FK is never adopted as the parent. (Mirrors the addAsParent exclusion the cascade
        // already applies in DatabaseRepoEntity::updateDependentEntities.) RC plan 36 §3.2 — see upstream-handoff doc.
        $firstMatchPropertyContainingId = null;
        $addAsParentPropertyContainingId = null;
        foreach ($propertiesToLazyLoad as $propertyName => $repoTypeAndLazyloadAttributeInstance) {
            $reflectionProperty = $reflectionClass->getProperty($propertyName);
            if (!($reflectionProperty->getType() instanceof ReflectionNamedType)) {
                continue;
            }
            if (!is_a($reflectionProperty->getType()->getName(), $entityOrSet::class, true)) {
                continue;
            }
            foreach ($repoTypeAndLazyloadAttributeInstance as $repoType => $lazyLoadAttributeInstance) {
                if (!in_array($repoType, LazyLoadRepo::DATABASE_REPOS)) {
                    continue;
                }
                $propertyContainingId = $lazyLoadAttributeInstance->getPropertyContainingId();
                if (!$propertyContainingId) {
                    continue;
                }
                if ($firstMatchPropertyContainingId === null) {
                    $firstMatchPropertyContainingId = $propertyContainingId;
                }
                if ($lazyLoadAttributeInstance->addAsParent && $addAsParentPropertyContainingId === null) {
                    $addAsParentPropertyContainingId = $propertyContainingId;
                }
            }
        }
        if ($addAsParentPropertyContainingId !== null) {
            return $addAsParentPropertyContainingId;
        }
        return $firstMatchPropertyContainingId ?? false;
    }
}