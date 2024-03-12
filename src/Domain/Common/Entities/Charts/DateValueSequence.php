<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

use DDD\Domain\Base\Entities\ObjectSet;


/**
 * @method DateValue first()
 * @method DateValue getByUniqueKey(string $uniqueKey)
 * @method DateValue[] getElements()
 * @property DateValue[] $elements;
 */
class DateValueSequence extends ObjectSet
{
    /** @var string The name of the DateValue Sequence / Series */
    public string $name;

    public function __construct(string $name = null)
    {
        if ($name) {
            $this->name = $name;
        }
        return parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name??'');
    }

    function orderByDate(): DateValueSequence {
        $elements = $this->getElements();
        usort($elements, function($a, $b) {
            return $a->x <=> $b->x;
        });
        $newDateValueSequence = new DateValueSequence();
        foreach ($elements as $element) {
            $newDateValueSequence->add($element);
        }
        return $newDateValueSequence;
    }
}