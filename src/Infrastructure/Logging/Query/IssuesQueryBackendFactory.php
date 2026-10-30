<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Query\Elasticsearch\ElasticsearchIssuesQueryBackend;
use DDD\Infrastructure\Logging\Query\Sentry\SentryIssuesQueryBackend;

/**
 * The query backend ISSUES_QUERY_BACKEND names.
 */
class IssuesQueryBackendFactory
{
    /**
     * @throws InternalErrorException Unknown backend
     */
    public static function create(IssuesRegistrySettings $settings): IssuesQueryBackend
    {
        return match ($settings->queryBackend) {
            IssuesRegistrySettings::BACKEND_LOKI => new LokiIssuesQueryBackend($settings),
            IssuesRegistrySettings::BACKEND_ELASTICSEARCH,
            IssuesRegistrySettings::BACKEND_OPENSEARCH => new ElasticsearchIssuesQueryBackend($settings),
            IssuesRegistrySettings::BACKEND_SENTRY => new SentryIssuesQueryBackend($settings),
            IssuesRegistrySettings::BACKEND_NONE => new NullIssuesQueryBackend(),
            default => throw new InternalErrorException(
                "Unknown issues query backend \"$settings->queryBackend\" (loki, elasticsearch, opensearch, sentry or none)."
            ),
        };
    }
}
