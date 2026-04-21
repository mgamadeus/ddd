---
name: ddd-message-handler-specialist
description: Create Symfony Messenger message + handler pairs for async background processing in the mgamadeus/ddd framework. Covers message classes, handler classes, auth context propagation, workspace routing, logging, messenger.yaml transport/routing config, and supervisor consumers.
metadata:
  author: mgamadeus
  version: "1.0.0"
  framework: mgamadeus/ddd
---

# DDD Message Handler Specialist

Async background processing via Symfony Messenger within the DDD Core framework (`mgamadeus/ddd`).

## When to Use

- Adding a new async background task processed by Symfony Messenger
- Adding a `bool $async` option to a service method and implementing dispatching
- Ensuring background work executes under the same account permissions as the triggering request
- Implementing heavy jobs with time/memory limits and consistent logging

## Framework Base Classes

| Class | Location | Purpose |
|-------|----------|---------|
| `AppMessage` | `src/Domain/Base/Entities/MessageHandlers/AppMessage.php` | Base message class (extends `ValueObject`, implements `SerializerInterface`) |
| `AppMessageHandler` | `src/Domain/Base/Entities/MessageHandlers/AppMessageHandler.php` | Abstract base handler with auth, logging, error handling |

**AppMessage provides:**
- `dispatch()` -- dispatches to Symfony Messenger bus, auto-captures `accountId` and `dispatchedFromWorkspaceDir`
- `encodeForCommandline()` / `decodeFromCommandline()` -- CLI transport (gzip + base64)
- `persistToTempDir()` / `loadFromTempDir()` -- temp file transport
- `processOnWorkspace()` / `processOnWorkspaceIfNecessary()` -- cross-workspace processing

**AppMessageHandler provides:**
- `getLogger()` -- returns injected `messengerLogger` or falls back to `DDDService::instance()->getLogger()`
- `setAuthAccountFromMessage(AppMessage)` -- restores auth context from message's `accountId`
- `logShortException(LoggerInterface, string, Throwable)` -- structured error log with top 3 stack trace frames
- `logIssue(Throwable, AppMessage, ?string)` -- comprehensive exception logging with message payload
- `extractMessagePayload(AppMessage)` -- extracts scalar properties for JSON logging

---

## Conventions

### 1. Prefer IDs in Message Payload

Store only IDs and primitive values in the message. Re-fetch the entity in the handler.

**Why:** Reduces message size, avoids serialization issues, handler always loads the latest DB state.

**Exception:** When the object is not yet stored (no ID) or serialization is explicitly desired.

### 2. Handler is Ultra-Slim — ALL Logic Lives in the Service

**The handler is a thin dispatcher. It MUST NOT contain business logic.** Its only job is:
1. Set auth context
2. Check workspace routing
3. Load entity by ID
4. Call a single service method with `async: false`
5. Catch and log errors

**NEVER** put moderation logic, translation calls, notification dispatch, rate limiting, DB queries, or any other business logic directly in the handler. All of that belongs in the service method. The handler should be ~20-30 lines max.

**Anti-pattern (WRONG):**
```php
// WRONG — handler doing business logic
class FooBarHandler extends AppMessageHandler {
    public function __invoke(FooBarMessage $message): void {
        // ...auth, workspace...
        $entity = FooBar::byId($message->id);
        // ❌ Business logic in handler:
        $this->doStep1($entity);
        $this->doStep2($entity);
        $this->doStep3($entity);
    }
    protected function doStep1(...) { /* 50 lines */ }
    protected function doStep2(...) { /* 80 lines */ }
}
```

**Correct pattern (RIGHT):**
```php
// Handler — thin
class FooBarHandler extends AppMessageHandler {
    public function __invoke(FooBarMessage $message): void {
        // ...auth, workspace...
        $service = FooBars::getService();
        $entity = $service->find($message->id);
        $service->processFooBar($entity, async: false); // ✅ One call
    }
}

// Service — all logic here
class FooBarsService extends EntitiesService {
    public function processFooBar(FooBar $entity, bool $async = true): void {
        if ($async) { (new FooBarMessage($entity->id))->dispatch(); return; }
        $this->doStep1($entity);
        $this->doStep2($entity);
        $this->doStep3($entity);
    }
}
```

### 3. Service Method Pattern: `bool $async = false`

Business logic lives in a service method that accepts `$async`. The caller decides whether to run immediately or enqueue:

```php
public function doSomething(int $entityId, bool $async = false): void
{
    if ($async) {
        (new DoSomethingMessage($entityId))->dispatch();
        return;
    }
    // synchronous execution
    // ...actual work...
}
```

**Handler side:** always calls the service method with `async: false` to avoid re-dispatch loops.

### 4. Always Propagate Auth Context

In every handler, call:

```php
$this->setAuthAccountFromMessage($message);
```

This ensures the message is processed with the rights of the account that dispatched it.

### 5. Workspace Routing Guard

Handlers must check workspace routing before processing:

```php
if ($message->processOnWorkspaceIfNecessary()) {
    return;
}
```

**Order:** set auth -> workspace guard -> run.

### 6. Logging Pattern

- Use `$this->getLogger()` for all logging -- **never** `DDDService::instance()->getLogger()` directly in handlers
- Log an `info` line before work starts with identifying IDs
- Wrap the body in `try/catch (Throwable $t)`
- In the catch block, use `$this->logShortException()` for structured error logging

```php
$this->getLogger()->info("Processing FooBar for {$message->fooBarId}");
try {
    // ...work...
} catch (Throwable $t) {
    $this->logShortException(
        $this->getLogger(),
        "Processing FooBar for {$message->fooBarId}",
        $t
    );
}
```

