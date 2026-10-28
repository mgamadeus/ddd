<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Services\IssuesLogService;
use Elastic\Monolog\Formatter\ElasticCommonSchemaFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

/**
 * Real issues lines for the backend tests: the throwables logged through the issues write path (IssuesLogService +
 * the ECS formatter) into a scratch file, returned line by line.
 */
class IssuesLogLineWriter
{
    /**
     * @param array<string, mixed> $additionalContext Context of every line (route_name, account.id, …)
     * @return string[] The JSON lines
     */
    public static function writeLines(RouterInterface $router, array $additionalContext, Throwable ...$throwables): array
    {
        return self::writeLinesForWorkspace(null, $router, $additionalContext, ...$throwables);
    }

    /**
     * @param array<string, mixed> $additionalContext
     * @return string[]
     */
    public static function writeLinesForWorkspace(?string $workspace, RouterInterface $router, array $additionalContext, Throwable ...$throwables): array
    {
        $logFile = sys_get_temp_dir() . '/ddd-issues-lines-' . bin2hex(random_bytes(6)) . '.log';
        $streamHandler = new StreamHandler($logFile);
        $streamHandler->setFormatter(new ElasticCommonSchemaFormatter());
        IssuesLogService::setSettings(new IssuesLogSettings(serviceName: 'ddd-it', release: 'r-test', workspace: $workspace));
        try {
            $issuesLogService = new IssuesLogService(new Logger('issues', [$streamHandler]), new RequestStack(), $router);
            foreach ($throwables as $throwable) {
                $issuesLogService->logThrowable($throwable, additionalContext: $additionalContext, section: IssuesLogService::LOG_MESSAGE_SECTION_CLI);
            }
        } finally {
            IssuesLogService::setSettings(null);
        }
        $jsonLines = array_values(array_filter(explode("\n", (string)file_get_contents($logFile))));
        unlink($logFile);
        return $jsonLines;
    }
}
