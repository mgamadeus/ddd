<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\GeographyRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Polyline;
use DDD\Domain\Common\Entities\GeoEntities\GeoPoint;
use DDD\Tests\Integration\Fixtures\Repo\DB\GeographyRows\DBGeographyRow;

/**
 * Longitude/latitude geometry columns: geometry(…,4326) on PostgreSQL, plain columns on MariaDB. The point is
 * nullable and declares a spatial index: a GiST index on PostgreSQL, none on MariaDB (error 1252 there).
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBGeographyRow::class)]
class GeographyRow extends Entity
{
    /** @var string|null Label */
    public ?string $label;

    /** @var Polyline|null A route, x = longitude, y = latitude */
    #[DatabaseColumn(srid: DatabaseColumn::SRID_WGS84)]
    public ?Polyline $route;

    /** @var GeoPoint|null A position with a spatial index on the nullable column */
    #[DatabaseColumn(srid: DatabaseColumn::SRID_WGS84)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_SPATIAL)]
    public ?GeoPoint $position;

    /** @var GeoPoint|null A point without SRID, as before */
    public ?GeoPoint $plainPosition;
}
