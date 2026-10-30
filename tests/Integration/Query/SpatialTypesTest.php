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
    /**
     * The spatial DQL functions exist on both engines (PostgreSQL registered none: "Expected known function, got
     * 'ST_GeomFromText'"): a DQL UPDATE writes a geometry through ST_GeomFromText with SRID 0, a DQL SELECT reads it
     * back through ST_AsText, ST_Area and ST_Within.
     */
    public function testSpatialDqlFunctionsWriteAndReadAGeometryOnBothEngines(): void
    {
        $spatialRow = new SpatialRow();
        $spatialRow->label = 'dql';
        $spatialRow->location = null;
        $spatialRow->embedding = new Vector([0.0, 0.0, 1.0]);
        $spatialRow->layout = new Polygon([new Point2D(0, 0), new Point2D(1, 0), new Point2D(1, 1), new Point2D(0, 1)]);
        $spatialRow->amount = 1;
        $spatialRow = $spatialRow->update();
        $alias = DBSpatialRowModel::MODEL_ALIAS;

        $updateQueryBuilder = DBSpatialRow::createQueryBuilder();
        $updateQueryBuilder->update(DBSpatialRowModel::class, $alias)
            ->set("$alias.layout", 'ST_GeomFromText(:layoutWkt, 0)')
            ->where("$alias.id = :id")
            ->setParameter('layoutWkt', 'POLYGON((0 0,4 0,4 4,0 4,0 0))')
            ->setParameter('id', $spatialRow->id);
        $this->assertSame(1, $updateQueryBuilder->getQuery()->execute());

        $selectQueryBuilder = DBSpatialRow::createQueryBuilder();
        $selectQueryBuilder->select(
            "ST_AsText($alias.layout) AS layoutText",
            "ST_Area($alias.layout) AS layoutArea",
            "ST_Within(ST_GeomFromText(:insideWkt, 0), $alias.layout) AS containsPoint"
        )
            ->from(DBSpatialRowModel::class, $alias)
            ->where("$alias.id = :id")
            ->setParameter('insideWkt', 'POINT(1 1)')
            ->setParameter('id', $spatialRow->id);
        $result = $selectQueryBuilder->getQuery()->getSingleResult();

        $this->assertSame('POLYGON((0 0,4 0,4 4,0 4,0 0))', $result['layoutText']);
        $this->assertEqualsWithDelta(16.0, (float)$result['layoutArea'], 0.0001);
        $this->assertTrue(filter_var($result['containsPoint'], FILTER_VALIDATE_BOOLEAN));
    }
}
