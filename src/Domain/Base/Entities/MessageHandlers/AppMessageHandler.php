<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionException;
use ReflectionProperty;
use Throwable;

abstract class AppMessageHandler
{
    /** @var LoggerInterface|null */
    protected ?LoggerInterface $messengerLogger = null;

    /** @var IssuesLogService */
    protected IssuesLogService $issuesLogService;

    /**
     * @param IssuesLogService|null $issuesLogService
     * @param LoggerInterface|null $messengerLogger
     */
    public function __construct(
        ?IssuesLogService $issuesLogService = null,
        ?LoggerInterface $messengerLogger = null,
    ) {
        $this->issuesLogService = $issuesLogService ?? DDDService::instance()->getService(IssuesLogService::class);
        $this->messengerLogger = $messengerLogger;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        if (isset($this->messengerLogger)) {
            return $this->messengerLogger;
        }
        return DDDService::instance()->getLogger();
    }

    protected function setAuthAccountFromMessage(AppMessage $appMessage): void
    {
        if ($appMessage->accountId ?? null) {
            AuthService::instance()->setAccountId($appMessage->accountId);
        }
    }

    /**
     * Logs an exception with only the first 3 trace frames.
     *
     * @param LoggerInterface $logger Ein PSR-3-Logger (z.B. $this->getLogger()).
     * @param string $context Beliebiger Text, z.B. "Location 123".
     * @param Throwable $t Geworfene Exception.
     */
    protected function logShortException(LoggerInterface $logger, string $context, Throwable $t): void
    {
        $traceLines = explode("\n", $t->getTraceAsString());
        $shortTrace = implode("\n", array_slice($traceLines, 0, 3));

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

    /**
     * Logs an issue by capturing the exception and additional context from the AppMessage.
     *
     * @param Throwable $e The exception to log.
     * @param AppMessage $appMessage The message associated with the exception.
     * @param string|null $customMessage An optional custom message to include in the log.
     */
    protected function logIssue(
        Throwable $e,
        AppMessage $appMessage,
        ?string $customMessage = null,
    ): void {
        $additionalContext = [];

        if ($customMessage) {
            $additionalContext['message.custom_message'] = $customMessage;
        }

        try {
            $appMessagePayload = $this->extractMessagePayload($appMessage);
        } catch (Throwable) {
            $appMessagePayload = [];
        }

        $additionalContext += $appMessagePayload;

        $this->issuesLogService->logThrowable(
            $e,
            LogLevel::CRITICAL,
            $additionalContext,
            false,
            IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER,
        );
    }

    /**
     * Extracts scalar properties from the message and JSON encodes them for logging.
     *
     * @param AppMessage $appMessage The message to extract payload from
     * @return array Additional context with message.payload containing JSON-encoded scalar values
     * @throws JsonException
     * @throws ReflectionException
     */
    protected function extractMessagePayload(AppMessage $appMessage): array
    {
        $payload = [];

        $reflection = $appMessage::getReflectionClass();
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($appMessage);
            if ($value !== null && !is_object($value) && !is_array($value)) {
                $payload[$property->getName()] = $value;
            }
        }

        return [
            'message.payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];
    }
}