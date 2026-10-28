<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use Elastic\Monolog\Formatter\ElasticCommonSchemaFormatter;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * The sink of the `issues` channel, registered by the DDD bundle at a high priority, so it sees every issues record
 * first ({@see \DDD\DDDBundle::build()}). Configured by {@see IssuesLogSettings} (environment, read at runtime):
 *
 * - target unset: handles nothing — the records reach the application's own handlers as before;
 * - `file`: one ECS JSON line per record (Monolog's StreamHandler with `flock()`, the Elastic Common Schema formatter —
 *   the same line a `stream` handler with `elastic/ecs-logging` writes) into {@see IssuesLogSettings::getFilePath()};
 *   a `{date}` file name starts a new file every day, there is no size rotation;
 * - `stderr`: the same line on the process's stderr;
 * - `none`: the records are dropped.
 *
 * The records stop here (no bubbling into the application's handlers). A write that fails (disk full, missing
 * directory) drops the record — the request never waits for or fails on the log.
 */
class IssuesLogSinkHandler extends AbstractHandler
{
    protected ?IssuesLogSettings $settings = null;

    protected ?StreamHandler $streamHandler = null;

    protected ?string $streamHandlerPath = null;

    public function __construct(
        #[Autowire('%kernel.environment%')]
        protected string $kernelEnvironment = 'prod',
        #[Autowire('%kernel.logs_dir%')]
        protected string $logsDirectory = '',
    ) {
        parent::__construct(Level::Debug, false);
    }

    /**
     * Replaces the settings read from the environment (tests). Not a constructor argument: the container would autowire
     * an empty settings object into it.
     */
    public function setSettings(?IssuesLogSettings $settings): static
    {
        $this->settings = $settings;
        $this->close();
        return $this;
    }

    public function getSettings(): IssuesLogSettings
    {
        return $this->settings ??= IssuesLogSettings::fromEnvironment($this->logsDirectory . '/issues');
    }

    public function isHandling(LogRecord $record): bool
    {
        $settings = $this->getSettings();
        if ($settings->target === null) {
            return false;
        }
        return Level::fromName($settings->level)->includes($record->level);
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }
        $settings = $this->getSettings();
        if ($settings->target === IssuesLogSettings::TARGET_NONE) {
            return true;
        }
        try {
            $this->getStreamHandler()->handle($record);
        } catch (Throwable $throwable) {
            error_log('IssuesLogSinkHandler: dropped an issues record: ' . $throwable->getMessage());
        }
        return true;
    }

    /** The stream of the current target (for a date-rotated file: today's file) */
    protected function getStreamHandler(): StreamHandler
    {
        $settings = $this->getSettings();
        $path = $settings->target === IssuesLogSettings::TARGET_STDERR
            ? 'php://stderr'
            : $settings->getFilePath($this->kernelEnvironment);
        if ($this->streamHandler === null || $this->streamHandlerPath !== $path) {
            $this->streamHandler?->close();
            if ($settings->target === IssuesLogSettings::TARGET_FILE && !is_dir(dirname($path))) {
                @mkdir(dirname($path), 0775, true);
            }
            $this->streamHandler = new StreamHandler(
                $path,
                Level::fromName($settings->level),
                false,
                null,
                $settings->target === IssuesLogSettings::TARGET_FILE && $settings->useLocking
            );
            $this->streamHandler->setFormatter(new ElasticCommonSchemaFormatter());
            $this->streamHandlerPath = $path;
        }
        return $this->streamHandler;
    }

    public function close(): void
    {
        $this->streamHandler?->close();
        $this->streamHandler = null;
        parent::close();
    }
}
