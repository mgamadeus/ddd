<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\SourceMaps;

use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrame;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrames;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\ObjectStorageService;
use Throwable;

/**
 * Resolves minified client frames to their original file, line, column and function — on view (the admin detail,
 * `app:issues:show --resolve`), never at ingest. The maps are private objects of the object store
 * {@see \DDD\Infrastructure\Logging\IssuesLogSettings::$sourceMapsStore} under `sourcemaps/<release>/<chunk path>.map`,
 * the chunk path being the frame file's URL path without the leading slash
 * (`https://x.com/_next/static/chunks/app/page-3f2a.js` → `sourcemaps/<release>/_next/static/chunks/app/page-3f2a.js.map`).
 *
 * Parsed maps are cached on local disk (bounded, least recently used removed first), resolved positions in the DDD
 * cache for a week. A frame without line and column, a missing map, an unconfigured store or a store error leaves the
 * minified frame with `resolved = false` and a note — never an exception; a store error is logged once per release and
 * process.
 */
class SourceMapResolver
{
    public const string KEY_PREFIX = 'sourcemaps/';
    public const string MAP_SUFFIX = '.map';
    public const string CACHE_KEY_PREFIX = 'ddd-sourcemap-position:';
    public const string CACHE_DIRECTORY_NAME = 'sourcemaps';

    public const string NOTE_NO_POSITION = 'no line or column';
    public const string NOTE_NO_RELEASE = 'no release';
    public const string NOTE_STORE_NOT_CONFIGURED = 'source map store not configured';
    public const string NOTE_NO_MAP = 'no source map';
    public const string NOTE_NO_MAPPING = 'no mapping at this position';
    public const string NOTE_STORE_ERROR = 'source map store error';

    /** @var SourceMap[] Parsed maps of this process, by object key */
    protected array $sourceMapsByKey = [];

    /** @var bool[] Object keys known to have no map in this process */
    protected array $missingSourceMapKeys = [];

    /** @var bool[] Releases whose store error was logged in this process */
    protected static array $storeErrorLoggedReleases = [];

    /** Resolves every frame; never throws */
    public function resolveFrames(string $release, ClientIssueFrames $clientIssueFrames): ResolvedFrames
    {
        $resolvedFrames = new ResolvedFrames();
        foreach ($clientIssueFrames->getElements() as $clientIssueFrame) {
            $resolvedFrame = $this->resolveFrame($release, $clientIssueFrame);
            $resolvedFrames->add($resolvedFrame);
        }
        return $resolvedFrames;
    }

    public function resolveFrame(string $release, ClientIssueFrame $clientIssueFrame): ResolvedFrame
    {
        $resolvedFrame = new ResolvedFrame();
        $resolvedFrame->file = $clientIssueFrame->file;
        $resolvedFrame->line = $clientIssueFrame->line;
        $resolvedFrame->column = $clientIssueFrame->column;
        $resolvedFrame->function = $clientIssueFrame->function;
        $resolvedFrame->inApp = $clientIssueFrame->inApp;
        if ($clientIssueFrame->line === null || $clientIssueFrame->column === null) {
            $resolvedFrame->note = self::NOTE_NO_POSITION;
            return $resolvedFrame;
        }
        if ($release === '') {
            $resolvedFrame->note = self::NOTE_NO_RELEASE;
            return $resolvedFrame;
        }
        $positionCacheKey = self::CACHE_KEY_PREFIX . hash('sha256', implode('|', [$release, $clientIssueFrame->file, $clientIssueFrame->line, $clientIssueFrame->column]));
        $cachedPosition = $this->readCachedPosition($positionCacheKey);
        if ($cachedPosition instanceof SourceMapPosition) {
            $this->applyPosition($resolvedFrame, $cachedPosition);
            return $resolvedFrame;
        }
        try {
            $objectStorage = $this->getObjectStorage();
        } catch (Throwable $throwable) {
            $this->logStoreErrorOnce($release, $throwable);
            $resolvedFrame->note = self::NOTE_STORE_ERROR;
            return $resolvedFrame;
        }
        if ($objectStorage === null) {
            $resolvedFrame->note = self::NOTE_STORE_NOT_CONFIGURED;
            return $resolvedFrame;
        }
        $objectKey = $this->getSourceMapKey($release, $clientIssueFrame->file);
        try {
            $sourceMap = $this->loadSourceMap($objectStorage, $objectKey);
        } catch (Throwable $throwable) {
            $this->logStoreErrorOnce($release, $throwable);
            $resolvedFrame->note = self::NOTE_STORE_ERROR;
            return $resolvedFrame;
        }
        if ($sourceMap === null) {
            $resolvedFrame->note = self::NOTE_NO_MAP;
            return $resolvedFrame;
        }
        $sourceMapPosition = $sourceMap->findOriginalPosition($clientIssueFrame->line, $clientIssueFrame->column);
        if ($sourceMapPosition === null) {
            $resolvedFrame->note = self::NOTE_NO_MAPPING;
            return $resolvedFrame;
        }
        $this->writeCachedPosition($positionCacheKey, $sourceMapPosition);
        $this->applyPosition($resolvedFrame, $sourceMapPosition);
        return $resolvedFrame;
    }

