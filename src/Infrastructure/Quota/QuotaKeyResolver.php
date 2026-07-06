<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

/**
 * Turns a {@see QuotaKeyStrategy} + the request's {@see QuotaContext} into the string key a limiter counts against.
 * Pure — no I/O — so it is fully unit-testable. Returns null when the strategy needs a field the context did not
 * resolve (e.g. BY_ACCOUNT on an unauthenticated request); the {@see QuotaGuard} treats a null key as fail-open for
 * that group. Keys are namespaced by strategy prefix; the limiter id already namespaces the category, so the key only
 * has to identify the subject.
 */
class QuotaKeyResolver
{
    /**
     * @param string[] $keyFields For BY_CUSTOM: request-field paths composed into the key (via $fieldReader).
     */
    public function resolve(
        string $keyStrategy,
        QuotaContext $context,
        ?string $keyOverride = null,
        array $keyFields = [],
        ?QuotaRequestFieldReaderInterface $fieldReader = null,
    ): ?string {
        return match ($keyStrategy) {
            QuotaKeyStrategy::BY_IP => $context->clientIp !== null ? 'ip:' . $context->clientIp : null,
            QuotaKeyStrategy::BY_ACCOUNT => $context->accountId !== null ? 'acct:' . $context->accountId : null,
            QuotaKeyStrategy::BY_LOCATION => $context->locationId !== null ? 'loc:' . $context->locationId : null,
            QuotaKeyStrategy::BY_ACCOUNT_LOCATION => ($context->accountId !== null && $context->locationId !== null)
                ? 'acct:' . $context->accountId . ':loc:' . $context->locationId
                : null,
            QuotaKeyStrategy::BY_CUSTOM => $this->resolveCustomKey($context, $keyOverride, $keyFields, $fieldReader),
            default => null,
        };
    }

    /**
     * BY_CUSTOM key: compose from the request fields when configured, else the attribute's key override / context key.
     * Returns null when NONE of the configured fields is present (fail-open — never key on an all-empty composite).
     *
     * @param string[] $keyFields
     */
    protected function resolveCustomKey(
        QuotaContext $context,
        ?string $keyOverride,
        array $keyFields,
        ?QuotaRequestFieldReaderInterface $fieldReader,
    ): ?string {
        if ($keyFields === [] || $fieldReader === null) {
            return $keyOverride ?? $context->customKey;
        }
        $parts = [];
        $anyPresent = false;
        foreach ($keyFields as $field) {
            $value = $fieldReader->fieldValue($field);
            if ($value !== null) {
                $anyPresent = true;
            }
            $parts[] = $value ?? '';
        }
        return $anyPresent ? 'custom:' . implode('|', $parts) : null;
    }
}
