<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Pagination;

trait PaginationCursorTrait
{
    public ?PaginationCursor $paginationCursor;
}