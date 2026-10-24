<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRow;

class DBTranslatedRow extends DBEntity
{
    public const string BASE_ENTITY_CLASS = TranslatedRow::class;
    public const string BASE_ORM_MODEL = DBTranslatedRowModel::class;
}
