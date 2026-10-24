<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A piece of SQL with its positional parameters: a whole statement, or one column assignment of an UPDATE / an
 * ON DUPLICATE KEY UPDATE list. `$values` and `$types` are aligned one to one with the `?` placeholders in `$sql`.
 */
class DoctrineBoundSql extends ValueObject
{
    /** @var string The SQL, with `?` placeholders */
    public string $sql;

    /** @var array The values bound to the placeholders, in order (a list; JSON values stay arrays for the json type) */
    public array $values = [];

    /** @var string[] The DBAL type of each value, in order */
    public array $types = [];

    /**
     * @param string $sql The SQL, with `?` placeholders
     * @param array $values The values bound to the placeholders, in order
     * @param string[] $types The DBAL type of each value, in order
     */
    public function __construct(string $sql = '', array $values = [], array $types = [])
    {
        $this->sql = $sql;
        $this->values = $values;
        $this->types = $types;
        parent::__construct();
    }

    /**
     * Identical SQL may legitimately repeat (two runs of a batch with the same shape), and order matters, so every
     * instance is its own element of a {@see DoctrineBoundSqls}.
     */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
