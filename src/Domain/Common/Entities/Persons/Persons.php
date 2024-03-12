<?php

namespace DDD\Domain\Common\Entities\Persons;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property Person[] $elements;
 * @method Person getByUniqueKey(string $uniqueKey)
 * @method Person[] getElements()
 * @method Person first()
 */
class Persons extends ObjectSet
{
}