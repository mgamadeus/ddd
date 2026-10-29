<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Browser and operating system read from a user agent string ({@see ClientUserAgentParser}).
 */
class ClientUserAgent extends ValueObject
{
    /** @var string|null e.g. `Chrome`, `Safari`, `Firefox`, `Edge`, `node` */
    public ?string $name = null;

    /** @var string|null The major version, e.g. `130` */
    public ?string $version = null;

    /** @var string|null e.g. `iOS`, `Android`, `Windows`, `macOS`, `Linux`, `ChromeOS` */
    public ?string $osName = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