    /** `sourcemaps/<release>/<URL path of the frame file without the leading slash>.map` */
    public function getSourceMapKey(string $release, string $frameFile): string
    {
        $path = (string)preg_replace('/^[a-z][a-z0-9+.-]*:\/\/[^\/]*/i', '', $frameFile);
        $path = ltrim((string)preg_replace('/[?#].*$/', '', $path), '/');
        return self::KEY_PREFIX . trim($release, '/') . '/' . $path . self::MAP_SUFFIX;
    }

    protected function applyPosition(ResolvedFrame $resolvedFrame, SourceMapPosition $sourceMapPosition): void
    {
        $resolvedFrame->resolved = true;
        $resolvedFrame->originalFile = $this->normaliseSourcePath($sourceMapPosition->source);
        $resolvedFrame->originalLine = $sourceMapPosition->line;
        $resolvedFrame->originalColumn = $sourceMapPosition->column;
        $resolvedFrame->originalFunction = $sourceMapPosition->name ?? $resolvedFrame->function;
        $resolvedFrame->contextLines = $sourceMapPosition->contextLines;
        $resolvedFrame->contextStartLine = $sourceMapPosition->contextStartLine;
    }

    /** `webpack://_N_E/./src/app/page.tsx` → `src/app/page.tsx`; `turbopack:///[project]/src/x.ts` → `src/x.ts` */
    public function normaliseSourcePath(string $source): string
    {
        $path = (string)preg_replace('/^[a-z][a-z0-9+.-]*:\/\/\/?/i', '', $source);
        $path = (string)preg_replace('/^(?:_N_E\/|\[project\]\/)/', '', $path);
        return (string)preg_replace('/^(?:\.\/)+/', '', $path);
    }

    /**
     * The parsed map: from this process, the disk cache or the store; null when the store has none.
     *
     * @throws Throwable A store error
     */
    protected function loadSourceMap(ObjectStorage $objectStorage, string $objectKey): ?SourceMap
    {
        if (isset($this->sourceMapsByKey[$objectKey])) {
            return $this->sourceMapsByKey[$objectKey];
        }
        if (isset($this->missingSourceMapKeys[$objectKey])) {
            return null;
        }
        $cacheFile = $this->getCacheFile($objectKey);
        if ($cacheFile !== null && is_file($cacheFile)) {
            $cachedSourceMap = @unserialize((string)@file_get_contents($cacheFile), ['allowed_classes' => [SourceMap::class]]);
            if ($cachedSourceMap instanceof SourceMap) {
                @touch($cacheFile);
                return $this->sourceMapsByKey[$objectKey] = $cachedSourceMap;
            }
        }
        $sourceMapJson = $objectStorage->getObjectContent($objectKey);
        if ($sourceMapJson === null) {
            $this->missingSourceMapKeys[$objectKey] = true;
            return null;
        }
        $sourceMap = (new SourceMapParser())->parseSourceMap($sourceMapJson);
        if ($cacheFile !== null) {
            $this->writeCacheFile($cacheFile, serialize($sourceMap));
        }
        return $this->sourceMapsByKey[$objectKey] = $sourceMap;
    }

