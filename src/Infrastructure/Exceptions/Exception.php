<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Exception extends \Exception
{
    use SerializerTrait;

    public const CONTENT_TYPE_JSON = 'application/json';

    /** @var string Error message */
    public ?string $error = null;

    public ?ExceptionDetails $exceptionDetails = null;

    /** @var int The default */
    protected static int $defaultCode = Response::HTTP_NOT_FOUND;

    protected string $contentType = self::CONTENT_TYPE_JSON;

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

    /**
     * Log Exception with short trace
     * @param LoggerInterface $logger
     * @param string $context
     * @param Throwable $t
     * @param int $traceDepth
     * @return void
     */
    public static function logShortException(LoggerInterface $logger, string $context, Throwable $t, int $traceDepth = 5): void
    {
        $traceLines = explode("\n", $t->getTraceAsString());
        $shortTrace = implode("\n", array_slice($traceLines, 0, $traceDepth));

        $logger->error(
            sprintf(
                '%s error [%s #%d] %s in %s:%d; Trace (top 3): %s',
                $context,
                get_class($t),
                $t->getCode(),
                $t->getMessage(),
                $t->getFile(),
                $t->getLine(),
                $shortTrace
            )
        );
    }
}
