<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\SourceMaps;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A position in the original source, as a source map maps a generated position to it (line and column 1-based).
 */
class SourceMapPosition extends ValueObject
{
    /** @var string The original file as the map names it, e.g. `webpack://_N_E/./src/app/[lang]/menu/page.tsx` */
    public string $source = '';

    public int $line = 1;

    public int $column = 1;

    /** @var string|null The original name at the position (the called function or the identifier) */
    public ?string $name = null;

    /** @var string[] The original lines around the position (the line itself in the middle), when the map embeds the source */
    public array $contextLines = [];

    /** @var int|null The line number of the first of {@see self::$contextLines} */
    public ?int $contextStartLine = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
