<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueContext;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvent;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvents;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueIngestResult;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueReport;
use DDD\Domain\Common\Entities\Issues\ErrorFrames\IssueErrorFrames;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\ClientIssueReportNormalizer;
use DDD\Infrastructure\Logging\ClientIssuesFingerprintBuilder;
use DDD\Infrastructure\Logging\ClientUserAgentParser;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Logging\IssuesFingerprintBuilder;
use DDD\Infrastructure\Logging\IssuesLogEvent;
use DDD\Infrastructure\Logging\IssuesLogRedactor;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use Error;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\RouterInterface;
use Throwable;
use WeakMap;

/**
 * Logs errors as structured events into the Monolog channel `issues`. Where they go is configuration
 * ({@see IssuesLogSettings}, {@see \DDD\Infrastructure\Logging\IssuesLogSinkHandler}): unset = the application's own
 * handlers, `file` = ECS JSON lines a collector (Alloy, Filebeat) ships, `stderr`, `none`. The request only appends a line
 * — no network, no database on the error path.
 *
 * Each throwable is logged once ({@see self::wasLogged()}): a service that logs and rethrows and the capture subscriber
 * that sees it again produce one event. Every event carries, besides the existing `error.*` / `log.origin.*` fields
 * (whose meaning is unchanged), the stable hashes `error.group_hash` / `error.issue_hash`
 * ({@see IssuesFingerprintBuilder}) and an `event.id`; its context is redacted and its size capped
 * ({@see IssuesLogRedactor}) before any sink sees it.
 */
class IssuesLogService
{
    /** @var WeakMap<Throwable, true>|null The throwables already logged in this process */
    protected static ?WeakMap $loggedThrowables = null;

    /** @var IssuesLogSettings|null Read from the environment on first use */
    protected static ?IssuesLogSettings $settings = null;

    /** @var LoggerInterface */
    protected LoggerInterface $issuesLogger;

    /** @var RequestStack */
    protected RequestStack $requestStack;

    /** @var RouterInterface */
    protected RouterInterface $router;

    /** @var string Constant used to indicate whether the current request is via HTTP. */
    public const string LOG_MESSAGE_SECTION_HTTP = 'HTTP';

    /** @var string Constant used to indicate whether the current request is via CLI. */
    public const string LOG_MESSAGE_SECTION_CLI = 'CLI';

    /** @var string Constant used to indicate whether the current request is via Messenger transport. */
    public const string LOG_MESSAGE_SECTION_MESSENGER = 'MESSENGER';

    /** A browser reported the error ({@see self::logClientIssue()}) */
    public const string LOG_MESSAGE_SECTION_WEB = 'WEB';

    /** The client app's own server (Node: server rendering, route handlers) reported the error */
    public const string LOG_MESSAGE_SECTION_WEB_SERVER = 'WEB_SERVER';

    /** A mobile app (React Native) reported the error — reserved */
    public const string LOG_MESSAGE_SECTION_MOBILE = 'MOBILE';

    /** The sections of client-reported errors */
    public const array CLIENT_SECTIONS = [
        self::LOG_MESSAGE_SECTION_WEB, self::LOG_MESSAGE_SECTION_WEB_SERVER, self::LOG_MESSAGE_SECTION_MOBILE,
    ];

    /** Context keys of the INGEST request that a client line does not carry (they describe the report's POST) */
    public const array INGEST_REQUEST_CONTEXT_KEYS = ['request_headers', 'url.query', 'http.request.body.content'];

    /** `error.stack_trace` keeps every frame up to here, framework frames after it are collapsed */
    public const int STACK_TRACE_LEADING_FRAMEWORK_FRAMES = 3;

    /** `error.causes`: at most this deep */
    public const int MAX_CAUSES = 5;

    /** Frames per cause */
    public const int CAUSE_FRAMES = 3;

    /** `route_parameters`: at most this many, strings cut at this length */
    public const int MAX_ROUTE_PARAMETERS = 20;
    public const int MAX_ROUTE_PARAMETER_LENGTH = 128;

    /** Size cap of `user_agent.original` */
    public const int MAX_USER_AGENT_LENGTH = 256;

    /**
     * Constructor for AsyncLogService.
     *
     * @param LoggerInterface $issuesLogger Logger instance for logging issues.
     * @param RequestStack $requestStack Stack of HTTP request objects.
     * @param RouterInterface $router Router service for accessing route configuration.
     */
    public function __construct(
        LoggerInterface $issuesLogger,
        RequestStack $requestStack,
        RouterInterface $router,
    ) {
        $this->issuesLogger = $issuesLogger;
        $this->requestStack = $requestStack;
        $this->router = $router;
    }

