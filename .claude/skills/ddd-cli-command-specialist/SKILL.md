---
name: ddd-cli-command-specialist
description: "Create Symfony console commands in the mgamadeus/ddd framework AND use its built-in CLI commands: app:db:read (read-only SQL to inspect stored rows from the terminal — no SQL client, no raw PDO one-liners), app:entity:show-sql (generated CREATE TABLE/index/FK DDL), app:entity:list, app:generate-doctrine-models-for-entities, app:process-cli-message, app:crons:list, app:crons:execute, app:crons:upsert (register or change a cron row, validated, with --dryRun) and app:crons:executions (what ran, what failed, captured output). Covers command structure, arguments/options, admin auth context setup, service access, output formatting (SymfonyStyle, tables, progress bars), batch processing, memory/time limits, signal handling, and fraction-based distributed execution. Use when writing a console command, inspecting database content or entity DDL from the CLI, regenerating Doctrine models, processing a CLI message, registering or changing a cron job, or checking whether a cron ran or why it failed."
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
- Running the framework's built-in commands — `app:db:read` (read-only SQL over stored rows), `app:entity:show-sql` / `app:entity:list` (entity DDL and discovery), doctrine-model generation, CLI message processing, cron registration (`app:crons:upsert`) and cron run history (`app:crons:executions`) — see [Framework-Provided Commands](#framework-provided-commands) below

## Namespace & Location

**Framework commands:** `DDD\Symfony\Commands\{Base|Common}\`
**Application commands:** `App\Symfony\Commands\{Domain}\`

**Directory structure:**
```
src/Symfony/Commands/
+-- Base/
|   +-- Database/ShowEntitySql.php, ListEntities.php, RunReadOnlyQuery.php (app:db:read)
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

The DDD Core framework ships these commands — run them via the consuming app's `bin/console`.

### Schema & content inspection — the SQL an entity produces, and the rows in the database

The three built-in read-only introspection commands: the first two read **structure** (entities, generated DDL), the third reads **content** (rows).

| Command | Arguments | Purpose |
|---------|-----------|---------|
| `app:entity:show-sql [entity]` | `entity` (optional): short name (`Account`) or FQN; omit ⇒ every entity | Prints the generated `CREATE TABLE` + index / foreign-key DDL for the entity, derived from its attributes. **Read-only — executes nothing.** The fastest way to see the *exact* schema an entity maps to: default per-column indexes, FK indexes, spatial/vector/fulltext indexes, and trait columns (`id`, `created`/`updated`). |
| `app:entity:list [filter]` | `filter` (optional): case-insensitive substring over name / table / FQN | Lists every DB-mapped entity with its SQL table name and FQN. Use it to discover entity names/tables before `show-sql`. STI subclasses are shown as folding into their parent table. |
| `app:db:read "<statement>" [--scope=DEFAULT\|LEGACY_DB] [--limit=200] [--format=json\|table]` | `statement` (required): ONE read-only SQL statement | Reads database **content** (rows) — the counterpart to the two structure commands above. Runs ONE read-only statement (`SELECT` / `WITH` / `SHOW` / `EXPLAIN` / `DESCRIBE` / `DESC`) against a Doctrine connection and prints the rows (JSON on stdout by default; `--format=table` for a console table). Writes, a second `;`-statement, and `INTO OUTFILE`/`DUMPFILE` are rejected before a connection opens; a `LIMIT` is appended to row-returning statements that carry none. **The keyword guard is NOT a security boundary** — point the connection's DB user at SELECT-only rights. |

```bash
# What SQL does the Account entity generate?
php bin/console app:entity:show-sql Account
# Ambiguous short name? pass the fully-qualified class name:
php bin/console app:entity:show-sql 'DDD\Domain\Common\Entities\Accounts\Account'
# The whole target schema (all entities):
php bin/console app:entity:show-sql
# Find an entity / its table name:
php bin/console app:entity:list memory
# Read actual ROWS (content, not schema) — read-only, LIMIT auto-appended:
php bin/console app:db:read "SELECT id, email FROM users WHERE id = 581"
# Console table instead of JSON; SHOW/EXPLAIN/DESCRIBE carry their own row count (no LIMIT added):
php bin/console app:db:read "SHOW COLUMNS FROM user_subscriptions" --format=table
```

> **Agentic tip:** when reasoning about an entity's persistence, run `app:entity:show-sql <Entity>` to see the *exact* DDL the generator emits instead of guessing from the PHP; to inspect the actual stored data, `app:db:read "SELECT …"` (read-only by construction — writes rejected, one statement, LIMIT enforced; its keyword guard is not a security boundary, so it must run against a SELECT-only DB user). For *how* those indexes/columns are decided, see `ddd-entity-specialist` → "Database Indexes & Virtual Columns"; for migrating a live database to this target schema, see `ddd-database-schema-diff-specialist`.

### Code generation, messaging, scheduling

| Command | Arguments | Purpose |
|---------|-----------|---------|
| `app:generate-doctrine-models-for-entities` | — | Generates `DB*Model.php` Doctrine model classes from entity attributes (wired into the apps' composer `post-update-cmd`) |
| `app:process-cli-message <message> [--useTempFile]` | `message` (required), `--useTempFile` (flag) | Decodes an `AppMessage` and invokes its handler (cross-workspace / CLI message handling) |
| `app:crons:execute` | — | The tick: runs every Cron row whose `nextExecutionScheduledAt` has passed, each in a child process via `Cron::execute()`, and records a `CronExecution`. Rows live in the `Cron` / `CronExecution` entities. |
| `app:crons:list` | — | Lists all registered cron jobs with status |
| `app:crons:upsert --name="<unique name>" [--description=… --schedule="*/5 * * * *" --command="app:x:y --flag" --active=1\|0] [--dryRun]` | `--name` (required) | Creates or updates ONE Cron, addressed by its unique name: absent → created (`--description`, `--schedule`, `--command` required), present → only the options passed change. The **entity** validates (expression grammar, the command must exist in THIS console, description ≥ 16 chars) and the violations are printed before anything is written; `--dryRun` shows the resulting row and writes nothing. A new or re-scheduled Cron gets `nextExecutionScheduledAt` computed from the expression, so the next tick picks it up at its next slot. **Register with `--active=0` when the tick host does not have the command's code yet, then flip it on with `--active=1` after the deploy.** |
| `app:crons:executions [--cron=<name\|id>] [--limit=20] [--state=SUCCESSFUL\|FAILED] [--output]` | — | What the scheduler actually DID: recent executions newest first, with start, duration, state and the first output line; `--output` prints each full captured output, which is where a failing command's real error text is. Read-only. Executions are cleaned up after 14 days, so an empty result can also mean "ran longer ago than that". |

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

---

## Cross-Reference

- **Service resolution & business logic** — commands are thin entry points that delegate to services; see `ddd-service-specialist`.
- **Async dispatch from a command** — when a command enqueues background work (`async: true`), the message + handler side lives in `ddd-message-handler-specialist`.
- **Loading entities by ID / sets** — see `ddd-entity-specialist` for the `byId` / repository patterns commands use after setting the auth context.
