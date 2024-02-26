<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @method ExceptionDetail first()
 * @method ExceptionDetail getByUniqueKey(string $uniqueKey)
 * @method ExceptionDetail[] getElements()
 * @property ExceptionDetail[] $elements;
 */
class ExceptionDetails extends ObjectSet
{
    /**
     * Adds Exception Detail
     * @param string $message
     * @param $detail
     * @return $this
     */
    public function addDetail(string $message, $detail): ExceptionDetails
    {
        $exceptionDetail = new ExceptionDetail($message, $detail);
        $this->add($exceptionDetail);
        return $this;
    }
}