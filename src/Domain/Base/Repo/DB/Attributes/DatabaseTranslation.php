<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Reflection\ReflectionClass;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\QueryBuilder;
use ReflectionException;

#[Attribute(Attribute::TARGET_CLASS)]
class DatabaseTranslation
{
    use BaseAttributeTrait;

    /** @var bool whether to use registry APC cache for this Repo OrmEntity or not */
    public const MODELS_WITH_SEARCHABLE_COLUMNS = [];

    /** @var string|null Default language code is set based on ENTITY_TRANSLATIONS_DEFAULT_LANGUAGE env variable, or defaults to en if not set */
    protected static ?string $defaultLanguageCode = null;

    /** @var string Currently active language code */
    public static string $languageCode;

    /** @var DatabaseTranslation[] */
    public static $instance = [];

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
     * @param string $languageCode
     * @return void
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
    public function getPropertiesToTranslate():?array {
        return $this->propertiesToTranslate;
    }

    /**
     * @return bool Returns true if properties to translate exist
     */
    public function hasPropertiesToTranslate():bool {
        return !empty($this->getPropertiesToTranslate());
    }

    /**
     * @return bool Returns true if current global languageCode is the default languageCode
     */
    public static function isCurrentLanguageCodeDefaultLanguage():bool {
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

    public static function getInstance(string $className): DatabaseTranslation|false
    {
        if (isset(self::$instance[$className]) && self::$instance[$className]) {
            return self::$instance[$className];
        } elseif (isset(self::$instance[$className]) && !self::$instance[$className]) {
            return false;
        }
        $reflectionClass = ReflectionClass::instance($className);
        self::$instance[$className] = false;
        foreach ($reflectionClass->getAttributes() as $attributes) {
            if (is_a($attributes->getName(), self::class, true)) {
                self::$instance[$className] = $attributes->newInstance();
            }
        }
        return self::$instance[$className] ?? false;
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
        return $queryBuilder;
    }

    /**
     * Applies translations found in DoctrineModel to its regular columns
     * @param DoctrineModel $doctrineModelInstance
     * @return void
     */
    public function applyTranslationToDoctrineModelInstance(DoctrineModel &$doctrineModelInstance): void {}

    /**
     * Updates or creates Translation
     * @param Entity $entity
     * @param DatabaseRepoEntity $databaseRepoEntity
     * @return void
     * @throws ForbiddenException
     * @throws Exception
     * @throws ReflectionException
     */
    public function updateOrCreateTranslation(Entity $entity, DatabaseRepoEntity $databaseRepoEntity): void {}

    /**
     * Deletes translation
     * @param Entity $entity
     * @param DatabaseRepoEntity $databaseRepoEntity
     * @return bool
     * @throws ReflectionException
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function deleteTranslation(Entity $entity, DatabaseRepoEntity $databaseRepoEntity): bool
    {
        return false;
    }
}