<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesLogRedactor;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Query\Elasticsearch\ElasticsearchIssuesQueryBackend;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorAggregationQuery;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorDetailAggregationQuery;
use PHPUnit\Framework\TestCase;

/** IssuesLogSettings over a committed `Common.Issues` instead of the application's config file */
class CommittedIssuesLogSettings extends IssuesLogSettings
{
    /** @var array<string, mixed> */
    public static array $committedSettings = [];

    protected static function readCommittedSettings(): array
    {
        return self::$committedSettings;
    }
}

/** IssuesRegistrySettings over a committed `Common.Issues` instead of the application's config file */
class CommittedIssuesRegistrySettings extends IssuesRegistrySettings
{
    /** @var array<string, mixed> */
    public static array $committedSettings = [];

    protected static function readCommittedSettings(): array
    {
        return self::$committedSettings;
    }
}

/**
 * The issues settings come from the committed configuration; an environment variable overrides a committed value;
 * secrets are read from the environment only.
 */
class IssuesCommittedSettingsTest extends TestCase
{
    public const array VARIABLES = [
        'ISSUES_LOG_LEVEL', 'ISSUES_CAPTURE_ENABLED', 'ISSUES_LOG_HEADER_PREFIXES', 'ISSUES_LOG_4XX_LEVEL',
        'ISSUES_LOG_SANITIZE_NUMBER_MIN_DIGITS', 'ISSUES_QUERY_BACKEND', 'ISSUES_QUERY_SELECTOR', 'ISSUES_QUERY_TOKEN',
        'ISSUES_QUERY_ENVIRONMENTS', 'ISSUES_QUERY_SELECTOR_DEVELOPMENT', 'ISSUES_ES_KEYWORD_SUFFIX', 'ISSUES_ES_WORKSPACE_FIELD',
        'ISSUES_ALERT_WEBHOOK', 'ISSUES_SEED_HOURS', 'ES_LOGS_INDEX', 'ES_LOGS_INDEX_DEV', 'ES_LOGS_PASSWORD', 'ES_LOGS_HOST',
    ];