    /**
     * Log a Throwable (Exception or Error) with structured context.
     *
     * This method logs a Throwable at the specified log level. It enriches the context
     * with additional metadata about the Throwable, such as the class, message, code,
     * file, line, and a short stack trace. Optionally, it can include the full stack trace.
     *
     * @param Throwable $throwable The throwable to log.
     * @param string $level The log level (default LogLevel::ERROR).
     * @param array $additionalContext Additional context to include in the log entry.
     * @param bool $includeFullStackTrace Whether to include the full stack trace in the log entry.
     * @param string $section Optional section name for categorizing the log entry.
     */
    public function logThrowable(
        Throwable $throwable,
        string $level = LogLevel::ERROR,
        array $additionalContext = [],
        bool $includeFullStackTrace = false,
        string $section = self::LOG_MESSAGE_SECTION_HTTP,
    ): void {
        if (static::wasLogged($throwable)) {
            return;
        }
        static::markLogged($throwable);
        $additionalContext['section'] = $section;
        try {
            $context = $this->compileExceptionContext($throwable, $additionalContext, $includeFullStackTrace);
        } catch (Throwable) {
            // Fallback to basic context if compilation fails
            $context = $additionalContext;
        }

        $this->safeLog($level, $this->buildThrowableMessage($throwable), $context);
    }

    /**
     * The event's message: `<Type>: <message>`, and for a throwable without a message `<Type> (no message) at
     * <file>:<line>` — list, alert and drawer still say what and where. Never a hash input.
     */
    public function buildThrowableMessage(Throwable $throwable): string
    {
        $shortType = substr(strrchr('\\' . get_class($throwable), '\\'), 1);
        if (trim($throwable->getMessage()) !== '') {
            return $shortType . ': ' . $throwable->getMessage();
        }
        return sprintf(
            '%s (no message) at %s:%d',
            $shortType,
            $this->getFingerprintBuilder()->getRelativeFile($throwable->getFile()),
            $throwable->getLine()
        );
    }

    /**
     * Logs the events of a client report (browser, Node, React Native) as ordinary issues events of the given section:
     * same sinks, hashes ({@see ClientIssuesFingerprintBuilder}), redaction, registry and alerts. The application's
     * ingest controller checks origin and rate, attributes the account (`AuthService::setAccount()`) and calls this.
     *
     * Caps are enforced here ({@see ClientIssueReportNormalizer}); events beyond {@see ClientIssueEvents::MAX_EVENTS},
     * ignored messages and crawler user agents are dropped and counted. With the kill switch off every event is
     * dropped. A client line is level `error` or `warning`; every kind but `manual` is logged `error.handled=false`.
     *
     * @param ClientIssueReport $clientIssueReport The report as the client sent it
     * @param string $section One of {@see self::CLIENT_SECTIONS}
     * @return ClientIssueIngestResult Accepted and dropped events
     * @throws BadRequestException The report has no event
     * @throws InternalErrorException The section is not a client section
     */
    public function logClientIssue(ClientIssueReport $clientIssueReport, string $section): ClientIssueIngestResult
    {
        if (!in_array($section, self::CLIENT_SECTIONS, true)) {
            throw new InternalErrorException(sprintf('"%s" is not a client issues section (%s)', $section, implode(', ', self::CLIENT_SECTIONS)));
        }
        $clientIssueIngestResult = new ClientIssueIngestResult();
        $settings = static::getSettings();
        if (!$settings->clientIngestEnabled) {
            foreach ($clientIssueReport->events?->getElements() ?? [] as $ignoredClientIssueEvent) {
                $clientIssueIngestResult->addDroppedEvent(ClientIssueIngestResult::DROP_REASON_INGEST_DISABLED);
            }
            return $clientIssueIngestResult;
        }
        (new ClientIssueReportNormalizer())->normalizeReport($clientIssueReport);
        $userAgent = $this->getClientUserAgentString($clientIssueReport->client);
        $userAgentIsIgnored = $userAgent !== null && $this->matchesAnyPattern($userAgent, $settings->clientIgnoreUserAgents);
        foreach (array_values($clientIssueReport->events->getElements()) as $eventIndex => $clientIssueEvent) {
            if ($eventIndex >= ClientIssueEvents::MAX_EVENTS) {
                $clientIssueIngestResult->addDroppedEvent(ClientIssueIngestResult::DROP_REASON_TOO_MANY_EVENTS);
            } elseif ($userAgentIsIgnored) {
                $clientIssueIngestResult->addDroppedEvent(ClientIssueIngestResult::DROP_REASON_IGNORED_USER_AGENT);
            } elseif ($this->matchesAnyPattern($clientIssueEvent->name . ': ' . $clientIssueEvent->message, $settings->clientIgnorePatterns)) {
                $clientIssueIngestResult->addDroppedEvent(ClientIssueIngestResult::DROP_REASON_IGNORED_MESSAGE);
            } else {
                $this->logClientIssueEvent($clientIssueEvent, $clientIssueReport->client, $section, $userAgent);
                $clientIssueIngestResult->accepted++;
            }
        }
        return $clientIssueIngestResult;
    }

