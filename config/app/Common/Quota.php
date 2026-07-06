<?php

declare(strict_types=1);

use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Quota\QuotaKeyStrategy;
use DDD\Infrastructure\Quota\QuotaLimiterSpec;

/**
 * Framework DEFAULT quota config (module-level). A consuming application overrides this whole file at
 * `config/app/Common/Quota.php` (app-level configs win over module-level — see DDDBundle::boot()).
 *
 * Read by {@see DDD\Infrastructure\Quota\QuotaRegistry} via `Config::get('Common.Quota')`.
 *
 * Schema:
 *   'cacheGroup'   — default DDD cache group for every limiter's counter store ({@see Cache} group constant).
 *                    A group may override it per group below. `redis` = cluster-wide; `apc` = faster per-node.
 *   'sharedGroups' — limiter groups appended to EVERY type (cross-cutting layers, e.g. a per-IP flood guard).
 *   'types'        — per quota type: a list of limiter groups. The FIRST rejecting limiter denies the request.
 *
 * Each group:
 *   'keyBy'      — a {@see QuotaKeyStrategy} constant (what the count is keyed on).
 *   'limits'     — horizon => max tokens. Horizons: 'minute' | 'hour' | 'day'.
 *   'policy'     — optional {@see QuotaLimiterSpec} policy (default 'sliding_window').
 *   'cacheGroup' — optional per-group cache group override (default the top-level one).
 *
 * The framework ships NO concrete types — a consuming app defines its own type identifiers (as string constants) and
 * declares their limits here. Example (commented):
 *
 *   'types' => [
 *     'ai.message' => [
 *       ['keyBy' => QuotaKeyStrategy::BY_ACCOUNT, 'limits' => ['minute' => 12, 'hour' => 100, 'day' => 500]],
 *     ],
 *   ],
 *   'sharedGroups' => [
 *     ['keyBy' => QuotaKeyStrategy::BY_IP, 'limits' => ['minute' => 120], 'cacheGroup' => Cache::CACHE_GROUP_APC],
 *   ],
 */
return [
    'cacheGroup' => Cache::CACHE_GROUP_REDIS,
    'sharedGroups' => [],
    'types' => [],
];
