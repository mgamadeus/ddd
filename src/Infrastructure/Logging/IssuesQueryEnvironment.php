<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One environment the issues registry watches: its name (the registry key next to the hash, e.g. `prod`, `testing`)
 * and the query backend selector of its lines (Loki stream selector, Elasticsearch index pattern, Sentry
 * `<organization>/<project>`).
 */
class IssuesQueryEnvironment extends ValueObject
{
    public function __construct(
        public readonly string $name,
        public readonly string $selector,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }
}
