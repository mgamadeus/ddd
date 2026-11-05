<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Repo\Virtual\VirtualEntityRegistry;
use DDD\Infrastructure\Logging\IssuesMessengerContextBuilder;
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
    /** Context key of a handler's own description of the failure (the key existing handlers write) */
    public const string ISSUES_CONTEXT_CUSTOM_MESSAGE = 'messenger.custom_message';

    /** Context key of the message's scalar public properties, JSON-encoded */
    public const string ISSUES_CONTEXT_MESSAGE_PAYLOAD = 'messenger.message_payload';

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
        // a caught job error is an issue too: into the issues log (registry, alerts) — once, even when the handler
        // also calls logIssue() for the same throwable
        $this->logThrowableToIssues($t, [self::ISSUES_CONTEXT_CUSTOM_MESSAGE => $context]);
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
            $additionalContext[self::ISSUES_CONTEXT_CUSTOM_MESSAGE] = $customMessage;
        }

        try {
            $appMessagePayload = $this->extractMessagePayload($appMessage);
        } catch (Throwable) {
            $appMessagePayload = [];
        }

        $additionalContext += $appMessagePayload;
        // what the message is about and where it came from — the same fields as an uncaught worker failure
        $additionalContext += (new IssuesMessengerContextBuilder())->buildMessageContext($appMessage);
        $additionalContext['messenger.handler'] = static::class;

        $this->logThrowableToIssues($e, $additionalContext);
    }

    /**
     * A caught job error into the issues log: CRITICAL, section MESSENGER. Logged once per throwable (the issues log's
     * log-once mark), and a failure of the logging itself never leaves the handler's catch block.
     *
     * @param array<string, mixed> $additionalContext Issues-log context (flat ECS fields)
     */
    protected function logThrowableToIssues(Throwable $throwable, array $additionalContext): void
    {
        try {
            $issuesLogService = $this->issuesLogService ?? DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable(
                $throwable,
                LogLevel::CRITICAL,
                $additionalContext,
                false,
                IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER,
            );
        } catch (Throwable) {
            // the job's own error handling goes on; the Monolog line of logShortException() is already written
        }
    }

    /**
     * Extracts scalar properties from the message and JSON encodes them for logging.
     *
     * @param AppMessage $appMessage The message to extract payload from
     * @return array<string, string> json_encode input: messenger.message_payload with the JSON-encoded scalar values
     * @throws JsonException
     * @throws ReflectionException
     */
    protected function extractMessagePayload(AppMessage $appMessage): array
    {
        $payload = [];

        $reflection = $appMessage::getReflectionClass();
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            // an unset typed property (e.g. tempDirFileName) would throw and lose the whole payload
            if ($property->isStatic() || !$property->isInitialized($appMessage)) {
                continue;
            }
            $value = $property->getValue($appMessage);
            if ($value !== null && !is_object($value) && !is_array($value)) {
                $payload[$property->getName()] = $value;
            }
        }

        return [
            self::ISSUES_CONTEXT_MESSAGE_PAYLOAD => json_encode($payload, JSON_THROW_ON_ERROR),
        ];
    }
}