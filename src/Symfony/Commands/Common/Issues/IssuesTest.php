<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Issues;

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
    description: 'Emits a test error into the issues log (with --uncaught through the console capture)',
)]
class IssuesTest extends Command
{
    public const string TEST_MESSAGE = 'Issues log test error (app:issues:test)';

    public function __construct(protected IssuesLogService $issuesLogService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('uncaught', null, InputOption::VALUE_NONE, 'Throw the error instead of logging it: the console capture logs it')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Level of the logged test error', LogLevel::ERROR);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = IssuesLogService::getSettings();
        $output->writeln('target: ' . ($settings->target ?? 'unset (the application\'s own handlers)'));
        if ($settings->target === 'file') {
            $output->writeln('file: ' . $settings->getFilePath((string)($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod')));
        }
        $output->writeln('capture: ' . ($settings->captureEnabled ? 'on' : 'off'));

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

    protected function createTestException(): RuntimeException
    {
        return new RuntimeException(self::TEST_MESSAGE);
    }
}
