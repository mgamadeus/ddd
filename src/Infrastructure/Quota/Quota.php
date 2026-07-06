<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

use Attribute;

/**
 * Declares that a controller / action is subject to a rate quota. The generic {@see \DDD\Symfony\EventListeners\QuotaSubscriber}
 * reads it (method-level overrides class-level) and enforces the limiters the {@see QuotaRegistry} maps for
 * {@see $quotaType} from the `Common.Quota` config — throwing HTTP 429 + `Retry-After` on breach. The attribute carries
 * only the category identifier (a string a consuming app defines, e.g. via its own `QuotaType` constants) + an optional
 * opt-out + an optional custom-key override; all numbers and the key strategy live in config, so a limit change never
 * touches a controller.
 *
 * ```php
 * #[Quota('ai.message')]                 public function postMessage(...) {}
 * #[Quota('ai.generic', disabled: true)] public function freeReadEndpoint(...) {}  // opt-out
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class Quota
{
    /**
     * @param string $quotaType The quota category identifier — must match a key under `Common.Quota` config `types`.
     * @param bool $disabled Opt-out at method level when the class carries the attribute.
     * @param string|null $keyOverride A caller-supplied composite key for {@see QuotaKeyStrategy::BY_CUSTOM}.
     */
    public function __construct(
        public string $quotaType,
        public bool $disabled = false,
        public ?string $keyOverride = null,
    ) {
    }
}
