<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * An object store in a local directory, for tests and development: a key is a file below the root (and the prefix).
 */
class FilesystemObjectStorage implements ObjectStorage
{
    public function __construct(
        protected string $rootDirectory,
        protected string $keyPrefix = '',
    ) {
        $this->rootDirectory = rtrim($rootDirectory, '/');
    }

    public static function fromSettings(ObjectStorageSettings $objectStorageSettings): static
    {
        if ($objectStorageSettings->path === null) {
            throw new InternalErrorException(sprintf(
                'Object storage "%s": the filesystem driver needs %s',
                $objectStorageSettings->name,
                ObjectStorageSettings::getEnvName($objectStorageSettings->name, ObjectStorageSettings::SETTING_PATH)
            ));
        }
        return new static($objectStorageSettings->path, $objectStorageSettings->prefix);
    }

    public function putObject(string $key, string $content, ?string $contentType = null): void
    {
        $filePath = $this->getFilePath($key);
        $directory = dirname($filePath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new InternalErrorException('Object storage: cannot create ' . $directory);
        }
        if (@file_put_contents($filePath, $content, LOCK_EX) === false) {
            throw new InternalErrorException('Object storage: cannot write ' . $filePath);
        }
    }

    public function getObjectContent(string $key): ?string
    {
        $filePath = $this->getFilePath($key);
        if (!is_file($filePath)) {
            return null;
        }
        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new InternalErrorException('Object storage: cannot read ' . $filePath);
        }
        return $content;
    }

    public function deleteObject(string $key): void
    {
        $filePath = $this->getFilePath($key);
        if (is_file($filePath) && !@unlink($filePath)) {
            throw new InternalErrorException('Object storage: cannot delete ' . $filePath);
        }
    }

    public function objectExists(string $key): bool
    {
        return is_file($this->getFilePath($key));
    }

    public function listObjects(string $keyPrefix = '', int $limit = 1000): ObjectStorageObjects
    {
        $baseDirectory = $this->rootDirectory . ($this->keyPrefix !== '' ? '/' . rtrim($this->keyPrefix, '/') : '');
        $keys = [];
        if (is_dir($baseDirectory)) {
            $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDirectory, FilesystemIterator::SKIP_DOTS));
            foreach ($fileIterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $key = substr($fileInfo->getPathname(), strlen($baseDirectory) + 1);
                if (str_starts_with($key, $keyPrefix)) {
                    $keys[] = $key;
                }
            }
        }
        sort($keys, SORT_STRING);
        $objectStorageObjects = new ObjectStorageObjects();
        foreach (array_slice($keys, 0, max(0, $limit)) as $key) {
            $objectStorageObject = new ObjectStorageObject();
            $objectStorageObject->key = $key;
            $filePath = $this->getFilePath($key);
            $objectStorageObject->size = (int)filesize($filePath);
            $objectStorageObject->lastModified = DateTime::fromTimestamp((int)filemtime($filePath));
            $objectStorageObjects->add($objectStorageObject);
        }
        return $objectStorageObjects;
    }

    public function createTemporaryUrl(string $key, int $expiresInSeconds = 3600): string
    {
        return 'file://' . $this->getFilePath($key);
    }

    /**
     * The file of a key; a key leaving the root (`..`, an absolute path) is refused.
     *
     * @throws InternalErrorException
     */
    protected function getFilePath(string $key): string
    {
        $key = ltrim($key, '/');
        if ($key === '' || in_array('..', explode('/', $key), true) || str_contains($key, "\0")) {
            throw new InternalErrorException(sprintf('Object storage: invalid key "%s"', $key));
        }
        return $this->rootDirectory . '/' . $this->keyPrefix . $key;
    }
}
