<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Common\Entities\Geometry\Cartesian\Point2D;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Polygon;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Tests\Integration\Fixtures\Entities\SpatialRows\SpatialRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\SpatialRows\DBSpatialRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\SpatialRows\DBSpatialRowModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * §6 step 6 subset (dual-dialect architecture plan §4.9): the real Dineful PostgreSQL failure — "Unknown column
 * type 'cartesian_polygon' requested" — happens the moment {@see \Doctrine\ORM\Mapping\ClassMetadataFactory}
 * loads an entity with a Cartesian/vector-typed property on a connection whose dialect never registered the
 * type. `Type::addType()`/`Type::overrideType()` are process-wide, not per-connection
 * ({@see \Doctrine\DBAL\Types\Type}), so a pure-`pdo_pgsql` process — which only ever calls
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}, never
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect::configureOrm()} — needs its own registration of every
 * name the codebase uses. The custom write path (`WriteExecutor`/`BindExpressionKind`, §4.3/§4.6) bypasses
 * Doctrine's Type system entirely and would never surface this (see {@see
 * \DDD\Tests\Integration\Repo\BatchUpsertTest}, which already passes on both drivers) — only a DQL/`find()` read
 * that goes through {@see \Doctrine\ORM\Mapping\ClassMetadataFactory} does, which is what this fixture exercises.
 */
class SpatialTypesTest extends IntegrationTestCase
{
    public function testACartesianPolygonAndAVectorRoundTripThroughDqlOnBothEngines(): void
    {
        $spatialRow = new SpatialRow();
        $spatialRow->label = 'layout';
        $spatialRow->location = null;
        $spatialRow->embedding = new Vector([0.5, 0.25, 0.125]);
        $spatialRow->layout = new Polygon([
            new Point2D(0, 0),
            new Point2D(10, 0),
            new Point2D(10, 10),
            new Point2D(0, 10),
        ]);
        $spatialRow->flag = true;
        $spatialRow->amount = 1;
        $spatialRow = $spatialRow->update();

        $queryBuilder = DBSpatialRow::createQueryBuilder(true);
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->where("$rootAlias.id = :id")->setParameter('id', $spatialRow->id);
        /** @var DBSpatialRowModel[] $results */
        $results = $queryBuilder->getQuery()->getResult();

        $this->assertCount(1, $results);
        $hydratedLayout = $results[0]->layout;
        $this->assertInstanceOf(Polygon::class, $hydratedLayout);
        // the VO implicitly closes the ring (first vertex repeated as the last) on both write and read
        $this->assertSame(
            [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0], [0.0, 0.0]],
            array_map(static fn(Point2D $point): array => [$point->x, $point->y], $hydratedLayout->outerRing)
        );
        $this->assertSame([0.5, 0.25, 0.125], $results[0]->embedding);
    }
}
