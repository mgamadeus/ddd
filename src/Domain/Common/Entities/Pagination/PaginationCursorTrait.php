<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Pagination;

trait PaginationCursorTrait
{
    public ?PaginationCursor $paginationCursor;

    public function getPaginationCursor(): ?PaginationCursor
    {
        if (!isset($this->paginationCursor)) {
            $this->paginationCursor = new PaginationCursor();
        }
        return $this->paginationCursor;
    }
}