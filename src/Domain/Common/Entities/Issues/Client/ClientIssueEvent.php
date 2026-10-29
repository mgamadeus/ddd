<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One error a client observed. Every field is capped by {@see \DDD\Infrastructure\Logging\ClientIssueReportNormalizer}
 * before it is logged — a client's own caps are never trusted.
 */
class ClientIssueEvent extends ValueObject
{
    /** A `window.onerror` / uncaught error */
    public const string KIND_ERROR = 'error';
    /** An unhandled promise rejection */
    public const string KIND_UNHANDLED_REJECTION = 'unhandledrejection';
    /** An error boundary caught a render error */
    public const string KIND_REACT_RENDER = 'react-render';
    /** A server-side render or route handler of the client app (Node) failed */
    public const string KIND_SERVER_REQUEST = 'server-request';
    /** Reported by the client's own code; the only kind logged as handled */
    public const string KIND_MANUAL = 'manual';
    public const array KINDS = [
        self::KIND_ERROR, self::KIND_UNHANDLED_REJECTION, self::KIND_REACT_RENDER, self::KIND_SERVER_REQUEST, self::KIND_MANUAL,
    ];

    public const string LEVEL_ERROR = 'error';
    public const string LEVEL_WARNING = 'warning';
    public const array LEVELS = [self::LEVEL_ERROR, self::LEVEL_WARNING];

    public const int MAX_NAME_LENGTH = 128;
    public const int MAX_MESSAGE_LENGTH = 1024;
    public const int MAX_RAW_STACK_LENGTH = 4096;
    public const int MAX_URL_LENGTH = 1024;
    public const int MAX_ROUTE_PATTERN_LENGTH = 256;
    public const int MAX_REQUEST_METHOD_LENGTH = 16;
    public const int MAX_CLIENT_FINGERPRINT_LENGTH = 128;

    /** @var string When it happened (ISO 8601, client clock) */
    public string $occurredAt = '';

    /** @var string One of {@see self::KINDS}; anything else becomes `error` */
    public string $kind = self::KIND_ERROR;

    /** @var string One of {@see self::LEVELS}; anything else becomes `error` */
    public string $level = self::LEVEL_ERROR;

    /** @var string The error's name / type, e.g. `TypeError` */
    public string $name = '';

    public string $message = '';

    public ClientIssueFrames $frames;

    /** @var string|null The unparsed stack; kept only when the client sent no frames */
    public ?string $rawStack = null;

    /** @var string|null The page URL (redacted by the client and again by Core) */
    public ?string $url = null;

    /** @var string|null The route pattern of the page, e.g. `/[lang]/menu/[locationSlug]` */
    public ?string $routePattern = null;

    /** @var string|null `server-request`: the request's method */
    public ?string $requestMethod = null;

    /** @var int|null `server-request`: the response status */
    public ?int $httpStatus = null;

    public ClientIssueBreadcrumbs $breadcrumbs;

    public ClientIssueTags $tags;

    /** @var int How often the client saw this event since its last report (its own de-duplication) */
    public int $count = 1;

    /** @var string|null The client's own de-duplication key; informational */
    public ?string $clientFingerprint = null;

    public function __construct()
    {
        $this->frames = new ClientIssueFrames();
        $this->breadcrumbs = new ClientIssueBreadcrumbs();
        $this->tags = new ClientIssueTags();
        parent::__construct();
    }

    /** `handled` is false for every kind but `manual` */
    public function isHandled(): bool
    {
        return $this->kind === self::KIND_MANUAL;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
