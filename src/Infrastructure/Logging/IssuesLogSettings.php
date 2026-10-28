<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use Psr\Log\LogLevel;

/**
 * The issues-log configuration, read from the environment. Nothing set = today's behaviour: the `issues` channel goes
 * through the application's own Monolog handlers ({@see self::$target} null), the capture subscriber is on.
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
    ) {
        parent::__construct();
    }

    public static function fromEnvironment(string $defaultPath = ''): static
    {
        $target = self::stringEnv(self::ENV_TARGET);
        $target = $target !== null && in_array(strtolower($target), self::TARGETS, true) ? strtolower($target) : null;
        $headerPrefixes = array_values(array_filter(array_map(
            static fn(string $prefix): string => strtolower(trim($prefix)),
            explode(',', (string)self::stringEnv(self::ENV_HEADER_PREFIXES))
        )));
        return new static(
            target: $target,
            path: rtrim(self::stringEnv(self::ENV_PATH) ?? $defaultPath, '/'),
            fileName: self::stringEnv(self::ENV_FILE_NAME) ?? self::DEFAULT_FILE_NAME,
            level: strtolower(self::stringEnv(self::ENV_LEVEL) ?? LogLevel::WARNING),
            useLocking: self::boolEnv(self::ENV_USE_LOCKING, true),
            captureEnabled: self::boolEnv(self::ENV_CAPTURE_ENABLED, true),
            clientErrorLevel: ($clientErrorLevel = self::stringEnv(self::ENV_CLIENT_ERROR_LEVEL)) !== null
                ? strtolower($clientErrorLevel)
                : null,
            headerPrefixes: $headerPrefixes,
            includeRequestBody: self::boolEnv(self::ENV_INCLUDE_REQUEST_BODY, false),
            includeQueryString: self::boolEnv(self::ENV_INCLUDE_QUERY, false),
            maxEventBytes: (int)(self::stringEnv(self::ENV_MAX_EVENT_BYTES) ?? self::DEFAULT_MAX_EVENT_BYTES),
            serviceName: self::stringEnv(self::ENV_SERVICE_NAME),
            release: self::stringEnv(self::ENV_RELEASE) ?? self::stringEnv('APP_VERSION'),
        );
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
