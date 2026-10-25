<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Locks;

use DDD\Domain\Base\Repo\DB\Dialect\Locks\LockKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Key derivation (architecture plan §4.12 "key space"): {@see LockKey::applicationKey()} and
 * {@see LockKey::internalKey()} must never collide, because PostgreSQL's single-bigint and two-int
 * `pg_advisory_lock` forms share one 64-bit keyspace (server packs key1 into the high 32 bits).
 */
final class LockKeyTest extends TestCase
{
    public function testApplicationKeyIsStableForTheSameName(): void
    {
        $this->assertSame(LockKey::applicationKey('ddd_schema_diff_apply'), LockKey::applicationKey('ddd_schema_diff_apply'));
    }

    public function testApplicationKeyDiffersForDifferentNames(): void
    {
        $this->assertNotSame(LockKey::applicationKey('lock-a'), LockKey::applicationKey('lock-b'));
    }

    /**
     * crc32() is unsigned 32-bit: applicationKey() must always leave the high 32 bits at zero, which is the
     * entire proof that it can never collide with internalKey()'s non-zero-namespace form below.
     */
    public function testApplicationKeyNeverSetsTheHigh32Bits(): void
    {
        foreach (['', 'x', 'ddd_schema_diff_apply', 'a-very-long-lock-name-with-unicode-münster', str_repeat('z', 500)] as $name) {
            $key = LockKey::applicationKey($name);
            $this->assertSame(0, $key >> 32, "applicationKey('$name') set a high bit: $key");
            $this->assertGreaterThanOrEqual(0, $key);
            $this->assertLessThanOrEqual(0xFFFFFFFF, $key);
        }
    }

    public function testInternalKeyReturnsTheNamespaceAndId(): void
    {
        $this->assertSame([7, 123], LockKey::internalKey(7, 123));
    }

    public function testInternalKeyRejectsAZeroNamespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LockKey::internalKey(0, 123);
    }

    /**
     * The actual non-collision proof: pack both forms the way PostgreSQL packs `pg_advisory_lock(key1, key2)`
     * into its 64-bit keyspace (key1 << 32 | key2 as unsigned 32-bit halves) and confirm no overlap across a
     * spread of application names and internal namespaces/ids.
     */
    public function testApplicationAndInternalKeysNeverOverlapInPostgresPackedForm(): void
    {
        $applicationPacked = [];
        foreach (['ddd_schema_diff_apply', 'lock-a', 'lock-b', 'another_name', ''] as $name) {
            $applicationPacked[] = LockKey::applicationKey($name);
        }

        foreach ([1, 2, 3, 42] as $namespace) {
            foreach ([0, 1, 123, 999999] as $id) {
                [$key1, $key2] = LockKey::internalKey($namespace, $id);
                $packed = ($key1 << 32) | ($key2 & 0xFFFFFFFF);
                $this->assertNotContains(
                    $packed,
                    $applicationPacked,
                    "internalKey($namespace, $id) packed to $packed, colliding with an applicationKey()"
                );
            }
        }
    }
}
