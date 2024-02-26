<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Lazyload;

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

    public const ARGUS = 'ARGUS';
    public const LEGACY_DB = 'LEGACY_DB';
    public const DB = 'DB';
    public const VIRTUAL = 'VIRTUAL';

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