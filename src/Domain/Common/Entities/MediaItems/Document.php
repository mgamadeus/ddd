<?php

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;

abstract class Document extends GenericMediaItem
{
    /** @var string|null The type of the media item */
    #[Choice(choices: [self::TYPE_DOCUMENT])]
    public ?string $type = self::TYPE_DOCUMENT;

    /** @var string The filename of the document (wihtout path) */
    public string $fileName;

    /** @var DateTime The creation time */
    public DateTime $createdDateTime;

    /** @var DateTime The modified time */
    public DateTime $modifiedDateTime;

    public static function cleanFileName(string $name): string
    {
        // remove control characters U+0000–U+001F
        $name = preg_replace('/[\x00-\x1F]/u', '', $name);
        // remove prohibited chars for Windows/macOS: \ / : * ? " < > |
        $name = str_replace(str_split('\\/:*?"<>|'), '', $name);
        // trim leading/trailing dots and spaces
        $name = trim($name, ' .');
        // avoid reserved Windows names
        $upper = strtoupper($name);
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $upper)) {
            $name = "_{$name}";
        }
        return $name;
    }
}