### 7. Time/Memory Limits for Heavy Jobs

```php
set_time_limit(120);           // 120s for standard tasks, 500s for heavy imports
ini_set('memory_limit', '1024M');
```

### 8. Admin Privilege Escalation (When Required)

Some async operations must run with admin privileges (e.g., cross-tenant data access):

```php
$defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
AuthService::instance()->setAccount($defaultAccount);
```

Use only when necessary. Still call `setAuthAccountFromMessage()` first for attribution.

---

## Implementation Steps

### Step 1: Choose Domain + Naming

**Location:** `src/Domain/{Domain}/MessageHandlers/`

**Naming:**
- `FooBarMessage` -- the message class
- `FooBarHandler` -- the handler class
- Transport name in `snake_case`: `process_foo_bar`

### Step 2: Create the Message Class

```php
<?php
declare(strict_types=1);

namespace {Namespace}\Domain\{Domain}\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

class FooBarMessage extends AppMessage
{
    public static string $messageHandler = FooBarHandler::class;

    public ?int $fooBarId = null;
    public bool $regenerate = false;

    public function __construct(?int $fooBarId = null, bool $regenerate = false)
    {
        parent::__construct();
        $this->fooBarId = $fooBarId;
        $this->regenerate = $regenerate;
    }
}
```

**Rules:**
- Extend `AppMessage`
- Define `public static string $messageHandler = FooBarHandler::class;`
- Use primitive payload (IDs, bools, strings) -- not entity objects
- Call `parent::__construct()` in constructor

### Step 3: Create the Handler Class

Application-level handlers should extend a project-specific `CustomAppMessageHandler` (which extends `AppMessageHandler`) or `AppMessageHandler` directly:

```php
<?php
declare(strict_types=1);

namespace {Namespace}\Domain\{Domain}\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(fromTransport: 'process_foo_bar')]
class FooBarHandler extends AppMessageHandler
{
    public function __invoke(FooBarMessage $message): void
    {
        set_time_limit(120);
        ini_set('memory_limit', '1024M');

        $this->setAuthAccountFromMessage($message);
        if ($message->processOnWorkspaceIfNecessary()) {
            return;
        }

        $this->getLogger()->info("Processing FooBar for {$message->fooBarId}");

        try {
            // 1. Load entity by ID
            $fooBar = FooBar::byId($message->fooBarId);
            if (!$fooBar) {
                return;
            }

            // 2. Call service method with async: false
            /** @var FooBarsService $fooBarsService */
            $fooBarsService = FooBars::getService();
            $fooBarsService->doSomething($fooBar->id, async: false);
        } catch (Throwable $t) {
            $this->logShortException(
                $this->getLogger(),
                "Processing FooBar for {$message->fooBarId}",
                $t
            );
        }
    }
}
```

**Rules:**
- Extend `AppMessageHandler` (or project-specific subclass)
- Add `#[AsMessageHandler(fromTransport: 'transport_name')]`
- Set auth context and workspace guard first
- Load entity by ID inside the handler (not from message payload)
- Call service method with `async: false`
- Wrap in try/catch with `logShortException()`

### Step 4: Wire Messenger Transport + Routing

Update `config/symfony/default/packages/messenger.yaml`:

```yaml
framework:
  messenger:
    transports:
      # Add transport (RabbitMQ queue)
      process_foo_bar: '%env(MESSENGER_TRANSPORT_DSN_RABBITMQ)%/process_foo_bar'

    routing:
      # Route message to transport
      '{Namespace}\Domain\{Domain}\MessageHandlers\FooBarMessage': process_foo_bar
```

**Debug tip:** For local troubleshooting, temporarily switch the transport to `sync://`.

### Step 5: Add Supervisor Consumer

Update `config/system/supervisorWorkers.conf`:

```ini
[program:process_foo_bar]
command=php /path/to/project/bin/console messenger:consume process_foo_bar --no-debug
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
```

Set `numprocs` based on job cost:
- IO-bound / short tasks: more workers (e.g., push notifications: 10)
- Heavy CPU/memory: fewer workers (e.g., imports: 1)

### Step 6: Use From Service

```php
public function doSomething(int $fooBarId, bool $async = false): void
{
    if ($async) {
        (new FooBarMessage($fooBarId))->dispatch();
        return;
    }

    // Synchronous execution
    $fooBar = FooBar::byId($fooBarId);
    // ...actual work...
}
```

---

## Checklist

- [ ] Message extends `AppMessage`
- [ ] Message has `public static string $messageHandler = FooBarHandler::class;`
- [ ] Message payload uses IDs/primitives (not entity objects)
- [ ] Message constructor calls `parent::__construct()`
- [ ] Handler extends `AppMessageHandler` (or project-specific subclass)
- [ ] Handler has `#[AsMessageHandler(fromTransport: '...')]`
- [ ] Handler calls `$this->setAuthAccountFromMessage($message)` first
- [ ] Handler calls `$message->processOnWorkspaceIfNecessary()` and returns early
- [ ] Handler uses `$this->getLogger()` (never `DDDService::instance()->getLogger()`)
- [ ] Handler uses `$this->logShortException()` in catch blocks
- [ ] Handler calls service method with `async: false`
- [ ] `messenger.yaml` has transport + routing entries
- [ ] Supervisor config has a consumer for the transport
- [ ] Service method has `bool $async = false` signature
- [ ] Never use `private` -- always `protected`
