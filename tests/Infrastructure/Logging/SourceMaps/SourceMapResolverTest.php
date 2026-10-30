<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging\SourceMaps;

use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrame;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrames;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Logging\SourceMaps\SourceMapParser;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\SourceMapResolverService;
use PHPUnit\Framework\TestCase;

/** A resolver reading a store the test hands it, without the container */
class ProbeSourceMapResolver extends SourceMapResolverService
{
    public ?ObjectStorage $probeObjectStorage = null;

    public int $storeReads = 0;

    protected function getObjectStorage(): ?ObjectStorage
    {
        $this->storeReads++;
        return $this->probeObjectStorage;
    }

    protected function readCachedPosition(string $positionCacheKey): mixed
    {
        return null;
    }

    public ?ObjectStorage $probeDevelopmentObjectStorage = null;

    protected function getDevelopmentObjectStorage(): ?ObjectStorage
    {
        return $this->probeDevelopmentObjectStorage;
    }
}

/**
 * Minified client frames resolve to the original file, line, column and function through a source map of the
 * release in the object store; anything missing leaves the minified frame with a note, never an exception.
 */
class SourceMapResolverTest extends TestCase
{
    public const string RELEASE = 'web-2026.10.29';
    public const string CHUNK_URL = 'https://dineful.com/_next/static/chunks/app/[lang]/menu/page-3f2a9c1b8e7d6f50.js?dpl=x';

    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/ddd-sourcemaps-' . bin2hex(random_bytes(4));
        IssuesLogService::setSettings(new IssuesLogSettings(sourceMapsCacheDirectory: $this->temporaryDirectory . '/cache'));
    }

    protected function tearDown(): void
    {
        IssuesLogService::setSettings(null);
        if (is_dir($this->temporaryDirectory)) {
            exec('rm -r ' . escapeshellarg($this->temporaryDirectory));
        }
    }

    protected function createFrame(?int $line, ?int $column, string $file = self::CHUNK_URL): ClientIssueFrame
    {
        $clientIssueFrame = new ClientIssueFrame();
        $clientIssueFrame->file = $file;
        $clientIssueFrame->line = $line;
        $clientIssueFrame->column = $column;
        $clientIssueFrame->function = 'a';
        $clientIssueFrame->inApp = true;
        return $clientIssueFrame;
    }

    protected function createResolverWithMenuPageMap(): ProbeSourceMapResolver
    {
        $filesystemObjectStorage = new FilesystemObjectStorage($this->temporaryDirectory . '/store');
        $probeSourceMapResolver = new ProbeSourceMapResolver();
        $filesystemObjectStorage->putObject(
            $probeSourceMapResolver->getSourceMapKey(self::RELEASE, self::CHUNK_URL),
            (string)json_encode(SourceMapFixtures::createMenuPageMap())
        );
        $probeSourceMapResolver->probeObjectStorage = $filesystemObjectStorage;
        return $probeSourceMapResolver;
    }

    public function testTheParserDecodesVlq(): void
    {
        $sourceMapParser = new SourceMapParser();
        $this->assertSame([0, 0, 16, 1], $sourceMapParser->decodeVlq('AAgBC'));
        $this->assertSame([-1, 123456], $sourceMapParser->decodeVlq(SourceMapFixtures::encodeVlqValue(-1) . SourceMapFixtures::encodeVlqValue(123456)));
    }

    public function testAMinifiedFrameResolvesToTheOriginalPositionWithContext(): void
    {
        $probeSourceMapResolver = $this->createResolverWithMenuPageMap();
        $clientIssueFrames = new ClientIssueFrames();
        $mapCallFrame = $this->createFrame(1, 35);
        $clientIssueFrames->add($mapCallFrame);
        $menuPageFrame = $this->createFrame(1, 2);
        $clientIssueFrames->add($menuPageFrame);

        $resolvedFrames = $probeSourceMapResolver->resolveFrames(self::RELEASE, $clientIssueFrames);

        $mapCallFrame = $resolvedFrames->first();
        $this->assertTrue($mapCallFrame->resolved);
        $this->assertSame('src/app/[lang]/menu/page.tsx', $mapCallFrame->originalFile);
        $this->assertSame(3, $mapCallFrame->originalLine);
        $this->assertSame(16, $mapCallFrame->originalColumn);
        $this->assertSame('map', $mapCallFrame->originalFunction);
        $this->assertSame(['  const items = loadItems();', '  return items.map(renderItem);', '}'], $mapCallFrame->contextLines);
        $this->assertSame(2, $mapCallFrame->contextStartLine);
        $this->assertSame(
            ['at map (src/app/[lang]/menu/page.tsx:3:16)', 'at MenuPage (src/app/[lang]/menu/page.tsx:1:1)'],
            $resolvedFrames->toStackTraceLines()
        );
        $this->assertSame(
            'sourcemaps/web-2026.10.29/_next/static/chunks/app/[lang]/menu/page-3f2a9c1b8e7d6f50.js.map',
            $probeSourceMapResolver->getSourceMapKey(self::RELEASE, self::CHUNK_URL)
        );
    }

    public function testTheParsedMapIsCachedOnDisk(): void
    {
        $this->createResolverWithMenuPageMap()->resolveFrame(self::RELEASE, $this->createFrame(1, 35));
        $this->assertCount(1, glob($this->temporaryDirectory . '/cache/*.ser'));

        // a second process: the store is gone, the disk cache answers
        $secondProcessResolver = new ProbeSourceMapResolver();
        $secondProcessResolver->probeObjectStorage = new FilesystemObjectStorage($this->temporaryDirectory . '/empty-store');
        $this->assertTrue($secondProcessResolver->resolveFrame(self::RELEASE, $this->createFrame(1, 35))->resolved);
    }

    public function testAnIndexedMapResolvesThroughItsSections(): void
    {
        $indexedMap = [
            'version' => 3,
            'sections' => [
                ['offset' => ['line' => 0, 'column' => 0], 'map' => [
                    'version' => 3, 'sources' => ['turbopack:///[project]/src/runtime.ts'], 'names' => [],
                    'mappings' => SourceMapFixtures::encodeMappings([0 => [[0, 0, 9, 0]]], 1),
                ]],
                ['offset' => ['line' => 4, 'column' => 0], 'map' => SourceMapFixtures::createMenuPageMap()],
            ],
        ];
        $sourceMap = (new SourceMapParser())->parseSourceMap((string)json_encode($indexedMap));

        $this->assertSame('turbopack:///[project]/src/runtime.ts', $sourceMap->findOriginalPosition(1, 5)->source);
        $this->assertSame(10, $sourceMap->findOriginalPosition(1, 5)->line);
        $menuPosition = $sourceMap->findOriginalPosition(5, 35);
        $this->assertSame(3, $menuPosition->line);
        $this->assertSame('map', $menuPosition->name);
        $this->assertSame('src/x.ts', (new SourceMapResolverService())->normaliseSourcePath('turbopack:///[project]/src/x.ts'));
    }

    public function testWhatCannotBeResolvedStaysMinifiedWithANote(): void
    {
        $probeSourceMapResolver = $this->createResolverWithMenuPageMap();
        $this->assertSame(SourceMapResolverService::NOTE_NO_POSITION, $probeSourceMapResolver->resolveFrame(self::RELEASE, $this->createFrame(null, null))->note);
        $this->assertSame(SourceMapResolverService::NOTE_NO_MAP, $probeSourceMapResolver->resolveFrame('other-release', $this->createFrame(1, 35))->note);
        $this->assertSame(SourceMapResolverService::NOTE_NO_MAPPING, $probeSourceMapResolver->resolveFrame(self::RELEASE, $this->createFrame(7, 1))->note);

        $unconfiguredResolver = new ProbeSourceMapResolver();
        $unresolvedFrame = $unconfiguredResolver->resolveFrame(self::RELEASE, $this->createFrame(1, 35));
        $this->assertFalse($unresolvedFrame->resolved);
        $this->assertSame(SourceMapResolverService::NOTE_STORE_NOT_CONFIGURED, $unresolvedFrame->note);
        $this->assertStringContainsString('[source map store not configured]', $unresolvedFrame->toStackTraceLine());
    }

    public function testANextServerFrameMapsToTheBuildDirectoryKey(): void
    {
        $sourceMapResolverService = new ProbeSourceMapResolver();
        $this->assertSame(
            'sourcemaps/web-r7/.next/server/chunks/ssr/[root-of-the-server]__0a1b._.js.map',
            $sourceMapResolverService->getSourceMapKey('web-r7', '/app/apps/web/.next/server/chunks/ssr/[root-of-the-server]__0a1b._.js')
        );
        $this->assertSame(
            'sourcemaps/web-r7/.next/server/app/page.js.map',
            $sourceMapResolverService->getSourceMapKey('web-r7', 'file:///app/apps/web/.next/server/app/page.js')
        );
        $this->assertSame('server/chunks/ssr/x.js.map', $sourceMapResolverService->getSourceMapKey('dev', '/code/apps/web/.next/dev/server/chunks/ssr/x.js'));
        $this->assertSame('static/chunks/app/page.js.map', $sourceMapResolverService->getSourceMapKey('dev', 'https://ame.dev.dineful.com/_next/static/chunks/app/page.js'));
    }

    public function testDevelopmentMapsComeFromTheBuildOutputAndAreNeverStale(): void
    {
        $buildOutput = new FilesystemObjectStorage($this->temporaryDirectory . '/next-dev');
        $sourceMapResolverService = new ProbeSourceMapResolver();
        $sourceMapResolverService->probeDevelopmentObjectStorage = $buildOutput;
        $serverChunk = '/code/apps/web/.next/dev/server/chunks/ssr/menu.js';
        $buildOutput->putObject('server/chunks/ssr/menu.js.map', (string)json_encode(SourceMapFixtures::createMenuPageMap()));

        $firstResolution = $sourceMapResolverService->resolveFrame('dev', $this->createFrame(1, 35, $serverChunk));
        // an edit: the next build maps the same position to another line
        $editedMap = SourceMapFixtures::createMenuPageMap();
        $editedMap['mappings'] = SourceMapFixtures::encodeMappings([0 => [[0, 0, 0, 0, 0], [30, 0, 1, 2, 1]]], 1);
        $buildOutput->putObject('server/chunks/ssr/menu.js.map', (string)json_encode($editedMap));
        $secondResolution = $sourceMapResolverService->resolveFrame('dev', $this->createFrame(1, 35, $serverChunk));

        $this->assertSame(3, $firstResolution->originalLine);
        $this->assertSame(2, $secondResolution->originalLine, 'the edited map, not the first parse');
        $this->assertSame([], glob($this->temporaryDirectory . '/cache/*.ser') ?: [], 'development maps are not cached on disk');
    }
}
