---
name: ddd-cli-command-specialist
description: Create Symfony console commands in the mgamadeus/ddd framework. Covers command structure, arguments/options, admin auth context setup, service access, output formatting, batch processing patterns, memory/time limits, and signal handling.
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD CLI Command Specialist

Symfony console commands within the DDD Core framework (`mgamadeus/ddd`).

## When to Use

- Creating new console commands for data processing, maintenance, or automation
- Implementing batch operations (imports, recalculations, migrations)
- Creating scheduled/cron-triggered jobs
- Understanding command structure and output patterns

## Namespace & Location

**Framework commands:** `DDD\Symfony\Commands\{Base|Common}\`
**Application commands:** `App\Symfony\Commands\{Domain}\`

**Directory structure:**
```
src/Symfony/Commands/
+-- Base/
|   +-- DoctrineModels/CreateDoctrineModels.php
|   +-- Messages/ProcessCLIMessage.php
+-- Common/
|   +-- Crons/CronsExecute.php, CronsList.php
```

Application commands follow the same pattern under `src/Symfony/Commands/{Domain}/`.

---

## Command Template

```php
<?php
declare(strict_types=1);

namespace {Namespace}\Symfony\Commands\{Domain};

use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:domain:action-name',
    description: 'Short description of what the command does'
)]
class ActionNameCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('worldId', null, InputOption::VALUE_REQUIRED, 'The World ID')
            ->addOption('dateFrom', null, InputOption::VALUE_OPTIONAL, 'Start date (Y-m-d)')
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Preview changes without applying');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(3600);

        // 1. Set up admin auth context for CLI operations
        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        if ($defaultAccount) {
            AuthService::instance()->setAccount($defaultAccount);
        }

        // 2. Parse input
        $worldId = (int) $input->getOption('worldId');
        $symfonyStyle = new SymfonyStyle($input, $output);

        // 3. Execute business logic via service
        try {
            /** @var MyService $myService */
            $myService = MyEntities::getService();
            $myService->throwErrors = true;
            $myService->doWork($worldId, $symfonyStyle);

            $symfonyStyle->success('Operation completed successfully.');
            return Command::SUCCESS;
        } catch (\Throwable $t) {
            $symfonyStyle->error($t->getMessage());
            return Command::FAILURE;
        }
    }
}
```

---

## Critical Patterns

### Admin Auth Context Setup

CLI commands run without an HTTP request, so there's no authenticated user. Most commands need admin privileges to access entities:

```php
$defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
if ($defaultAccount) {
    AuthService::instance()->setAccount($defaultAccount);
}
```

This loads a pre-configured admin account (from env/config) and sets it as the authenticated user for the command's execution context. **Without this, entity rights restrictions will block most queries.**

### Memory & Time Limits

Set appropriate limits based on the operation:

```php
ini_set('memory_limit', '1024M');   // Standard batch operations
ini_set('memory_limit', '2048M');   // Heavy operations (geo processing, large imports)
set_time_limit(3600);               // 1 hour for large batch jobs
set_time_limit(120);                // 2 minutes for quick operations
```

### Service Access

Use the same patterns as the rest of the framework:

```php
// Via Entity shorthand
$myService = MyEntities::getService();

// Via DDDService
/** @var MyService $myService */
$myService = DDDService::instance()->getService(MyService::class);
```

**Never** instantiate services directly with `new`.

---

## Arguments & Options

### Arguments (Positional, Required by Default)

```php
use Symfony\Component\Console\Input\InputArgument;

$this->addArgument('operation', InputArgument::REQUIRED, 'The operation to execute');
$this->addArgument('file', InputArgument::OPTIONAL, 'Optional file path');
```

### Options (Named, Prefixed with --)

```php
use Symfony\Component\Console\Input\InputOption;

// Required value
$this->addOption('worldId', null, InputOption::VALUE_REQUIRED, 'The World ID');

// Optional value with default
$this->addOption('dateFrom', null, InputOption::VALUE_OPTIONAL, 'Start date', date('Y-01-01'));

// Boolean flag (no value)
$this->addOption('dryRun', null, InputOption::VALUE_NONE, 'Preview without applying');

// With shortcut
$this->addOption('suite', 's', InputOption::VALUE_OPTIONAL, 'Test suite name');
```

### Reading Input

```php
$operation = $input->getArgument('operation');
$worldId = (int) $input->getOption('worldId');
$dateFrom = $input->getOption('dateFrom') ?? date('Y-01-01');
$dryRun = $input->getOption('dryRun');  // bool for VALUE_NONE
```

---

## Output Patterns

### SymfonyStyle (Preferred for Formatted Output)

```php
$symfonyStyle = new SymfonyStyle($input, $output);

$symfonyStyle->title('Command Title');
$symfonyStyle->section('Section Name');
$symfonyStyle->success('Operation completed.');
$symfonyStyle->error('Something failed.');
$symfonyStyle->warning('Check the results.');
$symfonyStyle->note('Additional info.');
$symfonyStyle->text('Regular text output.');
$symfonyStyle->newLine();
```

### Tables

```php
use Symfony\Component\Console\Helper\Table;

$table = new Table($output);
$table->setHeaders(['Name', 'Status', 'Created']);
foreach ($items as $item) {
    $table->addRow([$item->name, $item->status, $item->created?->format('Y-m-d H:i:s') ?? '-']);
}
$table->render();
```

### Progress Bars (for Batch Operations)

```php
$symfonyStyle->progressStart(count($items));
foreach ($items as $item) {
    // process item...
    $symfonyStyle->progressAdvance();
}
$symfonyStyle->progressFinish();
```

### Direct Output (Simple Status Messages)

```php
$output->writeln("Processing entity {$entity->id}...");
$output->writeln("<info>Done.</info>");
$output->writeln("<error>Failed: {$error}</error>");
```

### Passing Output to Services

Services can accept `OutputInterface` or `SymfonyStyle` to provide progress feedback during long operations:

```php
// In command
$myService->recalculateAll($worldId, $symfonyStyle);

