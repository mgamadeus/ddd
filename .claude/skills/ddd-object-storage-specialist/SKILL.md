---
name: ddd-object-storage-specialist
description: "Store and read files by key with the mgamadeus/ddd ObjectStorage layer: named stores (ObjectStorageService::getObjectStorage), the ObjectStorage interface (putObject, getObjectContent, deleteObject, objectExists, listObjects, createTemporaryUrl presigned URLs), S3CompatibleObjectStorage for AWS S3, Hetzner Object Storage, MinIO, Cloudflare R2 (async-aws/s3, suggested), FilesystemObjectStorage for tests and development, configuration in config/app/Common/ObjectStorage.php and OBJECT_STORAGE_<NAME>_* variables, and the source-map store of the issues log. Use when uploading or serving files, media, exports, PDFs or source maps, configuring an S3 bucket or a Hetzner / MinIO / R2 endpoint, presigning a download URL, or when a store is not configured or its package is missing."
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD Object Storage Specialist

One key-addressed storage layer for every consumer of an application — source maps of the issues log, media uploads,
exports, PDFs. A consumer asks for its store by NAME; which provider stores it is configuration.

## The interface

`DDD\Infrastructure\ObjectStorage\ObjectStorage`:

| Method | Does |
|---|---|
| `putObject(string $key, string $content, ?string $contentType = null): void` | writes, replacing |
| `getObjectContent(string $key): ?string` | the content; **null when missing** (not an error) |
| `deleteObject(string $key): void` | removes; missing is fine |
| `objectExists(string $key): bool` | |
| `listObjects(string $keyPrefix = '', int $limit = 1000): ObjectStorageObjects` | key order; each `ObjectStorageObject {key, size, lastModified}`; `getKeys()` |
| `createTemporaryUrl(string $key, int $expiresInSeconds = 3600): string` | S3: a presigned GET; filesystem: `file://` (development only) |

Keys are `/`-separated paths without a leading slash (`exports/2026/10/report.pdf`). The store's prefix is applied by the
adapter and never appears in the keys a caller passes or receives. Every method throws `InternalErrorException` when the
backend fails (the message names the store, the operation and the key).

```php
/** @var ObjectStorageService $objectStorageService */
$objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
$exportsObjectStorage = $objectStorageService->getObjectStorage('exports');
if ($exportsObjectStorage === null) {
    // not configured: degrade (keep it local, hide the download button), never fail at boot
    return null;
}
$exportsObjectStorage->putObject("reports/{$report->id}.pdf", $pdfContent, 'application/pdf');
$downloadUrl = $exportsObjectStorage->createTemporaryUrl("reports/{$report->id}.pdf", 600);
```

`getObjectStorage()` returns **null for a store without a driver** and throws only for a store that is configured but
incomplete (no bucket, no path) or whose driver package is missing. `setObjectStorage($name, $objectStorage)` replaces a
store (tests, or an adapter the application builds itself).

## Adapters

| Driver | Class | For |
|---|---|---|
| `s3` | `S3CompatibleObjectStorage` | AWS S3, Hetzner Object Storage, MinIO, Cloudflare R2, SeaweedFS — only the settings differ |
| `filesystem` | `FilesystemObjectStorage` | tests and local development (a key leaving the root is refused) |

The `s3` driver is built on **`async-aws/s3`, which Core only suggests**: the application requires it
(`composer require async-aws/s3`). Without it the store fails with "needs the package async-aws/s3: composer require
async-aws/s3". Core signs nothing itself.

## Configuration

Committed in `config/app/Common/ObjectStorage.php`; `OBJECT_STORAGE_<NAME>_<SETTING>` overrides per host (NAME upper
case, other characters than A-Z / 0-9 become `_`). **Credentials come from the environment only.**

```php
// config/app/Common/ObjectStorage.php
return [
    'stores' => [
        'sourcemaps' => [
            'driver' => 's3',
            'endpoint' => 'https://hel1.your-objectstorage.com',
            'region' => 'hel1',
            'bucket' => 'dineful-web-sourcemaps',
            'pathStyle' => false,
            'prefix' => '',
        ],
        'exports' => ['driver' => 'filesystem', 'path' => '/var/data/exports'],
    ],
];
```

| Setting (config key / variable suffix) | Default | |
|---|---|---|
| `driver` / `_DRIVER` | none = not configured | `s3` · `filesystem` |
| `endpoint` / `_ENDPOINT` | AWS | S3-compatible endpoint URL |
| `region` / `_REGION` | `us-east-1` | Hetzner: the location (`hel1`, `fsn1`, `nbg1`); R2: `auto` |
| `bucket` / `_BUCKET` | | required for `s3` |
| `pathStyle` / `_PATH_STYLE` | false | `https://endpoint/bucket/key`; MinIO and SeaweedFS need it |
| `prefix` / `_PREFIX` | '' | key prefix inside the bucket or directory |
| `path` / `_PATH` | | required for `filesystem` |
| — / `_ACCESS_KEY`, `_SECRET_KEY` | | environment only |

Providers: **Hetzner** endpoint `https://<location>.your-objectstorage.com`, region = location, virtual-host style.
**R2** endpoint `https://<account>.r2.cloudflarestorage.com`, region `auto`. **MinIO** endpoint of the server,
`pathStyle: true`. **AWS** no endpoint, the bucket's region.

## Consumers

- **Issues log, source maps:** `SourceMapResolver` reads `sourcemaps/<release>/<chunk URL path>.map` from the store named
  by `sourceMapsStore` in `Common.Issues` (default `sourcemaps`) — `ddd-issues-log-specialist` → "Client errors".
- Media uploads, exports, PDFs: one named store each (or one store with key prefixes), the same interface.

## Tests

`tests/Infrastructure/ObjectStorage/ObjectStorageContractTestCase` is the contract every adapter fulfils; the filesystem
run is a unit test, the S3 run an integration test against the harness's SeaweedFS
(`docker compose -f tests/Integration/docker-compose.yml up -d seaweedfs`, then `DDD_TEST_S3_ENDPOINT=http://127.0.0.1:9212`).
A new adapter adds one subclass of the contract test.
