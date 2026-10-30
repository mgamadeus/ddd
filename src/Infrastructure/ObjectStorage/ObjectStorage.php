<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * A key-addressed object store (an S3 bucket, a directory). Keys are `/`-separated paths without a leading slash, e.g.
 * `sourcemaps/<release>/_next/static/chunks/app/page-3f2a.js.map`; a store's configured prefix is applied by the
 * adapter and never part of the keys a caller sees. Obtain a store by name from
 * {@see \DDD\Infrastructure\Services\ObjectStorageService::getObjectStorage()}.
 *
 * Every method throws {@see InternalErrorException} when the backend fails; a missing object is not an error.
 */
interface ObjectStorage
{
    /**
     * Writes the object, replacing an existing one.
     *
     * @throws InternalErrorException
     */
    public function putObject(string $key, string $content, ?string $contentType = null): void;

    /**
     * The object's content; null when there is no object under the key.
     *
     * @throws InternalErrorException
     */
    public function getObjectContent(string $key): ?string;

    /**
     * Removes the object; a missing object is fine.
     *
     * @throws InternalErrorException
     */
    public function deleteObject(string $key): void;

    /** @throws InternalErrorException */
    public function objectExists(string $key): bool;

    /**
     * The objects whose key starts with the prefix, in key order, at most $limit.
     *
     * @throws InternalErrorException
     */
    public function listObjects(string $keyPrefix = '', int $limit = 1000): ObjectStorageObjects;

    /**
     * A URL that reads the object without credentials until it expires (S3: a presigned GET; filesystem: a `file://`
     * URL, for development only).
     *
     * @throws InternalErrorException
     */
    public function createTemporaryUrl(string $key, int $expiresInSeconds = 3600): string;
}
