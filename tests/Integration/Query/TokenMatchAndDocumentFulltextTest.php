<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatement;
use DDD\Tests\Integration\Fixtures\Entities\SearchRows\SearchRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\SearchRows\DBSearchRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\SearchRows\DBSearchRowModel;
use DDD\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;

/**
 * §6 step 5 query fixtures that need a column the shared three fixture entities (`CounterRow`/`TranslatedRow`/
 * `SpatialRow`, {@see IntegrationTestCase::FIXTURE_ENTITY_CLASSES}) do not carry: D14 `fullTextMode: DOCUMENT`
 * (`SearchRow.summary`) and D19 `TOKEN_MATCH` (`SearchRow.tags`, the Radbonus `Tracks.allocations` shape). Its own
 * `SearchRows` table is created/dropped on top of (never instead of) the shared three, so no golden-captured DDL
 * for the six `GOLDEN_TEST_CLASSES` ({@see \DDD\Tests\GoldenDdl\DdlGoldenTest}) is affected — `SearchRow` is
 * deliberately NOT part of `FIXTURE_ENTITY_CLASSES`.
 */
class TokenMatchAndDocumentFulltextTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        $connection = static::connection();
        $dialect = static::dialect();
        $databaseModel = DatabaseModel::fromEntityClass(SearchRow::class);

        if (static::currentDriver() === 'pdo_pgsql') {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $dialect->quote('SearchRows') . ' CASCADE');
            $statements = $dialect->ddlRenderer()->renderCreate($databaseModel);
            foreach (DdlStatement::sortByPhase($statements) as $statement) {
                $connection->executeStatement($statement->sql);
            }
        } else {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $dialect->quote('SearchRows'));
            foreach (static::splitSqlStatements($databaseModel->getSql()) as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            $connection = static::connection();
            $connection->executeStatement(
                'DROP TABLE IF EXISTS ' . static::quoteIdentifier('SearchRows')
                . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : '')
            );
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (static::isIntegrationDatabaseEnabled()) {
            static::connection()->executeStatement('DELETE FROM ' . static::quoteIdentifier('SearchRows'));
        }
    }

    /**
     * D14 `fullTextMode: DOCUMENT`: `ft` against a long-text property renders `websearch_to_tsquery` on
     * PostgreSQL ({@see \DDD\Domain\Base\Repo\DB\Dialect\Query\FulltextMatch}) instead of the `NAME` mode's
     * `pg_trgm` prefix match — both reached through the SAME `FiltersOptions` branch as
     * {@see \DDD\Tests\Integration\Query\QueryFixturesTest::testFulltextNameModeMatchesAndScoreOrdersBestMatchFirst()},
     * only the property's declared `fullTextMode` differs. MariaDB renders the same `MATCH … AGAINST` regardless
     * of `fullTextMode` (D14: no MariaDB effect) and is asserted identically.
     *
     * @group query
     */
    public function testDocumentModeFulltextMatchesOnBothEngines(): void
    {
        static::insertRow('SearchRows', [
            'summary' => json_encode(['en' => 'The grilled chicken sandwich comes with a side of fries']),
            'tags' => null,
        ]);
        static::insertRow('SearchRows', [
            'summary' => json_encode(['en' => 'A fresh garden salad with seasonal vegetables']),
            'tags' => null,
        ]);

        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(SearchRow::class);
        $queryBuilder = DBSearchRow::createQueryBuilder(true);
        $filtersOptions = FiltersOptions::fromString("summary ft 'chicken sandwich'");
        $filtersOptions->validateAgainstDefinitions($filtersDefinitions);
        $filtersOptions->applyFiltersToDoctrineQueryBuilder($queryBuilder);

        $matched = $queryBuilder->getQuery()->getResult();
        $this->assertCount(1, $matched, 'the DOCUMENT-mode search matches only the row containing both words');
        /** @var DBSearchRowModel $onlyMatch */
        $onlyMatch = $matched[0];
        $this->assertStringContainsString('chicken', $onlyMatch->summary['en']);
    }

    /** @return int[] ids of the SearchRows TOKEN_MATCH selects, bound with $mode and the given exact tokens */
    protected function tokenMatchingIds(array $tokens, string $mode): array
    {
        $queryBuilder = DBSearchRow::createQueryBuilder(true);
        $alias = DBSearchRowModel::MODEL_ALIAS;

        if (static::currentDriver() === 'pdo_pgsql') {
            // D19/§4.8: TOKEN_MATCH is a PostgreSQL-only DQL name (MariaDB keeps its own pre-existing MATCH …
            // AGAINST mechanism, built before this step and untouched by it — see the `else` branch).
            $queryBuilder->andWhere("TOKEN_MATCH($alias.tags, :tokens, '$mode') = true");
            $queryBuilder->setParameter('tokens', $tokens);
        } else {
            $against = match ($mode) {
                'ALL' => '+' . implode(' +', $tokens),
                'ANY' => implode(' ', $tokens),
                'NONE' => implode(' ', $tokens),
                default => throw new \InvalidArgumentException("Unknown mode \"$mode\""),
            };
            $condition = "MATCH($alias.tags) AGAINST ('$against' IN BOOLEAN MODE) > 0";
            $queryBuilder->andWhere($mode === 'NONE' ? "NOT ($condition)" : $condition);
        }

        $results = $queryBuilder->getQuery()->getResult();
        $ids = array_map(static fn(DBSearchRowModel $model): int => $model->id, $results);
        sort($ids);
        return $ids;
    }

    /**
     * D19/Mig §3.5 (the Radbonus `Tracks.allocations` shape): exact token-list matching, `ALL`/`ANY`/`NONE`.
     * `string_to_array(col, ' ') @> / && / NOT(… && …)` on PostgreSQL ({@see \DDD\Domain\Base\Repo\DB\Dialect\Query\TokenMatch}),
     * `MATCH … AGAINST (… IN BOOLEAN MODE)` on MariaDB (pre-existing, unchanged) — same matched id sets on both.
     *
     * @group query
     */
    public function testTokenMatchAllAnyNoneAgreeOnBothEngines(): void
    {
        static::insertRow('SearchRows', ['summary' => null, 'tags' => 'CH1 CH2 CH3']);
        $idAllThree = (int)static::connection()->lastInsertId();
        static::insertRow('SearchRows', ['summary' => null, 'tags' => 'CH2 CH3']);
        $idTwoAndThree = (int)static::connection()->lastInsertId();
        static::insertRow('SearchRows', ['summary' => null, 'tags' => 'CH4']);
        $idFourOnly = (int)static::connection()->lastInsertId();

        $expectedAll = [$idAllThree];
        $expectedAny = [$idAllThree, $idTwoAndThree];
        $expectedNone = [$idFourOnly];
        sort($expectedAll);
        sort($expectedAny);
        sort($expectedNone);

        $this->assertSame($expectedAll, $this->tokenMatchingIds(['CH1', 'CH2'], 'ALL'), 'ALL: only the row holding every token');
        $this->assertSame($expectedAny, $this->tokenMatchingIds(['CH1', 'CH2'], 'ANY'), 'ANY: every row holding at least one token');
        $this->assertSame($expectedNone, $this->tokenMatchingIds(['CH1', 'CH2'], 'NONE'), 'NONE: every row holding neither token');
    }
}
