<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Infrastructure\Logging\Query\Elasticsearch\ElasticsearchFieldNames;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorAggregationQuery;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorFieldRedactor;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorGroupDetail;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorKeyValue;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorOccurrence;
use PHPUnit\Framework\TestCase;

/**
 * An occurrence of the runtime-error detail built from the event shapes an issues log of mgamadeus/ddd ≥ 3.12.6 writes
 * (Fixtures/issues-event-shapes.json: four lines a consuming app wrote, redacted, plus one line from before the issues log).
 */
class RuntimeErrorOccurrenceTest extends TestCase
{
    /** @return array<string, mixed> The `_source` of one fixture shape */
    protected static function loadSource(string $shape): array
    {
        $shapes = json_decode((string)file_get_contents(__DIR__ . '/Fixtures/issues-event-shapes.json'), true);
        return $shapes[$shape];
    }

    protected static function buildOccurrence(array $source): RuntimeErrorOccurrence
    {
        return RuntimeErrorOccurrence::buildFromHit(['_source' => $source], new RuntimeErrorFieldRedactor(), new ElasticsearchFieldNames());
    }

    public function testHttpErrorWithCause(): void
    {
        $runtimeErrorOccurrence = self::buildOccurrence(self::loadSource('http_wrapped'));

        self::assertSame(500, $runtimeErrorOccurrence->httpStatus);
        self::assertSame('GET', $runtimeErrorOccurrence->httpMethod);
        self::assertSame('HTTP', $runtimeErrorOccurrence->section);
        self::assertFalse($runtimeErrorOccurrence->handled);
        self::assertNotNull($runtimeErrorOccurrence->groupHash);
        self::assertSame('mgn', $runtimeErrorOccurrence->workspace);
        self::assertSame(1, $runtimeErrorOccurrence->routeParameters->count());
        self::assertSame('locationId', $runtimeErrorOccurrence->routeParameters->first()->key);
        self::assertSame('12', $runtimeErrorOccurrence->routeParameters->first()->value);
        self::assertSame(1, $runtimeErrorOccurrence->causes->count());
        $runtimeErrorCause = $runtimeErrorOccurrence->causes->first();
        self::assertSame('JsonException', $runtimeErrorCause->errorType);
        self::assertSame('Syntax error', $runtimeErrorCause->message);
        self::assertStringEndsWith('PublicZzProbeController.php:50', (string)$runtimeErrorCause->throwSite);
        self::assertSame(3, $runtimeErrorCause->errorFrames->count());
        $throwSiteFrame = $runtimeErrorOccurrence->errorFrames->first();
        self::assertTrue($throwSiteFrame->inApp);
        self::assertNotSame([], $throwSiteFrame->codeContextLines);
        self::assertNull($runtimeErrorOccurrence->messenger);
    }

    public function testHttpErrorWithoutMessage(): void
    {
        $runtimeErrorOccurrence = self::buildOccurrence(self::loadSource('http_no_message'));

        self::assertStringStartsWith('Exception (no message) at src/', (string)$runtimeErrorOccurrence->message);
        self::assertStringEndsWith(':34', (string)$runtimeErrorOccurrence->message);
        self::assertSame(0, $runtimeErrorOccurrence->causes->count());
    }

    public function testFinalWorkerFailure(): void
    {
        $runtimeErrorOccurrence = self::buildOccurrence(self::loadSource('worker_final'));

        $messenger = $runtimeErrorOccurrence->messenger;
        self::assertNotNull($messenger);
        self::assertSame('App\Domain\ZzProbe\ZzProbeHandler::__invoke', $messenger->handler);
        self::assertSame('App\Domain\ZzProbe\ZzProbeMessage', $messenger->messageClass);
        self::assertSame(4, $messenger->attempt);
        self::assertSame(3, $messenger->retryCount);
        self::assertTrue($messenger->final);
        self::assertFalse($messenger->willRetry);
        self::assertSame(['process_partner_invoice'], $messenger->transports);
        self::assertSame('881', $messenger->messageKeys->getByUniqueKey(RuntimeErrorKeyValue::uniqueKeyStatic('orderId'))?->value);
        self::assertSame(['orderId', 'reason'], array_map(static fn($keyValue) => $keyValue->key, $messenger->messageKeys->getElements()));
        self::assertSame('order ready', $messenger->messageKeys->getElements()[1]->value);
        self::assertSame('mgn', $messenger->originWorkspace);
        self::assertSame('2026-10-30T00:56:18Z', $messenger->originDispatchedAt);
        self::assertSame(8, $messenger->ageSeconds);
        self::assertSame(27, $runtimeErrorOccurrence->errorFrames->count());
        $framesWithCodeContext = array_filter(
            $runtimeErrorOccurrence->errorFrames->getElements(),
            static fn($issueErrorFrame): bool => $issueErrorFrame->codeContextLines !== []
        );
        self::assertCount(2, $framesWithCodeContext);
        self::assertSame(0, $runtimeErrorOccurrence->routeParameters->count());
    }

