<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

/**
 * Puts `throwErrors` of every DDD service the process has instantiated back to its declared default. A service from
 * the container is shared: in a long-lived Messenger worker a flag job N switched on and never switched back would
 * otherwise make job N+1's lookups throw where they return null. Run before every consumed message by
 * {@see \DDD\Symfony\Messenger\Middleware\FreshWorkerStateMiddleware}; every service definition is also tagged
 * `kernel.reset` with {@see Service::resetThrowErrorsToDefault()}, so Symfony's `services_resetter` does the same after
 * each message (unless the worker runs with `--no-reset`) and in worker runtimes between requests.
 *
 * The iterable is filled by {@see \DDD\Symfony\CompilerPasses\ServicesThrowErrorsResetterPass} with every service
 * definition whose class extends {@see Service}, as references that yield only the services already instantiated — a
 * reset never creates a service.
 */
class ServicesThrowErrorsResetter
{
    /**
     * @param iterable<Service> $instantiatedServices The instantiated services of the container
     */
    public function __construct(protected iterable $instantiatedServices = [])
    {
    }

    /**
     * Puts `throwErrors` of every instantiated service back to its declared default; no other state is touched.
     *
     * @return int The number of services reset
     */
    public function resetThrowErrorsOfInstantiatedServices(): int
    {
        $resetServicesCount = 0;
        foreach ($this->instantiatedServices as $service) {
            if ($service instanceof Service) {
                $service->resetThrowErrorsToDefault();
                $resetServicesCount++;
            }
        }
        return $resetServicesCount;
    }
}