// In service
public function recalculateAll(int $worldId, ?SymfonyStyle $output = null): void
{
    $entities = $this->findAll();
    $output?->progressStart($entities->count());
    foreach ($entities->getElements() as $entity) {
        // process...
        $output?->progressAdvance();
    }
    $output?->progressFinish();
}
```

---

## Common Command Types

### Data Processing / Recalculation

```php
#[AsCommand(name: 'app:recalculate-journals', description: 'Recalculates journals for current year')]
class RecalculateJournals extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('worldId', null, InputOption::VALUE_OPTIONAL, 'Limit to specific World')
            ->addOption('year', null, InputOption::VALUE_OPTIONAL, 'Year', date('Y'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');

        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        AuthService::instance()->setAccount($defaultAccount);

        $symfonyStyle = new SymfonyStyle($input, $output);
        $journalsService = Journals::getService();
        $journalsService->recalculateForYear(
            (int) $input->getOption('year'),
            $input->getOption('worldId') ? (int) $input->getOption('worldId') : null,
            $symfonyStyle
        );

        return Command::SUCCESS;
    }
}
```

### Scheduled / Cron Jobs

```php
#[AsCommand(name: 'app:send-scheduled-notifications', description: 'Sends pending scheduled notifications')]
class SendScheduledNotifications extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');

        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        AuthService::instance()->setAccount($defaultAccount);

        $notificationsService = Notifications::getService();
        $notificationsService->sendScheduledNotifications();

        return Command::SUCCESS;
    }
}
```

### Import / Migration

```php
#[AsCommand(name: 'app:import-data', description: 'Imports data from config or external source')]
class ImportData extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('operation', InputArgument::REQUIRED, 'Import operation')
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Preview only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(3600);

        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        AuthService::instance()->setAccount($defaultAccount);

        $operation = $input->getArgument('operation');
        $symfonyStyle = new SymfonyStyle($input, $output);

        match ($operation) {
            'allergens' => Allergens::getService()->importFromConfig(),
            'ingredients' => Ingredients::getService()->importFromConfig(Allergens::getService()),
            default => $symfonyStyle->error("Unknown operation: {$operation}"),
        };

        $symfonyStyle->success("Import '{$operation}' completed.");
        return Command::SUCCESS;
    }
}
```

### Async Dispatch (Trigger Background Processing)

```php
#[AsCommand(name: 'app:process-geo-hashes', description: 'Dispatches geo hash processing for tracks')]
class ProcessGeoHashes extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');

        $defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        AuthService::instance()->setAccount($defaultAccount);

        // Dispatch async -- the actual work happens in message handlers
        $tracksService = Tracks::getService();
        $tracksService->processGeoHashesForTracks(async: true);

        $output->writeln('<info>Async processing dispatched.</info>');
        return Command::SUCCESS;
    }
}
```

---

## Advanced Patterns

### Signal Handling for Graceful Interruption

For long-running batch operations, handle SIGINT for clean shutdown:

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $interrupted = false;
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGINT, function () use (&$interrupted, $output) {
            $output->writeln("\n<comment>Interrupt received, finishing current item...</comment>");
            $interrupted = true;
        });
    }

    foreach ($items as $item) {
        if ($interrupted) {
            break;
        }
        // process item...
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    return Command::SUCCESS;
}
```

### Distributed Execution via Fraction Parameter

For commands that run on multiple servers or schedules:

```php
$this->addOption('fraction', null, InputOption::VALUE_OPTIONAL, 'Fraction of items to process (0.0-1.0)', '1');

// In execute:
$fraction = (float) $input->getOption('fraction');
$totalConnections = count($connections);
$connectionsToProcess = (int) ceil($totalConnections * $fraction);
$connections = array_slice($connections, 0, $connectionsToProcess);
```

Run `* * * * * app:import --fraction=0.25` four times per hour to cover all items.

---

## Framework-Provided Commands

The DDD Core framework ships these commands:

| Command | Purpose |
|---------|---------|
| `app:generate-doctrine-models-for-entities` | Generates `DB*Model.php` Doctrine model classes from entity attributes |
| `app:process-cli-message` | Processes a serialized `AppMessage` via CLI (used for cross-workspace message handling) |
| `app:crons:execute` | Executes all cron jobs that are due |
| `app:crons:list` | Lists all registered cron jobs with status |

---

## Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Command name | `app:{domain}:{action}` | `app:challenges:recalculate-journals` |
| Class name | PascalCase action | `RecalculateJournals` |
| Options | camelCase | `--worldId`, `--dateFrom`, `--dryRun` |
| Arguments | camelCase | `operation`, `filePath` |

---

## Checklist

- [ ] Uses `#[AsCommand]` attribute with `name` and `description`
- [ ] Sets `memory_limit` and `set_time_limit()` appropriate to the operation
- [ ] Sets admin auth context via `DDDService::instance()->getDefaultAccountForCliOperations()`
- [ ] Uses `SymfonyStyle` for formatted output
- [ ] Returns `Command::SUCCESS` or `Command::FAILURE`
- [ ] Services accessed via service locator (never `new`)
- [ ] Long batch operations use progress bars
- [ ] Error handling with try/catch and formatted error output
- [ ] Never use `private` -- always `protected`