    /** One client event as one line */
    protected function logClientIssueEvent(
        ClientIssueEvent $clientIssueEvent,
        ClientIssueContext $clientIssueContext,
        string $section,
        ?string $userAgent
    ): void {
        try {
            $settings = static::getSettings();
            $redactor = new IssuesLogRedactor($settings->sanitizeNumberMinDigits, $settings->redactPathSegments);
            $clientIssuesFingerprintBuilder = new ClientIssuesFingerprintBuilder();
            $groupHash = $clientIssuesFingerprintBuilder->buildGroupHash($section, $clientIssueEvent);
            $context = [
                'section' => $section,
                'event.id' => $this->generateEventId(),
                'error.group_hash' => $groupHash,
                'error.issue_hash' => $clientIssuesFingerprintBuilder->buildIssueHash($groupHash, $clientIssueEvent),
                'error.entry_point' => $clientIssuesFingerprintBuilder->buildEntryPoint($section, $clientIssueEvent),
                'error.type' => $clientIssueEvent->name,
                'error.kind' => $clientIssueEvent->kind,
                'error.handled' => $clientIssueEvent->isHandled(),
                'error.stack_trace' => $this->renderClientStackTrace($clientIssueEvent),
                'client.runtime' => $clientIssueContext->runtime,
                'client.count' => $clientIssueEvent->count,
                'client.occurred_at' => $clientIssueEvent->occurredAt,
                'client.frames' => array_map(static fn($clientIssueFrame): array => array_filter([
                    'file' => $clientIssueFrame->file,
                    'line' => $clientIssueFrame->line,
                    'column' => $clientIssueFrame->column,
                    'function' => $clientIssueFrame->function,
                    'in_app' => $clientIssueFrame->inApp,
                ], static fn(mixed $value): bool => $value !== null), array_values($clientIssueEvent->frames->getElements())),
                'client.breadcrumbs' => array_map(fn($clientIssueBreadcrumb): array => array_filter([
                    'at' => $clientIssueBreadcrumb->at,
                    'category' => $clientIssueBreadcrumb->category,
                    'message' => $redactor->sanitizeClientText($clientIssueBreadcrumb->message),
                    'url' => $clientIssueBreadcrumb->url !== null ? $redactor->redactUrl($clientIssueBreadcrumb->url) : null,
                    'from' => $clientIssueBreadcrumb->from !== null ? $redactor->redactUrl($clientIssueBreadcrumb->from) : null,
                    'to' => $clientIssueBreadcrumb->to !== null ? $redactor->redactUrl($clientIssueBreadcrumb->to) : null,
                    'status' => $clientIssueBreadcrumb->status,
                ], static fn(mixed $value): bool => $value !== null), array_values($clientIssueEvent->breadcrumbs->getElements())),
            ];
            $firstInAppFrame = $clientIssueEvent->frames->getFirstInAppFrame();
            if ($firstInAppFrame !== null) {
                $context['log.origin.file.name'] = $clientIssuesFingerprintBuilder->normaliseFilePath($firstInAppFrame->file);
                if ($firstInAppFrame->line !== null) {
                    $context['log.origin.file.line'] = $firstInAppFrame->line;
                }
            }
            $tags = [];
            foreach ($clientIssueEvent->tags->getElements() as $clientIssueTag) {
                $tags[$clientIssueTag->key] = $clientIssueTag->value;
            }
            $optionalContext = [
                'client.app' => $clientIssueContext->app,
                'client.tab_id' => $clientIssueContext->tabId,
                'client.locale' => $clientIssueContext->locale,
                'client.viewport' => $clientIssueContext->viewportWidth !== null && $clientIssueContext->viewportHeight !== null
                    ? $clientIssueContext->viewportWidth . 'x' . $clientIssueContext->viewportHeight
                    : null,
                'client.backend_release' => $settings->release,
                'client.fingerprint' => $clientIssueEvent->clientFingerprint,
                'client.tags' => $tags !== [] ? $tags : null,
                'service.version' => $clientIssueContext->release ?? $settings->release,
                'route_name' => $clientIssueEvent->routePattern,
                'url.original' => $clientIssueEvent->url !== null ? $redactor->redactUrl($clientIssueEvent->url) : null,
                'url.path' => ($urlPath = $clientIssuesFingerprintBuilder->getUrlPath($clientIssueEvent->url)) !== null
                    ? $redactor->redactUrl($urlPath)
                    : null,
                'http.request.method' => $clientIssueEvent->requestMethod,
                'http.response.status_code' => $clientIssueEvent->httpStatus,
            ];
            if ($userAgent !== null) {
                $clientUserAgent = (new ClientUserAgentParser())->parseUserAgent($userAgent);
                $optionalContext['user_agent.original'] = mb_substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH);
                $optionalContext['user_agent.name'] = $clientUserAgent->name;
                $optionalContext['user_agent.version'] = $clientUserAgent->version;
                $optionalContext['user_agent.os.name'] = $clientUserAgent->osName;
            }
            $context += array_filter($optionalContext, static fn(mixed $value): bool => $value !== null);

            $enrichedContext = $this->enrichContext($context);
            // the line describes the client's request, not the report's POST to the ingest route
            foreach (self::INGEST_REQUEST_CONTEXT_KEYS as $ingestRequestContextKey) {
                unset($enrichedContext[$ingestRequestContextKey]);
            }
            foreach (['route_name', 'http.request.method'] as $clientOwnedContextKey) {
                if (!isset($context[$clientOwnedContextKey])) {
                    unset($enrichedContext[$clientOwnedContextKey]);
                }
            }
            $message = $redactor->sanitizeClientText($clientIssueEvent->name . ': ' . $clientIssueEvent->message);
            $event = new IssuesLogEvent($message, $redactor->redactContext($enrichedContext));
            $redactor->capEvent($event, $settings->getMaxEventBytesForSection($section));
            $level = $clientIssueEvent->level === ClientIssueEvent::LEVEL_WARNING ? LogLevel::WARNING : LogLevel::ERROR;
            $this->issuesLogger->log($level, $event->message, $event->context);
        } catch (Throwable $throwable) {
            error_log('IssuesLogService failed on a client issue: ' . $throwable->getMessage());
        }
    }

    /** The frames as `at function (file:line:column)` lines, innermost first; the raw stack when there are none */
    protected function renderClientStackTrace(ClientIssueEvent $clientIssueEvent): string
    {
        if ($clientIssueEvent->frames->count() === 0) {
            return (string)$clientIssueEvent->rawStack;
        }
        $stackTraceLines = [];
        foreach ($clientIssueEvent->frames->getElements() as $clientIssueFrame) {
            $location = $clientIssueFrame->file
                . ($clientIssueFrame->line !== null ? ':' . $clientIssueFrame->line : '')
                . ($clientIssueFrame->column !== null ? ':' . $clientIssueFrame->column : '');
            $stackTraceLines[] = 'at ' . ($clientIssueFrame->function ?? '<anonymous>') . ' (' . $location . ')';
        }
        return implode("\n", $stackTraceLines);
    }

    /** A browser's user agent is its request's header; Node and React Native send their own */
    protected function getClientUserAgentString(ClientIssueContext $clientIssueContext): ?string
    {
        if ($clientIssueContext->runtime !== ClientIssueContext::RUNTIME_BROWSER && $clientIssueContext->userAgent !== null) {
            return $clientIssueContext->userAgent;
        }
        $requestUserAgent = $this->requestStack->getCurrentRequest()?->headers->get('User-Agent');
        return $requestUserAgent !== null && $requestUserAgent !== '' ? $requestUserAgent : $clientIssueContext->userAgent;
    }

    /**
     * Whether the text contains one of the patterns (case-insensitive substrings)
     *
     * @param string[] $patterns
     */
    protected function matchesAnyPattern(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && stripos($text, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compile the context for an exception.
     *
     * This method merges additional context with metadata extracted from the provided exception.
     * It includes details such as the exception type, message, code, file, line, and a short stack trace.
     * Optionally, it can include the full stack trace.
     *
     * @param Throwable $exception The exception to log.
     * @param array $additionalContext Additional context to include in the log entry.
     * @param bool $includeFullStackTrace Whether to include the full stack trace in the log entry.
     * @return array The compiled context array with enriched metadata.
     */
    protected function compileExceptionContext(
        Throwable $exception,
        array $additionalContext = [],
        bool $includeFullStackTrace = false,
    ): array {
        $errorFingerprint = $this->generateErrorFingerprint($exception);
        $errorFingerprintHash = $this->generateErrorFingerprintHash($errorFingerprint);
        $errorFingerprintShort = $this->generateFingerprintSlug($exception, $errorFingerprintHash);

        $fingerprintBuilder = $this->getFingerprintBuilder();
        $groupHash = $fingerprintBuilder->buildGroupHash($exception);
        $entryPoint = $this->determineEntryPoint($exception);
        // display only: the hashes above come from getFrames(), these frames never feed them
        $issueErrorFrames = $fingerprintBuilder->getErrorFrames($exception);
        $context = array_merge($additionalContext, [
            'event.id' => $this->generateEventId(),
            'error.group_hash' => $groupHash,
            'error.issue_hash' => $fingerprintBuilder->buildIssueHash($exception, $groupHash, $entryPoint),
            'error.entry_point' => $entryPoint,
            'error.type' => get_class($exception),
            'error.kind' => $exception instanceof Error ? 'Error' : 'Exception',
            'error.handled' => $additionalContext['error.handled'] ?? true,
            'error.code' => (string)$exception->getCode(),
            'log.origin.file.name' => basename($exception->getFile()),
            'log.origin.file.line' => $exception->getLine(),
            'error.stack_trace' => $this->renderStackTrace($issueErrorFrames),
            'error.frames' => $this->toFramesContext($issueErrorFrames),
            'error.fingerprint' => $errorFingerprint,
            'error.fingerprint_hash' => $errorFingerprintHash,
            'error.fingerprint_short' => $errorFingerprintShort,
        ]);
        $causes = $this->buildCausesContext($exception, $fingerprintBuilder);
        if ($causes !== []) {
            $context['error.causes'] = $causes;
        }

        if ($includeFullStackTrace) {
            $context['error.full_stack_trace'] = $exception->getTraceAsString();
        }

        return $context;
    }

    /**
     * `error.frames`: one entry per frame, innermost first — `file` (relative), `line`, `function`, `in_app`, and for
     * the first in-app frames `code_context` (`start_line`, `lines`).
     *
     * @return array<int, array<string, mixed>> PSR-3 context
     */
    protected function toFramesContext(IssueErrorFrames $issueErrorFrames): array
    {
        $framesContext = [];
        foreach ($issueErrorFrames->getElements() as $issueErrorFrame) {
            $frameContext = [
                'file' => $issueErrorFrame->file,
                'line' => $issueErrorFrame->line,
                'function' => $issueErrorFrame->function,
                'in_app' => $issueErrorFrame->inApp,
            ];
            if ($issueErrorFrame->codeContextLines !== []) {
                $frameContext['code_context'] = [
                    'start_line' => $issueErrorFrame->codeContextStartLine,
                    'lines' => $issueErrorFrame->codeContextLines,
                ];
            }
            $framesContext[] = $frameContext;
        }
        return $framesContext;
    }

    /**
     * `error.stack_trace` from the frames: `#0 src/X.php(12): App\X->y() ← thrown here`, then every application
     * frame; framework frames after the first {@see self::STACK_TRACE_LEADING_FRAMEWORK_FRAMES} are collapsed into one
     * `… n framework frames` line.
     */
    protected function renderStackTrace(IssueErrorFrames $issueErrorFrames): string
    {
        $stackTraceLines = [];
        $collapsedFrameworkFrames = 0;
        foreach (array_values($issueErrorFrames->getElements()) as $frameIndex => $issueErrorFrame) {
            if (!$issueErrorFrame->inApp && $frameIndex >= self::STACK_TRACE_LEADING_FRAMEWORK_FRAMES) {
                $collapsedFrameworkFrames++;
                continue;
            }
            if ($collapsedFrameworkFrames > 0) {
                $stackTraceLines[] = sprintf('… %d framework frames', $collapsedFrameworkFrames);
                $collapsedFrameworkFrames = 0;
            }
            $stackTraceLines[] = sprintf(
                '#%d %s(%s): %s()%s',
                $frameIndex,
                $issueErrorFrame->file !== '' ? $issueErrorFrame->file : '[internal function]',
                $issueErrorFrame->line ?? '?',
                $issueErrorFrame->function,
                $frameIndex === 0 ? ' ← thrown here' : ''
            );
        }
        if ($collapsedFrameworkFrames > 0) {
            $stackTraceLines[] = sprintf('… %d framework frames', $collapsedFrameworkFrames);
        }
        return implode("\n", $stackTraceLines);
    }

    /**
     * `error.causes`: the getPrevious() chain, outermost first, at most {@see self::MAX_CAUSES} deep — each with type,
     * message, throw site and its first frames.
     *
     * @return array<int, array<string, mixed>> PSR-3 context
     */
    protected function buildCausesContext(Throwable $throwable, IssuesFingerprintBuilder $fingerprintBuilder): array
    {
        $causes = [];
        $cause = $throwable->getPrevious();
        while ($cause !== null && count($causes) < self::MAX_CAUSES) {
            $causeFrames = $fingerprintBuilder->getErrorFrames($cause, codeContextFrameCount: 0);
            $causes[] = [
                'type' => get_class($cause),
                'message' => $cause->getMessage(),
                'file' => $fingerprintBuilder->getRelativeFile($cause->getFile()) . ':' . $cause->getLine(),
                'frames' => array_slice($this->toFramesContext($causeFrames), 0, self::CAUSE_FRAMES),
            ];
            $cause = $cause->getPrevious();
        }
        return $causes;
    }

    /**
     * The matched route's parameters (ids of the venue, order, …) — scalars, strings capped; the redactor still blanks
     * secret-looking names.
     *
     * @param array<string|int, mixed> $routeParameters
     * @return array<string, int|float|bool|string> PSR-3 context
     */
    protected function getRouteParametersContext(array $routeParameters): array
    {
        $routeParametersContext = [];
        foreach ($routeParameters as $name => $value) {
            if (!is_scalar($value) || str_starts_with((string)$name, '_')) {
                continue;
            }
            $routeParametersContext[(string)$name] = is_string($value) ? mb_substr($value, 0, self::MAX_ROUTE_PARAMETER_LENGTH) : $value;
            if (count($routeParametersContext) >= self::MAX_ROUTE_PARAMETERS) {
                break;
            }
        }
        return $routeParametersContext;
    }

    /**
     * Generate a short stack trace from an exception.
     *
     * This method extracts the first few lines of the stack trace from the provided exception,
     * which can be useful for logging purposes where a full stack trace is not necessary.
     *
     * @param Throwable $exception The exception from which to extract the stack trace.
     * @return string A string containing the first few lines of the stack trace.
     */
    protected function getShortStackTrace(Throwable $exception, int $linesToShow = 7): string
    {
        $traceLines = explode("\n", $exception->getTraceAsString());
        return implode("\n", array_slice($traceLines, 0, $linesToShow));
    }

    /**
     * Retrieve the ErrorFingerprint as a string.
     *
     * This method generates a string representation of the ErrorFingerprint up to a specified depth.
     * If an error occurs during the generation of the ErrorFingerprint, a fallback message is returned.
     *
     * @param int $maxDepth The maximum depth of the ErrorFingerprint to include.
     * @return string The string representation of the ErrorFingerprint.
     */
    public function generateErrorFingerprint(Throwable $exception, int $maxDepth = 7): string
    {
        try {
            $fingerprintPrefix = $this->determineEntryPoint($exception);
            $fingerprint = $this->buildFromErrorStack($exception, $maxDepth);

            return $fingerprintPrefix . '::' . $fingerprint;
        } catch (Throwable $t) {
            return 'Fingerprint generation failed: ' . $t->getMessage();
        }
    }

    /**
     * Determine the entry point of the current request.
     *
     * This method inspects the debug backtrace to identify the entry point of the current request.
     * It first checks for CLI commands and message handlers, and if neither is found, it falls back
     * to the HTTP route. The entry point is returned as a string, which can be useful for logging
     * and debugging purposes.
     *
     * @param Throwable $exception
     * @return string The entry point of the current request, formatted as 'MessageHandler:<transport>',
     *                 'CLI:<commandName>', or the HTTP route.
     */
    protected function determineEntryPoint(Throwable $exception): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $trace = $exception->getTrace();

        foreach ($trace as $frame) {
            if (!isset($frame['class'])) {
                continue;
            }

            /** @var string $className */
            $className = $frame['class'];

            // Check for Message Handler
            if (is_a($className, AppMessageHandler::class, true)) {
                $transport = $this->extractMessageHandlerTransport($className);
                if ($transport) {
                    return 'MessageHandler:' . $transport;
                }
            }

            // Check for CLI Command
            if (is_a($className, Command::class, true)) {
                $commandName = $this->extractCommandName($className);
                if ($commandName) {
                    return 'CLI:' . $commandName;
                }
            }
        }

        // Fall back to HTTP route
        return $request?->attributes->get('_route', 'N/A') ?? 'N/A';
    }

    /**
     * Extract the transport name from a message handler class.
     *
     * This method uses reflection to inspect the provided class and extract the transport name
     * from the AsMessageHandler attribute. If the attribute is not present or an error occurs,
     * it returns null.
     *
     * @param string $className The fully qualified name of the class to inspect.
     * @return string|null The transport name if found, or null if not found or an error occurs.
     */
    protected function extractMessageHandlerTransport(string $className): ?string
    {
        try {
            $reflectionClass = ReflectionClass::instance($className);
            /** @var AsMessageHandler $attribute */
            $attribute = $reflectionClass->getAttributeInstance(AsMessageHandler::class);
            return $attribute?->fromTransport;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extract the command name from a class.
     *
     * This method uses reflection to inspect the provided class and extract the command name
     * from the AsCommand attribute. If the attribute is not present or an error occurs,
     * it returns null.
     *
     * @param string $className The fully qualified name of the class to inspect.
     * @return string|null The command name if found, or null if not found or an error occurs.
     */
    protected function extractCommandName(string $className): ?string
    {
        try {
            $reflectionClass = ReflectionClass::instance($className);
            /** @var AsCommand $attribute */
            $attribute = $reflectionClass->getAttributeInstance(AsCommand::class);
            return $attribute?->name;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Build a string representation of the error stack trace.
     *
     * This method processes the stack trace of the provided Throwable and generates
     * a string representation of the stack trace up to a specified depth. It filters
     * out classes that are deemed irrelevant for application-level debugging, such as
     * framework or middleware classes.
     *
     * @param Throwable $throwable The throwable whose stack trace is to be processed.
     * @param int $maxDepth The maximum depth of the stack trace to include in the output.
     * @return string A string representation of the filtered stack trace, with each
     *                entry formatted as 'ClassName->methodName:lineNumber', separated by '|'.
     */
    protected function buildFromErrorStack(Throwable $throwable, int $maxDepth): string
    {
        $trace = $throwable->getTrace();
        $traceArray = [];
        $level = 0;

        $classesToIgnore = $this->getClassesToIgnore();

        foreach ($trace as $frame) {
            if (!isset($frame['class']) || $level >= $maxDepth) {
                break;
            }

            $classWithNamespace = new ClassWithNamespace($frame['class']);
            if (isset($classesToIgnore[$classWithNamespace->name])) {
                continue;
            }

            $line = $frame['line'] ?? 'N/A';
            $traceArray[] = $classWithNamespace->name . '->' . $frame['function'] . ':' . $line;
            $level++;
        }

        return implode('|', $traceArray);
    }

    /**
     * Get a list of classes to ignore in the call stack.
     *
     * This method returns an array of class names that should be ignored when building the call stack.
     * These classes are typically framework or middleware classes that are not relevant for application-level
     * logging and debugging.
     *
     * @return array An associative array where the keys are class names to ignore.
     */
    protected function getClassesToIgnore(): array
    {
        return [
            'ArgusApiOperations' => true,
            'HttpKernelRunner' => true,
            'Kernel' => true,
            'HttpKernel' => true,
            'Application' => true,
            'Command' => true,
            'ConsoleApplicationRunner' => true,
            'RejectRedeliveredMessageMiddleware' => true,
            'DispatchAfterCurrentBusMiddleware' => true,
            'FailedMessageProcessingMiddleware' => true,
            'SendMessageMiddleware' => true,
            'HandleMessageMiddleware' => true,
            'SyncTransport' => true,
            'RoutableMessageBus' => true,
            'TraceableMessageBus' => true,
            'MessageBus' => true,
            'TraceableMiddleware' => true,
            'AddBusNameStampMiddleware' => true,
            'IssuesLogService' => true,
            'IssuesLoggingSubscriber' => true,
            'EventDispatcher' => true,
            'WrappedListener' => true,
        ];
    }

    /**
     * Safely log a message with a specified log level and context.
     *
     * This method attempts to log a message at the specified log level, enriching the context
     * with additional metadata. If an exception occurs during logging, it falls back to using
     * error_log to avoid disrupting the main application flow.
     *
     * @param string $level The log level (e.g., 'error', 'warning', 'info').
     * @param string $message The message to log.
     * @param array $context Additional context to include in the log entry.
     */
    protected function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            $settings = static::getSettings();
            $redactor = new IssuesLogRedactor($settings->sanitizeNumberMinDigits, $settings->redactPathSegments);
            $event = new IssuesLogEvent($redactor->sanitizeText($message), $redactor->redactContext($this->enrichContext($context)));
            $redactor->capEvent($event, $settings->getMaxEventBytesForSection($context['section'] ?? null));
            $this->issuesLogger->log($level, $event->message, $event->context);
        } catch (Throwable $e) {
            error_log('IssuesLogService failed: ' . $e->getMessage() . ' Original message: ' . $message);
        }
    }

    /**
     * Enrich the logging context with additional metadata.
     *
     * This method adds application-specific metadata to the provided context array.
     * It includes the application name, environment, and request-specific metadata
     * such as the route name and route parameters if available.
     *
     * @param array $context The original context array to enrich.
     * @return array The enriched context array with additional metadata.
     */
    protected function enrichContext(array $context): array
    {
        $appEnv = Config::getEnv('APP_ENV');

        $baseContext = [
            'application' => 'symfony',
            'environment' => $appEnv ?? 'unknown',
        ];

        // Add request metadata if available
        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->attributes->has('_route')) {
            $baseContext['route_name'] = $request->attributes->get('_route');
            $routeParameters = $this->getRouteParametersContext((array)$request->attributes->get('_route_params', []));
            if ($routeParameters !== []) {
                $baseContext['route_parameters'] = $routeParameters;
            }
        }

        $settings = static::getSettings();
        $baseContext['service.name'] = $settings->serviceName ?? (string)(Config::getEnv('APP_NAME') ?? 'symfony');
        $baseContext['service.environment'] = (string)(Config::getEnv('APP_STAGE') ?? $appEnv ?? 'unknown');
        if ($settings->workspace !== null) {
            $baseContext['service.workspace'] = $settings->workspace;
        }
        if ($settings->release !== null) {
            $baseContext['service.version'] = $settings->release;
        }
        $hostName = getenv('HOSTNAME') ?: gethostname();
        if ($hostName) {
            $baseContext['host.hostname'] = $hostName;
        }
        if ($request) {
            $baseContext['http.request.method'] = $request->getMethod();
            if ($request->headers->has('x-request-id')) {
                $baseContext['http.request.id'] = $request->headers->get('x-request-id');
            }
            if ($request->headers->has('traceparent')) {
                $baseContext['traceparent'] = $request->headers->get('traceparent');
            }
            if ($settings->headerPrefixes !== []) {
                $baseContext['request_headers'] = [];
                foreach ($request->headers->all() as $headerName => $headerValue) {
                    foreach ($settings->headerPrefixes as $headerPrefix) {
                        if (str_starts_with(strtolower((string)$headerName), $headerPrefix)) {
                            $baseContext['request_headers'][$headerName] = $headerValue;
                            break;
                        }
                    }
                }
            }
            if ($settings->includeQueryString && $request->getQueryString() !== null) {
                $baseContext['url.query'] = $request->getQueryString();
            }
            if ($settings->includeRequestBody) {
                $baseContext['http.request.body.content'] = (string)$request->getContent();
            }
        }
        try {
            $accountId = AuthService::instance()->getAccount()?->id ?? null;
            if ($accountId !== null) {
                $baseContext['account.id'] = $accountId;
            }
        } catch (Throwable) {
        }

        return array_merge($baseContext, $context);
    }

    /**
     * Generate a hash for the given error fingerprint.
     *
     * This method generates a hash for the provided error fingerprint string using
     * the xxh128 algorithm if available, otherwise it falls back to the md5 algorithm.
     *
     * @param string $errorFingerprint The error fingerprint string to hash.
     * @return string The generated hash of the error fingerprint.
     */
    /** Whether this throwable was logged already in this process (a service logged it before it reached the capture) */
    public static function wasLogged(Throwable $throwable): bool
    {
        return isset(static::$loggedThrowables[$throwable]);
    }

    public static function markLogged(Throwable $throwable): void
    {
        static::$loggedThrowables ??= new WeakMap();
        static::$loggedThrowables[$throwable] = true;
    }

    public static function getSettings(): IssuesLogSettings
    {
        return static::$settings ??= IssuesLogSettings::fromEnvironment();
    }

    /** Replaces the settings read from the environment (tests, or after the environment changed) */
    public static function setSettings(?IssuesLogSettings $settings): void
    {
        static::$settings = $settings;
    }

    protected function getFingerprintBuilder(): IssuesFingerprintBuilder
    {
        try {
            $projectDirectory = DDDService::instance()->getRootDir();
        } catch (Throwable) {
            $projectDirectory = '';
        }
        return new IssuesFingerprintBuilder($projectDirectory);
    }

    /** A random UUID (v4) per event, for de-duplication in the backend */
    protected function generateEventId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    protected function generateErrorFingerprintHash(string $errorFingerprint): string
    {
        $hashAlgorithms = hash_algos();
        $algo = in_array('xxh128', $hashAlgorithms, true) ? 'xxh128' : 'md5';

        return hash($algo, $errorFingerprint);
    }

    /**
     * Generate a short, unique slug for an error fingerprint.
     *
     * This method creates a concise, unique identifier (slug) for an error fingerprint
     * by combining the entry point, exception class, and top stack frames. It ensures
     * the slug length does not exceed a specified maximum number of characters.
     *
     * @param Throwable $exception The exception to generate the slug for.
     * @param string $errorFingerprintHash The hash of the error fingerprint.
     * @param int $maxFrames The maximum number of stack frames to include in the slug.
     * @param int $maxChars The maximum length of the generated slug.
     * @return string The generated slug for the error fingerprint.
     */
    protected function generateFingerprintSlug(
        Throwable $exception,
        string $errorFingerprintHash,
        int $maxFrames = 3,
        int $maxChars = 240,
    ): string {
        $entry = $this->abbreviateEntryPoint($this->determineEntryPoint($exception));
        $entry = str_replace(' ', '', $entry);

        $excShort = (new ClassWithNamespace(get_class($exception)))->name;
        $frames = $this->topFramesShort($exception, $maxFrames); // returns Class->method:line
        $core = $entry . '|' . $excShort;
        if (!empty($frames)) {
            $core .= '@' . implode('|', $frames);
        }

        $suffix = '~' . substr($errorFingerprintHash, 0, 12);
        $budget = max(0, $maxChars - strlen($suffix));

        // Drop tail frames first
        while (strlen($core) > $budget && !empty($frames)) {
            array_pop($frames);
            $core = $entry . '|' . $excShort;
            if (!empty($frames)) {
                $core .= '@' . implode('|', $frames);
            }
        }

        if (strlen($core) > $budget) {
            $fixed = '|' . $excShort;
            if (!empty($frames)) {
                $fixed .= '@' . implode('|', $frames);
            }
            $allow = $budget - strlen($fixed);
            if ($allow < 0) {
                $allow = 0;
            }
            $entry = substr($entry, 0, $allow);
            $core = $entry . $fixed;

            if (strlen($core) > $budget) {
                $core = substr($core, 0, $budget);
            }
        }

        return $core . $suffix;
    }

    /**
     * Abbreviate route/entry. E.g. "App\...\PostsController:getPosts__GET:/path"
     * → "PostsController:getPosts GET:/path"
     * (Leaves MessageHandler:/CLI: entries as-is.)
     *
     * @param string $entry
     * @return string
     */
    protected function abbreviateEntryPoint(string $entry): string
    {
        if ($entry === '' || $entry === 'N/A') {
            return $entry;
        }

        if (str_contains($entry, ':')) {
            [$classPart, $rest] = explode(':', $entry, 2);
            $classShort = (new ClassWithNamespace($classPart))->name;

            $rest = str_replace(' ', '', $rest);

            if (!str_contains($rest, '__') && preg_match(
                    '/^(.*?)(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS):/i',
                    $rest,
                    $m,
                )) {
                $rest = $m[1] . '__' . substr($rest, strlen($m[1]));
            }

            return $classShort . ':' . $rest;
        }

        return str_replace(' ', '', $entry);
    }

    /**
     * Return up to N application frames as "Class.method[:line]" skipping ignored framework classes.
     *
     * @param Throwable $throwable
     * @param int $maxFrames
     * @param bool $includeLineNumbers
     * @return array
     */
    protected function topFramesShort(Throwable $throwable, int $maxFrames = 3, bool $includeLineNumbers = false): array
    {
        $trace = $throwable->getTrace();
        $classesToIgnore = $this->getClassesToIgnore();
        $out = [];

        foreach ($trace as $frame) {
            if (!isset($frame['class'])) {
                continue;
            }

            $classWithNamespace = new ClassWithNamespace($frame['class']);
            $shortClass = $classWithNamespace->name;

            if (isset($classesToIgnore[$shortClass])) {
                continue;
            }

            $method = $frame['function'] ?? 'unknown';
            $token = $shortClass . '->' . $method;

            if ($includeLineNumbers && isset($frame['line']) && is_numeric($frame['line'])) {
                $token .= ':' . $frame['line'];
            }

            $out[] = $token;
            if (count($out) >= $maxFrames) {
                break;
            }
        }

        return $out;
    }
}
