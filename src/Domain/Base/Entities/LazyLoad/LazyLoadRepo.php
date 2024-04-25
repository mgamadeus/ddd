<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\LazyLoad;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Infrastructure\Libs\Config;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class LazyLoadRepo
{
    use BaseAttributeTrait;

    public ?string $repoType;
    public ?string $repoClass;

    public bool $forceDBEntityModelCreation = false;
    public bool $isDefault = false;

    /** @var string ARGUS API */
    public const ARGUS = 'ARGUS';

    /** @var string Legacy Database Repos */
    public const LEGACY_DB = 'LEGACY_DB';

    /** @var string Default DB Repo (based on Entity structure) */
    public const DB = 'DB';

    /** @var string Default Virtual Repo Class */
    public const VIRTUAL = 'VIRTUAL';

    /** @var string A method from the Entity Class itself */
    public const CLASS_METHOD = 'CLASS_METHOD';

    /** @var array Repositories that refer to database */
    public const DATABASE_REPOS = [self::DB, self::LEGACY_DB];

    /** @var string Default repo type */
    public static string $defaultRepoType;

    public function __construct(
        string $repoType = null,
        string $repoClass = '',
        bool $forceDBEntityModelCreation = false,
        bool $isDefault = false
    ) {
        if (!$repoType) {
            $repoType = self::getDafaultRepoType();
        }
        $this->repoType = $repoType;
        $this->repoClass = $repoClass;
        $this->isDefault = $isDefault;
        $this->forceDBEntityModelCreation = $forceDBEntityModelCreation;
    }

    /**
     * @return string Returns default repo type
     */
    public static function getDafaultRepoType(): string
    {
        if (isset(self::$defaultRepoType)) {
            return self::$defaultRepoType;
        }
        self::$defaultRepoType = Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE');
        return self::$defaultRepoType;
    }
}