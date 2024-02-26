<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Exception extends \Exception
{
    public const CONTENT_TYPE_JSON = 'application/json';
    use SerializerTrait;

    /** @var int The default */
    protected static int $defaultCode = Response::HTTP_NOT_FOUND;
    /** @var string Error message */
    public ?string $error = null;
    protected string $contentType = self::CONTENT_TYPE_JSON;

    public ?ExceptionDetails $exceptionDetails = null;

    public function __construct(string $message, ExceptionDetails &$exceptionDetails = null, Throwable $previous = null)
    {
        $this->exceptionDetails = $exceptionDetails;
        $this->error = $message;
        parent::__construct($message, static::$defaultCode, $previous);
    }

    /**
     * Returns the default error code for current Exception Class
     * @return int
     */
    public static function getErrorCode(): int
    {
        return static::$defaultCode;
    }

    /**
     * @return string
     */
    public function getContentType()
    {
        return $this->contentType;
    }
}
