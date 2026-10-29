<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a browser, Node or React Native client sends to the application's ingest endpoint: one client context and its
 * events. An application's request DTO embeds it, so the OpenAPI / TypeScript schema of the wire contract comes from
 * here. Logged by {@see \DDD\Infrastructure\Services\IssuesLogService::logClientIssue()}.
 */
class ClientIssueReport extends ValueObject
{
    public ClientIssueContext $client;

    public ClientIssueEvents $events;

    public function __construct()
    {
        $this->client = new ClientIssueContext();
        $this->events = new ClientIssueEvents();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
