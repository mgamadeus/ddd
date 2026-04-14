<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Libs\Config;
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

/**
 * Service for asynchronous logging to JSON files that can be picked up by Filebeat
 * and shipped to Elasticsearch without blocking the main application flow.
 */
class IssuesLogService
{
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
        $additionalContext['section'] = $section;
        try {
            $context = $this->compileExceptionContext($throwable, $additionalContext, $includeFullStackTrace);
        } catch (Throwable) {
            // Fallback to basic context if compilation fails
            $context = $additionalContext;
        }

        $this->safeLog($level, 'Exception occurred: ' . $throwable->getMessage(), $context);
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

        $context = array_merge($additionalContext, [
            'error.type' => get_class($exception),
            'error.kind' => $exception instanceof Error ? 'Error' : 'Exception',
            'error.handled' => $additionalContext['error.handled'] ?? true,
            'error.code' => (string)$exception->getCode(),
            'log.origin.file.name' => basename($exception->getFile()),
            'log.origin.file.line' => $exception->getLine(),
            'error.stack_trace' => $this->getShortStackTrace($exception),
            'error.fingerprint' => $errorFingerprint,
            'error.fingerprint_hash' => $errorFingerprintHash,
            'error.fingerprint_short' => $errorFingerprintShort,
        ]);

        if ($includeFullStackTrace) {
            $context['error.full_stack_trace'] = $exception->getTraceAsString();
        }

        return $context;
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
            $enrichedContext = $this->enrichContext($context);
            $this->issuesLogger->log($level, $message, $enrichedContext);
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
