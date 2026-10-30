<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\DDDService;
use Psr\Log\LogLevel;
use Throwable;

/**
 * The issues-log configuration. Committed in the application's `config/app/Common/Issues.php`
 * (`Config::get('Common.Issues')`, keys = the property names, e.g. `captureEnabled`, `headerPrefixes` as a list); an
 * environment variable overrides a committed value — for what differs per host. Nothing set = today's behaviour: the
 * `issues` channel goes through the application's own Monolog handlers ({@see self::$target} null), the capture
 * subscriber is on.
 */
class IssuesLogSettings extends ValueObject
{
    public const string ENV_TARGET = 'ISSUES_LOG_TARGET';
    public const string ENV_PATH = 'ISSUES_LOG_PATH';
    public const string ENV_FILE_NAME = 'ISSUES_LOG_FILE_NAME';
    public const string ENV_LEVEL = 'ISSUES_LOG_LEVEL';
    public const string ENV_USE_LOCKING = 'ISSUES_LOG_USE_LOCKING';
    public const string ENV_CAPTURE_ENABLED = 'ISSUES_CAPTURE_ENABLED';
    public const string ENV_CLIENT_ERROR_LEVEL = 'ISSUES_LOG_4XX_LEVEL';
    public const string ENV_HEADER_PREFIXES = 'ISSUES_LOG_HEADER_PREFIXES';
    public const string ENV_INCLUDE_REQUEST_BODY = 'ISSUES_LOG_INCLUDE_REQUEST_BODY';
    public const string ENV_INCLUDE_QUERY = 'ISSUES_LOG_INCLUDE_QUERY';
    public const string ENV_MAX_EVENT_BYTES = 'ISSUES_LOG_MAX_EVENT_BYTES';
    public const string ENV_SERVICE_NAME = 'ISSUES_SERVICE_NAME';
    public const string ENV_RELEASE = 'ISSUES_RELEASE';
    public const string ENV_WORKSPACE = 'ISSUES_WORKSPACE';
    public const string ENV_SANITIZE_NUMBER_MIN_DIGITS = 'ISSUES_LOG_SANITIZE_NUMBER_MIN_DIGITS';
    public const string ENV_CLIENT_INGEST_ENABLED = 'ISSUES_CLIENT_INGEST_ENABLED';
    public const string ENV_CLIENT_IGNORE_PATTERNS = 'ISSUES_CLIENT_IGNORE_PATTERNS';
    public const string ENV_CLIENT_IGNORE_USER_AGENTS = 'ISSUES_CLIENT_IGNORE_USER_AGENTS';
    public const string ENV_REDACT_PATH_SEGMENTS = 'ISSUES_REDACT_PATH_SEGMENTS';
    public const string ENV_MAX_EVENT_BYTES_WEB = 'ISSUES_LOG_MAX_EVENT_BYTES_WEB';
    public const string ENV_SOURCE_MAPS_STORE = 'ISSUES_SOURCE_MAPS_STORE';
    public const string ENV_SOURCE_MAPS_CACHE_DIR = 'ISSUES_SOURCE_MAPS_CACHE_DIR';
    public const string ENV_SOURCE_MAPS_CACHE_MB = 'ISSUES_SOURCE_MAPS_CACHE_MB';
    public const string ENV_SOURCE_MAPS_DEVELOPMENT_RELEASE = 'ISSUES_SOURCE_MAPS_DEVELOPMENT_RELEASE';
    public const string ENV_SOURCE_MAPS_DEVELOPMENT_STORE = 'ISSUES_SOURCE_MAPS_DEVELOPMENT_STORE';

    /** The release a development client reports; its maps come from the build output, never cached */
    public const string DEFAULT_SOURCE_MAPS_DEVELOPMENT_RELEASE = 'dev';
    /** The store of the development build output (filesystem, rooted at the workspace's `.next/dev`) */
    public const string DEFAULT_SOURCE_MAPS_DEVELOPMENT_STORE = 'sourcemaps-dev';

    /** The object store the source maps are read from (`sourcemaps/<release>/<chunk path>.map`) */
    public const string DEFAULT_SOURCE_MAPS_STORE = 'sourcemaps';
    /** Size cap of the parsed-map disk cache */
    public const int DEFAULT_SOURCE_MAPS_CACHE_MB = 512;

    /** The committed configuration of the issues log and registry (`config/app/Common/Issues.php`) */
    public const string CONFIG_PATH = 'Common.Issues';

    /** JSON lines into a file of {@see self::$path} */
    public const string TARGET_FILE = 'file';
    /** JSON lines to the process's stderr (PHP-FPM: catch_workers_output, decorate_workers_output = no, log_limit) */
    public const string TARGET_STDERR = 'stderr';
    /** Drop the issues channel */
    public const string TARGET_NONE = 'none';
    public const array TARGETS = [self::TARGET_FILE, self::TARGET_STDERR, self::TARGET_NONE];

