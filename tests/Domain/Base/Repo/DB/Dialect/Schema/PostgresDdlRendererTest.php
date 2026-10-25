<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Schema;

use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumns;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKeys;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndexes;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumns;
use DDD\Domain\Base\Repo\DB\Database\FullTextMode;
use DDD\Domain\Base\Repo\DB\Database\VectorIndexProfile;
use DDD\Domain\Base\Repo\DB\Dialect\Identifiers\IdentifierPolicy;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatement;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage (no database) for the PostgresDdlRenderer branches the integration harness fixtures
 * (CounterRows/TranslatedRows/SpatialRows) do not exercise: foreign keys (no fixture declares one), the GiST
 * spatial index (the one fixture geometry column is nullable, so the generator auto-suppresses its index), the
 * `IN_MEMORY` vector profile and its dimension ceiling, `DOCUMENT`-mode fulltext, `nullsNotDistinct`, and the
 * collation-mapping refusal. Models are built by hand (not via `DatabaseModel::fromEntityClass()`) so each test
 * isolates exactly the shape it names.
 */
final class PostgresDdlRendererTest extends TestCase
{
    private PostgresDdlRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PostgresDdlRenderer(new IdentifierPolicy());
    }

    /**
     * `DatabaseColumn`'s constructor overwrites its own class-level property defaults (`allowsNull = true`,
     * `varCharLength = 255`) with its own params, which default to `null` — real columns only get those back via
     * `DatabaseColumn::createFromReflectionProperty()`'s explicit post-construction assignment. This helper mirrors
     * that for hand-built test fixtures instead of every test re-deriving it.
     */
    private function column(string $name, string $sqlType): DatabaseColumn
    {
        $column = new DatabaseColumn();
        $column->name = $name;
        $column->sqlType = $sqlType;
        $column->allowsNull = true;
        $column->varCharLength = 255;
        return $column;
    }

    private function model(string $table, DatabaseColumns $columns): DatabaseModel
    {
        $model = new DatabaseModel();
        $model->sqlTableName = $table;
        $model->columns = $columns;
        $model->indexes = new DatabaseIndexes();
        $model->foreignKeys = new DatabaseForeignKeys();
        $model->virtualColumns = new DatabaseVirtualColumns();
        return $model;
    }

    private function findStatement(array $statements, string $phase): DdlStatement
    {
        foreach ($statements as $statement) {
            if ($statement->phase === $phase) {
                return $statement;
            }
        }
        $this->fail("No statement with phase \"$phase\" was rendered.");
    }

    // ───────────────────────────────────────────────────────── column types

    public function testUnsignedIntBecomesBigintAndPlainIntBecomesInteger(): void
    {
        $fkColumn = $this->column('businessId', DatabaseColumn::SQL_TYPE_INT);
        $fkColumn->isUnsigned = true;
        $plainColumn = $this->column('amount', DatabaseColumn::SQL_TYPE_INT);

        $columns = new DatabaseColumns();
        $columns->add($fkColumn);
        $columns->add($plainColumn);
        $model = $this->model('Widgets', $columns);

        $createTable = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_TABLE);
        $this->assertStringContainsString('"businessId" bigint', $createTable->sql);
        $this->assertStringContainsString('"amount" integer', $createTable->sql);
    }

    public function testBlobBecomesByteaAndGeometryGetsSridZero(): void
    {
        $blob = $this->column('payload', DatabaseColumn::SQL_TYPE_BLOB);
        $point = $this->column('anchor', DatabaseColumn::SQL_TYPE_POINT);

        $columns = new DatabaseColumns();
        $columns->add($blob);
        $columns->add($point);
        $model = $this->model('Widgets', $columns);

        $createTable = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_TABLE);
        $this->assertStringContainsString('"payload" bytea', $createTable->sql);
        $this->assertStringContainsString('"anchor" geometry(Point,0)', $createTable->sql);
    }

    public function testBoolDefaultsRenderAsTrueFalseLiterals(): void
    {
        $activeColumn = $this->column('isActive', DatabaseColumn::SQL_TYPE_BOOL);
        $activeColumn->sqlDefaultValue = 1; // DatabaseColumn::createFromReflectionProperty() stores MariaDB's (int) form
        $flagColumn = $this->column('flag', DatabaseColumn::SQL_TYPE_BOOL);
        $flagColumn->sqlDefaultValue = 0;

        $columns = new DatabaseColumns();
        $columns->add($activeColumn);
        $columns->add($flagColumn);
        $model = $this->model('Widgets', $columns);

        $createTable = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_TABLE);
        $this->assertStringContainsString('"isActive" boolean DEFAULT TRUE', $createTable->sql);
        $this->assertStringContainsString('"flag" boolean DEFAULT FALSE', $createTable->sql);
    }

    public function testTextColumnGetsDefaultCollationAndBinaryOverrideMapsToC(): void
    {
        $defaultCollationColumn = $this->column('name', DatabaseColumn::SQL_TYPE_VARCHAR);
        $binaryColumn = $this->column('emojiReaction', DatabaseColumn::SQL_TYPE_VARCHAR);
        $binaryColumn->collation = DatabaseColumn::COLLATION_UTF8MB4_BIN;

        $columns = new DatabaseColumns();
        $columns->add($defaultCollationColumn);
        $columns->add($binaryColumn);
        $model = $this->model('Widgets', $columns);

        $createTable = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_TABLE);
        $this->assertStringContainsString('"name" varchar(255) COLLATE "ddd_ci_ai"', $createTable->sql);
        $this->assertStringContainsString('"emojiReaction" varchar(255) COLLATE "C"', $createTable->sql);
    }

    public function testUnmappedMariaDbCollationIsRefused(): void
    {
        $column = $this->column('name', DatabaseColumn::SQL_TYPE_VARCHAR);
        $column->collation = 'utf8mb4_0900_ai_ci';
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Widgets', $columns);

        $this->expectException(InternalErrorException::class);
        $this->renderer->renderCreate($model);
    }

    public function testIdentityColumnRendersGeneratedByDefaultAsIdentity(): void
    {
        $id = $this->column('id', DatabaseColumn::SQL_TYPE_INT);
        $id->isPrimaryKey = true;
        $id->isUnsigned = true;
        $id->hasAutoIncrement = true;
        $id->allowsNull = false;
        $columns = new DatabaseColumns();
        $columns->add($id);
        $model = $this->model('Widgets', $columns);

        $createTable = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_TABLE);
        $this->assertStringContainsString('"id" bigint NOT NULL GENERATED BY DEFAULT AS IDENTITY', $createTable->sql);
        $this->assertStringContainsString('PRIMARY KEY ("id")', $createTable->sql);
    }

    // ───────────────────────────────────────────────────────── indexes

    public function testUniqueIndexWithNullsNotDistinct(): void
    {
        $column = $this->column('locationId', DatabaseColumn::SQL_TYPE_INT);
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Widgets', $columns);
        $index = new DatabaseIndex(indexType: DatabaseIndex::TYPE_UNIQUE, indexColumns: ['locationId'], nullsNotDistinct: true);
        $model->indexes->add($index);

        $statement = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_INDEX);
        $this->assertSame(
            'CREATE UNIQUE INDEX "Widgets_uniq_locationId" ON "Widgets" ("locationId") NULLS NOT DISTINCT',
            $statement->sql
        );
    }

    public function testSpatialIndexUsesGist(): void
    {
        $column = $this->column('anchor', DatabaseColumn::SQL_TYPE_POINT);
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Widgets', $columns);
        $index = new DatabaseIndex(indexType: DatabaseIndex::TYPE_SPATIAL, indexColumns: ['anchor']);
        $model->indexes->add($index);

        $statement = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_INDEX);
        $this->assertSame('CREATE INDEX "Widgets_spx_anchor" ON "Widgets" USING gist ("anchor")', $statement->sql);
    }

    public function testFulltextDocumentModeUsesToTsvector(): void
    {
        $column = $this->column('body', DatabaseColumn::SQL_TYPE_TEXT);
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('SupportMessages', $columns);
        $index = new DatabaseIndex(indexType: DatabaseIndex::TYPE_FULLTEXT, indexColumns: ['body'], fullTextMode: FullTextMode::DOCUMENT);
        $model->indexes->add($index);

        $statement = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_INDEX);
        $this->assertSame(
            'CREATE INDEX "SupportMessages_ft_body" ON "SupportMessages" USING gin (to_tsvector(\'simple\', ddd_unaccent("body")))',
            $statement->sql
        );
    }

    public function testFulltextNameModeUsesTrigramOnDddFold(): void
    {
        $column = $this->column('virtualNameSearch', DatabaseColumn::SQL_TYPE_TEXT);
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Zones', $columns);
        $index = new DatabaseIndex(indexType: DatabaseIndex::TYPE_FULLTEXT, indexColumns: ['virtualNameSearch']);
        $model->indexes->add($index);

        $statement = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_INDEX);
        $this->assertSame(
            'CREATE INDEX "Zones_ft_virtualNameSearch" ON "Zones" USING gin ((ddd_fold("virtualNameSearch") COLLATE "C") gin_trgm_ops)',
            $statement->sql
        );
    }

    public function testVectorIndexDefaultsToOnDiskDiskann(): void
    {
        $column = $this->column('embedding', DatabaseColumn::SQL_TYPE_VECTOR);
        $column->vectorDimensions = 1536;
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Articles', $columns);
        $index = new DatabaseIndex(indexType: DatabaseIndex::TYPE_VECTOR, indexColumns: ['embedding']);
        $model->indexes->add($index);

        $statement = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_INDEX);
        $this->assertSame(
            'CREATE INDEX "Articles_vx_embedding" ON "Articles" USING diskann ("embedding" vector_cosine_ops)',
            $statement->sql
        );
    }

    public function testVectorIndexInMemoryUnderTwoThousandDimsIsPlainHnsw(): void
    {
        $column = $this->column('embedding', DatabaseColumn::SQL_TYPE_VECTOR);
        $column->vectorDimensions = 768;
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Articles', $columns);
        $index = new DatabaseIndex(
            indexType: DatabaseIndex::TYPE_VECTOR,
            indexColumns: ['embedding'],
            distanceMetric: DatabaseIndex::DISTANCE_METRIC_EUCLIDEAN,
            vectorIndexProfile: VectorIndexProfile::IN_MEMORY
        );
        $model->indexes->add($index);

        $statement = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_INDEX);
        $this->assertSame(
            'CREATE INDEX "Articles_vx_embedding" ON "Articles" USING hnsw ("embedding" vector_l2_ops)',
            $statement->sql
        );
    }

    public function testVectorIndexInMemoryBetween2001And4000DimsCastsToHalfvec(): void
    {
        $column = $this->column('embedding', DatabaseColumn::SQL_TYPE_VECTOR);
        $column->vectorDimensions = 3072;
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Articles', $columns);
        $index = new DatabaseIndex(
            indexType: DatabaseIndex::TYPE_VECTOR,
            indexColumns: ['embedding'],
            vectorIndexProfile: VectorIndexProfile::IN_MEMORY
        );
        $model->indexes->add($index);

        $statement = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_INDEX);
        $this->assertSame(
            'CREATE INDEX "Articles_vx_embedding" ON "Articles" USING hnsw (("embedding"::halfvec(3072)) halfvec_cosine_ops)',
            $statement->sql
        );
    }

    public function testVectorIndexInMemoryAbove4000DimsIsRefused(): void
    {
        $column = $this->column('embedding', DatabaseColumn::SQL_TYPE_VECTOR);
        $column->vectorDimensions = 4096;
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Articles', $columns);
        $index = new DatabaseIndex(
            indexType: DatabaseIndex::TYPE_VECTOR,
            indexColumns: ['embedding'],
            vectorIndexProfile: VectorIndexProfile::IN_MEMORY
        );
        $model->indexes->add($index);

        $this->expectException(InternalErrorException::class);
        $this->renderer->renderCreate($model);
    }

    public function testVectorColumnGetsItsOwnPostgresZeroDefaultNotMariaDbsExpression(): void
    {
        $column = $this->column('embedding', DatabaseColumn::SQL_TYPE_VECTOR);
        $column->vectorDimensions = 3;
        $column->allowsNull = false; // createFromReflectionProperty() forces this for every VECTOR column
        $column->sqlDefaultValue = DatabaseColumn::createMariaDbNullVectorDefault(3); // must be ignored/overridden
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('SpatialRows', $columns);

        $createTable = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_TABLE);
        $this->assertStringContainsString("\"embedding\" vector(3) NOT NULL DEFAULT '[0,0,0]'::vector", $createTable->sql);
        $this->assertStringNotContainsString('VEC_FromText', $createTable->sql);
    }

    // ───────────────────────────────────────────────────────── foreign keys

    public function testForeignKeyIsTablePrefixedAndActionsPassThrough(): void
    {
        $column = $this->column('businessId', DatabaseColumn::SQL_TYPE_INT);
        $column->isUnsigned = true;
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Locations', $columns);

        $foreignKey = new DatabaseForeignKey(onUpdateAction: DatabaseForeignKey::ACTION_CASCADE, onDeleteAction: DatabaseForeignKey::ACTION_SET_NULL);
        $foreignKey->internalIdColumn = 'businessId';
        $foreignKey->internalColumn = 'business';
        $foreignKey->foreignTable = 'Businesses';
        $foreignKey->foreignModelClassName = 'DBBusinessModel';
        $model->foreignKeys->add($foreignKey);

        $statement = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_FOREIGN_KEY);
        $this->assertSame(
            'ALTER TABLE "Locations" ADD CONSTRAINT "Locations_fk_businessId" FOREIGN KEY ("businessId") '
            . 'REFERENCES "Businesses" ("id") ON UPDATE CASCADE ON DELETE SET NULL',
            $statement->sql
        );
    }

    public function testForeignKeyConstraintNameIsShortenedPastSixtyThreeBytes(): void
    {
        $longColumn = str_repeat('a', 60) . 'Id';
        $column = $this->column($longColumn, DatabaseColumn::SQL_TYPE_INT);
        $column->isUnsigned = true;
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('EntityAIConversationTaskPushNotificationConfigs', $columns);

        $foreignKey = new DatabaseForeignKey();
        $foreignKey->internalIdColumn = $longColumn;
        $foreignKey->internalColumn = 'aiConversation';
        $foreignKey->foreignTable = 'AiConversations';
        $foreignKey->foreignModelClassName = 'DBAiConversationModel';
        $model->foreignKeys->add($foreignKey);

        $statement = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_FOREIGN_KEY);
        preg_match('/ADD CONSTRAINT "([^"]+)"/', $statement->sql, $matches);
        $this->assertLessThanOrEqual(63, strlen($matches[1]));
        $this->assertMatchesRegularExpression('/_[0-9a-f]{8}$/', $matches[1]);
    }

    public function testApplyForeignKeyConstraintFalseSkipsTheForeignKey(): void
    {
        $column = $this->column('businessId', DatabaseColumn::SQL_TYPE_INT);
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Locations', $columns);

        $foreignKey = new DatabaseForeignKey(applyForeignKeyConstraint: false);
        $foreignKey->internalIdColumn = 'businessId';
        $foreignKey->internalColumn = 'business';
        $foreignKey->foreignTable = 'Businesses';
        $foreignKey->foreignModelClassName = 'DBBusinessModel';
        $model->foreignKeys->add($foreignKey);

        $statements = $this->renderer->renderCreate($model);
        foreach ($statements as $statement) {
            $this->assertNotSame(DdlStatement::PHASE_CREATE_FOREIGN_KEY, $statement->phase);
        }
    }

    // ───────────────────────────────────────────────────────── virtual columns

    public function testTranslatableFullTextSearchColumnRendersAsStoredGeneratedColumn(): void
    {
        $nameColumn = $this->column('name', DatabaseColumn::SQL_TYPE_JSON);
        $columns = new DatabaseColumns();
        $columns->add($nameColumn);
        $model = $this->model('Zones', $columns);

        $virtualColumn = new DatabaseVirtualColumn(as: '(irrelevant MariaDB expression)', stored: true, createIndex: false);
        $searchColumn = $this->column('nameSearch', DatabaseColumn::SQL_TYPE_TEXT);
        $virtualColumn->referenceColumn = $searchColumn;
        $virtualColumn->virtualColumnNameOverride = 'virtualNameSearch';
        $virtualColumn->isTranslatableFullTextSearchColumn = true;
        $virtualColumn->translatableBaseColumnName = 'name';
        $model->virtualColumns->add($virtualColumn);

        $createTable = $this->findStatement($this->renderer->renderCreate($model), DdlStatement::PHASE_CREATE_TABLE);
        $this->assertStringContainsString(
            '"virtualNameSearch" text COLLATE "ddd_ci_ai" GENERATED ALWAYS AS (ddd_translatable_text("name")) STORED',
            $createTable->sql
        );
    }

    public function testNonTranslatableVirtualColumnIsRefused(): void
    {
        $column = $this->column('locationId', DatabaseColumn::SQL_TYPE_INT);
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('AccountRights', $columns);

        $virtualColumn = new DatabaseVirtualColumn(as: 'IFNULL(`locationId`, 0)', stored: true, createIndex: false);
        $referenceColumn = $this->column('virtualLocationId', DatabaseColumn::SQL_TYPE_INT);
        $virtualColumn->referenceColumn = $referenceColumn;
        $model->virtualColumns->add($virtualColumn);

        $this->expectException(InternalErrorException::class);
        $this->renderer->renderCreate($model);
    }

    public function testSingleTableInheritanceSubclassRendersNoStatements(): void
    {
        $columns = new DatabaseColumns();
        $model = $this->model('Posts', $columns);
        $parentClass = new \DDD\Infrastructure\Reflection\ClassWithNamespace('Post', 'DDD\\Tests\\Fixtures', 'Post.php');
        $model->parentEntityCLassWithNamespace = $parentClass;

        $this->assertSame([], $this->renderer->renderCreate($model));
    }

    // ───────────────────────────────────────────────────────── ensure()

    public function testEnsureDeclaresEveryRequiredExtensionCollationAndFunction(): void
    {
        $statements = $this->renderer->ensure();
        $sql = implode("\n", array_map(static fn (DdlStatement $s): string => $s->sql, $statements));

        foreach (['pg_trgm', 'unaccent', 'postgis', 'vector', 'vectorscale', 'pg_stat_statements'] as $extension) {
            $this->assertStringContainsString("CREATE EXTENSION IF NOT EXISTS \"$extension\"", $sql);
        }
        $this->assertStringContainsString('CREATE COLLATION IF NOT EXISTS "ddd_ci_ai"', $sql);
        foreach (['ddd_now', 'ddd_unaccent', 'ddd_fold', 'ddd_translatable_text'] as $function) {
            $this->assertStringContainsString("FUNCTION $function(", $sql);
        }
    }

    public function testEnsureStatementsSortBeforeAnyCreateTableStatement(): void
    {
        $column = $this->column('amount', DatabaseColumn::SQL_TYPE_INT);
        $columns = new DatabaseColumns();
        $columns->add($column);
        $model = $this->model('Widgets', $columns);

        $all = array_merge($this->renderer->ensure(), $this->renderer->renderCreate($model));
        $sorted = DdlStatement::sortByPhase($all);

        $phases = array_map(static fn (DdlStatement $s): string => $s->phase, $sorted);
        $firstCreateTableIndex = array_search(DdlStatement::PHASE_CREATE_TABLE, $phases, true);
        $this->assertNotFalse($firstCreateTableIndex);
        foreach (array_slice($phases, 0, $firstCreateTableIndex) as $earlierPhase) {
            $this->assertContains(
                $earlierPhase,
                [DdlStatement::PHASE_EXTENSION, DdlStatement::PHASE_COLLATION, DdlStatement::PHASE_FUNCTION]
            );
        }
    }
}
