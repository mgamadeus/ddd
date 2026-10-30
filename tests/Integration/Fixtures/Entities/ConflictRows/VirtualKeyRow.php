<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\ConflictRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows\DBVirtualKeyRow;

/**
 * A UNIQUE key over a generated column — the shape of ddd-common-translations' AppTranslationValue
 * `(appTranslationKeyId, languageId, virtualCountryId)` over `IFNULL(countryId, 0)`: a save without an id must merge
 * onto the row of the same key, as MariaDB's ON DUPLICATE KEY UPDATE does.
 */
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['groupKey', 'virtualCountryId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBVirtualKeyRow::class)]
#[QueryOptions]
class VirtualKeyRow extends Entity
{
    use QueryOptionsTrait;

    public ?string $groupKey = null;

    /** @var int|null Null and 0 are one key (the generated column is IFNULL(countryId, 0)) */
    #[DatabaseVirtualColumn(as: '(IFNULL(countryId, 0))')]
    public ?int $countryId = null;

    /** @var string|null Free text, to see which row a save touched */
    public ?string $label = null;
}
