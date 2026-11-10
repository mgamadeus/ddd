<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\GeographyRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\GeographyRows\GeographyRows;

class DBGeographyRows extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBGeographyRow::class;
    public const string BASE_ENTITY_SET_CLASS = GeographyRows::class;
}
