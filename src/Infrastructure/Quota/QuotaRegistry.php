<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Quota;

use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Libs\Config;
use InvalidArgumentException;

/**
 * Reads the `Common.Quota` config and maps each quota type to its limiter groups. The single source of truth is the
 * config file (app-level `config/app/Common/Quota.php` overrides the framework default of the same path) — adding or
 * tuning a quota is a config change, never a code change here or in a controller. One type can carry SEVERAL groups
 * keyed differently, plus the config's `sharedGroups` (cross-cutting layers, e.g. a per-IP flood guard) appended to
 * every type. An unknown type returns `[]` (the guard then fails open) so a typo can never hard-block traffic.
 *
 * Config shape:
 * ```php
 * return [
 *   'cacheGroup'   => Cache::CACHE_GROUP_REDIS,           // default storage; overridable per group
 *   'sharedGroups' => [ ['keyBy' => 'ip', 'limits' => ['minute' => 120]] ],
 *   'types'        => [ 'ai.message' => [ ['keyBy' => 'account', 'limits' => ['minute' => 12, 'hour' => 100]] ] ],
 * ];
 * ```
 * Per group, optional `policy` (default sliding_window) and `cacheGroup` (default the top-level one).
 */
class QuotaRegistry
{
    protected const string CONFIG_KEY = 'Common.Quota';

    /** Limiter-id prefix for shared groups — type-independent, so their counter is one budget across every type. */
    protected const string SHARED_GROUP_ID_PREFIX = 'shared';

    /** Horizon keyword → rate-limiter interval string. */
    protected const array HORIZON_INTERVALS = [
        'minute' => '1 minute',
        'hour' => '1 hour',
        'day' => '24 hours',
    ];

    /** Horizon keyword → window length in seconds (for the APC/Redis-sync fixed-window bucket). */
    protected const array HORIZON_SECONDS = [
        'minute' => 60,
        'hour' => 3600,
        'day' => 86400,
    ];

    /** @var array<string, mixed>|null */
    protected ?array $config = null;

    /**
     * The limiter groups to enforce for $quotaType — the type's own groups then the shared groups. Consumed in order,
     * the first rejection denies the request.
     *
     * @return QuotaDefinition[]
     */
    public function forType(string $quotaType): array
    {
        $config = $this->loadConfig();
        $typeGroups = $config['types'][$quotaType] ?? null;
        if ($typeGroups === null) {
            return [];
        }
        $definitions = [];
        foreach ($typeGroups as $groupConfig) {
            $definitions[] = $this->buildDefinition($quotaType, $groupConfig);
        }
        // Shared groups are prefixed 'shared' (NOT the type) so their counter is ONE budget across every type — e.g. a
        // per-IP flood guard caps 120/min per IP total, not 120/min per type.
        foreach (($config['sharedGroups'] ?? []) as $groupConfig) {
            $definitions[] = $this->buildDefinition(self::SHARED_GROUP_ID_PREFIX, $groupConfig);
        }
        return $definitions;
    }

    /** Whether $quotaType is a configured category. */
    public function isKnown(string $quotaType): bool
    {
        return isset($this->loadConfig()['types'][$quotaType]);
    }

    /** The default DDD cache group for storage (a group may override it). */
    public function defaultCacheGroup(): string
    {
        return $this->loadConfig()['cacheGroup'] ?? Cache::CACHE_GROUP_REDIS;
    }

    /**
     * @param string $idPrefix The limiter-id namespace: the quota type for a type group, {@see self::SHARED_GROUP_ID_PREFIX}
     *                         for a shared group.
     * @param array<string, mixed> $groupConfig
     */
    protected function buildDefinition(string $idPrefix, array $groupConfig): QuotaDefinition
    {
        $keyStrategy = $groupConfig['keyBy'];
        $keyFields = $groupConfig['keyFields'] ?? [];
        $onExceed = $groupConfig['onExceed'] ?? QuotaOnExceed::THROTTLE_429;
        $message = $groupConfig['message'] ?? null;
        $policy = $groupConfig['policy'] ?? QuotaLimiterSpec::POLICY_SLIDING_WINDOW;
        $cacheGroup = $groupConfig['cacheGroup'] ?? $this->defaultCacheGroup();
        $store = $groupConfig['store'] ?? QuotaLimiterSpec::STORE_RATE_LIMITER;
        $syncEverySeconds = (int)($groupConfig['syncEverySeconds'] ?? 5);
        $syncEveryRequests = (int)($groupConfig['syncEveryRequests'] ?? 50);

        $limiterSpecs = [];
        foreach ($groupConfig['limits'] as $horizon => $limit) {
            if (!isset(self::HORIZON_INTERVALS[$horizon])) {
                throw new InvalidArgumentException("Unknown quota horizon '{$horizon}' for '{$idPrefix}'.");
            }
            $limiterSpecs[] = new QuotaLimiterSpec(
                id: $idPrefix . ':' . $keyStrategy . ':' . $horizon,
                limit: (int)$limit,
                interval: self::HORIZON_INTERVALS[$horizon],
                windowSeconds: self::HORIZON_SECONDS[$horizon],
                policy: $policy,
                cacheGroup: $cacheGroup,
                store: $store,
                syncEverySeconds: $syncEverySeconds,
                syncEveryRequests: $syncEveryRequests,
            );
        }
        return new QuotaDefinition($limiterSpecs, $keyStrategy, $keyFields, $onExceed, $message);
    }

    /**
     * Loads + memoizes the `Common.Quota` config. Protected so tests can subclass and return a fixture without the
     * Config subsystem (DDD convention: everything protected, overridable).
     *
     * @return array<string, mixed>
     */
    protected function loadConfig(): array
    {
        return $this->config ??= (Config::get(self::CONFIG_KEY) ?? []);
    }
}
