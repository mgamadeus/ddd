<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\TranslatedRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Tests\Integration\Fixtures\Entities\FixtureSettings;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRow;

/**
 * JSON fixture: a searchable Translatable (fullTextIndex), a plain Translatable and a mergable JSON value object.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBTranslatedRow::class)]
#[QueryOptions]
class TranslatedRow extends Entity
{
    use QueryOptionsTrait, TranslatableTrait;

    /** @var string|null Searchable through virtualNameSearch */
    #[Translatable(fullTextIndex: true)]
    public ?string $name;

    /** @var string|null Plain Translatable, no search column */
    #[Translatable]
    public ?string $title;

    /** @var FixtureSettings|null Mergable JSON */
    #[DatabaseColumn(isMergableJSONColumn: true)]
    public ?FixtureSettings $settings;
}
