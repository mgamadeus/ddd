<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use PHPUnit\Framework\TestCase;

/**
 * A subclass that redeclares a relation keeps the parent's declared type (typed properties are invariant) and names its
 * own target with `#[LazyLoad(entityClassName: …)]` — lazy loading resolves that class's repo. The generated foreign key
 * must point to the same class, or the subclass's table references the parent target's table (an application account
 * on its legacy table became `REFERENCES Accounts`).
 */
class DatabaseModelForeignEntityClassTest extends TestCase
{
    public function testTheLazyLoadEntityClassNameWinsWhenItIsASubclassOfTheDeclaredType(): void
    {
        $this->assertSame(
            ForeignEntityClassSpecificTarget::class,
            DatabaseModel::resolveForeignEntityClassName(ForeignEntityClassDeclaredTarget::class, ForeignEntityClassSpecificTarget::class)
        );
    }

    public function testWithoutAnEntityClassNameTheDeclaredTypeStays(): void
    {
        $this->assertSame(
            ForeignEntityClassDeclaredTarget::class,
            DatabaseModel::resolveForeignEntityClassName(ForeignEntityClassDeclaredTarget::class, null)
        );
        $this->assertSame(
            ForeignEntityClassDeclaredTarget::class,
            DatabaseModel::resolveForeignEntityClassName(ForeignEntityClassDeclaredTarget::class, '')
        );
    }

    public function testAnUnrelatedEntityClassNameIsIgnored(): void
    {
        $this->assertSame(
            ForeignEntityClassDeclaredTarget::class,
            DatabaseModel::resolveForeignEntityClassName(ForeignEntityClassDeclaredTarget::class, ForeignEntityClassUnrelatedTarget::class)
        );
    }
}

class ForeignEntityClassDeclaredTarget
{
}

class ForeignEntityClassSpecificTarget extends ForeignEntityClassDeclaredTarget
{
}

class ForeignEntityClassUnrelatedTarget
{
}
