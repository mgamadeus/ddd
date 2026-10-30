<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Issues;

use DDD\Domain\Common\Entities\Issues\Client\ClientIssueContext;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvent;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrame;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueReport;
use DDD\Infrastructure\Logging\ClientIssuesFingerprintBuilder;
use DDD\Infrastructure\Logging\IssuesFingerprintBuilder;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Emits one test error into the issues channel, to verify the sink and the collector end to end. The error is the same
 * root cause on every run (its hashes carry no message and no line), so a backend or registry sees one group.
 */
#[AsCommand(
    name: 'app:issues:test',
    description: 'Emits a test error into the issues log (with --uncaught through the console capture, with --client as a browser error of section WEB)',
)]
class IssuesTest extends Command
{
    public const string TEST_MESSAGE = 'Issues log test error (app:issues:test)';
    public const string CLIENT_TEST_NAME = 'TypeError';
    public const string CLIENT_TEST_MESSAGE = 'Issues log test client error (app:issues:test --client)';
    public const string CLIENT_TEST_FILE = '/_next/static/chunks/app/issues-test/page-0123456789abcdef.js';
    public const string CLIENT_TEST_ROUTE_PATTERN = '/issues-test';

    public function __construct(protected IssuesLogService $issuesLogService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('uncaught', null, InputOption::VALUE_NONE, 'Throw the error instead of logging it: the console capture logs it')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Level of the logged test error', LogLevel::ERROR)
            ->addOption('client', null, InputOption::VALUE_NONE, 'Log a synthetic browser error (section WEB) through logClientIssue()');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = IssuesLogService::getSettings();
        $output->writeln('target: ' . ($settings->target ?? 'unset (the application\'s own handlers)'));
        if ($settings->target === 'file') {
            $output->writeln('file: ' . $settings->getFilePath((string)($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod')));
        }
        $output->writeln('capture: ' . ($settings->captureEnabled ? 'on' : 'off'));

        if ($input->getOption('client')) {
            return $this->logClientTestIssue($output);
        }
        $testException = $this->createTestException();
        $fingerprintBuilder = new IssuesFingerprintBuilder(DDDService::instance()->getRootDir());
        $output->writeln('error.group_hash: ' . $fingerprintBuilder->buildGroupHash($testException));

        if ($input->getOption('uncaught')) {
            throw $testException;
        }
        $this->issuesLogService->logThrowable(
            $testException,
            (string)$input->getOption('level'),
            ['issues_test' => true],
            section: IssuesLogService::LOG_MESSAGE_SECTION_CLI
        );
        $output->writeln('logged');
        return Command::SUCCESS;
    }

    /** One synthetic WEB event through the client ingest; its group hash is the same on every run */
    protected function logClientTestIssue(OutputInterface $output): int
    {
        $clientIssueReport = $this->createClientTestReport();
        $clientIssuesFingerprintBuilder = new ClientIssuesFingerprintBuilder();
        $output->writeln('section: ' . IssuesLogService::LOG_MESSAGE_SECTION_WEB);
        $output->writeln('error.group_hash: ' . $clientIssuesFingerprintBuilder->buildGroupHash(
            IssuesLogService::LOG_MESSAGE_SECTION_WEB,
            $clientIssueReport->events->first()
        ));
        $clientIssueIngestResult = $this->issuesLogService->logClientIssue($clientIssueReport, IssuesLogService::LOG_MESSAGE_SECTION_WEB);
        $output->writeln(sprintf('accepted: %d, dropped: %d%s', $clientIssueIngestResult->accepted, $clientIssueIngestResult->dropped,
            $clientIssueIngestResult->dropReasons !== [] ? ' (' . implode(', ', $clientIssueIngestResult->dropReasons) . ')' : ''));
        return $clientIssueIngestResult->accepted === 1 ? Command::SUCCESS : Command::FAILURE;
    }

    protected function createClientTestReport(): ClientIssueReport
    {
        $clientIssueReport = new ClientIssueReport();
        $clientIssueReport->client->runtime = ClientIssueContext::RUNTIME_BROWSER;
        $clientIssueReport->client->app = 'issues-test';
        $clientIssueEvent = new ClientIssueEvent();
        $clientIssueEvent->occurredAt = gmdate('Y-m-d\TH:i:s\Z');
        $clientIssueEvent->name = self::CLIENT_TEST_NAME;
        $clientIssueEvent->message = self::CLIENT_TEST_MESSAGE;
        $clientIssueEvent->routePattern = self::CLIENT_TEST_ROUTE_PATTERN;
        $clientIssueEvent->url = 'https://example.invalid' . self::CLIENT_TEST_ROUTE_PATTERN;
        $clientIssueFrame = new ClientIssueFrame();
        $clientIssueFrame->file = self::CLIENT_TEST_FILE;
        $clientIssueFrame->line = 1;
        $clientIssueFrame->column = 42;
        $clientIssueFrame->function = 'IssuesTestPage';
        $clientIssueFrame->inApp = true;
        $clientIssueEvent->frames->add($clientIssueFrame);
        $clientIssueReport->events->add($clientIssueEvent);
        return $clientIssueReport;
    }

    protected function createTestException(): RuntimeException
    {
        return new RuntimeException(self::TEST_MESSAGE);
    }
}
