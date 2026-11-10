# Writing messages and handlers

The message class, the handler class and the service method behind them. Transport and consumer: [transports-routing-and-consumers.md](transports-routing-and-consumers.md).

## Contents

1. Framework base classes
2. Prefer IDs in Message Payload
3. Handler is Ultra-Slim — ALL Logic Lives in the Service
4. Service Method Pattern: `bool $async = false`
5. Always Propagate Auth Context
6. Logging Pattern
7. Time/Memory Limits for Heavy Jobs
8. Admin Privilege Escalation (When Required)
9. Choose Domain + Naming
10. Create the Message Class
11. Create the Handler Class
12. Use From Service
13. Checklist

## Framework base classes

| Class | Location | Purpose |
|-------|----------|---------|
| `AppMessage` | `src/Domain/Base/Entities/MessageHandlers/AppMessage.php` | Base message class (extends `ValueObject`, implements `SerializerInterface`) |
| `AppMessageHandler` | `src/Domain/Base/Entities/MessageHandlers/AppMessageHandler.php` | Abstract base handler with auth, logging, error handling |

**AppMessage provides:**
- `dispatch()` -- dispatches to Symfony Messenger bus, auto-captures `accountId` and `dispatchedFromWorkspaceDir`
- `encodeForCommandline()` / `decodeFromCommandline()` -- CLI transport (gzip + base64)
- `persistToTempDir()` / `loadFromTempDir()` -- temp file transport
- `dispatchedFromWorkspaceDir` / `dispatchedFromEnvironment` -- where a message came from (stamped by `dispatch()`; read by the workspace-origin guard)
- `dispatchOrigin` (`AppMessageDispatchOrigin`: `dispatchedAt`, `route`, `command`) -- when and from which request or command it was dispatched (stamped by `dispatch()`); a failing job's issues event shows it as `messenger.origin.*` and `messenger.age_seconds`. Do not declare `dispatchOrigin` on a message class

**AppMessageHandler provides:**
- `getLogger()` -- returns injected `messengerLogger` or falls back to `DDDService::instance()->getLogger()`
- `setAuthAccountFromMessage(AppMessage)` -- restores auth context from message's `accountId`
- `logShortException(LoggerInterface, string, Throwable)` -- structured error log with top 3 stack trace frames
- `logIssue(Throwable, AppMessage, ?string)` -- comprehensive exception logging with message payload
- `extractMessagePayload(AppMessage)` -- extracts scalar properties for JSON logging
- `RESET_WORKER_STATE_BEFORE_JOB` (const, default `true`) -- a consumed job starts on fresh process state; override with `false` on a handler that deliberately keeps warm state across messages
- `resetWorkerStateForNewJob()` (static) -- the ONE fresh-state implementation: Doctrine unit of work cleared, every DB connection without an active transaction closed (lazy reconnect = fresh session/snapshot), `DoctrineEntityRegistry::clear()` + `VirtualEntityRegistry::clear()`; run by the core `FreshWorkerStateMiddleware` before every message with a `ReceivedStamp`
- `renewDatabaseConnection()` (static) -- the connection part alone (`EntityManagerFactory::renewAllConnections()`), fail-soft; agent loops call it before every tool call

## Prefer IDs in Message Payload

Store only IDs and primitive values in the message. Re-fetch the entity in the handler.

**Why:** Reduces message size, avoids serialization issues, handler always loads the latest DB state.

**Exception:** When the object is not yet stored (no ID) or serialization is explicitly desired.

## Handler is Ultra-Slim — ALL Logic Lives in the Service

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

## Service Method Pattern: `bool $async = false`

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

## Always Propagate Auth Context

In every handler, call:

```php
$this->setAuthAccountFromMessage($message);
```

This ensures the message is processed with the rights of the account that dispatched it.

## Logging Pattern

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

## Time/Memory Limits for Heavy Jobs

```php
set_time_limit(120);           // 120s for standard tasks, 500s for heavy imports
ini_set('memory_limit', '1024M');
```

## Admin Privilege Escalation (When Required)

Some async operations must run with admin privileges (e.g., cross-tenant data access):

```php
$defaultAccount = DDDService::instance()->getDefaultAccountForCliOperations();
AuthService::instance()->setAccount($defaultAccount);
```

Use only when necessary. Still call `setAuthAccountFromMessage()` first for attribution.

## Choose Domain + Naming

**Location:** `src/Domain/{Domain}/MessageHandlers/`

**Naming:**
- `FooBarMessage` -- the message class
- `FooBarHandler` -- the handler class
- Transport name in `snake_case`: `process_foo_bar`

## Create the Message Class

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

## Create the Handler Class

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
- Set auth context first
- Load entity by ID inside the handler (not from message payload)
- Call service method with `async: false`
- Wrap in try/catch with `logShortException()`

## Use From Service

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

## Checklist

- [ ] Message extends `AppMessage`
- [ ] Message has `public static string $messageHandler = FooBarHandler::class;`
- [ ] Message payload uses IDs/primitives (not entity objects)
- [ ] Message constructor calls `parent::__construct()`
- [ ] Handler extends `AppMessageHandler` (or project-specific subclass)
- [ ] Handler has `#[AsMessageHandler(fromTransport: '...')]` naming a transport that EXISTS and that the message is routed to — on Symfony 7.4 a wrong name silently disconnects the handler (the blanket `messenger.message_handler` tag from `services.yaml` is dropped), so check `debug:messenger` shows `(when from_transport=…)`
- [ ] Handler calls `$this->setAuthAccountFromMessage($message)` first
- [ ] Handler uses `$this->getLogger()` (never `DDDService::instance()->getLogger()`)
- [ ] Handler uses `$this->logShortException()` in catch blocks
- [ ] No service state left behind: a checked lookup restores `throwErrors` in `finally`
- [ ] Handler calls service method with `async: false`
- [ ] `messenger.yaml` has transport + routing entries
- [ ] Supervisor config has a consumer for the transport
- [ ] Service method has `bool $async = false` signature
- [ ] Never use `private` -- always `protected`