    /** @var array<string, mixed> */
    protected array $previousEnvironment = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $variable) {
            $this->previousEnvironment[$variable] = $_ENV[$variable] ?? null;
            unset($_ENV[$variable]);
            putenv($variable);
        }
        CommittedIssuesLogSettings::$committedSettings = [];
        CommittedIssuesRegistrySettings::$committedSettings = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $variable => $value) {
            if ($value === null) {
                unset($_ENV[$variable]);
            } else {
                $_ENV[$variable] = $value;
            }
        }
    }

    public function testTheLogSettingsComeFromTheCommittedConfigurationAndTheEnvironmentOverrides(): void
    {
        CommittedIssuesLogSettings::$committedSettings = [
            'level' => 'ERROR',
            'captureEnabled' => false,
            'clientErrorLevel' => 'notice',
            'headerPrefixes' => ['X-', ' x-rc-'],
            'maxEventBytes' => 4096,
            'sanitizeNumberMinDigits' => 0,
        ];

        $committedSettings = CommittedIssuesLogSettings::fromEnvironment();
        self::assertSame('error', $committedSettings->level);
        self::assertFalse($committedSettings->captureEnabled);
        self::assertSame('notice', $committedSettings->clientErrorLevel);
        self::assertSame(['x-', 'x-rc-'], $committedSettings->headerPrefixes);
        self::assertSame(4096, $committedSettings->maxEventBytes);
        self::assertSame(0, $committedSettings->sanitizeNumberMinDigits);

        $_ENV['ISSUES_LOG_LEVEL'] = 'critical';
        $_ENV['ISSUES_CAPTURE_ENABLED'] = 'true';
        $overriddenSettings = CommittedIssuesLogSettings::fromEnvironment();
        self::assertSame('critical', $overriddenSettings->level);
        self::assertTrue($overriddenSettings->captureEnabled);
        self::assertSame(['x-', 'x-rc-'], $overriddenSettings->headerPrefixes, 'a value the environment leaves unset stays committed');
    }

    public function testTheRegistrySettingsComeFromTheCommittedConfigurationButSecretsOnlyFromTheEnvironment(): void
    {
        CommittedIssuesRegistrySettings::$committedSettings = [
            'queryBackend' => 'elasticsearch',
            'querySelector' => 'logstash-prod-*',
            'querySelectorDevelopment' => 'logstash-dev-*',
            'queryEnvironments' => ['prod' => 'logstash-prod-*', 'testing' => 'logstash-testing-*'],
            'elasticsearchKeywordSuffix' => '',
            'elasticsearchWorkspaceField' => 'service.workspace',
            'seedHours' => 6,
            'queryToken' => 'committed-secret',
            'alertWebhook' => 'https://hooks.example/committed',
        ];

        $registrySettings = CommittedIssuesRegistrySettings::fromEnvironment();
        self::assertSame(IssuesRegistrySettings::BACKEND_ELASTICSEARCH, $registrySettings->queryBackend);
        self::assertSame('logstash-prod-*', $registrySettings->querySelector);
        self::assertSame('logstash-dev-*', $registrySettings->querySelectorDevelopment);
        self::assertSame(['prod', 'testing'], $registrySettings->getQueryEnvironments()->getNames());
        self::assertSame('', $registrySettings->elasticsearchKeywordSuffix, 'an empty committed suffix is a value');
        self::assertSame('service.workspace', $registrySettings->elasticsearchWorkspaceField);
        self::assertSame(6, $registrySettings->seedHours);
        self::assertNull($registrySettings->queryToken, 'a committed secret is never read');
        self::assertNull($registrySettings->alertWebhook, 'a committed secret is never read');

        $_ENV['ISSUES_QUERY_SELECTOR'] = 'logstash-override-*';
        $_ENV['ISSUES_QUERY_TOKEN'] = 'env-secret';
        $_ENV['ISSUES_QUERY_ENVIRONMENTS'] = '{"prod": "logstash-a-*"}';
        $overriddenSettings = CommittedIssuesRegistrySettings::fromEnvironment();
        self::assertSame('logstash-override-*', $overriddenSettings->querySelector);
        self::assertSame('env-secret', $overriddenSettings->queryToken);
        self::assertSame(['prod'], $overriddenSettings->getQueryEnvironments()->getNames());
    }

    public function testADevelopmentQueryWithoutADevelopmentIndexThrowsInsteadOfReadingProduction(): void
    {
        $elasticsearchIssuesQueryBackend = new ElasticsearchIssuesQueryBackend(
            new IssuesRegistrySettings(IssuesRegistrySettings::BACKEND_ELASTICSEARCH, 'http://es', querySelector: 'logstash-prod-*')
        );

        self::assertSame('logstash-prod-*', $elasticsearchIssuesQueryBackend->getLogIndexPatternForEnvironment(RuntimeErrorAggregationQuery::ENVIRONMENT_PRODUCTION));
        $this->expectException(InternalErrorException::class);
        $elasticsearchIssuesQueryBackend->getLogIndexPatternForEnvironment(RuntimeErrorAggregationQuery::ENVIRONMENT_DEVELOPMENT);
    }

    public function testGroupHashGroupsAndDetailsOnTheRootCause(): void
    {
        $groupsBody = RuntimeErrorAggregationQuery::build(groupingDimension: RuntimeErrorAggregationQuery::DIMENSION_GROUP_HASH)->toGroupsBody();
        self::assertSame('error.group_hash.keyword', $groupsBody['aggs'][RuntimeErrorAggregationQuery::OUTER_AGG_KEY]['terms']['field']);

        $detailBody = RuntimeErrorDetailAggregationQuery::build('a1b2c3', groupingDimension: RuntimeErrorAggregationQuery::DIMENSION_GROUP_HASH)->toDetailBody();
        self::assertContains(['term' => ['error.group_hash.keyword' => 'a1b2c3']], $detailBody['query']['bool']['filter']);
        self::assertSame('error.group_hash.keyword', $detailBody['aggs'][RuntimeErrorAggregationQuery::OUTER_AGG_KEY]['terms']['field']);
    }

    public function testDigitRunsAreConfigurableAndStackTracesAreSanitised(): void
    {
        self::assertSame('place 123456789012 at 1730203200123', (new IssuesLogRedactor(0))->sanitizeText('place 123456789012 at 1730203200123'));
        self::assertSame('place [NUMBER]', (new IssuesLogRedactor())->sanitizeText('place 123456789012'));

        $redactedContext = (new IssuesLogRedactor(0))->redactContext([
            'error.stack_trace' => "#0 Mailer->send('anna.berg@example.com')",
            'error.full_stack_trace' => "#0 Login->check('Bearer abc.def')",
        ]);
        self::assertSame("#0 Mailer->send('[EMAIL]')", $redactedContext['error.stack_trace']);
        self::assertSame("#0 Login->check('Bearer [REDACTED]')", $redactedContext['error.full_stack_trace']);
    }
}
