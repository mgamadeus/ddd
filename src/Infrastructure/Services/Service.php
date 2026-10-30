<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use ReflectionProperty;

/**
 * Base of every DDD service. A service from the container is one shared instance per process: a flag a caller
 * switches on it stays switched for every later caller of the same process — the next request in a worker runtime,
 * the next message in a Messenger worker, the rest of a long job. A caller that needs a checked lookup therefore
 * switches the flag for its own call only ({@see enableThrowErrors()} / {@see restoreThrowErrors()} in `finally`).
 *
 * Between two consumed Messenger messages the framework puts the flag of every instantiated service back to its
 * declared default ({@see ServicesThrowErrorsResetter}, {@see resetThrowErrorsToDefault()}).
 */
class Service
{
    /** @var bool If true, the service itself will throw errors */
    public bool $throwErrors = false;

    /** @var bool[] Declared default of `throwErrors` by service class, read once per class */
    protected static array $declaredThrowErrorsDefaultsByClass = [];

    /**
     * Switches `throwErrors` on for the caller's own lookups and returns the value before, which the caller hands to
     * {@see restoreThrowErrors()} in `finally`:
     *
     *     $throwErrorsBefore = $accountsService->enableThrowErrors();
     *     try {
     *         $account = $accountsService->find($accountId);
     *     } finally {
     *         $accountsService->restoreThrowErrors($throwErrorsBefore);
     *     }
     *
     * @return bool The value of `throwErrors` before the call
     */
    public function enableThrowErrors(): bool
    {
        $throwErrorsBefore = $this->throwErrors;
        $this->throwErrors = true;
        return $throwErrorsBefore;
    }

    /**
     * Switches `throwErrors` off for the caller's own lookups and returns the value before, see
     * {@see enableThrowErrors()}.
     *
     * @return bool The value of `throwErrors` before the call
     */
    public function disableThrowErrors(): bool
    {
        $throwErrorsBefore = $this->throwErrors;
        $this->throwErrors = false;
        return $throwErrorsBefore;
    }

    /**
     * Puts `throwErrors` back to the value {@see enableThrowErrors()} / {@see disableThrowErrors()} returned.
     *
     * @param bool $throwErrorsBefore The value returned by enableThrowErrors() or disableThrowErrors()
     * @return void
     */
    public function restoreThrowErrors(bool $throwErrorsBefore): void
    {
        $this->throwErrors = $throwErrorsBefore;
    }

    /**
     * Puts `throwErrors` back to the default the service class declares (a subclass may redeclare the property with
     * `true`). Only the flag — no other state of the service. Called between two consumed Messenger messages and by
     * Symfony's `services_resetter` (`kernel.reset`).
     *
     * @return void
     */
    public function resetThrowErrorsToDefault(): void
    {
        $serviceClass = static::class;
        if (!isset(static::$declaredThrowErrorsDefaultsByClass[$serviceClass])) {
            static::$declaredThrowErrorsDefaultsByClass[$serviceClass]
                = (bool)(new ReflectionProperty($this, 'throwErrors'))->getDefaultValue();
        }
        $this->throwErrors = static::$declaredThrowErrorsDefaultsByClass[$serviceClass];
    }
}
