<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use PHPUnit\Framework\TestCase;

/**
 * The Elasticsearch settings fall back to the ES_LOGS_* variables of an existing dashboard; ISSUES_QUERY_* wins.
 */
class IssuesRegistrySettingsTest extends TestCase
{
    public const array VARIABLES = [
        'ISSUES_QUERY_ENVIRONMENTS', 'APP_STAGE', 'ISSUES_ALERT_LINK_TEMPLATE',
        'ISSUES_QUERY_BACKEND', 'ISSUES_QUERY_URL', 'ISSUES_QUERY_USER', 'ISSUES_QUERY_SELECTOR', 'ISSUES_ES_KEYWORD_SUFFIX',
        'ES_LOGS_HOST', 'ES_LOGS_PORT', 'ES_LOGS_SCHEME', 'ES_LOGS_USER', 'ES_LOGS_PASSWORD', 'ES_LOGS_INDEX', 'ES_LOGS_INDEX_DEV',
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

    public function testElasticsearchFallsBackToEsLogsVariables(): void
    {
        $_ENV['ES_LOGS_HOST'] = 'logger.example';
        $_ENV['ES_LOGS_USER'] = 'reader';
        $_ENV['ES_LOGS_PASSWORD'] = 'pw';
        $_ENV['ES_LOGS_INDEX'] = 'logstash-sf-*';
        $_ENV['ES_LOGS_INDEX_DEV'] = 'logstash-dev-sf-*';

        $issuesRegistrySettings = IssuesRegistrySettings::fromEnvironmentForElasticsearch();

        self::assertSame(IssuesRegistrySettings::BACKEND_ELASTICSEARCH, $issuesRegistrySettings->queryBackend);
        self::assertSame('https://logger.example:9200', $issuesRegistrySettings->queryUrl);
        self::assertSame('reader', $issuesRegistrySettings->queryUser);
        self::assertSame('pw', $issuesRegistrySettings->queryToken);
        self::assertSame('logstash-sf-*', $issuesRegistrySettings->querySelector);
        self::assertSame('logstash-dev-sf-*', $issuesRegistrySettings->querySelectorDevelopment);
        self::assertSame('.keyword', $issuesRegistrySettings->elasticsearchKeywordSuffix);
        // the registry stays off: ES_LOGS_* alone does not turn it on
        self::assertFalse(IssuesRegistrySettings::fromEnvironment()->hasQueryBackend());
    }

    public function testEnvironments(): void
    {
        $_ENV['ISSUES_QUERY_BACKEND'] = 'loki';
        $_ENV['ISSUES_QUERY_SELECTOR'] = '{service="app"}';
        $_ENV['APP_STAGE'] = 'staging';
        $_ENV['ISSUES_ALERT_LINK_TEMPLATE'] = 'https://admin/issues?environment={environment}&group={group_hash}';

        $singleEnvironmentSettings = IssuesRegistrySettings::fromEnvironment();
        self::assertSame(['staging'], $singleEnvironmentSettings->getQueryEnvironments()->getNames());
        self::assertSame('{service="app"}', $singleEnvironmentSettings->getQueryEnvironments()->first()->selector);
        self::assertFalse($singleEnvironmentSettings->hasSeveralEnvironments());
        self::assertSame('https://admin/issues?environment=staging&group=g%2F1', $singleEnvironmentSettings->getAlertLink('g/1', 'staging'));

        $_ENV['ISSUES_QUERY_ENVIRONMENTS'] = '{"prod": "{workspace=\\"prod\\"}", "mgn": "{workspace=\\"mgn\\"}"}';
        $severalEnvironmentsSettings = IssuesRegistrySettings::fromEnvironment();
        self::assertSame(['prod', 'mgn'], $severalEnvironmentsSettings->getQueryEnvironments()->getNames());
        self::assertSame('{workspace="mgn"}', $severalEnvironmentsSettings->getQueryEnvironments()->getByName('mgn')->selector);
        self::assertSame('{workspace="mgn"}', $severalEnvironmentsSettings->withQuerySelector('{workspace="mgn"}')->querySelector);
        self::assertSame('loki', $severalEnvironmentsSettings->withQuerySelector('x')->queryBackend);

        $_ENV['ISSUES_QUERY_ENVIRONMENTS'] = '["prod"]';
        $this->expectException(InternalErrorException::class);
        IssuesRegistrySettings::fromEnvironment();
    }

    public function testIssuesVariablesWinAndAnEmptySuffixIsAValue(): void
    {
        $_ENV['ES_LOGS_HOST'] = 'logger.example';
        $_ENV['ES_LOGS_INDEX'] = 'logstash-sf-*';
        $_ENV['ISSUES_QUERY_BACKEND'] = 'opensearch';
        $_ENV['ISSUES_QUERY_URL'] = 'http://opensearch:9200/';
        $_ENV['ISSUES_QUERY_SELECTOR'] = 'issues-*';
        $_ENV['ISSUES_ES_KEYWORD_SUFFIX'] = '';

        $issuesRegistrySettings = IssuesRegistrySettings::fromEnvironment();

        self::assertSame(IssuesRegistrySettings::BACKEND_OPENSEARCH, $issuesRegistrySettings->queryBackend);
        self::assertTrue($issuesRegistrySettings->isElasticsearch());
        self::assertSame('http://opensearch:9200', $issuesRegistrySettings->queryUrl);
        self::assertSame('issues-*', $issuesRegistrySettings->querySelector);
        self::assertSame('', $issuesRegistrySettings->elasticsearchKeywordSuffix);
        self::assertSame(IssuesRegistrySettings::BACKEND_OPENSEARCH, IssuesRegistrySettings::fromEnvironmentForElasticsearch()->queryBackend);
    }
}
