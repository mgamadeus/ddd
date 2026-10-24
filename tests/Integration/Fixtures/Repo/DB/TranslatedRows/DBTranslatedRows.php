<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRows;

class DBTranslatedRows extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBTranslatedRow::class;
    public const string BASE_ENTITY_SET_CLASS = TranslatedRows::class;
}
