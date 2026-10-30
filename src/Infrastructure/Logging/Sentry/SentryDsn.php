<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Sentry;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * A Sentry DSN (`https://<public key>@<host>[/<path>]/<project id>`) and the envelope endpoint it names.
 */
class SentryDsn extends ValueObject
{
    public const string CLIENT_NAME = 'mgamadeus-ddd-issues/1.0';

    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly string $publicKey,
        public readonly string $projectId,
        /** @var string Path before the project id, e.g. "" or "/sentry" */
        public readonly string $pathPrefix = '',
        public readonly ?int $port = null,
    ) {
        parent::__construct();
    }

    /**
     * @throws InternalErrorException Not a DSN
     */
    public static function fromString(string $dsn): static
    {
        $parts = parse_url(trim($dsn));
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        $lastSlash = strrpos($path, '/');
        $projectId = $lastSlash !== false ? substr($path, $lastSlash + 1) : '';
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['user']) || $projectId === '') {
            throw new InternalErrorException('Invalid Sentry DSN (expected https://<key>@<host>/<project id>)');
        }
        return new static(
            (string)$parts['scheme'],
            (string)$parts['host'],
            (string)$parts['user'],
            $projectId,
            substr($path, 0, (int)$lastSlash),
            isset($parts['port']) ? (int)$parts['port'] : null,
        );
    }

    public function getEnvelopeUrl(): string
    {
        return sprintf(
            '%s://%s%s%s/api/%s/envelope/',
            $this->scheme,
            $this->host,
            $this->port !== null ? ':' . $this->port : '',
            $this->pathPrefix,
            $this->projectId
        );
    }

    public function getAuthHeader(): string
    {
        return sprintf('Sentry sentry_version=7, sentry_key=%s, sentry_client=%s', $this->publicKey, self::CLIENT_NAME);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->getEnvelopeUrl());
    }
}
