<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities;

use DDD\Domain\Base\Entities\Lazyload\LazyLoad;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Infrastructure\Base\DateTime\Date;

class StaticRegistry
{
    /** @var string[] static registry for OrmEntity::class => OrmEntitySet::class association */
    public static array $entitySetClasses = [];

    /** @var string[] */
    public static array $entityClasses = [];

    /** @var string[] Doctrine model class names by Entity class names */
    public static array $modelNamesForEntityClasses = [];

    /** @var LazyLoad[][][] Allocation of class names and properties to lazyload */
    public static array $propertiesToLazyLoadForClasses = [];

    /** @var LazyLoadRepo[][] */
    public static ?array $repoTypesForClasses = [];

    /** @var LazyLoadRepo[][] */
    public static ?array $defaultRepoTypesForClasses = [];

    /** @var string[][][] */
    public static ?array $repoClassesForProperties = [];

    /** @var array|null */
    public static ?array $entityServices = [];

    /** @var array|null */
    public static ?array $entityDependsOnEntity = [];

    /** @var Date[] as many date series contain a lot of dates, this caching reduces the laod */
    public static $dateFromStringCache = [];

    /** @var string[] Allocation of DDD framework class names to App class names, app classes replace framework classes */
    public static $frameworkToAppClassNames = [];

    /**
     * @var array Allocation of Roles required for update of Entities based on RolesRequiredForUpdate attribute
     */
    public static $rolesRequiredForUpdateOnEntities = [];

    /**
     * @var array Allocation Container Classes (as class names can be overwritten in services.yaml)
     */
    public static $containerServiceClassMap = null;
}