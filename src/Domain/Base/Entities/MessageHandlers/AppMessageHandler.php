<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Repo\Virtual\VirtualEntityRegistry;
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
    /**
     * A CONSUMED worker job starts on fresh state by default, like a web request: Doctrine unit of work cleared,
     * database connections renewed (fresh session and snapshot), static entity registries emptied — see
     * {@see self::resetWorkerStateForNewJob()}, invoked by {@see \DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware}
     * before the message reaches the handler. A handler that deliberately processes messages on WARM state (a batch
     * handler that accumulates across messages, a handler whose warm registry is the point) overrides this constant
     * with false; the middleware then leaves the process state untouched for that handler's messages.
     */
    public const bool RESET_WORKER_STATE_BEFORE_JOB = true;

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
     * Resets the process to the state a fresh PHP process would have before a job runs: Doctrine unit-of-work caches
     * cleared, every database connection without an active transaction closed (lazy reconnect = new session, new
     * snapshot, no inherited session state) and the static entity registries (Doctrine + virtual) emptied. A
     * long-lived worker otherwise serves a later job from what an earlier job left behind — a session whose
     * REPEATABLE READ snapshot predates the previous job's writes, and registries holding the earlier entities.
     * ONE implementation for every handler: the {@see \DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware}
     * calls it before any consumed message (unless {@see self::RESET_WORKER_STATE_BEFORE_JOB} is false on the
     * handler); a handler that runs sub-jobs in-process may call it itself between them.
     */
    public static function resetWorkerStateForNewJob(): void
    {
        EntityManagerFactory::clearAllInstanceCaches();
        static::renewDatabaseConnection();
        DoctrineEntityRegistry::clear();
        VirtualEntityRegistry::clear();
    }

    /**
     * Renews the database connections alone ({@see EntityManagerFactory::renewAllConnections()}): never inside an
     * active transaction, fail-soft — a renewal problem never fails the caller, the next statement reconnects anyway.
     * Agent loops call it before every tool call, because a read-then-write tool computes its baseline on the
     * session it finds.
     */
    public static function renewDatabaseConnection(): void
    {
        try {
            EntityManagerFactory::renewAllConnections();
        } catch (Throwable) {
            // fail-soft, see the docblock
        }
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