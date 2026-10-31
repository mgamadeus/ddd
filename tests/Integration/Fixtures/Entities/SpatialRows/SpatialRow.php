<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\SpatialRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Polygon;
use DDD\Domain\Common\Entities\GeoEntities\GeoPoint;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Tests\Integration\Fixtures\Entities\FixtureSettings;
use DDD\Tests\Integration\Fixtures\Repo\DB\SpatialRows\DBSpatialRow;

/**
 * Geometry + vector fixture; the bool / JSON / int columns come after the spatial one, so a misaligned bind type
 * after a NULL geometry shows in their stored values.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBSpatialRow::class)]
#[QueryOptions]
class SpatialRow extends Entity
{
    use QueryOptionsTrait;

    /** @var string|null Label */
    public ?string $label;

    /** @var GeoPoint|null Geometry column */
    public ?GeoPoint $location;

    /** @var Vector|null Vector column */
    #[DatabaseColumn(vectorDimensions: 3)]
    public ?Vector $embedding;

    /**
     * @var Polygon|null Cartesian SRID-0 polygon column — the real PostgreSQL failure this fixture proves
     * fixed: "Unknown column type 'cartesian_polygon' requested" the moment ClassMetadataFactory loads this
     * entity on a connection whose dialect never registered it (dual-dialect architecture plan §4.9/§6 step 6).
     */
    public ?Polygon $layout;

    /** @var bool Bool after the spatial column */
    public bool $flag = false;

    /** @var FixtureSettings|null JSON after the spatial column */
    public ?FixtureSettings $payload;

    /** @var int|null Int after the spatial column */
    public ?int $amount;
}
