<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Http\Fixtures;

use DDD\Infrastructure\Http\PublicUrlGuard;

/**
 * The guard with a scripted resolver: each lookup of a host takes the next scripted answer (the last one repeats),
 * so a test can play a TTL-0 host that answers public first and internal afterwards. Counts the lookups per host.
 */
class FakeDnsPublicUrlGuard extends PublicUrlGuard
{
    /** @var array<string, string[][]> */
    protected static array $answersByHost = [];

    /** @var array<string, int> */
    public static array $lookupCountByHost = [];

    public static function reset(): void
    {
        static::$answersByHost = [];
        static::$lookupCountByHost = [];
    }

    /**
     * @param string[] ...$answers one IP list per lookup, in order
     */
    public static function answerFor(string $host, array ...$answers): void
    {
        static::$answersByHost[$host] = $answers;
    }

    /** @return string[] */
    protected static function resolveHostToIps(string $host): array
    {
        $literalIp = trim($host, '[]');
        if (filter_var($literalIp, FILTER_VALIDATE_IP) !== false) {
            return [$literalIp];
        }
        static::$lookupCountByHost[$host] = (static::$lookupCountByHost[$host] ?? 0) + 1;
        $answers = static::$answersByHost[$host] ?? [[]];
        $answer = count($answers) > 1 ? array_shift($answers) : $answers[0];
        static::$answersByHost[$host] = $answers;
        return $answer;
    }
}
