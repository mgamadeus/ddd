<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;

/**
 * The query backend ISSUES_QUERY_BACKEND names.
 */
class IssuesQueryBackendFactory
{
    /**
     * @throws InternalErrorException The backend is not available in this release
     */
    public static function create(IssuesRegistrySettings $settings): IssuesQueryBackend
    {
        return match ($settings->queryBackend) {
            IssuesRegistrySettings::BACKEND_LOKI => new LokiIssuesQueryBackend($settings),
            IssuesRegistrySettings::BACKEND_NONE => new NullIssuesQueryBackend(),
            default => throw new InternalErrorException(
                "The issues query backend \"$settings->queryBackend\" is not available yet (loki or none)."
            ),
        };
    }
}
