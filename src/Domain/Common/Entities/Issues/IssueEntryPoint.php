<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * An entry point an issue group was reached through: a route, `CLI:<command>` or `MessageHandler:<transport>`.
 */
class IssueEntryPoint extends ValueObject
{
    public string $name = '';

    public function __construct(string $name = '')
    {
        $this->name = $name;
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }
}
