<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Crons;

use Cron\CronExpression;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Validation\ValidationErrors;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Creates or updates ONE Cron row from the console — the operator's way to register a scheduled command without the
 * admin UI or a hand-written INSERT. Addressed by the Cron's unique `name`: absent → created (description, schedule
 * and command required), present → only the options passed are changed. Every write goes through the entity's own
 * validation (cron expression grammar, the command must exist in THIS console, description length), so a typo never
 * reaches the table; `--dryRun` validates and prints what would be written without persisting. A new or re-scheduled
 * Cron gets its next execution computed from the expression right away, so the next `app:crons:execute` tick picks it up.
 */
#[AsCommand(
    name: 'app:crons:upsert',
    description: 'Create or update one Cron (by unique name): schedule, command, description, active state — validated, --dryRun supported',
)]
class CronsUpsert extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The Cron\'s unique name (the handle; created when absent)')
            ->addOption('description', null, InputOption::VALUE_OPTIONAL, 'What the Cron does (min 16 characters; required on create)')
            ->addOption('schedule', null, InputOption::VALUE_OPTIONAL, 'Cron expression, e.g. "*/5 * * * *" (required on create)')
            ->addOption('command', null, InputOption::VALUE_OPTIONAL, 'The console command incl. arguments, e.g. "app:ai-agent:reap" (required on create; must exist)')
            ->addOption('active', null, InputOption::VALUE_REQUIRED, 'Active state: 1|0 / yes|no (default on create: active)')
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Validate and show the resulting Cron without writing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(120);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $defaultAdminAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        if ($defaultAdminAccount) {
            AuthService::instance()->setAccount($defaultAdminAccount);
        }
        putenv('SYMFONY_DEPRECATIONS_HELPER=disabled');

        $name = trim((string)$input->getOption('name'));
        if ($name === '') {
            $symfonyStyle->error('--name is required.');
            return Command::FAILURE;
        }
        $description = $input->getOption('description');
        $schedule = $input->getOption('schedule');
        $command = $input->getOption('command');
        $activeRaw = $input->getOption('active');
        $active = $this->parseActive($activeRaw);
        if ($activeRaw !== null && $active === null) {
            $symfonyStyle->error('--active takes 1|0 or yes|no.');
            return Command::FAILURE;
        }

        try {
            $cronsService = Crons::getService();
            $cronsService->throwErrors = true;
            $cron = $cronsService->findByName($name);
            $isNew = !$cron instanceof Cron;
            if ($isNew) {
                $missing = array_keys(array_filter(
                    ['description' => $description, 'schedule' => $schedule, 'command' => $command],
                    static fn ($value) => $value === null || trim((string)$value) === ''
                ));
                if ($missing !== []) {
                    $symfonyStyle->error("Cron '$name' does not exist — creating it needs --" . implode(', --', $missing) . '.');
                    return Command::FAILURE;
                }
                $cron = new Cron();
                $cron->name = $name;
                $cron->isActive = true;
            }
            $scheduleChanged = false;
            if ($description !== null) {
                $cron->description = trim((string)$description);
            }
            if ($schedule !== null && trim((string)$schedule) !== ($cron->schedule ?? null)) {
                $cron->schedule = trim((string)$schedule);
                $scheduleChanged = true;
            }
            if ($command !== null) {
                $cron->command = trim((string)$command);
            }
            if (is_bool($active)) {
                $cron->isActive = $active;
            }

            // The entity's own constraints decide (expression grammar, command exists in this console, description length).
            // validate() hands back TRUE, the collected errors, or FALSE for an object it refuses to validate at all.
            // It returns the ValidationErrors instance it was GIVEN even when that instance stays empty, so the
            // argument has to be null here and the result compared against true — never against emptiness.
            $validationErrors = null;
            $validation = $cron->validate($validationErrors);
            if ($validation !== true) {
                $symfonyStyle->error("Cron '$name' is invalid — nothing written.");
                if ($validation instanceof ValidationErrors) {
                    $this->renderValidationErrors($symfonyStyle, $validation);
                }
                return Command::FAILURE;
            }
            if ($isNew || $scheduleChanged) {
                // A new or re-scheduled Cron is due at its NEXT slot — never "immediately" and never at a stale slot of
                // the old expression. Cron::getNextScheduledExecution() is deliberately NOT used: it keeps an existing
                // future value, which is exactly wrong after a re-schedule.
                $cronExpression = new CronExpression($cron->schedule);
                $cron->nextExecutionScheduledAt = DateTime::fromTimestamp($cronExpression->getNextRunDate()->getTimestamp());
            }

            if ($input->getOption('dryRun')) {
                $symfonyStyle->note(($isNew ? 'DRY RUN — would CREATE Cron ' : 'DRY RUN — would UPDATE Cron ') . "'$name':");
                $this->renderCron($symfonyStyle, $cron);
                return Command::SUCCESS;
            }
            $cron = $cron->update();
            $symfonyStyle->success(($isNew ? 'Created' : 'Updated') . " Cron '$name' (id {$cron->id}).");
            $this->renderCron($symfonyStyle, $cron);
            return Command::SUCCESS;
        } catch (BadRequestException $badRequestException) {
            $symfonyStyle->error("Cron '$name' was refused: " . $badRequestException->getMessage());
            if (isset($badRequestException->validationErrors) && $badRequestException->validationErrors instanceof ValidationErrors) {
                $this->renderValidationErrors($symfonyStyle, $badRequestException->validationErrors);
            }
            return Command::FAILURE;
        } catch (Throwable $t) {
            $symfonyStyle->error(get_class($t) . ': ' . $t->getMessage());
            return Command::FAILURE;
        }
    }

    /** 1|0|yes|no|true|false|on|off → bool; null when the option was not passed OR the value is unparseable (the caller tells the two apart). */
    protected function parseActive(mixed $raw): ?bool
    {
        if ($raw === null) {
            return null;
        }
        $normalized = strtolower(trim((string)$raw));
        if (in_array($normalized, ['1', 'yes', 'true', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'no', 'false', 'off'], true)) {
            return false;
        }
        return null;
    }

    protected function renderCron(SymfonyStyle $symfonyStyle, Cron $cron): void
    {
        $symfonyStyle->table(
            ['Field', 'Value'],
            [
                ['name', $cron->name],
                ['description', $cron->description ?? ''],
                ['schedule', $cron->schedule ?? ''],
                ['command', $cron->command ?? ''],
                ['active', $cron->isActive ? 'yes' : 'no'],
                ['next execution', isset($cron->nextExecutionScheduledAt) ? $cron->nextExecutionScheduledAt->format('Y-m-d H:i:s') : '-'],
                ['last execution', isset($cron->lastExecutionStartedAt) ? $cron->lastExecutionStartedAt->format('Y-m-d H:i:s') : '-'],
            ]
        );
    }

    protected function renderValidationErrors(SymfonyStyle $symfonyStyle, ValidationErrors $validationErrors): void
    {
        $rows = [];
        foreach ($validationErrors->getElements() as $validationError) {
            foreach ($validationError->getElements() as $validationResult) {
                $rows[] = [
                    ($validationError->jsonPath ?? '') . '.' . ($validationResult->propertyName ?? '?'),
                    $validationResult->errorMessage ?? '',
                    (string)($validationResult->receivedValue ?? ''),
                ];
            }
        }
        if ($rows === []) {
            $rows[] = ['-', 'validation failed (no detail available)', ''];
        }
        $symfonyStyle->table(['Property', 'Problem', 'Received'], $rows);
    }
}
