<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonValueKind;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * The JSON readers and the atomic JSON document update of the dialect facade answer the same on both engines: one
 * SQL text written with {@see JsonPath} segments, run for real against a json column.
 */
class JsonFacadeTest extends IntegrationTestCase
{
    protected const string TABLE = 'JsonFacadeRows';

    protected const string DOCUMENT = '{"subAgent": {"status": "running", "number": 7, "cost": 1.25, "done": true, '
        . '"open": false, "nothing": null, "runs": {"2": {"status": "ended"}}, "tags": ["a", "b", "c"]}}';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        $dialect = static::dialect();
        static::connection()->executeStatement('DROP TABLE IF EXISTS ' . $dialect->quote(static::TABLE));
        static::connection()->executeStatement(
            'CREATE TABLE ' . $dialect->quote(static::TABLE) . ' (' . $dialect->quote('id') . ' INT PRIMARY KEY, '
            . $dialect->quote('document') . ' ' . (static::currentDriver() === 'pdo_pgsql' ? 'json' : 'JSON') . ' NULL)'
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            static::connection()->executeStatement('DROP TABLE IF EXISTS ' . static::dialect()->quote(static::TABLE));
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote(static::TABLE));
        static::insertRow(static::TABLE, ['id' => 1, 'document' => static::DOCUMENT]);
        static::insertRow(static::TABLE, ['id' => 2, 'document' => null]);
    }

    /** @param array<string, mixed> $parameters */
    protected function selectFromRow(string $expressionSql, array $parameters = [], int $id = 1): mixed
    {
        $dialect = static::dialect();
        return static::connection()->fetchOne(
            "SELECT $expressionSql FROM " . $dialect->quote(static::TABLE) . ' WHERE ' . $dialect->quote('id') . " = $id",
            $parameters
        );
    }

    /** @param array<string, mixed> $parameters */
    protected function updateDocumentAndRead(string $documentSql, array $parameters = [], int $id = 1): array
    {
        $dialect = static::dialect();
        $affectedRows = static::connection()->executeStatement(
            'UPDATE ' . $dialect->quote(static::TABLE) . ' SET ' . $dialect->quote('document') . " = $documentSql WHERE "
            . $dialect->quote('id') . " = $id",
            $parameters
        );
        // an update that changes nothing counts 0 rows on MariaDB (changed) and 1 on PostgreSQL (matched)
        $this->assertLessThanOrEqual(1, $affectedRows);
        return json_decode((string)$this->selectFromRow($dialect->quote('document'), [], $id), true);
    }

    public function testJsonTextReadsScalarsAndAnswersNullForMissingAndJsonNull(): void
    {
        $dialect = static::dialect();
        $document = $dialect->quote('document');

        $this->assertSame('running', $this->selectFromRow($dialect->jsonText($document, JsonPath::fromKeys('subAgent', 'status'))));
        $this->assertSame('true', $this->selectFromRow($dialect->jsonText($document, JsonPath::fromKeys('subAgent', 'done'))));
        $this->assertSame('7', (string)$this->selectFromRow($dialect->jsonText($document, JsonPath::fromKeys('subAgent', 'number'))));
        $this->assertNull($this->selectFromRow($dialect->jsonText($document, JsonPath::fromKeys('subAgent', 'nothing'))));
        $this->assertNull($this->selectFromRow($dialect->jsonText($document, JsonPath::fromKeys('subAgent', 'missing'))));
        $this->assertNull($this->selectFromRow($dialect->jsonText($document, JsonPath::fromKeys('subAgent', 'status')), [], 2));
    }

    public function testAPathSegmentCanBeABoundParameter(): void
    {
        $dialect = static::dialect();
        $path = JsonPath::fromKeys('subAgent', 'runs')->withParameter(':runNumber')->withKey('status');

        $status = $this->selectFromRow($dialect->jsonText($dialect->quote('document'), $path), ['runNumber' => '2']);

        $this->assertSame('ended', $status);
    }

    public function testTheTypedReaders(): void
    {
        $dialect = static::dialect();
        $document = $dialect->quote('document');

        $this->assertSame(7, (int)$this->selectFromRow($dialect->jsonInt($document, JsonPath::fromKeys('subAgent', 'number'))));
        $this->assertSame('1.2500', (string)$this->selectFromRow($dialect->jsonDecimal($document, JsonPath::fromKeys('subAgent', 'cost'), 10, 4)));
        $this->assertNull($this->selectFromRow($dialect->jsonInt($document, JsonPath::fromKeys('subAgent', 'missing'))));
        $table = $dialect->quote(static::TABLE);
        $this->assertSame(1, (int)static::connection()->fetchOne(
            "SELECT COUNT(*) FROM $table WHERE " . $dialect->jsonBool($document, JsonPath::fromKeys('subAgent', 'done'))
        ));
        $this->assertSame(0, (int)static::connection()->fetchOne(
            "SELECT COUNT(*) FROM $table WHERE " . $dialect->jsonBool($document, JsonPath::fromKeys('subAgent', 'open'))
        ));
    }

    /** @return array<string, array{0: string[], 1: ?string, 2: ?int}> path keys, type, length */
    public static function typesAndLengths(): array
    {
        return [
            'object' => [['subAgent'], 'object', 8],
            'array' => [['subAgent', 'tags'], 'array', 3],
            'string' => [['subAgent', 'status'], 'string', 1],
            'integer' => [['subAgent', 'number'], 'number', 1],
            'decimal' => [['subAgent', 'cost'], 'number', 1],
            'boolean' => [['subAgent', 'done'], 'boolean', 1],
            'json null' => [['subAgent', 'nothing'], 'null', 1],
            'missing' => [['subAgent', 'missing'], null, null],
            'root' => [[], 'object', 1],
        ];
    }

    /** @dataProvider typesAndLengths */
    public function testJsonTypeAndJsonLengthUseTheSameWordsOnBothEngines(array $keys, ?string $type, ?int $length): void
    {
        $dialect = static::dialect();
        $path = JsonPath::fromKeys(...$keys);

        $this->assertSame($type, $this->selectFromRow($dialect->jsonType($dialect->quote('document'), $path)));
        $actualLength = $this->selectFromRow($dialect->jsonLength($dialect->quote('document'), $path));
        $this->assertSame($length, $actualLength === null ? null : (int)$actualLength);
    }

    public function testJsonUpdateSetsEveryValueKindInOneStatement(): void
    {
        $dialect = static::dialect();
        $documentSql = $dialect->jsonUpdate($dialect->quote('document'))
            ->set(JsonPath::fromKeys('subAgent', 'status'), ':status', JsonValueKind::TEXT)
            ->set(JsonPath::fromKeys('subAgent', 'label'), ':label', JsonValueKind::TEXT)
            ->set(JsonPath::fromKeys('subAgent', 'cost'), 'CAST(:cost AS DECIMAL(16, 8))', JsonValueKind::NUMBER)
            ->set(JsonPath::fromKeys('subAgent', 'open'), ':open', JsonValueKind::BOOLEAN)
            ->set(JsonPath::fromKeys('subAgent', 'lastRun'), ':lastRun', JsonValueKind::JSON)
            ->set(JsonPath::fromKeys('subAgent', 'runs')->withParameter(':runNumber'), ':newRun', JsonValueKind::JSON)
            ->set(JsonPath::fromKeys('subAgent', 'cleared'), ':cleared', JsonValueKind::TEXT)
            ->toSql();

        $document = $this->updateDocumentAndRead($documentSql, [
            'status' => 'ended',
            'label' => '42',
            'cost' => '2.5',
            'open' => 1,
            'lastRun' => '{"number": 3, "ok": true}',
            'runNumber' => '3',
            'newRun' => '{"status": "running"}',
            'cleared' => null,
        ]);

        $this->assertSame('ended', $document['subAgent']['status']);
        $this->assertSame('42', $document['subAgent']['label']);
        $this->assertEqualsWithDelta(2.5, $document['subAgent']['cost'], 0.0000001);
        $this->assertIsNotString($document['subAgent']['cost']);
        $this->assertTrue($document['subAgent']['open']);
        $this->assertEquals(['number' => 3, 'ok' => true], $document['subAgent']['lastRun']);
        $this->assertSame(['status' => 'running'], $document['subAgent']['runs']['3']);
        $this->assertSame(['status' => 'ended'], $document['subAgent']['runs']['2']);
        $this->assertArrayHasKey('cleared', $document['subAgent']);
        $this->assertNull($document['subAgent']['cleared']);
        $this->assertSame(7, $document['subAgent']['number']);
    }

    public function testJsonUpdateAddsToANumberOfTheDocumentAtomically(): void
    {
        $dialect = static::dialect();
        $document = $dialect->quote('document');
        $costPath = JsonPath::fromKeys('subAgent', 'cost');
        $documentSql = $dialect->jsonUpdate($document)
            ->set($costPath, 'COALESCE(' . $dialect->jsonDecimal($document, $costPath, 16, 8) . ', 0) + CAST(:added AS DECIMAL(16, 8))', JsonValueKind::NUMBER)
            ->toSql();

        $this->updateDocumentAndRead($documentSql, ['added' => '0.5']);
        $updated = $this->updateDocumentAndRead($documentSql, ['added' => '0.25']);

        $this->assertEqualsWithDelta(2.0, $updated['subAgent']['cost'], 0.0000001);
    }

    public function testAMissingParentLeavesTheDocumentUnchangedUnlessItIsCreatedFirst(): void
    {
        $dialect = static::dialect();
        $document = $dialect->quote('document');
        $scopesPath = JsonPath::fromKeys('costsByScope');
        $scopePath = $scopesPath->withParameter(':scope');

        $withoutParent = $this->updateDocumentAndRead(
            $dialect->jsonUpdate($document)->set($scopePath, ':amount', JsonValueKind::TEXT)->toSql(),
            ['scope' => 'TURN', 'amount' => 'x']
        );
        $this->assertArrayNotHasKey('costsByScope', $withoutParent);

        $withParentSql = $dialect->jsonUpdate($document)
            ->setObjectUnlessPresent($scopesPath)
            ->set($scopePath, ':amount', JsonValueKind::TEXT)
            ->toSql();
        $this->updateDocumentAndRead($withParentSql, ['scope' => 'TURN', 'amount' => 'first']);
        $withParent = $this->updateDocumentAndRead($withParentSql, ['scope' => 'SUMMARY', 'amount' => 'second']);

        // the second call keeps the object the first one created
        $this->assertEquals(['TURN' => 'first', 'SUMMARY' => 'second'], $withParent['costsByScope']);
    }

    public function testANullColumnIsUpdatedAsAnEmptyObject(): void
    {
        $dialect = static::dialect();
        $documentSql = $dialect->jsonUpdate($dialect->quote('document'))
            ->set(JsonPath::fromKeys('environment'), ':environment', JsonValueKind::TEXT)
            ->toSql();

        $this->assertSame(['environment' => 'prod'], $this->updateDocumentAndRead($documentSql, ['environment' => 'prod'], 2));
    }

    public function testJsonUpdateRemovesAndMergesInOrder(): void
    {
        $dialect = static::dialect();
        $documentSql = $dialect->jsonUpdate($dialect->quote('document'))
            ->remove(JsonPath::fromKeys('subAgent', 'tags'))
            ->remove(JsonPath::fromKeys('subAgent', 'missing'))
            ->mergePatch(':patch')
            ->set(JsonPath::fromKeys('merged'), ':merged', JsonValueKind::BOOLEAN)
            ->toSql();

        $document = $this->updateDocumentAndRead($documentSql, [
            'patch' => '{"subAgent": {"status": null, "number": 8}, "added": {"k": "v"}}',
            'merged' => 1,
        ]);

        $this->assertArrayNotHasKey('tags', $document['subAgent']);
        $this->assertArrayNotHasKey('status', $document['subAgent']);
        $this->assertSame(8, $document['subAgent']['number']);
        $this->assertSame(['status' => 'ended'], $document['subAgent']['runs']['2']);
        $this->assertSame(['k' => 'v'], $document['added']);
        $this->assertTrue($document['merged']);
    }

    public function testAKeyWithQuotesAndDotsIsOneSegment(): void
    {
        $dialect = static::dialect();
        $path = JsonPath::fromKeys('by.scope', "it's \"quoted\"");
        $documentSql = $dialect->jsonUpdate($dialect->quote('document'))
            ->setObjectUnlessPresent(JsonPath::fromKeys('by.scope'))
            ->set($path, ':value', JsonValueKind::TEXT)
            ->toSql();

        $document = $this->updateDocumentAndRead($documentSql, ['value' => 'kept']);

        $this->assertSame('kept', $document['by.scope']["it's \"quoted\""]);
        $this->assertSame('kept', $this->selectFromRow($dialect->jsonText($dialect->quote('document'), $path)));
    }
}
