<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging\SourceMaps;

/**
 * Builds source maps for the tests: an independent base64 VLQ encoder, so the parser's decoding is checked against it.
 */
class SourceMapFixtures
{
    public const string ORIGINAL_SOURCE = "export function MenuPage() {\n  const items = loadItems();\n  return items.map(renderItem);\n}\n";

    public static function encodeVlqValue(int $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $vlq = $value < 0 ? ((-$value) << 1) | 1 : $value << 1;
        $encoded = '';
        do {
            $digit = $vlq & 31;
            $vlq >>= 5;
            if ($vlq > 0) {
                $digit |= 32;
            }
            $encoded .= $alphabet[$digit];
        } while ($vlq > 0);
        return $encoded;
    }

    /**
     * Mappings of absolute segments per generated line: [generatedColumn, sourceIndex, sourceLine, sourceColumn, nameIndex?] (0-based).
     *
     * @param array<int, array<int, int[]>> $segmentsByGeneratedLine
     */
    public static function encodeMappings(array $segmentsByGeneratedLine, int $lineCount): string
    {
        $previous = [0, 0, 0, 0];
        $lines = [];
        for ($generatedLine = 0; $generatedLine < $lineCount; $generatedLine++) {
            $previousColumn = 0;
            $encodedSegments = [];
            foreach ($segmentsByGeneratedLine[$generatedLine] ?? [] as $segment) {
                $encoded = self::encodeVlqValue($segment[0] - $previousColumn);
                $previousColumn = $segment[0];
                if (count($segment) >= 4) {
                    for ($field = 1; $field <= 3; $field++) {
                        $encoded .= self::encodeVlqValue($segment[$field] - $previous[$field - 1]);
                        $previous[$field - 1] = $segment[$field];
                    }
                    if (isset($segment[4])) {
                        $encoded .= self::encodeVlqValue($segment[4] - $previous[3]);
                        $previous[3] = $segment[4];
                    }
                }
                $encodedSegments[] = $encoded;
            }
            $lines[] = implode(',', $encodedSegments);
        }
        return implode(';', $lines);
    }

    /**
     * A minified chunk `function a(){const b=c();return b.map(d)}` on line 1 mapped to {@see self::ORIGINAL_SOURCE}:
     * column 1 → MenuPage (line 1), column 15 → line 2, column 31 → `items.map` (line 3, column 10, name `map`).
     *
     * @return array<string, mixed>
     */
    public static function createMenuPageMap(): array
    {
        return [
            'version' => 3,
            'file' => 'page-3f2a9c1b8e7d6f50.js',
            'sources' => ['webpack://_N_E/./src/app/[lang]/menu/page.tsx'],
            'names' => ['MenuPage', 'map'],
            'sourcesContent' => [self::ORIGINAL_SOURCE],
            'mappings' => self::encodeMappings([0 => [[0, 0, 0, 0, 0], [14, 0, 1, 2], [30, 0, 2, 15, 1]]], 1),
        ];
    }
}
