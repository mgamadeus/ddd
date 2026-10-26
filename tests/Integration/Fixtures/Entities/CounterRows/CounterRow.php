<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\CounterRows;

use DDD\Domain\Base\Entities\Attributes\PreserveOnOmission;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRow;

/**
 * Scalar fixture: a business-key UNIQUE, an incrementing onUpdateAction (the Radbonus TrackGeoHash shape), a
 * VALUES-free keep-existing action, int / bool / float / date / datetime columns and a property preserved on omission.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBCounterRow::class)]
#[QueryOptions]
class CounterRow extends Entity
{
    use QueryOptionsTrait;

    /** @var string|null Business key */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE)]
    public ?string $businessKey;

    /** @var int|null On a duplicate key the incoming value is added to the stored one */
    #[DatabaseColumn(onUpdateAction: '`counter` + COALESCE(VALUES(`counter`), 1)')]
    public ?int $counter;

    /** @var int|null An update never changes it */
    #[DatabaseColumn(onUpdateAction: '`keptValue`')]
    public ?int $keptValue;

    /** @var string|null Free text */
    public ?string $label;

    /** @var int|null Plain int column */
    public ?int $amount;

    /** @var float|null Plain float column */
    public ?float $ratio;

    /** @var bool Plain bool column */
    public bool $isActive = true;

    /** @var Date|null Plain date column */
    public ?Date $dueOn;

    /** @var DateTime|null Plain datetime column */
    public ?DateTime $occurredAt;

    /** @var int Kept when an update payload omits it */
    #[PreserveOnOmission]
    public int $displayOrder = 0;
}
