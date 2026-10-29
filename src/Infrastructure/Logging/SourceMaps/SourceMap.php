<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\SourceMaps;

/**
 * A parsed source map (version 3), regular or indexed (`sections`, as Turbopack emits them): looks up the original
 * position of a generated line and column. Built by {@see SourceMapParser}; serialisable for the disk cache.
 *
 * The decoded mapping table is held as integer arrays on purpose — a production map has hundreds of thousands of
 * segments, one object each would cost an order of magnitude in memory and parse time. It never leaves this class: the
 * public result is {@see SourceMapPosition}.
 */
class SourceMap
{
    /** The original lines returned around a position: the line and one before and after */
    public const int CONTEXT_LINES_AROUND = 1;

    /**
     * @param string[] $sources The original files
     * @param string[] $names The original names
     * @param array<int, string|null> $sourcesContent The embedded original sources by source index
     * @param array<int, array<int, int[]>> $segmentsByGeneratedLine Decoded segments per generated line (0-based):
     *     [generatedColumn, sourceIndex, sourceLine, sourceColumn, nameIndex?], sorted by generated column
     * @param array<int, array{0: int, 1: int, 2: SourceMap}> $sections Indexed map: [offsetLine, offsetColumn, map], in order
     */
    public function __construct(
        protected array $sources = [],
        protected array $names = [],
        protected array $sourcesContent = [],
        protected array $segmentsByGeneratedLine = [],
        protected array $sections = [],
    ) {
    }

    /**
     * The original position of a generated position as a JavaScript stack trace reports it (line and column 1-based);
     * null when the map has no mapping for it.
     */
    public function findOriginalPosition(int $generatedLine, int $generatedColumn): ?SourceMapPosition
    {
        return $this->findOriginalPositionZeroBased(max(0, $generatedLine - 1), max(0, $generatedColumn - 1));
    }

    protected function findOriginalPositionZeroBased(int $generatedLine, int $generatedColumn): ?SourceMapPosition
    {
        if ($this->sections !== []) {
            $matchingSection = null;
            foreach ($this->sections as $section) {
                [$offsetLine, $offsetColumn] = $section;
                if ($offsetLine < $generatedLine || ($offsetLine === $generatedLine && $offsetColumn <= $generatedColumn)) {
                    $matchingSection = $section;
                } else {
                    break;
                }
            }
            if ($matchingSection === null) {
                return null;
            }
            [$offsetLine, $offsetColumn, $sectionMap] = $matchingSection;
            return $sectionMap->findOriginalPositionZeroBased(
                $generatedLine - $offsetLine,
                $generatedLine === $offsetLine ? $generatedColumn - $offsetColumn : $generatedColumn
            );
        }
        $lineSegments = $this->segmentsByGeneratedLine[$generatedLine] ?? [];
        // the last segment starting at or before the column
        $low = 0;
        $high = count($lineSegments) - 1;
        $foundSegment = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($lineSegments[$middle][0] <= $generatedColumn) {
                $foundSegment = $lineSegments[$middle];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }
        if ($foundSegment === null || count($foundSegment) < 4) {
            return null;
        }
        $sourceMapPosition = new SourceMapPosition();
        $sourceMapPosition->source = $this->sources[$foundSegment[1]] ?? '';
        $sourceMapPosition->line = $foundSegment[2] + 1;
        $sourceMapPosition->column = $foundSegment[3] + 1;
        $sourceMapPosition->name = isset($foundSegment[4]) ? ($this->names[$foundSegment[4]] ?? null) : null;
        $sourceContent = $this->sourcesContent[$foundSegment[1]] ?? null;
        if (is_string($sourceContent)) {
            $sourceLines = preg_split('/\r\n|\n|\r/', $sourceContent) ?: [];
            $firstContextIndex = max(0, $foundSegment[2] - self::CONTEXT_LINES_AROUND);
            $sourceMapPosition->contextLines = array_slice($sourceLines, $firstContextIndex, 2 * self::CONTEXT_LINES_AROUND + 1);
            $sourceMapPosition->contextStartLine = $firstContextIndex + 1;
        }
        return $sourceMapPosition;
    }
}
