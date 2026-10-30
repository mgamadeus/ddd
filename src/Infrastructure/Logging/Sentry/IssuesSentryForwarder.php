<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Sentry;

use Psr\Log\LogLevel;

/**
 * Forwards the error events of the issues log files to Sentry — asynchronously, never from the request: it runs next
 * to the files (a cron or a loop in the pod / on the host that writes them) and remembers per file how far it has read
 * (inode + byte offset, in a JSON state file). A rotated or truncated file starts over at 0; a file seen for the first
 * time starts at its end unless `fromStart` (no flood of history). A trailing line without newline is left for the
 * next run (still being written). Sentry rate limits and outages stop the run; the rest follows next time.
 *
 * Forwarded: level ≥ the threshold (error by default) and every uncaught error (`error.handled: false`).
 */
class IssuesSentryForwarder
{
    public const string DEFAULT_STATE_FILE_NAME = '.issues-sentry-forward.json';

    /** PSR-3 levels by severity */
    public const array LEVEL_SEVERITIES = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    public const int DEFAULT_MAX_EVENTS_PER_RUN = 1000;

    protected SentryEventBuilder $sentryEventBuilder;

    public function __construct(
        protected SentryEnvelopeSender $sentryEnvelopeSender,
        protected string $stateFilePath,
        protected string $minimumLevel = LogLevel::ERROR,
        protected int $maxEventsPerRun = self::DEFAULT_MAX_EVENTS_PER_RUN,
    ) {
        $this->sentryEventBuilder = new SentryEventBuilder();
    }

    /**
     * One pass over the files (missing ones are skipped).
     */
    public function forwardFiles(bool $fromStart, string ...$filePaths): IssuesSentryForwardResult
    {
        $result = new IssuesSentryForwardResult();
        $lockHandle = fopen($this->stateFilePath . '.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            // another forwarder works on these files
            return $result;
        }
        try {
            $issuesLogFileOffsets = $this->readState();
            foreach ($filePaths as $filePath) {
                if (!is_file($filePath)) {
                    continue;
                }
                $result->filesRead++;
                $issuesLogFileOffset = $this->forwardFile($filePath, $issuesLogFileOffsets->getForFile($filePath), $fromStart, $result);
                $issuesLogFileOffsets->setForFile($issuesLogFileOffset);
                $this->writeState($issuesLogFileOffsets);
                if ($result->stoppedForRetry || $result->eventsSent + $result->eventsRejected >= $this->maxEventsPerRun) {
                    break;
                }
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
        return $result;
    }

    /**
     * Forwards the new complete lines of one file and returns the new position.
     */
    protected function forwardFile(
        string $filePath,
        ?IssuesLogFileOffset $previousOffset,
        bool $fromStart,
        IssuesSentryForwardResult $result
    ): IssuesLogFileOffset {
        clearstatcache(true, $filePath);
        $inode = (int)fileinode($filePath);
        $size = (int)filesize($filePath);
        if ($previousOffset === null) {
            $offset = $fromStart ? 0 : $size;
        } elseif ($previousOffset->inode !== $inode || $previousOffset->offset > $size) {
            // rotated or truncated
            $offset = 0;
        } else {
            $offset = $previousOffset->offset;
        }
        $fileHandle = fopen($filePath, 'r');
        if ($fileHandle === false) {
            return new IssuesLogFileOffset($filePath, $inode, $offset);
        }
        try {
            fseek($fileHandle, $offset);
            while (($line = fgets($fileHandle)) !== false) {
                if (!str_ends_with($line, "\n")) {
                    // still being written
                    break;
                }
                $outcome = $this->forwardLine(trim($line), $result);
                if ($outcome === SentryEnvelopeSender::OUTCOME_RETRY) {
                    $result->stoppedForRetry = true;
                    break;
                }
                $offset += strlen($line);
                if ($result->eventsSent + $result->eventsRejected >= $this->maxEventsPerRun) {
                    break;
                }
            }
        } finally {
            fclose($fileHandle);
        }
        return new IssuesLogFileOffset($filePath, $inode, $offset);
    }

    /** @return string|null The send outcome; null = skipped */
    protected function forwardLine(string $jsonLine, IssuesSentryForwardResult $result): ?string
    {
        if ($jsonLine === '' || !$this->isForwarded($jsonLine)) {
            $result->linesSkipped++;
            return null;
        }
        $eventJson = $this->sentryEventBuilder->buildEventJson($jsonLine);
        if ($eventJson === null) {
            $result->linesSkipped++;
            return null;
        }
        $outcome = $this->sentryEnvelopeSender->sendEvent($this->sentryEventBuilder->buildEventIdFromJsonLine($jsonLine), $eventJson);
        if ($outcome === SentryEnvelopeSender::OUTCOME_SENT) {
            $result->eventsSent++;
        } elseif ($outcome === SentryEnvelopeSender::OUTCOME_REJECTED) {
            $result->eventsRejected++;
        }
        return $outcome;
    }

    /** Level at or above the threshold, or an uncaught error */
    public function isForwarded(string $jsonLine): bool
    {
        $fields = json_decode($jsonLine, true);
        if (!is_array($fields)) {
            return false;
        }
        if (($fields['error.handled'] ?? $fields['error']['handled'] ?? null) === false) {
            return true;
        }
        $level = strtolower((string)($fields['log.level'] ?? $fields['log']['level'] ?? ''));
        return (self::LEVEL_SEVERITIES[$level] ?? -1) >= (self::LEVEL_SEVERITIES[strtolower($this->minimumLevel)] ?? self::LEVEL_SEVERITIES[LogLevel::ERROR]);
    }

    protected function readState(): IssuesLogFileOffsets
    {
        return IssuesLogFileOffsets::fromStateJson(is_file($this->stateFilePath) ? (string)file_get_contents($this->stateFilePath) : '');
    }

    /** Atomic write */
    protected function writeState(IssuesLogFileOffsets $issuesLogFileOffsets): void
    {
        $temporaryPath = $this->stateFilePath . '.' . getmypid() . '.tmp';
        file_put_contents($temporaryPath, $issuesLogFileOffsets->toStateJson());
        rename($temporaryPath, $this->stateFilePath);
    }
}
