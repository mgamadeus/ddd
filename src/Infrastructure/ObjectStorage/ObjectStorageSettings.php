<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use Throwable;

/**
 * The configuration of one named object store. Committed in the application's `config/app/Common/ObjectStorage.php`
 * (`Config::get('Common.ObjectStorage')`, `['stores' => ['<name>' => ['driver' => 's3', 'endpoint' => …]]]`); the
 * environment variable `OBJECT_STORAGE_<NAME>_<SETTING>` overrides a committed value (NAME upper case, every other
 * character than A-Z and 0-9 an underscore). The credentials come from the environment only:
 * `OBJECT_STORAGE_<NAME>_ACCESS_KEY` / `_SECRET_KEY`.
 *
 * A store without a driver is not configured: {@see \DDD\Infrastructure\Services\ObjectStorageService} returns null for
 * it and its consumers degrade.
 */
class ObjectStorageSettings extends ValueObject
{
    public const string CONFIG_PATH = 'Common.ObjectStorage';
    public const string ENV_PREFIX = 'OBJECT_STORAGE_';

    /** Any S3-compatible service: AWS S3, Hetzner Object Storage, MinIO, Cloudflare R2 — only the settings differ */
    public const string DRIVER_S3 = 's3';
    /** A local directory: tests and development */
    public const string DRIVER_FILESYSTEM = 'filesystem';
    public const array DRIVERS = [self::DRIVER_S3, self::DRIVER_FILESYSTEM];

    public const string SETTING_DRIVER = 'DRIVER';
    public const string SETTING_ENDPOINT = 'ENDPOINT';
    public const string SETTING_REGION = 'REGION';
    public const string SETTING_BUCKET = 'BUCKET';
    public const string SETTING_ACCESS_KEY = 'ACCESS_KEY';
    public const string SETTING_SECRET_KEY = 'SECRET_KEY';
    public const string SETTING_PATH_STYLE = 'PATH_STYLE';
    public const string SETTING_PREFIX = 'PREFIX';
    public const string SETTING_PATH = 'PATH';

    public const string DEFAULT_REGION = 'us-east-1';

    public function __construct(
        /** @var string The store's name, e.g. `sourcemaps` */
        public readonly string $name = '',
        /** @var string|null One of {@see self::DRIVERS}; null = not configured */
        public readonly ?string $driver = null,
        /** @var string|null S3: the endpoint, e.g. `https://hel1.your-objectstorage.com`; null = AWS */
        public readonly ?string $endpoint = null,
        public readonly string $region = self::DEFAULT_REGION,
        /** @var string|null S3: the bucket */
        public readonly ?string $bucket = null,
        public readonly ?string $accessKey = null,
        public readonly ?string $secretKey = null,
        /** @var bool S3: `https://endpoint/bucket/key` instead of `https://bucket.endpoint/key` (MinIO needs it) */
        public readonly bool $pathStyle = false,
        /** @var string Key prefix inside the bucket or directory, e.g. `shop/`; '' = none */
        public readonly string $prefix = '',
        /** @var string|null Filesystem: the root directory */
        public readonly ?string $path = null,
    ) {
        parent::__construct();
    }

    /** The store's settings from the committed configuration and the environment */
    public static function fromEnvironment(string $storeName): static
    {
        $driver = static::readSetting($storeName, self::SETTING_DRIVER, 'driver');
        $driver = $driver !== null && in_array(strtolower($driver), self::DRIVERS, true) ? strtolower($driver) : null;
        $prefix = static::readSetting($storeName, self::SETTING_PREFIX, 'prefix') ?? '';
        return new static(
            name: $storeName,
            driver: $driver,
            endpoint: ($endpoint = static::readSetting($storeName, self::SETTING_ENDPOINT, 'endpoint')) !== null ? rtrim($endpoint, '/') : null,
            region: static::readSetting($storeName, self::SETTING_REGION, 'region') ?? self::DEFAULT_REGION,
            bucket: static::readSetting($storeName, self::SETTING_BUCKET, 'bucket'),
            accessKey: static::readEnv(static::getEnvName($storeName, self::SETTING_ACCESS_KEY)),
            secretKey: static::readEnv(static::getEnvName($storeName, self::SETTING_SECRET_KEY)),
            pathStyle: in_array(strtolower(static::readSetting($storeName, self::SETTING_PATH_STYLE, 'pathStyle') ?? 'false'), ['1', 'true', 'yes', 'on'], true),
            prefix: $prefix !== '' ? trim($prefix, '/') . '/' : '',
            path: static::readSetting($storeName, self::SETTING_PATH, 'path'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->driver !== null;
    }

    /** `OBJECT_STORAGE_<NAME>_<SETTING>` */
    public static function getEnvName(string $storeName, string $setting): string
    {
        return self::ENV_PREFIX . strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '_', $storeName)) . '_' . $setting;
    }

    /** The environment variable when set, else the committed value of the store, else null */
    protected static function readSetting(string $storeName, string $setting, string $configKey): ?string
    {
        $value = static::readEnv(static::getEnvName($storeName, $setting))
            ?? static::readCommittedStoreSettings($storeName)[$configKey]
            ?? null;
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }

    /**
     * The committed settings of the store; empty when the application has none. The method a test overrides.
     *
     * @return array<string, mixed>
     */
    protected static function readCommittedStoreSettings(string $storeName): array
    {
        try {
            $committedSettings = Config::get(self::CONFIG_PATH);
        } catch (Throwable) {
            return [];
        }
        $storeSettings = is_array($committedSettings) ? ($committedSettings['stores'][$storeName] ?? null) : null;
        return is_array($storeSettings) ? $storeSettings : [];
    }

    /** From .env (Config) or the process environment */
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
        return self::uniqueKeyStatic($this->name);
    }
}
