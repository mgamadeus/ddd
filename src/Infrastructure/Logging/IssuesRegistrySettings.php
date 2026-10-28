<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * Configuration of the issues registry, its job, the query backend and the alert, read from the environment.
 * `ISSUES_QUERY_BACKEND` unset or `none` = no registry, no alert: the application only logs.
 */
class IssuesRegistrySettings extends ValueObject
{
    public const string ENV_QUERY_BACKEND = 'ISSUES_QUERY_BACKEND';
    public const string ENV_QUERY_URL = 'ISSUES_QUERY_URL';
    public const string ENV_QUERY_USER = 'ISSUES_QUERY_USER';
    public const string ENV_QUERY_TOKEN = 'ISSUES_QUERY_TOKEN';
    public const string ENV_QUERY_SELECTOR = 'ISSUES_QUERY_SELECTOR';
    public const string ENV_QUERY_TENANT = 'ISSUES_QUERY_TENANT';
    public const string ENV_QUERY_TIMEOUT_SECONDS = 'ISSUES_QUERY_TIMEOUT_SECONDS';
    public const string ENV_ALERT_WEBHOOK = 'ISSUES_ALERT_WEBHOOK';
    public const string ENV_ALERT_LINK_TEMPLATE = 'ISSUES_ALERT_LINK_TEMPLATE';
    public const string ENV_DISCOVERY_WINDOW_MINUTES = 'ISSUES_DISCOVERY_WINDOW_MINUTES';
    public const string ENV_SEED_HOURS = 'ISSUES_SEED_HOURS';
    public const string ENV_GROUP_EXPLOSION_LIMIT = 'ISSUES_GROUP_EXPLOSION_LIMIT';
    public const string ENV_JOB_STALE_MINUTES = 'ISSUES_JOB_STALE_MINUTES';
    public const string ENV_COLLECTOR_SILENT_MINUTES = 'ISSUES_COLLECTOR_SILENT_MINUTES';

    public const string BACKEND_NONE = 'none';
    public const string BACKEND_LOKI = 'loki';
    public const string BACKEND_ELASTICSEARCH = 'elasticsearch';
    public const string BACKEND_SENTRY = 'sentry';

    /** The link placeholder of the alert template */
    public const string LINK_GROUP_HASH_PLACEHOLDER = '{group_hash}';

    public function __construct(
        /** @var string none | loki | elasticsearch | sentry */
        public readonly string $queryBackend = self::BACKEND_NONE,
        /** @var string Base URL of the backend (Loki: http://loki:3100) */
        public readonly string $queryUrl = '',
        public readonly ?string $queryUser = null,
        /** @var string|null Password / API token (with a user: basic auth, without: bearer) */
        public readonly ?string $queryToken = null,
        /** @var string Loki stream selector / ES index pattern / Sentry org+project */
        public readonly string $querySelector = '',
        /** @var string|null Loki tenant (X-Scope-OrgID) */
        public readonly ?string $queryTenant = null,
        public readonly int $queryTimeoutSeconds = 10,
        /** @var string|null Slack-compatible incoming-webhook URL; null = no alert */
        public readonly ?string $alertWebhook = null,
        /** @var string|null Link in the alert; {group_hash} is replaced */
        public readonly ?string $alertLinkTemplate = null,
        /** @var int Discovery window: the collector's maximum buffer age */
        public readonly int $discoveryWindowMinutes = 120,
        /** @var int Seed mode after the first run: groups are registered without alerts */
        public readonly int $seedHours = 24,
        /** @var int New groups per hour above which they stop alerting one by one (one explosion alert instead) */
        public readonly int $groupExplosionLimit = 50,
        /** @var int The job counts as stalled after this long without a successful run */
        public readonly int $jobStaleMinutes = 10,
        /** @var int The collector counts as silent after this long without any new event */
        public readonly int $collectorSilentMinutes = 60,
    ) {
        parent::__construct();
    }

    public static function fromEnvironment(): static
    {
        $queryBackend = strtolower(self::readEnv(self::ENV_QUERY_BACKEND) ?? self::BACKEND_NONE);
        return new static(
            queryBackend: in_array($queryBackend, [self::BACKEND_LOKI, self::BACKEND_ELASTICSEARCH, self::BACKEND_SENTRY], true)
                ? $queryBackend
                : self::BACKEND_NONE,
            queryUrl: rtrim(self::readEnv(self::ENV_QUERY_URL) ?? '', '/'),
            queryUser: self::readEnv(self::ENV_QUERY_USER),
            queryToken: self::readEnv(self::ENV_QUERY_TOKEN),
            querySelector: self::readEnv(self::ENV_QUERY_SELECTOR) ?? '',
            queryTenant: self::readEnv(self::ENV_QUERY_TENANT),
            queryTimeoutSeconds: (int)(self::readEnv(self::ENV_QUERY_TIMEOUT_SECONDS) ?? 10),
            alertWebhook: self::readEnv(self::ENV_ALERT_WEBHOOK),
            alertLinkTemplate: self::readEnv(self::ENV_ALERT_LINK_TEMPLATE),
            discoveryWindowMinutes: (int)(self::readEnv(self::ENV_DISCOVERY_WINDOW_MINUTES) ?? 120),
            seedHours: (int)(self::readEnv(self::ENV_SEED_HOURS) ?? 24),
            groupExplosionLimit: (int)(self::readEnv(self::ENV_GROUP_EXPLOSION_LIMIT) ?? 50),
            jobStaleMinutes: (int)(self::readEnv(self::ENV_JOB_STALE_MINUTES) ?? 10),
            collectorSilentMinutes: (int)(self::readEnv(self::ENV_COLLECTOR_SILENT_MINUTES) ?? 60),
        );
    }

    public function hasQueryBackend(): bool
    {
        return $this->queryBackend !== self::BACKEND_NONE;
    }

    public function getAlertLink(string $groupHash): ?string
    {
        return $this->alertLinkTemplate !== null
            ? str_replace(self::LINK_GROUP_HASH_PLACEHOLDER, rawurlencode($groupHash), $this->alertLinkTemplate)
            : null;
    }

    /** The variable from .env (Config) or the process environment */
    protected static function readEnv(string $name): ?string
    {
        $value = Config::getEnv($name);
        if ($value === null || $value === '') {
            $value = $_SERVER[$name] ?? getenv($name);
        }
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