    /** Placeholders of the file name */
    public const string FILE_NAME_ENVIRONMENT_PLACEHOLDER = '{environment}';
    public const string FILE_NAME_DATE_PLACEHOLDER = '{date}';

    public const string DEFAULT_FILE_NAME = 'issues_' . self::FILE_NAME_ENVIRONMENT_PLACEHOLDER . '.log';
    public const int DEFAULT_MAX_EVENT_BYTES = 8192;
    /** Digit runs of this length and longer in free text become `[NUMBER]` (phone, account and card numbers) */
    public const int DEFAULT_SANITIZE_NUMBER_MIN_DIGITS = 9;
    /**
     * Size cap of a client event (sections WEB, WEB_SERVER, MOBILE) with the file sink; stderr keeps
     * {@see self::DEFAULT_MAX_EVENT_BYTES}, because containerd splits a stderr line at 16 KB
     */
    public const int DEFAULT_MAX_EVENT_BYTES_WEB = 16384;

    /** Client errors that are noise (case-insensitive substrings of `name: message`) */
    public const array DEFAULT_CLIENT_IGNORE_PATTERNS = [
        'ChunkLoadError', 'Loading chunk', 'Loading CSS chunk', 'Failed to fetch dynamically imported module',
        'Importing a module script failed', 'error loading dynamically imported module', 'Script error.',
        'ResizeObserver loop',
    ];

    /** Crawlers (case-insensitive substrings of the user agent) */
    public const array DEFAULT_CLIENT_IGNORE_USER_AGENTS = [
        'bot/', 'bot;', 'crawler', 'spider', 'slurp', 'facebookexternalhit', 'bingpreview', 'lighthouse',
    ];

    /** URL path segments whose NEXT segment is a credential (a token in the path) */
    public const array DEFAULT_REDACT_PATH_SEGMENTS = ['invitations', 'claim-account', 'verify-email', 'reset-password'];

    public function __construct(
        /** @var string|null file | stderr | none; null = the application's own handlers (today's behaviour) */
        public readonly ?string $target = null,
        /** @var string Directory of the JSON files (file target) */
        public readonly string $path = '',
        /** @var string File name; {environment} and {date} (Y-m-d, a new file per day) are replaced */
        public readonly string $fileName = self::DEFAULT_FILE_NAME,
        /** @var string Lowest PSR-3 level the sink writes */
        public readonly string $level = LogLevel::WARNING,
        /** @var bool flock() around each write (many FPM children append to one file) */
        public readonly bool $useLocking = true,
        /** @var bool Whether the capture subscriber logs uncaught HTTP, console, messenger and fatal errors */
        public readonly bool $captureEnabled = true,
        /** @var string|null Level for uncaught 4xx; null = 4xx are not logged */
        public readonly ?string $clientErrorLevel = null,
        /** @var string[] Lower-case request header prefixes copied into the context (e.g. "x-") */
        public readonly array $headerPrefixes = [],
        public readonly bool $includeRequestBody = false,
        public readonly bool $includeQueryString = false,
        /** @var int Size cap of one event's context in bytes (the trace is cut first, the message last) */
        public readonly int $maxEventBytes = self::DEFAULT_MAX_EVENT_BYTES,
        public readonly ?string $serviceName = null,
        public readonly ?string $release = null,
        /** @var string|null `service.workspace` of every line: the dev workspace (`mgn`) or the stage (`prod`) */
        public readonly ?string $workspace = null,
        /** @var int Digit runs of at least this length in free text become `[NUMBER]`; 0 = digits are kept */
        public readonly int $sanitizeNumberMinDigits = self::DEFAULT_SANITIZE_NUMBER_MIN_DIGITS,
        /** @var bool Kill switch of {@see IssuesLogService::logClientIssue()}: off = every event is dropped */
        public readonly bool $clientIngestEnabled = true,
        /** @var string[] Client errors dropped at ingest: case-insensitive substrings of `name: message` */
        public readonly array $clientIgnorePatterns = self::DEFAULT_CLIENT_IGNORE_PATTERNS,
        /** @var string[] Client user agents dropped at ingest: case-insensitive substrings */
        public readonly array $clientIgnoreUserAgents = self::DEFAULT_CLIENT_IGNORE_USER_AGENTS,
        /** @var string[] URL path segments whose next segment is redacted (`/invitations/<token>`) */
        public readonly array $redactPathSegments = self::DEFAULT_REDACT_PATH_SEGMENTS,
        /** @var int Size cap of a client event with the file sink (the other sinks keep {@see self::$maxEventBytes}) */
        public readonly int $maxEventBytesWeb = self::DEFAULT_MAX_EVENT_BYTES_WEB,
        /** @var string The object store of the source maps ({@see \DDD\Infrastructure\Services\ObjectStorageService}) */
        public readonly string $sourceMapsStore = self::DEFAULT_SOURCE_MAPS_STORE,
        /** @var string|null Directory of the parsed-map cache; null = `<kernel cache dir>/sourcemaps` */
        public readonly ?string $sourceMapsCacheDirectory = null,
        /** @var int Size cap of the parsed-map cache; the least recently used maps are removed beyond it */
        public readonly int $sourceMapsCacheMegabytes = self::DEFAULT_SOURCE_MAPS_CACHE_MB,
        /** @var string|null The release whose maps are read from the development build output; '' = none */
        public readonly ?string $sourceMapsDevelopmentRelease = self::DEFAULT_SOURCE_MAPS_DEVELOPMENT_RELEASE,
        /** @var string The object store of the development build output */
        public readonly string $sourceMapsDevelopmentStore = self::DEFAULT_SOURCE_MAPS_DEVELOPMENT_STORE,
    ) {
        parent::__construct();
    }

