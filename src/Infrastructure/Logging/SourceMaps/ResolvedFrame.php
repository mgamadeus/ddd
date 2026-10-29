<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\SourceMaps;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A client frame with its original position when a source map resolved it; else the minified frame with
 * `resolved = false` and the reason in {@see self::$note}.
 */
class ResolvedFrame extends ValueObject
{
    /** @var string The minified file as the client reported it */
    public string $file = '';

    public ?int $line = null;

    public ?int $column = null;

    public ?string $function = null;

    public bool $inApp = false;

    public bool $resolved = false;

    /** @var string|null The original file, e.g. `src/app/[lang]/menu/page.tsx` */
    public ?string $originalFile = null;

    public ?int $originalLine = null;

    public ?int $originalColumn = null;

    /** @var string|null The original name at the position; the minified function name when the map has none */
    public ?string $originalFunction = null;

    /** @var string[] The original lines around the position, when the map embeds the source */
    public array $contextLines = [];

    public ?int $contextStartLine = null;

    /** @var string|null Why the frame is not resolved (no line/column, no map, store not configured, store error) */
    public ?string $note = null;

    /** `at function (file:line:column)`, original when resolved */
    public function toStackTraceLine(): string
    {
        if ($this->resolved) {
            return sprintf('at %s (%s:%d:%d)', $this->originalFunction ?? '<anonymous>', $this->originalFile, $this->originalLine, $this->originalColumn);
        }
        return sprintf(
            'at %s (%s%s%s)%s',
            $this->function ?? '<anonymous>',
            $this->file,
            $this->line !== null ? ':' . $this->line : '',
            $this->column !== null ? ':' . $this->column : '',
            $this->note !== null ? ' [' . $this->note . ']' : ''
        );
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
