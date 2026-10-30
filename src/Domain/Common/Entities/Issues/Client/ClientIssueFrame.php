<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One stack frame of a client error, innermost first, as the client parsed it (minified until source maps resolve it).
 */
class ClientIssueFrame extends ValueObject
{
    public const int MAX_FILE_LENGTH = 512;
    public const int MAX_FUNCTION_LENGTH = 128;

    /** @var string The script URL or path of the frame, e.g. `/_next/static/chunks/app/page-3f2a9c1b.js` */
    public string $file = '';

    public ?int $line = null;

    public ?int $column = null;

    /** @var string|null The function name as the runtime reports it */
    public ?string $function = null;

    /** @var bool Whether the frame is the application's own code (not a library, extension or the runtime) */
    public bool $inApp = false;

    public function uniqueKey(): string
    {
        // the same frame may legitimately repeat (recursion): order matters, duplicates stay
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
