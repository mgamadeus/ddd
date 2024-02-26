<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use DDD\Domain\Base\Entities\ValueObject;

class ExceptionDetail extends ValueObject
{
    /** @var string|null Exception message */
    public ?string $message;

    /** @var array|null Exception detail, can be a payload of different types */
    public ?array $details;

    public function __construct(string $message, $details = null)
    {
        $this->message = $message;
        $this->details = $details;
        parent::__construct();
    }
}