<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

use Symfony\Component\HttpFoundation\Request;

/**
 * The HTTP implementation of {@see QuotaRequestFieldReaderInterface}: resolves a dotted path against a Symfony request.
 * `ip` is the proxy-aware client IP (`X-Real-IP` then the connection IP); `header.*` / `query.*` read those bags;
 * `body.*` reads the request payload (form OR JSON, via `getPayload()`). Used to compose a
 * {@see QuotaKeyStrategy::BY_CUSTOM} key from request data — e.g. a signup limiter keyed on an installation id + IP.
 */
class RequestQuotaFieldReader implements QuotaRequestFieldReaderInterface
{
    public function __construct(protected readonly Request $request)
    {
    }

    public function fieldValue(string $path): ?string
    {
        if ($path === 'ip') {
            return $this->request->headers->get('x-real-ip') ?? $this->request->getClientIp();
        }

        $parts = explode('.', $path, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$source, $name] = $parts;

        $value = match ($source) {
            'header' => $this->request->headers->get($name),
            'query' => $this->request->query->get($name),
            'body' => $this->request->getPayload()->get($name),
            default => null,
        };

        return ($value === null || $value === '') ? null : (string)$value;
    }
}
