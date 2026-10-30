<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A key-value pair — a bar of a categorical chart, a row of a breakdown. Subclasses type the value.
 */
abstract class AbstractKeyValue extends ValueObject
{
    /** @var string|null The key (category, label) */
    public ?string $key = null;

    public function __construct(?string $key = null)
    {
        $this->key = $key;
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->key ?? (string)spl_object_id($this));
    }
}
