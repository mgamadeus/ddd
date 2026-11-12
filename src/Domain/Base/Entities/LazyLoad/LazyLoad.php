<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\LazyLoad;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\DDDService;
use ReflectionNamedType;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class LazyLoad
{
    use BaseAttributeTrait;

    public const string DEFAULT_LAZYLOAD_METHOD = 'lazyload';

    /** @var string|null The repostiroy type used for lazy loading */
    public ?string $repoType;

    /**
     * @var bool Whether the attribute names its repo type. Without one, the repo type is the application's default
     * (`LAZYLOAD_DEFAULT_REPO_TYPE`), or the target class' own default repo when the target has no repo of that type
     * ({@see LazyLoad::applyDefaultRepoTypeOfTargetClass()}).
     */
    public bool $isRepoTypeDeclared = false;

    /** @var string|null The target entity class name, by default this is defined by the type of the property, but it can be overwritten */
    public ?string $entityClassName;

    /** @var string|null The lazy load method within the repository class to call on load */
    public ?string $loadMethod = self::DEFAULT_LAZYLOAD_METHOD;

    /** @var string|null Defined Entity Class that represents intermediary table in case of n-n relations */
    public ?string $loadThrough = null;

    /** @var string|null The property containing the id to load */
    protected ?string $propertyContainingId;

    /** @var bool If true, adds the the loaded Entity as child */
    public bool $addAsChild = true;

    /** @var bool If true, adds the the loaded Entity as parent */
    public bool $addAsParent = false;

    /** @var string|null The property name the Lazyload is applied to */
    public ?string $propertyName;

    /** @var bool If true, no loading is performed, but only an instance is created */
    public bool $createInstanceWithoutLoading = false;

    /** @var bool If true, no lazyloading will by executed on __get method, used to deactivate unintentional triggering of lazyloading */
    public static $disableLazyLoadGlobally = false;

    /**
     * @var string|null The repository class used for lazyloading
     * sometimes it is necessary to have an individual repository class, e.g. when using unon types on property to lazyload
     * e.g. SettingsA|SettingsB => we use DirectorySettings as Repo class which returns SettingsA or SettingsB
     */
    public ?string $repoClass;

    /**
     * @param string|null $repoType The repository type used for lazy loading
     * @param string|null $loadMethod The lazy load method within the repository class to call on load
     * @param string|null $propertyContainingId The property containing the id to load
     * @param bool|null $addAsChild If true, adds the the loaded Entity as child
     * @param bool|null $addAsParent If true, adds the the loaded Entity as parent
     * @param bool $useCache If true, lazyload uses the corresponding caching mechanism of the repository
     * @param string|null $repoClass The repository class used for lazyloading
     * @param bool $createInstanceWithoutLoading If true, no loading is performed, but only an instance is created
     */
    public function __construct(
        ?string $repoType = null,
        ?string $loadMethod = null,
        ?string $propertyContainingId = null,
        ?bool $addAsChild = null,
        ?bool $addAsParent = null,
        public bool $useCache = true,
        ?string $repoClass = null,
        bool $createInstanceWithoutLoading = false,
        ?string $loadThrough = null,
        ?string $entityClassName = null
    ) {
        $this->isRepoTypeDeclared = $repoType !== null && $repoType !== '';
        $this->repoType = $repoType ? $repoType : LazyLoadRepo::getDafaultRepoType();
        $this->loadMethod = $loadMethod ? $loadMethod : self::DEFAULT_LAZYLOAD_METHOD;
        $this->propertyContainingId = $propertyContainingId;
        if($addAsChild === true || ($addAsChild === null && !$addAsParent ) ) {
            $addAsChild = true;
            $addAsParent = false;
        } elseif ($addAsParent === true) {
            $addAsChild = false;
        } else {
            $addAsChild = true;
            $addAsParent = false;
        }
        $this->addAsParent = $addAsParent;
        $this->addAsChild = $addAsChild;
        $this->repoClass = $repoClass;
        $this->createInstanceWithoutLoading = $createInstanceWithoutLoading;
        $this->loadThrough = $loadThrough;
        $this->entityClassName = $entityClassName;
    }

    /**
     * @param string $propertyNameAttributeIsAttachedTo
     */
    public function setPropertyNameAttributeIsAttachedTo(string $propertyNameAttributeIsAttachedTo): void
    {
        $this->propertyNameAttributeIsAttachedTo = $propertyNameAttributeIsAttachedTo;
        $this->propertyName = $propertyNameAttributeIsAttachedTo;
    }

    /**
     * The class the lazy load reads: `entityClassName` when set, else the declared type of the property (null for a
     * union, a builtin or an untyped property) — as the application's class of it (getApplicationClassNameOfTarget()).
     *
     * @param ReflectionProperty $reflectionProperty the property the attribute is attached to
     * @return string|null
     */
    public function getTargetClassNameForProperty(ReflectionProperty $reflectionProperty): ?string
    {
        $declaredClassName = null;
        if ($this->entityClassName) {
            $declaredClassName = $this->entityClassName;
        } else {
            $propertyType = $reflectionProperty->getType();
            if ($propertyType instanceof ReflectionNamedType && !$propertyType->isBuiltin()) {
                $declaredClassName = $propertyType->getName();
            }
        }
        return $declaredClassName === null ? null : static::getApplicationClassNameOfTarget($declaredClassName);
    }

    /**
     * The class a lazy load of a declared framework or module class reads: the application's class of it (a
     * container alias or the same path under `App\`) when that class declares repositories of its own, so a module's
     * `?Account $account` loads the application's account through the application's repository and repo type.
     * An application class without a LazyLoadRepo attribute (class attributes are not inherited) leaves the declared
     * class, whose repositories it shares.
     *
     * @param string $declaredClassName
     * @return string
     */
    public static function getApplicationClassNameOfTarget(string $declaredClassName): string
    {
        $applicationClassName = DDDService::instance()->getContainerServiceClassNameForClass($declaredClassName);
        if (
            !$applicationClassName
            || $applicationClassName === $declaredClassName
            || !class_exists($applicationClassName)
            || !is_a($applicationClassName, $declaredClassName, true)
            || !ReflectionClass::instance($applicationClassName)->getAttributes(LazyLoadRepo::class, \ReflectionAttribute::IS_INSTANCEOF)
        ) {
            return $declaredClassName;
        }
        return $applicationClassName;
    }

    /**
     * A lazy load without a declared repo type that the target class has no repo for (the application's default is
     * `LEGACY_DB`, the target of a package has only a `DB` repo) takes the target's own default repo type: its repo
     * marked as default, else the repo of the application's default type, else its first database repo. A declared
     * repo type, a class-method load and a target that has a repo of the default type stay as they are.
     *
     * @param string|null $targetClassName the class the lazy load reads
     * @return void
     */
    public function applyDefaultRepoTypeOfTargetClass(?string $targetClassName): void
    {
        if ($this->isRepoTypeDeclared || $this->repoClass || !$targetClassName || !class_exists($targetClassName)) {
            return;
        }
        if (!method_exists($targetClassName, 'getRepoClass') || !method_exists($targetClassName, 'getDefaultRepoTypeOfClass')) {
            return;
        }
        if ($targetClassName::getRepoClass($this->repoType)) {
            return;
        }
        $targetDefaultRepoType = $targetClassName::getDefaultRepoTypeOfClass();
        if ($targetDefaultRepoType !== null) {
            $this->repoType = $targetDefaultRepoType;
        }
    }

    public function getPropertyContainingId():?string {
        if (isset($this->propertyContainingId))
            return $this->propertyContainingId;
        // try to contactenate with "Id" to see if this exists $lazyloadInitiator
        $propertyContainingId = $this->propertyName . 'Id';

        $initiatingReflectionClass = ReflectionClass::instance($this->classNameAttributeIsAttachedTo);
        if ($initiatingReflectionClass->hasProperty($propertyContainingId)) {
            $this->propertyContainingId = $propertyContainingId;
            return $this->propertyContainingId;
        }
        $this->propertyContainingId = null;
        return null;
    }
}