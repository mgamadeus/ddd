<?php

namespace DDD\Domain\Common\Entities\Files;

use DDD\Domain\Base\Entities\ValueObject;

class File extends ValueObject
{
    /** @var string The name of the file */
    public string $originalName;

    /** @var string The mime type of the file */
    public string $mimeType;

    /** @var int The error code of the file */
    public int $error;

    /** @var string The original path of the file */
    public string $originalPath;

    /** @var string The tmp path of the file */
    public string $path;
}
