<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Issues;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Services\IssueGroupsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Logging\Query\IssueEvent;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base of the issues registry commands: `--format=json` (stdout carries only the JSON, messages go to stderr), relative
 * or absolute time options, the group lookup by hash. The registry tables are admin-only; the commands run without
 * rights restrictions (the operator of the console is trusted).
 */
abstract class IssuesCommand extends Command
{
    public const string OPTION_FORMAT = 'format';
    public const string FORMAT_TEXT = 'text';
    public const string FORMAT_JSON = 'json';
    public const string OPTION_ENVIRONMENT = 'environment';

    /** The command's work, run without rights restrictions; invalid input and unknown groups end as INVALID */
    abstract protected function executeIssuesCommand(InputInterface $input, OutputInterface $output): int;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->executeIssuesCommand($input, $output);
        } catch (BadRequestException|NotFoundException $exception) {
            $this->getErrorOutput($output)->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    protected function addFormatOption(): static
    {
        $this->addOption(self::OPTION_FORMAT, null, InputOption::VALUE_REQUIRED, 'text or json (stdout carries only the JSON)', self::FORMAT_TEXT);
        return $this;
    }

    protected function addEnvironmentOption(string $description = 'Registry environment (a key of ISSUES_QUERY_ENVIRONMENTS, e.g. prod, testing, mgn)'): static
    {
        $this->addOption(self::OPTION_ENVIRONMENT, null, InputOption::VALUE_REQUIRED, $description);
        return $this;
    }

    protected function getEnvironmentOption(InputInterface $input): ?string
    {
        $environment = $input->hasOption(self::OPTION_ENVIRONMENT) ? $input->getOption(self::OPTION_ENVIRONMENT) : null;
        return $environment !== null && trim((string)$environment) !== '' ? trim((string)$environment) : null;
    }

    /**
     * The group payload with `environment` and `workspace` always present.
     *
     * @return array<string, mixed> json_encode input only
     */
    protected function toGroupPayload(IssueGroup $issueGroup): array
    {
        $groupPayload = $this->toPayload($issueGroup);
        unset($groupPayload['issues']);
        return $groupPayload + ['environment' => null, 'workspace' => null];
    }

    /**
     * An event as JSON: its raw line (all fields, e.g. the stack trace) with `issues.environment` and
     * `issues.workspace` in front; the parsed fields when the backend has no raw line.
     *
     * @return array<string, mixed> json_encode input only
     */
    protected function toEventPayload(IssueEvent $issueEvent, ?string $environment): array
    {
        $rawFields = json_decode($issueEvent->rawJson, true);
        if (!is_array($rawFields)) {
            $rawFields = $this->toPayload($issueEvent);
            unset($rawFields['rawJson']);
        }
        return ['issues.environment' => $environment, 'issues.workspace' => $issueEvent->workspace] + $rawFields;
    }

    protected function isJsonFormat(InputInterface $input): bool
    {
        return $input->hasOption(self::OPTION_FORMAT) && $input->getOption(self::OPTION_FORMAT) === self::FORMAT_JSON;
    }

    protected function writeJson(OutputInterface $output, mixed $payload): void
    {
        $output->writeln((string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function getErrorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /** @return array<string, mixed> The serialised object (public properties, nulls dropped) — json_encode input only */
    protected function toPayload(DefaultObject $object): array
    {
        return (array)$object->toObject(true, false, [], false, true, false);
    }

    /**
     * A point in time: relative to now (`30m`, `24h`, `7d`) or absolute (`2026-10-28 12:00`).
     *
     * @throws BadRequestException
     */
    protected function parseTime(?string $value, ?DateTime $now = null): ?DateTime
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $now ??= DateTime::fromTimestamp(time());
        if (preg_match('/^(\d+)\s*([smhd])$/i', trim($value), $matches)) {
            $seconds = (int)$matches[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($matches[2])];
            return DateTime::fromTimestamp($now->getTimestamp() - $seconds);
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new BadRequestException(sprintf('Invalid time "%s" (use 30m, 24h, 7d or a date)', $value));
        }
        return DateTime::fromTimestamp($timestamp);
    }

    /**
     * The group of a hash in one environment; without an environment the only one — a hash registered in several
     * environments needs `--environment`.
     *
     * @throws NotFoundException
     * @throws BadRequestException The hash is registered in several environments
     */
    protected function getGroupByHash(string $groupHash, ?string $environment = null): IssueGroup
    {
        /** @var IssueGroupsService $issueGroupsService */
        $issueGroupsService = IssueGroup::getService();
        if ($environment !== null) {
            $issueGroup = $issueGroupsService->findByGroupHash($groupHash, $environment);
            if ($issueGroup === null) {
                throw new NotFoundException(sprintf('No issue group %s in environment %s', $groupHash, $environment));
            }
            return $issueGroup;
        }
        $issueGroups = $issueGroupsService->findGroupsByGroupHash($groupHash);
        if ($issueGroups->count() === 0) {
            throw new NotFoundException(sprintf('No issue group %s', $groupHash));
        }
        if ($issueGroups->count() > 1) {
            throw new BadRequestException(sprintf(
                'Issue group %s exists in several environments (%s): pass --environment',
                $groupHash,
                implode(', ', array_map(static fn(IssueGroup $issueGroup): string => (string)$issueGroup->environment, $issueGroups->getElements()))
            ));
        }
        return $issueGroups->first();
    }
}
