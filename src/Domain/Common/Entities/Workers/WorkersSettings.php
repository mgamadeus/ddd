<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use Throwable;

/**
 * Configuration of the worker definition and monitoring. Committed in the application's
 * `config/app/Common/Workers.php` ({@see self::CONFIG_PATH}, keys = the property names); an environment variable
 * (`WORKERS_` + the name in upper snake case) overrides a committed value.
 */
class WorkersSettings extends ValueObject
{
    public const string CONFIG_PATH = 'Common.Workers';
    public const string ENV_PREFIX = 'WORKERS_';
    public const string DEFAULT_DEFINITION_FILE = 'config/system/workers.yaml';

    /** One {@see WorkerMessageRun} per consumed message */
    public const string MESSAGE_LOG_ALL = 'all';
    /** Only for messages that failed (thrown or caught) */
    public const string MESSAGE_LOG_FAILURES = 'failures';
    public const string MESSAGE_LOG_OFF = 'off';
    public const array MESSAGE_LOG_MODES = [self::MESSAGE_LOG_ALL, self::MESSAGE_LOG_FAILURES, self::MESSAGE_LOG_OFF];

    /** A process without a heartbeat for this many intervals counts as lost */
    public const int LOST_AFTER_HEARTBEATS = 3;

    public function __construct(
        /** @var bool The monitoring writes statistics, processes, samples and message runs (needs their tables) */
        public readonly bool $enabled = false,
        /** @var string The worker list file, relative to the project directory or absolute */
        public readonly string $definitionFile = self::DEFAULT_DEFINITION_FILE,
        /** @var string Default of a consumer's `messageLog`: all, failures or off */
        public readonly string $messageLog = self::MESSAGE_LOG_ALL,
        /** @var int A worker writes its collected statistics at most this often */
        public readonly int $flushSeconds = 10,
        /** @var int A worker reports itself (and samples its transports) this often */
        public readonly int $heartbeatSeconds = 10,
        /** @var int Stat buckets and transport samples are kept this long */
        public readonly int $statRetentionDays = 14,
        /** @var int Stopped and lost processes are kept this long */
        public readonly int $processRetentionDays = 14,
        /** @var int Message runs that were handled are kept this long */
        public readonly int $handledRunRetentionDays = 3,
        /** @var int Message runs that failed are kept this long */
        public readonly int $failedRunRetentionDays = 14,
        /** @var int Messages waiting without a live process for longer than this: the transport is stuck */
        public readonly int $stuckAfterSeconds = 180,
        /** @var int A process whose current message runs longer than this is shown as silent */
        public readonly int $busySilentSeconds = 3600,
        /** @var int A stored queue count younger than this is used instead of asking the broker */
        public readonly int $sampleMaxAgeSeconds = 90,
        /**
         * @var bool A process with a message in work whose operating-system process no longer exists on the host that
         * prunes is closed as lost at once. Switch off where processes share a host name without sharing process ids
         */
        public readonly bool $closeGoneProcessesOnOwnHost = true,
    ) {
        parent::__construct();
    }

    public static function fromEnvironment(): static
    {
        $messageLog = strtolower(static::stringSetting('messageLog') ?? self::MESSAGE_LOG_ALL);
        return new static(
            enabled: static::boolSetting('enabled', false),
            definitionFile: static::stringSetting('definitionFile') ?? self::DEFAULT_DEFINITION_FILE,
            messageLog: in_array($messageLog, self::MESSAGE_LOG_MODES, true) ? $messageLog : self::MESSAGE_LOG_ALL,
            flushSeconds: max(1, static::intSetting('flushSeconds', 10)),
            heartbeatSeconds: max(1, static::intSetting('heartbeatSeconds', 10)),
            statRetentionDays: max(1, static::intSetting('statRetentionDays', 14)),
            processRetentionDays: max(1, static::intSetting('processRetentionDays', 14)),
            handledRunRetentionDays: max(1, static::intSetting('handledRunRetentionDays', 3)),
            failedRunRetentionDays: max(1, static::intSetting('failedRunRetentionDays', 14)),
            stuckAfterSeconds: max(1, static::intSetting('stuckAfterSeconds', 180)),
            busySilentSeconds: max(1, static::intSetting('busySilentSeconds', 3600)),
            sampleMaxAgeSeconds: max(1, static::intSetting('sampleMaxAgeSeconds', 90)),
            closeGoneProcessesOnOwnHost: static::boolSetting('closeGoneProcessesOnOwnHost', true),
        );
    }

    /** @return int Seconds without a heartbeat after which an idle process counts as lost */
    public function getLostAfterSeconds(): int
    {
        return $this->heartbeatSeconds * self::LOST_AFTER_HEARTBEATS;
    }

    /** `flushSeconds` → `WORKERS_FLUSH_SECONDS` */
    public static function getEnvName(string $settingName): string
    {
        return self::ENV_PREFIX . strtoupper((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $settingName));
    }

    /**
     * The committed configuration; empty when the application has none. The method a test overrides.
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
    protected static function readSetting(string $settingName): mixed
    {
        $envName = static::getEnvName($settingName);
        $envValue = Config::getEnv($envName);
        if ($envValue === null || $envValue === '') {
            $processValue = $_SERVER[$envName] ?? getenv($envName);
            $envValue = is_string($processValue) && $processValue !== '' ? $processValue : null;
        }
        return $envValue ?? static::readCommittedSettings()[$settingName] ?? null;
    }

    protected static function stringSetting(string $settingName): ?string
    {
        $value = static::readSetting($settingName);
        return $value === null || $value === '' || is_array($value) || is_bool($value) ? null : (string)$value;
    }

    protected static function intSetting(string $settingName, int $default): int
    {
        $value = static::readSetting($settingName);
        return is_numeric($value) ? (int)$value : $default;
    }

    protected static function boolSetting(string $settingName, bool $default): bool
    {
        $value = static::readSetting($settingName);
        if ($value === null || $value === '' || is_array($value)) {
            return $default;
        }
        return is_bool($value) ? $value : !in_array(strtolower((string)$value), ['0', 'off', 'no', 'false'], true);
    }
}
