<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Query\Elasticsearch\ElasticsearchIssuesQueryBackend;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorGroup;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorGroupDetail;
use DDD\Infrastructure\Reflection\ReflectionDocComment;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A bulk read sets its own timeout per search, a probe can see the whole error body, and the runtime-error result
 * objects document their fields for the generated OpenAPI / TypeScript models.
 */
class RuntimeErrorBackendOptionsTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    protected array $requestHistory = [];

    protected function createBackend(Response ...$responses): ElasticsearchIssuesQueryBackend
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->requestHistory));
        return new ElasticsearchIssuesQueryBackend(
            new IssuesRegistrySettings(IssuesRegistrySettings::BACKEND_ELASTICSEARCH, 'http://es', querySelector: 'logs-*'),
            new Client(['handler' => $handlerStack])
        );
    }

    public function testASearchTakesItsOwnTimeout(): void
    {
        $elasticsearchIssuesQueryBackend = $this->createBackend(new Response(200, [], '{"hits":{}}'), new Response(200, [], '{"hits":{}}'));

        $elasticsearchIssuesQueryBackend->search('logs-*', ['size' => 0]);
        $elasticsearchIssuesQueryBackend->search('logs-*', ['size' => 5000], timeoutSeconds: 120);

        $this->assertSame(10, $this->requestHistory[0]['options']['timeout']);
        $this->assertSame(120, $this->requestHistory[1]['options']['timeout']);
        $this->assertSame(10, $this->requestHistory[1]['options']['connect_timeout']);
    }

    public function testTheErrorBodyIsCutUnlessTheFullBodyIsAsked(): void
    {
        $errorBody = '{"error":{"reason":"' . str_repeat('x', 900) . 'END"}}';
        $elasticsearchIssuesQueryBackend = $this->createBackend(new Response(400, [], $errorBody), new Response(400, [], $errorBody));
        try {
            $elasticsearchIssuesQueryBackend->search('logs-*', []);
            $this->fail('An error response must throw');
        } catch (InternalErrorException $internalErrorException) {
            $this->assertStringNotContainsString('END', $internalErrorException->getMessage());
        }

        $elasticsearchIssuesQueryBackend->setErrorBodyMaxLength(null);
        $this->expectExceptionMessageMatches('/END"}}$/');
        $elasticsearchIssuesQueryBackend->search('logs-*', []);
    }

    public function testEveryResultFieldIsDocumented(): void
    {
        foreach ([RuntimeErrorGroup::class, RuntimeErrorGroupDetail::class] as $resultClass) {
            foreach ((new \ReflectionClass($resultClass))->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
                if ($reflectionProperty->isStatic() || $reflectionProperty->getDeclaringClass()->getName() !== $resultClass) {
                    continue;
                }
                $description = (new ReflectionDocComment((string)$reflectionProperty->getDocComment()))
                    ->getDescription(forProperty: true, includeExamples: false);
                $this->assertNotSame('', trim((string)$description), $resultClass . '::$' . $reflectionProperty->getName());
            }
        }
    }
}
