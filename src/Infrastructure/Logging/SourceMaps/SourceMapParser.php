<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\SourceMaps;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * Parses a source map (version 3): `sources`, `names`, optional `sourcesContent` and the base64 VLQ `mappings`, or an
 * indexed map whose `sections` each carry an `offset` and an embedded `map` (Turbopack). Sections referring to a map by
 * `url` are not supported (a build tool emits embedded maps).
 */
class SourceMapParser
{
    public const string BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /** @throws InternalErrorException Not a source map */
    public function parseSourceMap(string $sourceMapJson): SourceMap
    {
        $sourceMapData = json_decode($sourceMapJson, true);
        if (!is_array($sourceMapData)) {
            throw new InternalErrorException('Not a source map: invalid JSON');
        }
        return $this->buildSourceMap($sourceMapData);
    }

    /**
     * @param array<string, mixed> $sourceMapData The decoded JSON
     * @throws InternalErrorException
     */
    protected function buildSourceMap(array $sourceMapData): SourceMap
    {
        if (isset($sourceMapData['sections']) && is_array($sourceMapData['sections'])) {
            $sections = [];
            foreach ($sourceMapData['sections'] as $sectionData) {
                if (!is_array($sectionData['map'] ?? null)) {
                    continue;
                }
                $sections[] = [
                    (int)($sectionData['offset']['line'] ?? 0),
                    (int)($sectionData['offset']['column'] ?? 0),
                    $this->buildSourceMap($sectionData['map']),
                ];
            }
            usort($sections, static fn(array $first, array $second): int => [$first[0], $first[1]] <=> [$second[0], $second[1]]);
            return new SourceMap(sections: $sections);
        }
        if (!isset($sourceMapData['mappings']) || !is_string($sourceMapData['mappings'])) {
            throw new InternalErrorException('Not a source map: no mappings');
        }
        $sourceRoot = is_string($sourceMapData['sourceRoot'] ?? null) && $sourceMapData['sourceRoot'] !== ''
            ? rtrim($sourceMapData['sourceRoot'], '/') . '/'
            : '';
        $sources = array_map(
            static fn(mixed $source): string => $sourceRoot . (string)$source,
            is_array($sourceMapData['sources'] ?? null) ? $sourceMapData['sources'] : []
        );
        return new SourceMap(
            sources: $sources,
            names: array_map('strval', is_array($sourceMapData['names'] ?? null) ? $sourceMapData['names'] : []),
            sourcesContent: is_array($sourceMapData['sourcesContent'] ?? null) ? $sourceMapData['sourcesContent'] : [],
            segmentsByGeneratedLine: $this->decodeMappings($sourceMapData['mappings']),
        );
    }

    /**
     * The `mappings` string as absolute segments per generated line.
     *
     * @return array<int, array<int, int[]>>
     */
    public function decodeMappings(string $mappings): array
    {
        $segmentsByGeneratedLine = [];
        $sourceIndex = $sourceLine = $sourceColumn = $nameIndex = 0;
        foreach (explode(';', $mappings) as $generatedLine => $lineMappings) {
            if ($lineMappings === '') {
                continue;
            }
            $generatedColumn = 0;
            $lineSegments = [];
            foreach (explode(',', $lineMappings) as $segmentMapping) {
                if ($segmentMapping === '') {
                    continue;
                }
                $values = $this->decodeVlq($segmentMapping);
                $generatedColumn += $values[0];
                $segment = [$generatedColumn];
                if (count($values) >= 4) {
                    $sourceIndex += $values[1];
                    $sourceLine += $values[2];
                    $sourceColumn += $values[3];
                    array_push($segment, $sourceIndex, $sourceLine, $sourceColumn);
                    if (count($values) >= 5) {
                        $nameIndex += $values[4];
                        $segment[] = $nameIndex;
                    }
                }
                $lineSegments[] = $segment;
            }
            usort($lineSegments, static fn(array $first, array $second): int => $first[0] <=> $second[0]);
            $segmentsByGeneratedLine[$generatedLine] = $lineSegments;
        }
        return $segmentsByGeneratedLine;
    }

    /**
     * The signed integers of one base64 VLQ segment.
     *
     * @return int[]
     */
    public function decodeVlq(string $segmentMapping): array
    {
        $values = [];
        $value = 0;
        $shift = 0;
        $length = strlen($segmentMapping);
        for ($characterIndex = 0; $characterIndex < $length; $characterIndex++) {
            $digit = strpos(self::BASE64_ALPHABET, $segmentMapping[$characterIndex]);
            if ($digit === false) {
                throw new InternalErrorException(sprintf('Invalid source map mapping character "%s"', $segmentMapping[$characterIndex]));
            }
            $value += ($digit & 31) << $shift;
            if ($digit & 32) {
                $shift += 5;
                continue;
            }
            $values[] = ($value & 1) ? -($value >> 1) : ($value >> 1);
            $value = 0;
            $shift = 0;
        }
        return $values;
    }
}
