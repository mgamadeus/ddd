<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Lazyload;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\TextUI\ReflectionException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class LazyLoad
{
    use BaseAttributeTrait;

    public const DEFAULT_LAZYLOAD_METHOD = 'lazyload';

    /** @var string|null The repostiroy type used for lazy loading */
    public ?string $repoType;

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
        string $repoType = null,
        string $loadMethod = null,
        string $propertyContainingId = null,
        bool $addAsChild = null,
        bool $addAsParent = null,
        public bool $useCache = true,
        string $repoClass = null,
        bool $createInstanceWithoutLoading = false,
        string $loadThrough = null,
        string $entityClassName = null
    ) {
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