    public function testConsoleError(): void
    {
        $runtimeErrorOccurrence = self::buildOccurrence(self::loadSource('console'));

        self::assertSame('zz:probe-cli', $runtimeErrorOccurrence->command);
        self::assertSame('CLI', $runtimeErrorOccurrence->section);
        self::assertNotNull($runtimeErrorOccurrence->exitCode);
        self::assertNull($runtimeErrorOccurrence->messenger);
    }

    public function testLineFromBeforeTheIssuesLog(): void
    {
        $source = self::loadSource('old_line');
        $runtimeErrorOccurrence = self::buildOccurrence($source);

        self::assertSame($source['message'], $runtimeErrorOccurrence->message);
        self::assertSame($source['error.stack_trace'], $runtimeErrorOccurrence->stackTrace);
        self::assertSame($source['route_name'], $runtimeErrorOccurrence->route);
        self::assertSame('ivs', $runtimeErrorOccurrence->workspace);
        self::assertSame(4183511, $runtimeErrorOccurrence->locationId);
        foreach (['eventId', 'level', 'section', 'groupHash', 'issueHash', 'entryPoint', 'handled', 'httpStatus', 'command', 'exitCode', 'messenger'] as $propertyName) {
            self::assertNull($runtimeErrorOccurrence->$propertyName, $propertyName);
        }
        self::assertSame(0, $runtimeErrorOccurrence->routeParameters->count());
        self::assertSame(0, $runtimeErrorOccurrence->errorFrames->count());
        self::assertSame(0, $runtimeErrorOccurrence->causes->count());
    }

    public function testUserInfluencedValuesAreRedacted(): void
    {
        $source = self::loadSource('http_wrapped');
        $source['route_parameters'] = ['locationId' => 'token=abc'];
        $source['error.causes'][0]['message'] = 'Mail to jane.doe@example.com failed';
        $source['error.frames'][0]['code_context']['lines'][0] = '$client->send("Bearer xyz");';

        $runtimeErrorOccurrence = self::buildOccurrence($source);

        self::assertStringNotContainsString('abc', (string)$runtimeErrorOccurrence->routeParameters->first()->value);
        self::assertStringNotContainsString('jane.doe@example.com', (string)$runtimeErrorOccurrence->causes->first()->message);
        self::assertStringNotContainsString('xyz', $runtimeErrorOccurrence->errorFrames->first()->codeContextLines[0]);
    }

    public function testNestedSourceGivesTheSameOccurrence(): void
    {
        $flatSource = self::loadSource('worker_final');
        $nestedSource = [];
        foreach ($flatSource as $key => $value) {
            if (str_starts_with($key, 'messenger.')) {
                $nestedSource['messenger'][substr($key, strlen('messenger.'))] = $value;
            } elseif (in_array($key, ['error.frames', 'error.group_hash', 'error.handled'], true)) {
                $nestedSource['error'][substr($key, strlen('error.'))] = $value;
            } else {
                $nestedSource[$key] = $value;
            }
        }

        self::assertEquals(
            self::buildOccurrence($flatSource)->toObject(),
            self::buildOccurrence($nestedSource)->toObject()
        );
    }

    public function testDetailSerialisationCarriesTheNewFields(): void
    {
        $aggregation = [
            RuntimeErrorAggregationQuery::OUTER_AGG_KEY => [
                'buckets' => [[
                    'key' => 'abc123',
                    'doc_count' => 1,
                    'recent_occurrences' => ['hits' => ['hits' => [['_source' => self::loadSource('worker_final')]]]],
                ]],
            ],
        ];

        $detailObject = json_decode((string)json_encode(
            RuntimeErrorGroupDetail::buildFromAggregation($aggregation, new RuntimeErrorFieldRedactor())->toObject()
        ), true);

        $firstOccurrence = $detailObject['recentOccurrences']['elements'][0];
        self::assertCount(27, $firstOccurrence['errorFrames']['elements']);
        self::assertArrayHasKey('elements', $firstOccurrence['causes']);
        self::assertArrayHasKey('elements', $firstOccurrence['routeParameters']);
        self::assertSame(4, $firstOccurrence['messenger']['attempt']);
        self::assertCount(2, $firstOccurrence['messenger']['messageKeys']['elements']);
    }
}
