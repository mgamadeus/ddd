<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorageSettings;
use DDD\Infrastructure\ObjectStorage\S3CompatibleObjectStorage;

/**
 * The named object stores of the application ({@see ObjectStorageSettings}): a consumer asks for its store by name —
 * the issues log's source maps use `sourcemaps`, media uploads or exports their own — and gets null when the store is
 * not configured, so it can degrade instead of failing at boot.
 */
class ObjectStorageService extends Service
{
    /** @var ObjectStorage[] The stores built in this process, by name */
    protected array $objectStoragesByName = [];

    /**
     * The store; null when it is not configured (no driver).
     *
     * @throws InternalErrorException The store is configured but incomplete, or its driver's package is missing
     */
    public function getObjectStorage(string $storeName): ?ObjectStorage
    {
        if (isset($this->objectStoragesByName[$storeName])) {
            return $this->objectStoragesByName[$storeName];
        }
        $objectStorageSettings = $this->getObjectStorageSettings($storeName);
        if (!$objectStorageSettings->isConfigured()) {
            return null;
        }
        return $this->objectStoragesByName[$storeName] = $this->createObjectStorage($objectStorageSettings);
    }

    public function isObjectStorageConfigured(string $storeName): bool
    {
        return $this->getObjectStorageSettings($storeName)->isConfigured();
    }

    /** Replaces a store (tests, or a store an application builds itself) */
    public function setObjectStorage(string $storeName, ?ObjectStorage $objectStorage): void
    {
        if ($objectStorage === null) {
            unset($this->objectStoragesByName[$storeName]);
            return;
        }
        $this->objectStoragesByName[$storeName] = $objectStorage;
    }

    public function getObjectStorageSettings(string $storeName): ObjectStorageSettings
    {
        return ObjectStorageSettings::fromEnvironment($storeName);
    }

    /** @throws InternalErrorException */
    protected function createObjectStorage(ObjectStorageSettings $objectStorageSettings): ObjectStorage
    {
        return match ($objectStorageSettings->driver) {
            ObjectStorageSettings::DRIVER_S3 => new S3CompatibleObjectStorage($objectStorageSettings),
            ObjectStorageSettings::DRIVER_FILESYSTEM => FilesystemObjectStorage::fromSettings($objectStorageSettings),
            default => throw new InternalErrorException(sprintf('Object storage "%s": unknown driver', $objectStorageSettings->name)),
        };
    }
}
