<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\S3\S3Client;
use DateTimeImmutable;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Throwable;

/**
 * An object store in any S3-compatible service — AWS S3, Hetzner Object Storage, MinIO, Cloudflare R2; only the
 * settings differ (endpoint, region, path style). Built on `async-aws/s3`, which the application requires
 * (`composer require async-aws/s3`): Core only suggests it.
 */
class S3CompatibleObjectStorage implements ObjectStorage
{
    public const string REQUIRED_PACKAGE = 'async-aws/s3';

    protected ?S3Client $s3Client = null;

    public function __construct(protected ObjectStorageSettings $objectStorageSettings)
    {
        if (!class_exists(S3Client::class)) {
            throw new InternalErrorException(sprintf(
                'Object storage "%s" uses the s3 driver, which needs the package %s: composer require %s',
                $objectStorageSettings->name,
                self::REQUIRED_PACKAGE,
                self::REQUIRED_PACKAGE
            ));
        }
        if ($objectStorageSettings->bucket === null) {
            throw new InternalErrorException(sprintf(
                'Object storage "%s": the s3 driver needs %s',
                $objectStorageSettings->name,
                ObjectStorageSettings::getEnvName($objectStorageSettings->name, ObjectStorageSettings::SETTING_BUCKET)
            ));
        }
    }

    public function putObject(string $key, string $content, ?string $contentType = null): void
    {
        try {
            $this->getS3Client()->putObject(array_filter([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
                'Body' => $content,
                'ContentType' => $contentType,
            ], static fn(mixed $value): bool => $value !== null))->resolve();
        } catch (Throwable $throwable) {
            throw $this->createStorageException('put', $key, $throwable);
        }
    }

    public function getObjectContent(string $key): ?string
    {
        try {
            return $this->getS3Client()->getObject([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
            ])->getBody()->getContentAsString();
        } catch (NoSuchKeyException) {
            return null;
        } catch (Throwable $throwable) {
            throw $this->createStorageException('get', $key, $throwable);
        }
    }

    public function deleteObject(string $key): void
    {
        try {
            $this->getS3Client()->deleteObject([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
            ])->resolve();
        } catch (Throwable $throwable) {
            throw $this->createStorageException('delete', $key, $throwable);
        }
    }

    public function objectExists(string $key): bool
    {
        try {
            return $this->getS3Client()->objectExists([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Key' => $this->getStorageKey($key),
            ])->isSuccess();
        } catch (Throwable $throwable) {
            throw $this->createStorageException('check', $key, $throwable);
        }
    }

    public function listObjects(string $keyPrefix = '', int $limit = 1000): ObjectStorageObjects
    {
        $objectStorageObjects = new ObjectStorageObjects();
        try {
            $listObjectsOutput = $this->getS3Client()->listObjectsV2([
                'Bucket' => $this->objectStorageSettings->bucket,
                'Prefix' => $this->getStorageKey($keyPrefix, allowEmpty: true),
            ]);
            foreach ($listObjectsOutput->getContents() as $awsObject) {
                if ($objectStorageObjects->count() >= $limit) {
                    break;
                }
                $objectStorageObject = new ObjectStorageObject();
                $objectStorageObject->key = substr((string)$awsObject->getKey(), strlen($this->objectStorageSettings->prefix));
                $objectStorageObject->size = $awsObject->getSize();
                $objectStorageObject->lastModified = $awsObject->getLastModified() !== null
                    ? DateTime::fromTimestamp($awsObject->getLastModified()->getTimestamp())
                    : null;
                $objectStorageObjects->add($objectStorageObject);
            }
        } catch (Throwable $throwable) {
            throw $this->createStorageException('list', $keyPrefix, $throwable);
        }
        return $objectStorageObjects;
    }

    public function createTemporaryUrl(string $key, int $expiresInSeconds = 3600): string
    {
        try {
            return $this->getS3Client()->presign(
                new GetObjectRequest(['Bucket' => $this->objectStorageSettings->bucket, 'Key' => $this->getStorageKey($key)]),
                new DateTimeImmutable('+' . max(1, $expiresInSeconds) . ' seconds')
            );
        } catch (Throwable $throwable) {
            throw $this->createStorageException('presign', $key, $throwable);
        }
    }

    protected function getS3Client(): S3Client
    {
        return $this->s3Client ??= new S3Client(array_filter([
            'region' => $this->objectStorageSettings->region,
            'endpoint' => $this->objectStorageSettings->endpoint,
            'accessKeyId' => $this->objectStorageSettings->accessKey,
            'accessKeySecret' => $this->objectStorageSettings->secretKey,
            'pathStyleEndpoint' => $this->objectStorageSettings->pathStyle ? 'true' : 'false',
        ], static fn(mixed $value): bool => $value !== null));
    }

    /** The key inside the bucket: the store's prefix plus the caller's key */
    protected function getStorageKey(string $key, bool $allowEmpty = false): string
    {
        $key = ltrim($key, '/');
        if (!$allowEmpty && $key === '') {
            throw new InternalErrorException('Object storage: empty key');
        }
        return $this->objectStorageSettings->prefix . $key;
    }

    protected function createStorageException(string $operation, string $key, Throwable $throwable): InternalErrorException
    {
        return new InternalErrorException(sprintf(
            'Object storage "%s": %s of "%s" failed: %s',
            $this->objectStorageSettings->name,
            $operation,
            $key,
            $throwable->getMessage()
        ));
    }
}
