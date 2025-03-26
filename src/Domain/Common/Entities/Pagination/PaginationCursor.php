<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Pagination;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Persons\PersonGender;

class PaginationCursor extends ValueObject
{
    /** @var string Cursor of current / requested result set */
    public ?string $requestedCursor;

    /** @var string Cursor of previous result set */
    public ?string $currentCursor;

    /** @var string Cursor of next result set */
    public ?string $nextCursor;
}