    protected function getObjectStorage(): ?ObjectStorage
    {
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        return $objectStorageService->getObjectStorage(IssuesLogService::getSettings()->sourceMapsStore);
    }

    protected function getCacheDirectory(): ?string
    {
        $configuredDirectory = IssuesLogService::getSettings()->sourceMapsCacheDirectory;
        if ($configuredDirectory !== null) {
            return rtrim($configuredDirectory, '/');
        }
        try {
            return rtrim(DDDService::instance()->getCacheDir(false), '/') . '/' . self::CACHE_DIRECTORY_NAME;
        } catch (Throwable) {
            return null;
        }
    }

    protected function getCacheFile(string $objectKey): ?string
    {
        $cacheDirectory = $this->getCacheDirectory();
        return $cacheDirectory !== null ? $cacheDirectory . '/' . hash('sha256', $objectKey) . '.ser' : null;
    }

    /** Writes the cache file, then removes the least recently used files beyond the size cap. Fail-soft. */
    protected function writeCacheFile(string $cacheFile, string $serializedSourceMap): void
    {
        $cacheDirectory = dirname($cacheFile);
        if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
            return;
        }
        if (@file_put_contents($cacheFile, $serializedSourceMap, LOCK_EX) === false) {
            return;
        }
        $this->evictLeastRecentlyUsedCacheFiles($cacheDirectory);
    }

    protected function evictLeastRecentlyUsedCacheFiles(string $cacheDirectory): void
    {
        $maxBytes = IssuesLogService::getSettings()->sourceMapsCacheMegabytes * 1024 * 1024;
        $cacheFiles = glob($cacheDirectory . '/*.ser') ?: [];
        $modificationTimesByFile = [];
        $totalBytes = 0;
        foreach ($cacheFiles as $cacheFile) {
            $modificationTimesByFile[$cacheFile] = (int)@filemtime($cacheFile);
            $totalBytes += (int)@filesize($cacheFile);
        }
        if ($totalBytes <= $maxBytes) {
            return;
        }
        asort($modificationTimesByFile);
        foreach (array_keys($modificationTimesByFile) as $cacheFile) {
            if ($totalBytes <= $maxBytes) {
                break;
            }
            $totalBytes -= (int)@filesize($cacheFile);
            @unlink($cacheFile);
        }
    }

    protected function readCachedPosition(string $positionCacheKey): mixed
    {
        try {
            return Cache::instance()->get($positionCacheKey);
        } catch (Throwable) {
            return null;
        }
    }

    protected function writeCachedPosition(string $positionCacheKey, SourceMapPosition $sourceMapPosition): void
    {
        try {
            Cache::instance()->set($positionCacheKey, $sourceMapPosition, Cache::CACHE_TTL_ONE_WEEK);
        } catch (Throwable) {
        }
    }

    protected function logStoreErrorOnce(string $release, Throwable $throwable): void
    {
        if (isset(static::$storeErrorLoggedReleases[$release])) {
            return;
        }
        static::$storeErrorLoggedReleases[$release] = true;
        try {
            DDDService::instance()->getLogger()->warning(sprintf(
                'SourceMapResolver: source maps of release %s unavailable: %s',
                $release,
                $throwable->getMessage()
            ));
        } catch (Throwable) {
            error_log('SourceMapResolver: source maps of release ' . $release . ' unavailable: ' . $throwable->getMessage());
        }
    }
}