    /**
     * The settings from the committed configuration ({@see self::CONFIG_PATH}), each value overridden by its
     * environment variable when that is set. Release and workspace come from the environment only.
     */
    public static function fromEnvironment(string $defaultPath = ''): static
    {
        $target = static::stringSetting('target', self::ENV_TARGET);
        $target = $target !== null && in_array(strtolower($target), self::TARGETS, true) ? strtolower($target) : null;
        $headerPrefixes = static::readSetting('headerPrefixes', self::ENV_HEADER_PREFIXES);
        $headerPrefixes = array_values(array_filter(array_map(
            static fn(mixed $prefix): string => strtolower(trim((string)$prefix)),
            is_array($headerPrefixes) ? $headerPrefixes : explode(',', (string)$headerPrefixes)
        )));
        return new static(
            target: $target,
            path: rtrim(static::stringSetting('path', self::ENV_PATH) ?? $defaultPath, '/'),
            fileName: static::stringSetting('fileName', self::ENV_FILE_NAME) ?? self::DEFAULT_FILE_NAME,
            level: strtolower(static::stringSetting('level', self::ENV_LEVEL) ?? LogLevel::WARNING),
            useLocking: static::boolSetting('useLocking', self::ENV_USE_LOCKING, true),
            captureEnabled: static::boolSetting('captureEnabled', self::ENV_CAPTURE_ENABLED, true),
            clientErrorLevel: ($clientErrorLevel = static::stringSetting('clientErrorLevel', self::ENV_CLIENT_ERROR_LEVEL)) !== null
                ? strtolower($clientErrorLevel)
                : null,
            headerPrefixes: $headerPrefixes,
            includeRequestBody: static::boolSetting('includeRequestBody', self::ENV_INCLUDE_REQUEST_BODY, false),
            includeQueryString: static::boolSetting('includeQueryString', self::ENV_INCLUDE_QUERY, false),
            maxEventBytes: (int)(static::stringSetting('maxEventBytes', self::ENV_MAX_EVENT_BYTES) ?? self::DEFAULT_MAX_EVENT_BYTES),
            serviceName: static::stringSetting('serviceName', self::ENV_SERVICE_NAME),
            release: self::stringEnv(self::ENV_RELEASE) ?? self::stringEnv('APP_VERSION'),
            workspace: self::stringEnv(self::ENV_WORKSPACE) ?? self::detectWorkspaceName() ?? self::stringEnv('APP_STAGE'),
            sanitizeNumberMinDigits: (int)(static::stringSetting('sanitizeNumberMinDigits', self::ENV_SANITIZE_NUMBER_MIN_DIGITS)
                ?? self::DEFAULT_SANITIZE_NUMBER_MIN_DIGITS),
            clientIngestEnabled: static::boolSetting('clientIngestEnabled', self::ENV_CLIENT_INGEST_ENABLED, true),
            clientIgnorePatterns: static::listSetting('clientIgnorePatterns', self::ENV_CLIENT_IGNORE_PATTERNS)
                ?? self::DEFAULT_CLIENT_IGNORE_PATTERNS,
            clientIgnoreUserAgents: static::listSetting('clientIgnoreUserAgents', self::ENV_CLIENT_IGNORE_USER_AGENTS)
                ?? self::DEFAULT_CLIENT_IGNORE_USER_AGENTS,
            redactPathSegments: static::listSetting('redactPathSegments', self::ENV_REDACT_PATH_SEGMENTS)
                ?? self::DEFAULT_REDACT_PATH_SEGMENTS,
            maxEventBytesWeb: (int)(static::stringSetting('maxEventBytesWeb', self::ENV_MAX_EVENT_BYTES_WEB)
                ?? self::DEFAULT_MAX_EVENT_BYTES_WEB),
            sourceMapsStore: static::stringSetting('sourceMapsStore', self::ENV_SOURCE_MAPS_STORE) ?? self::DEFAULT_SOURCE_MAPS_STORE,
            sourceMapsCacheDirectory: static::stringSetting('sourceMapsCacheDirectory', self::ENV_SOURCE_MAPS_CACHE_DIR),
            sourceMapsCacheMegabytes: (int)(static::stringSetting('sourceMapsCacheMegabytes', self::ENV_SOURCE_MAPS_CACHE_MB)
                ?? self::DEFAULT_SOURCE_MAPS_CACHE_MB),
            sourceMapsDevelopmentRelease: static::stringSetting('sourceMapsDevelopmentRelease', self::ENV_SOURCE_MAPS_DEVELOPMENT_RELEASE)
                ?? self::DEFAULT_SOURCE_MAPS_DEVELOPMENT_RELEASE,
            sourceMapsDevelopmentStore: static::stringSetting('sourceMapsDevelopmentStore', self::ENV_SOURCE_MAPS_DEVELOPMENT_STORE)
                ?? self::DEFAULT_SOURCE_MAPS_DEVELOPMENT_STORE,
        );
    }

