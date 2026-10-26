<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Write\ConflictTargetResolver;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\AmbiguousConflictRow;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\ConflictAttributeRow;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP — {@see ConflictTargetResolver::resolve()} is reflection over `DatabaseModel::fromEntityClass()`, no
 * connection needed (mirrors `UpsertMultipleStatementsTest`'s own "the entity manager never connects" pattern).
 *
 * Fixtures ({@see AmbiguousConflictRow}, {@see ConflictAttributeRow}): two independent NOT-NULL-backed UNIQUE
 * keys (`handle`, `code`), both always set — the real Dineful `DeviceRegistration` shape
 * (`(tokenHash, audience)` + `uuid`), minus/plus the `#[UpsertConflictKey]` that disambiguates it.
 */
class ConflictTargetResolverTest extends TestCase
{
    public function testTwoAmbiguousNotNullBackedKeysWithoutTheAttributeThrowNamingTheEntityAndBothKeys(): void
    {
        $databaseModel = DatabaseModel::fromEntityClass(AmbiguousConflictRow::class);

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage(AmbiguousConflictRow::class);

        try {
            ConflictTargetResolver::resolve($databaseModel, ['handle', 'code', 'label'], false);
        } catch (InternalErrorException $exception) {
            $this->assertStringContainsString('(handle)', $exception->getMessage());
            $this->assertStringContainsString('(code)', $exception->getMessage());
            $this->assertStringContainsString('UpsertConflictKey', $exception->getMessage());
            throw $exception;
        }
    }

    public function testTheAttributeResolvesTheAmbiguityToItsDeclaredKey(): void
    {
        $databaseModel = DatabaseModel::fromEntityClass(ConflictAttributeRow::class);

        $resolution = ConflictTargetResolver::resolve($databaseModel, ['handle', 'code', 'label'], false);

        $this->assertFalse($resolution->needsNullableFallback);
        $this->assertSame(['handle'], $resolution->columns);
    }

    public function testAnIdSetResolvesToThePrimaryKeyRegardlessOfAmbiguousCandidates(): void
    {
        $databaseModel = DatabaseModel::fromEntityClass(AmbiguousConflictRow::class);

        $resolution = ConflictTargetResolver::resolve($databaseModel, ['handle', 'code', 'label'], true);

        $this->assertFalse($resolution->needsNullableFallback);
        $this->assertSame(['id'], $resolution->columns);
    }

    public function testASingleUniqueKeyResolvesDirectlyWithNoAttributeNeeded(): void
    {
        $databaseModel = DatabaseModel::fromEntityClass(CounterRow::class);

        $resolution = ConflictTargetResolver::resolve($databaseModel, ['businessKey', 'counter', 'label'], false);

        $this->assertFalse($resolution->needsNullableFallback);
        $this->assertSame(['businessKey'], $resolution->columns);
    }

    public function testNoUniqueKeyPresentResolvesToAPlainInsert(): void
    {
        $databaseModel = DatabaseModel::fromEntityClass(CounterRow::class);

        $resolution = ConflictTargetResolver::resolve($databaseModel, ['counter', 'label'], false);

        $this->assertFalse($resolution->needsNullableFallback);
        $this->assertSame([], $resolution->columns);
    }
}
