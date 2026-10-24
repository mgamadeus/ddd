<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\TranslatedRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRows;
use DDD\Tests\Integration\Fixtures\Services\TranslatedRowsService;

/**
 * @property TranslatedRow[] $elements
 * @method TranslatedRow|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBTranslatedRows::class)]
class TranslatedRows extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = TranslatedRowsService::class;
}