    /**
     * The size cap of an event of the section: a client section with the file sink gets {@see self::$maxEventBytesWeb}
     * (stderr stays at {@see self::$maxEventBytes}: containerd splits a stderr line at 16 KB).
     */
    public function getMaxEventBytesForSection(?string $section): int
    {
        return $this->target === self::TARGET_FILE && in_array($section, IssuesLogService::CLIENT_SECTIONS, true)
            ? $this->maxEventBytesWeb
            : $this->maxEventBytes;
    }

    /**
     * A list setting: the environment variable as a comma list, else the committed list; null = not set (the default
     * applies). An explicitly empty list is a value (nothing is ignored).
     *
     * @return string[]|null
     */
    protected static function listSetting(string $configKey, string $envName): ?array
    {
        $value = static::readSetting($configKey, $envName);
        if ($value === null) {
            return null;
        }
        $listItems = is_array($value) ? $value : explode(',', (string)$value);
        return array_values(array_filter(
            array_map(static fn(mixed $listItem): string => trim((string)$listItem), $listItems),
            static fn(string $listItem): bool => $listItem !== ''
        ));
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
            $committedSettings = Config::get(self::CONFIG_PATH);
        } catch (Throwable) {
            return [];
        }
        return is_array($committedSettings) ? $committedSettings : [];
    }

    /** The environment variable when set, else the committed value, else null */
    protected static function readSetting(string $configKey, string $envName): mixed
    {
        return self::readEnv($envName) ?? static::readCommittedSettings()[$configKey] ?? null;
    }

    protected static function stringSetting(string $configKey, string $envName): ?string
    {
        $value = static::readSetting($configKey, $envName);
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }

    protected static function boolSetting(string $configKey, string $envName, bool $default): bool
    {
        $value = static::readSetting($configKey, $envName);
        if ($value === null || $value === '' || is_array($value)) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower((string)$value), ['0', 'off', 'no', 'false'], true);
    }

    /** The log file for the given environment and day */
    public function getFilePath(string $environment, ?string $date = null): string
    {
        $fileName = str_replace(
            [self::FILE_NAME_ENVIRONMENT_PLACEHOLDER, self::FILE_NAME_DATE_PLACEHOLDER],
            [$environment, $date ?? date('Y-m-d')],
            $this->fileName
        );
        return ($this->path !== '' ? $this->path . '/' : '') . $fileName;
    }

    public function isDateRotated(): bool
    {
        return str_contains($this->fileName, self::FILE_NAME_DATE_PLACEHOLDER);
    }

    /**
     * The variable from the application's environment (.env via Config), else the process environment — a container
     * sets ISSUES_* as real environment variables, which do not always reach $_ENV.
     */
    protected static function readEnv(string $name): bool|int|float|string|null
    {
        $value = Config::getEnv($name);
        if ($value !== null && $value !== '') {
            return $value;
        }
        $processValue = $_SERVER[$name] ?? getenv($name);
        return is_string($processValue) && $processValue !== '' ? $processValue : null;
    }

    /** The dev workspace this process runs in (`/dev-workspaces/<name>/` in its path), null outside one */
    protected static function detectWorkspaceName(): ?string
    {
        try {
            return DDDService::instance()->getWorkspaceName();
        } catch (Throwable) {
            return null;
        }
    }

    protected static function stringEnv(string $name): ?string
    {
        $value = self::readEnv($name);
        if ($value === null || $value === '' || is_bool($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : null;
        }
        return (string)$value;
    }

    protected static function boolEnv(string $name, bool $default): bool
    {
        $value = self::readEnv($name);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower((string)$value), ['0', 'off', 'no', 'false'], true);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
