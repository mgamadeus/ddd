<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
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
        );
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
