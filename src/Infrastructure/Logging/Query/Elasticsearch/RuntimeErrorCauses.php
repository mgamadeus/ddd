<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * An occurrence's cause chain, outermost first.
 *
 * @property RuntimeErrorCause[] $elements
 * @method RuntimeErrorCause|null first()
 * @method RuntimeErrorCause[] getElements()
 */
class RuntimeErrorCauses extends ObjectSet
{
}
