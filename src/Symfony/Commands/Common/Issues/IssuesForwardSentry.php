<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Issues;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Sentry\IssuesSentryForwarder;
use DDD\Infrastructure\Logging\Sentry\IssuesSentryForwardResult;
use DDD\Infrastructure\Logging\Sentry\SentryDsn;
use DDD\Infrastructure\Logging\Sentry\SentryEnvelopeSender;
use DDD\Infrastructure\Services\IssuesLogService;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Forwards the error events of the issues log files to Sentry ({@see IssuesSentryForwarder}). Runs where the files
 * are — a sidecar / cron next to the application, one per pod or host — never in the request. The files are the
 * `ISSUES_LOG_TARGET=file` files of this environment (all days with `{date}`), or `--file`.
 */
#[AsCommand(
    name: 'app:issues:forward-sentry',
    description: 'Forwards the error events of the issues log files to Sentry (ISSUES_SENTRY_DSN), asynchronously',
)]
class IssuesForwardSentry extends IssuesCommand
{
    public const int DEFAULT_INTERVAL_SECONDS = 10;

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Log file(s) to forward (default: the issues log files)')
            ->addOption('state-file', null, InputOption::VALUE_REQUIRED, 'Where the read positions are kept (default: next to the log files)')
            ->addOption('min-level', null, InputOption::VALUE_REQUIRED, 'Lowest level forwarded (uncaught errors always)', LogLevel::ERROR)
            ->addOption('from-start', null, InputOption::VALUE_NONE, 'Files seen for the first time are read from their start (default: from their end)')
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Run forever, one pass per interval')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between two passes with --loop', (string)self::DEFAULT_INTERVAL_SECONDS)
            ->addFormatOption();
    }

    protected function executeIssuesCommand(InputInterface $input, OutputInterface $output): int
    {
        $issuesRegistrySettings = IssuesRegistrySettings::fromEnvironment();
        if ($issuesRegistrySettings->sentryDsn === null) {
            $this->getErrorOutput($output)->writeln('<error>No Sentry DSN: set ISSUES_SENTRY_DSN (or SENTRY_DSN)</error>');
            return Command::INVALID;
        }
        try {
            $sentryDsn = SentryDsn::fromString($issuesRegistrySettings->sentryDsn);
        } catch (InternalErrorException $exception) {
            $this->getErrorOutput($output)->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }
        $issuesLogSettings = IssuesLogService::getSettings();
        $explicitFilePaths = (array)$input->getOption('file');
        if ($explicitFilePaths === [] && $issuesLogSettings->target !== IssuesLogSettings::TARGET_FILE) {
            $this->getErrorOutput($output)->writeln('<error>ISSUES_LOG_TARGET is not "file": name the files with --file</error>');
            return Command::INVALID;
        }
        $stateFilePath = (string)($input->getOption('state-file')
            ?? dirname($explicitFilePaths[0] ?? $issuesLogSettings->getFilePath($this->getEnvironment())) . '/' . IssuesSentryForwarder::DEFAULT_STATE_FILE_NAME);
        $issuesSentryForwarder = new IssuesSentryForwarder(
            new SentryEnvelopeSender($sentryDsn),
            $stateFilePath,
            strtolower((string)$input->getOption('min-level')),
        );
        $intervalSeconds = max(1, (int)$input->getOption('interval'));
        do {
            $startedAt = time();
            $filePaths = $explicitFilePaths !== [] ? $explicitFilePaths : $this->findIssuesLogFiles($issuesLogSettings);
            $issuesSentryForwardResult = $issuesSentryForwarder->forwardFiles((bool)$input->getOption('from-start'), ...$filePaths);
            $this->writeResult($input, $output, $issuesSentryForwardResult);
            if ($input->getOption('loop')) {
                sleep(max(1, $intervalSeconds - (time() - $startedAt)));
            }
        } while ($input->getOption('loop'));
        return $issuesSentryForwardResult->stoppedForRetry ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * This environment's issues log files: with a `{date}` file name the files of the last two days.
     *
     * @return string[]
     */
    protected function findIssuesLogFiles(IssuesLogSettings $issuesLogSettings): array
    {
        if (!$issuesLogSettings->isDateRotated()) {
            return [$issuesLogSettings->getFilePath($this->getEnvironment())];
        }
        return [
            $issuesLogSettings->getFilePath($this->getEnvironment(), date('Y-m-d', time() - 86400)),
            $issuesLogSettings->getFilePath($this->getEnvironment()),
        ];
    }

    protected function getEnvironment(): string
    {
        return (string)($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod');
    }

    protected function writeResult(InputInterface $input, OutputInterface $output, IssuesSentryForwardResult $issuesSentryForwardResult): void
    {
        if ($this->isJsonFormat($input)) {
            $this->writeJson($output, $this->toPayload($issuesSentryForwardResult));
            return;
        }
        $output->writeln(sprintf(
            '%d files, %d events sent, %d rejected, %d lines skipped%s',
            $issuesSentryForwardResult->filesRead,
            $issuesSentryForwardResult->eventsSent,
            $issuesSentryForwardResult->eventsRejected,
            $issuesSentryForwardResult->linesSkipped,
            $issuesSentryForwardResult->stoppedForRetry ? ' (Sentry asked to retry: the rest follows next pass)' : ''
        ));
    }
}
