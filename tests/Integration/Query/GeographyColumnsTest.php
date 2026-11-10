<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\SqlScriptLexer;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Point2D;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Polyline;
use DDD\Domain\Common\Entities\GeoEntities\GeoPoint;
use DDD\Tests\Integration\Fixtures\Entities\GeographyRows\GeographyRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\GeographyRows\DBGeographyRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\GeographyRows\DBGeographyRowModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * Columns declaring #[DatabaseColumn(srid: 4326)]: on PostgreSQL they are geometry(…,4326), every write path (upsert,
 * partial update) stores the value with SRID 4326 and the explicit spatial index on the nullable point is a GiST
 * index. On MariaDB the columns, the writes (SRID 0) and the missing index are unchanged.
 */
class GeographyColumnsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $databaseModel = DatabaseModel::fromEntityClass(GeographyRow::class);
        static::connection()->executeStatement(
            'DROP TABLE IF EXISTS ' . static::quoteIdentifier($databaseModel->sqlTableName)
        );
        if (static::currentDriver() === 'pdo_pgsql') {
            foreach (static::dialect()->ddlRenderer()->renderCreate($databaseModel)->sortedByPhase() as $statement) {
                static::connection()->executeStatement($statement->sql);
            }
            return;
        }
        foreach (SqlScriptLexer::splitStatements($databaseModel->getSql()) as $statement) {
            static::connection()->executeStatement($statement);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            static::connection()->executeStatement(
                'DROP TABLE IF EXISTS ' . static::quoteIdentifier(DatabaseModel::fromEntityClass(GeographyRow::class)->sqlTableName)
            );
        }
        parent::tearDownAfterClass();
    }

    public function testLongitudeLatitudeColumnsWriteAndReadWithTheirSrid(): void
    {
        $geographyRow = new GeographyRow();
        $geographyRow->label = 'route';
        $geographyRow->route = new Polyline([new Point2D(13.405, 52.52), new Point2D(11.582, 48.1351)]);
        $geographyRow->position = new GeoPoint(52.52, 13.405);
        $geographyRow->plainPosition = new GeoPoint(1.5, 2.5);
        $geographyRow = $geographyRow->update();

        $geographyRow->position = new GeoPoint(48.1351, 11.582);
        $geographyRow->updatePartialIgnoringRights('position');

        $alias = DBGeographyRowModel::MODEL_ALIAS;
        $queryBuilder = DBGeographyRow::createQueryBuilder();
        $queryBuilder->select(
            "ST_AsText($alias.route) AS routeText",
            "ST_AsText($alias.position) AS positionText",
            "ST_SRID($alias.route) AS routeSrid",
            "ST_SRID($alias.position) AS positionSrid",
            "ST_SRID($alias.plainPosition) AS plainPositionSrid"
        )
            ->from(DBGeographyRowModel::class, $alias)
            ->where("$alias.id = :id")
            ->setParameter('id', $geographyRow->id);
        $result = $queryBuilder->getQuery()->getSingleResult();

        $this->assertSame('LINESTRING(13.405 52.52,11.582 48.1351)', $result['routeText']);
        $this->assertSame('POINT(11.582 48.1351)', $result['positionText']);
        $expectedSrid = static::currentDriver() === 'pdo_pgsql' ? 4326 : 0;
        $this->assertSame($expectedSrid, (int)$result['routeSrid']);
        $this->assertSame($expectedSrid, (int)$result['positionSrid']);
        $this->assertSame(0, (int)$result['plainPositionSrid']);

        static::entityManager()->clear();
        $reloadedGeographyRow = GeographyRow::getService()->find($geographyRow->id, false);
        $this->assertSame([[13.405, 52.52], [11.582, 48.1351]], array_map(
            static fn(Point2D $point): array => [$point->x, $point->y],
            $reloadedGeographyRow->route->points
        ));
        $this->assertSame([48.1351, 11.582], [$reloadedGeographyRow->position->lat, $reloadedGeographyRow->position->lng]);
    }

    public function testTheSpatialIndexOnTheNullablePointExistsOnPostgresOnly(): void
    {
        if (static::currentDriver() === 'pdo_pgsql') {
            $positionIndexDefinition = static::connection()->fetchOne(
                "SELECT indexdef FROM pg_indexes WHERE tablename = 'GeographyRows' AND indexdef LIKE '%\"position\"%'"
            );
            $this->assertIsString($positionIndexDefinition);
            $this->assertStringContainsString('USING gist', $positionIndexDefinition);
            return;
        }
        $this->assertSame(0, (int)static::connection()->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'GeographyRows' AND COLUMN_NAME = 'position'"
        ));
    }
}
