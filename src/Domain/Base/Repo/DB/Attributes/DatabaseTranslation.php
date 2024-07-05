<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Reflection\ReflectionClass;
use Doctrine\ORM\QueryBuilder;
use ReflectionException;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_CLASS)]
class DatabaseTranslation
{
    use BaseAttributeTrait;

    /** @var array whether to use registry APC cache for this Repo OrmEntity or not */
    public const MODELS_WITH_SEARCHABLE_COLUMNS = [];

    /** @var string|null Default language code is set based on ENTITY_TRANSLATIONS_DEFAULT_LANGUAGE env variable, or defaults to en if not set */
    protected static ?string $defaultLanguageCode = null;

    /** @var string Currently active language code */
    public static string $languageCode;

    /** @var DatabaseTranslation[] */
    public static array $instance = [];

    /** @var string[] Properties to translate */
    public array $propertiesToTranslate = [];

    /**
     * @param string ...$propertiesToTranslate
     */
    public function __construct(string ...$propertiesToTranslate)
    {
        $this->propertiesToTranslate = $propertiesToTranslate;
    }

    public static function getDefaultLanguageCode(): string
    {
        if (isset(static::$defaultLanguageCode)) {
            return static::$defaultLanguageCode;
        }
        $languageCode = Config::getEnv('ENTITY_TRANSLATIONS_DEFAULT_LANGUAGE');
        if (!$languageCode) {
            $languageCode = 'en';
        }
        self::$defaultLanguageCode = $languageCode;
        return self::$defaultLanguageCode;
    }#

    /**
     * Sets global current languageCode
     * @param string $languageCode
     * @return void
     */
    public static function setLanguageCode(string $languageCode): void
    {
        self::$languageCode = $languageCode;
    }

    /**
     * Returns current glonal languageCode
     * @return string
     */
    public static function getLanguageCode(): string
    {
        if (isset(static::$languageCode)) {
            return static::$languageCode;
        }
        return static::getDefaultLanguageCode();
    }

    /**
     * @return string[]|null Returns current properties to translate
     */
    public function getPropertiesToTranslate(): ?array
    {
        return $this->propertiesToTranslate;
    }

    /**
     * @return bool Returns true if properties to translate exist
     */
    public function hasPropertiesToTranslate(): bool
    {
        return !empty($this->getPropertiesToTranslate());
    }

    /**
     * @return void Adds propertiesToTranslate
     */
    public function addPropertiesToTranslate(string ...$propertiesToTranslate): void
    {
        if (!is_array($this->propertiesToTranslate)) {
            $this->propertiesToTranslate = [];
        }
        $this->propertiesToTranslate = array_merge($this->propertiesToTranslate, $propertiesToTranslate);
    }

    /**
     * @return bool Returns true if current global languageCode is the default languageCode
     */
    public static function isCurrentLanguageCodeDefaultLanguage(): bool
    {
        return static::getLanguageCode() == static::getDefaultLanguageCode();
    }

    /**
     * Reverse a string - supports UTF-8 encoding
     *
     * @param $str
     * @return string
     */
    public static function utf8_strrev($str)
    {
        preg_match_all('/./us', $str, $ar);
        return implode(array_reverse($ar[0]));
    }

    /**
     * @param string $repoEntityClassName
     * @return false|static
     * @throws ReflectionException
     */
    public static function getInstance(string $repoEntityClassName): static|false
    {
        if (isset(self::$instance[$repoEntityClassName]) && self::$instance[$repoEntityClassName]) {
            return self::$instance[$repoEntityClassName];
        }
        if (isset(self::$instance[$repoEntityClassName]) && !self::$instance[$repoEntityClassName]) {
            return false;
        }

        $reflectionClass = ReflectionClass::instance($repoEntityClassName);
        self::$instance[$repoEntityClassName] = false;
        foreach ($reflectionClass->getAttributes() as $attributes) {
            if (is_a($attributes->getName(), self::class, true)) {
                /** @var static $translationAttributeInstance */
                $translationAttributeInstance = $attributes->newInstance();
                /** @var DatabaseRepoEntity $repoEntityClassName */
                $entityReflectionClass = ReflectionClass::instance($repoEntityClassName::BASE_ENTITY_CLASS);
                // Add propreties from Entity Class that have DatabaseTranslation attribute
                foreach ($entityReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
                    if ($reflectionProperty->hasAttribute(self::class)) {
                        $translationAttributeInstance->addPropertiesToTranslate($reflectionProperty->getName());
                    }
                }

                /** @var string $repoEntityClassName */
                self::$instance[$repoEntityClassName] = $attributes->newInstance();
            }
        }

        return self::$instance[$repoEntityClassName] ?? false;
    }

    /**
     * Applies Translation join to QueryBuilder
     * @param QueryBuilder $queryBuilder
     * @param string $tableName
     * @param string $modelAlias
     * @return QueryBuilder|null
     */
    public function applyTranslationJoinToQueryBuilder(
        QueryBuilder &$queryBuilder,
        string $tableName,
        string $modelAlias
    ): ?QueryBuilder {
        // If current Language is default language, no join needs to be applied
        if (static::isCurrentLanguageCodeDefaultLanguage()) {
            return $queryBuilder;
        }
        return $queryBuilder;
    }

    /**
     * Applies translations found in DoctrineModel to its regular properties
     * @param DoctrineModel $doctrineModelInstance
     * @return void
     */
    public function applyTranslationToDoctrineModelInstance(DoctrineModel &$doctrineModelInstance): void
    {
    }

    /**
     * Updates or creates Translation
     * @param Entity $entity
     * @param DatabaseRepoEntity $databaseRepoEntity
     * @return void
     */
    public function updateOrCreateTranslation(Entity $entity, DatabaseRepoEntity $databaseRepoEntity): void
    {
    }

    /**
     * Deletes translation
     * @param Entity $entity
     * @param DatabaseRepoEntity $databaseRepoEntity
     * @return bool
     */
    public function deleteTranslation(Entity $entity, DatabaseRepoEntity $databaseRepoEntity): bool
    {
        return false;
    }
}
