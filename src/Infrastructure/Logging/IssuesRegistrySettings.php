<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use Throwable;

/**
 * Configuration of the issues registry, its job, the query backend and the alert. Committed in the application's
 * `config/app/Common/Issues.php` ({@see IssuesLogSettings::CONFIG_PATH}, keys = the property names, e.g. `queryBackend`,
 * `querySelector`, `queryEnvironments` as a map environment => selector); an environment variable overrides a
 * committed value. Secrets come from the environment only: {@see self::ENV_QUERY_TOKEN}, {@see self::ENV_ALERT_WEBHOOK},
 * {@see self::ENV_SENTRY_DSN}. Backend unset or `none` = no registry, no alert: the application only logs.
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
    public const string ENV_QUERY_SELECTOR_DEVELOPMENT = 'ISSUES_QUERY_SELECTOR_DEVELOPMENT';
    public const string ENV_QUERY_TLS_VERIFY = 'ISSUES_QUERY_TLS_VERIFY';
    public const string ENV_ES_KEYWORD_SUFFIX = 'ISSUES_ES_KEYWORD_SUFFIX';
    public const string ENV_ES_LOCATION_FIELD = 'ISSUES_ES_LOCATION_FIELD';
    public const string ENV_ES_WORKSPACE_FIELD = 'ISSUES_ES_WORKSPACE_FIELD';
    public const string ENV_SENTRY_DSN = 'ISSUES_SENTRY_DSN';
    public const string ENV_QUERY_ENVIRONMENTS = 'ISSUES_QUERY_ENVIRONMENTS';

    /** The read-only Elasticsearch variables of an existing runtime-error dashboard (`ES_LOGS_*`): the fallback of the ES backend */
    public const string ENV_ES_LOGS_HOST = 'ES_LOGS_HOST';
    public const string ENV_ES_LOGS_PORT = 'ES_LOGS_PORT';
    public const string ENV_ES_LOGS_SCHEME = 'ES_LOGS_SCHEME';
    public const string ENV_ES_LOGS_USER = 'ES_LOGS_USER';
    public const string ENV_ES_LOGS_PASSWORD = 'ES_LOGS_PASSWORD';
    public const string ENV_ES_LOGS_INDEX = 'ES_LOGS_INDEX';
    public const string ENV_ES_LOGS_INDEX_DEV = 'ES_LOGS_INDEX_DEV';
    /** Sentry's own DSN variable: the fallback of ISSUES_SENTRY_DSN */
    public const string ENV_SENTRY_DSN_FALLBACK = 'SENTRY_DSN';

    public const string BACKEND_NONE = 'none';
    public const string BACKEND_LOKI = 'loki';
    public const string BACKEND_ELASTICSEARCH = 'elasticsearch';
    public const string BACKEND_SENTRY = 'sentry';
    /** OpenSearch speaks the same _search API: the Elasticsearch backend */
    public const string BACKEND_OPENSEARCH = 'opensearch';
    public const array BACKENDS = [self::BACKEND_LOKI, self::BACKEND_ELASTICSEARCH, self::BACKEND_OPENSEARCH, self::BACKEND_SENTRY];

    /** Elasticsearch: dynamically mapped string fields (Logstash, Filebeat defaults) aggregate on their keyword sub-field */
    public const string DEFAULT_ES_KEYWORD_SUFFIX = '.keyword';
    public const string DEFAULT_ES_LOCATION_FIELD = 'lastLocationId';
    public const string DEFAULT_ES_WORKSPACE_FIELD = 'workspace';

    /** The link placeholder of the alert template */
    public const string LINK_GROUP_HASH_PLACEHOLDER = '{group_hash}';
    public const string LINK_ENVIRONMENT_PLACEHOLDER = '{environment}';

    /** Name of the single environment when neither ISSUES_QUERY_ENVIRONMENTS nor APP_STAGE / APP_ENV is set */
    public const string FALLBACK_ENVIRONMENT_NAME = 'unknown';

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
        /** @var string Elasticsearch: index pattern of the development logs (`--logstashEnv=DEVELOPMENT`) */
        public readonly string $querySelectorDevelopment = '',
        /** @var bool Verify the backend's TLS certificate */
        public readonly bool $queryTlsVerify = true,
        /** @var string Elasticsearch: suffix of the aggregatable sub-field of a string field ('' = mapped as keyword) */
        public readonly string $elasticsearchKeywordSuffix = self::DEFAULT_ES_KEYWORD_SUFFIX,
        /** @var string Elasticsearch: numeric field counted as "affected locations" */
        public readonly string $elasticsearchLocationField = self::DEFAULT_ES_LOCATION_FIELD,
        /** @var string Elasticsearch: string field of the dev workspace (`--workspace`) */
        public readonly string $elasticsearchWorkspaceField = self::DEFAULT_ES_WORKSPACE_FIELD,
        /** @var string|null Sentry DSN of `app:issues:forward-sentry` */
        public readonly ?string $sentryDsn = null,
        /** @var IssuesQueryEnvironments|null The watched environments; null = one, {@see self::$defaultEnvironment} */
        public readonly ?IssuesQueryEnvironments $queryEnvironments = null,
        /** @var string Name of the single environment (APP_STAGE, else APP_ENV — as `service.environment` of the lines) */
        public readonly string $defaultEnvironment = self::FALLBACK_ENVIRONMENT_NAME,
    ) {
        parent::__construct();
    }

    public static function fromEnvironment(): static
    {
        $queryBackend = strtolower(static::stringSetting('queryBackend', self::ENV_QUERY_BACKEND) ?? self::BACKEND_NONE);
        return static::fromEnvironmentForBackend(in_array($queryBackend, self::BACKENDS, true) ? $queryBackend : self::BACKEND_NONE);
    }

    /**
     * The settings with the Elasticsearch backend, whatever ISSUES_QUERY_BACKEND says (OpenSearch stays OpenSearch) —
     * for the `app:runtime-errors:*` commands, which read Elasticsearch also where the registry job is off.
     */
    public static function fromEnvironmentForElasticsearch(): static
    {
        $queryBackend = strtolower(static::stringSetting('queryBackend', self::ENV_QUERY_BACKEND) ?? '');
        return static::fromEnvironmentForBackend(
            $queryBackend === self::BACKEND_OPENSEARCH ? self::BACKEND_OPENSEARCH : self::BACKEND_ELASTICSEARCH
        );
    }

    /**
     * Each value: its ISSUES_* variable, for Elasticsearch / OpenSearch then the ES_LOGS_* variable (URL as
     * scheme://host:port, user, password, index, development index), then the committed value, then the default.
     */
    protected static function fromEnvironmentForBackend(string $queryBackend): static
    {
        $isElasticsearch = in_array($queryBackend, [self::BACKEND_ELASTICSEARCH, self::BACKEND_OPENSEARCH], true);
        $queryUrl = self::readEnv(self::ENV_QUERY_URL);
        if ($queryUrl === null && $isElasticsearch && ($esLogsHost = self::readEnv(self::ENV_ES_LOGS_HOST)) !== null) {
            $queryUrl = (self::readEnv(self::ENV_ES_LOGS_SCHEME) ?? 'https') . '://' . $esLogsHost
                . ':' . (self::readEnv(self::ENV_ES_LOGS_PORT) ?? '9200');
        }
        $queryUrl ??= static::stringSetting('queryUrl', self::ENV_QUERY_URL);
        $fallback = static fn(string $configKey, string $name, string $esLogsName): ?string => self::readEnv($name)
            ?? ($isElasticsearch ? self::readEnv($esLogsName) : null)
            ?? static::stringSetting($configKey, $name);
        $committedKeywordSuffix = static::readCommittedSettings()['elasticsearchKeywordSuffix'] ?? null;
        return new static(
            queryBackend: $queryBackend,
            queryUrl: rtrim($queryUrl ?? '', '/'),
            queryUser: $fallback('queryUser', self::ENV_QUERY_USER, self::ENV_ES_LOGS_USER),
            // a secret: environment only
            queryToken: self::readEnv(self::ENV_QUERY_TOKEN) ?? ($isElasticsearch ? self::readEnv(self::ENV_ES_LOGS_PASSWORD) : null),
            querySelector: $fallback('querySelector', self::ENV_QUERY_SELECTOR, self::ENV_ES_LOGS_INDEX) ?? '',
            queryTenant: static::stringSetting('queryTenant', self::ENV_QUERY_TENANT),
            queryTimeoutSeconds: (int)(static::stringSetting('queryTimeoutSeconds', self::ENV_QUERY_TIMEOUT_SECONDS) ?? 10),
            alertWebhook: self::readEnv(self::ENV_ALERT_WEBHOOK),
            alertLinkTemplate: static::stringSetting('alertLinkTemplate', self::ENV_ALERT_LINK_TEMPLATE),
            discoveryWindowMinutes: (int)(static::stringSetting('discoveryWindowMinutes', self::ENV_DISCOVERY_WINDOW_MINUTES) ?? 120),
            seedHours: (int)(static::stringSetting('seedHours', self::ENV_SEED_HOURS) ?? 24),
            groupExplosionLimit: (int)(static::stringSetting('groupExplosionLimit', self::ENV_GROUP_EXPLOSION_LIMIT) ?? 50),
            jobStaleMinutes: (int)(static::stringSetting('jobStaleMinutes', self::ENV_JOB_STALE_MINUTES) ?? 10),
            collectorSilentMinutes: (int)(static::stringSetting('collectorSilentMinutes', self::ENV_COLLECTOR_SILENT_MINUTES) ?? 60),
            querySelectorDevelopment: $fallback('querySelectorDevelopment', self::ENV_QUERY_SELECTOR_DEVELOPMENT, self::ENV_ES_LOGS_INDEX_DEV) ?? '',
            queryTlsVerify: !in_array(strtolower(static::stringSetting('queryTlsVerify', self::ENV_QUERY_TLS_VERIFY) ?? 'true'), ['0', 'false', 'no', 'off'], true),
            // an explicitly empty suffix is a value: the fields are mapped as keyword
            elasticsearchKeywordSuffix: self::readRawEnv(self::ENV_ES_KEYWORD_SUFFIX)
                ?? (is_string($committedKeywordSuffix) ? $committedKeywordSuffix : self::DEFAULT_ES_KEYWORD_SUFFIX),
            elasticsearchLocationField: static::stringSetting('elasticsearchLocationField', self::ENV_ES_LOCATION_FIELD) ?? self::DEFAULT_ES_LOCATION_FIELD,
            elasticsearchWorkspaceField: static::stringSetting('elasticsearchWorkspaceField', self::ENV_ES_WORKSPACE_FIELD) ?? self::DEFAULT_ES_WORKSPACE_FIELD,
            // a secret: environment only
            sentryDsn: self::readEnv(self::ENV_SENTRY_DSN) ?? self::readEnv(self::ENV_SENTRY_DSN_FALLBACK),
            queryEnvironments: static::readQueryEnvironments(),
            defaultEnvironment: self::readEnv('APP_STAGE') ?? self::readEnv('APP_ENV') ?? self::FALLBACK_ENVIRONMENT_NAME,
        );
    }

    /** ISSUES_QUERY_ENVIRONMENTS (JSON), else the committed map `queryEnvironments` (environment => selector), else null */
    protected static function readQueryEnvironments(): ?IssuesQueryEnvironments
    {
        if (($queryEnvironmentsJson = self::readEnv(self::ENV_QUERY_ENVIRONMENTS)) !== null) {
            return IssuesQueryEnvironments::fromJson($queryEnvironmentsJson);
        }
        $committedQueryEnvironments = static::readCommittedSettings()['queryEnvironments'] ?? null;
        return is_array($committedQueryEnvironments) && $committedQueryEnvironments !== []
            ? IssuesQueryEnvironments::fromSelectorsByEnvironmentName($committedQueryEnvironments)
            : null;
    }

    /**
     * The committed configuration of the issues log and registry; empty when the application has none. The method a
     * test overrides.
     *
     * @return array<string, mixed>
     */
    protected static function readCommittedSettings(): array
    {
        try {
            $committedSettings = Config::get(IssuesLogSettings::CONFIG_PATH);
        } catch (Throwable) {
            return [];
        }
        return is_array($committedSettings) ? $committedSettings : [];
    }

    /** The environment variable when set, else the committed value (a string, number or bool), else null */
    protected static function stringSetting(string $configKey, string $envName): ?string
    {
        $value = self::readEnv($envName) ?? static::readCommittedSettings()[$configKey] ?? null;
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }

    public function isElasticsearch(): bool
    {
        return in_array($this->queryBackend, [self::BACKEND_ELASTICSEARCH, self::BACKEND_OPENSEARCH], true);
    }

    public function hasQueryBackend(): bool
    {
        return $this->queryBackend !== self::BACKEND_NONE;
    }

    /** The alert link; `{group_hash}` and `{environment}` are replaced */
    public function getAlertLink(string $groupHash, ?string $environment = null): ?string
    {
        return $this->alertLinkTemplate !== null
            ? str_replace(
                [self::LINK_GROUP_HASH_PLACEHOLDER, self::LINK_ENVIRONMENT_PLACEHOLDER],
                [rawurlencode($groupHash), rawurlencode((string)$environment)],
                $this->alertLinkTemplate
            )
            : null;
    }

    /**
     * The environments the registry watches: ISSUES_QUERY_ENVIRONMENTS, else one — {@see self::$defaultEnvironment}
     * with ISSUES_QUERY_SELECTOR.
     */
    public function getQueryEnvironments(): IssuesQueryEnvironments
    {
        if ($this->queryEnvironments !== null && $this->queryEnvironments->count() > 0) {
            return $this->queryEnvironments;
        }
        $issuesQueryEnvironments = new IssuesQueryEnvironments();
        $issuesQueryEnvironment = new IssuesQueryEnvironment($this->defaultEnvironment, $this->querySelector);
        $issuesQueryEnvironments->add($issuesQueryEnvironment);
        return $issuesQueryEnvironments;
    }

    /** Whether several environments are configured (ISSUES_QUERY_ENVIRONMENTS) */
    public function hasSeveralEnvironments(): bool
    {
        return $this->getQueryEnvironments()->count() > 1;
    }

    /** The same settings for one environment's selector (the backend of that environment) */
    public function withQuerySelector(string $querySelector): static
    {
        $constructorArguments = [];
        foreach ((new \ReflectionMethod(static::class, '__construct'))->getParameters() as $constructorParameter) {
            $constructorArguments[$constructorParameter->getName()] = $this->{$constructorParameter->getName()};
        }
        $constructorArguments['querySelector'] = $querySelector;
        return new static(...$constructorArguments);
    }

    /** The variable as set, also when set to an empty string; null = not set at all */
    protected static function readRawEnv(string $name): ?string
    {
        if (array_key_exists($name, $_ENV)) {
            return is_bool($_ENV[$name]) ? ($_ENV[$name] ? 'true' : 'false') : (string)$_ENV[$name];
        }
        $value = $_SERVER[$name] ?? getenv($name);
        return $value === false || $value === null ? null : (string)$value;
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
