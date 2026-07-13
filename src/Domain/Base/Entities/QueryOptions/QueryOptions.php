<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Definitions for Query Options Definitions and applied Options such as
 * - filters
 * - orderBy
 * - limit
 * - offset
 * - expand
 */
#[Attribute(Attribute::TARGET_CLASS)]
class QueryOptions extends ValueObject
{
    use BaseAttributeTrait;

    /** @var int Number of results to be skipped / offsetted */
    public int $skip;

    /** @var int The number of results to be returned */
    public ?int $top = 50;

    /**
     * @var int|null Hard upper bound for `top`: any request for a larger `top` (request DTO, expand clause or
     * programmatic setTop) throws a BadRequestException instead of returning the oversized result. Null = no bound
     * (the default — entities that do not declare it keep today's behavior). Declare it on entity sets whose rows
     * are expensive to serialize (e.g. full-text lists rendered into LLM context).
     */
    public ?int $maxTop = null;

    /** @var array Array of filters options, can be either string or an array of [filterName, filterOption1, filterOption2 etc.] */
    public $filters = [];

    /** @var array Array of orderBy options] */
    public $orderBy = [];

    public function __construct(
        ?int $top = null,
        ?int $skip = null,
        array $filters = [],
        array $orderBy = [],
        ?int $maxTop = null
    ) {
        if ($top) {
            $this->top = $top;
        }
        if ($maxTop) {
            $this->maxTop = $maxTop;
        }
        if ($skip) {
            $this->skip = $skip;
        }
        if ($filters) {
            $this->filters = $filters;
        }
        if ($orderBy) {
            $this->orderBy = $orderBy;
        }
        parent::__construct();
    }
}