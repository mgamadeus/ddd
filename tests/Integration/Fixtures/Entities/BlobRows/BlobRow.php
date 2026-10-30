<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\BlobRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Tests\Integration\Fixtures\Repo\DB\BlobRows\DBBlobRow;

/**
 * A BLOB column whose Doctrine field type is `string` — the real Radbonus `Tracks.trackDetails` shape (LONGBLOB
 * holding gzip). On PostgreSQL the column is `bytea`; bound as text, the gzip bytes fail with "invalid byte
 * sequence for encoding UTF8".
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBBlobRow::class)]
class BlobRow extends Entity
{
    /** @var string|null Free text, to tell rows apart */
    public ?string $label;

    /** @var string|null Raw bytes */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_LONGBLOB)]
    public ?string $payload;
}
