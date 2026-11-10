<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Reflection\ReflectionClass;
use PHPUnit\Framework\TestCase;

class LoadedRelationDeclaredTarget
{
}

class LoadedRelationSpecificTarget extends LoadedRelationDeclaredTarget
{
}

class LoadedRelationUnrelatedTarget
{
}

/** A module's holder of a relation */
class LoadedRelationModuleHolder
{
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?LoadedRelationDeclaredTarget $target = null;
}

/** An application's same-path subclass: the invariant declared type, its own class as the LazyLoad target */
class LoadedRelationApplicationHolder extends LoadedRelationModuleHolder
{
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true, entityClassName: LoadedRelationSpecificTarget::class)]
    public ?LoadedRelationDeclaredTarget $target = null;
}

/** A LazyLoad naming a class outside the declared type's hierarchy */
class LoadedRelationUnrelatedHolder
{
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true, entityClassName: LoadedRelationUnrelatedTarget::class)]
    public ?LoadedRelationDeclaredTarget $target = null;
}

/**
 * A row of a relation that is loaded together with its holder (a join, an expand) is mapped by the repo of the class
 * the LazyLoad names — the class a later lazy load and the foreign key use. Before, the declared type's repo mapped it:
 * an application conversation whose account relation names the application's account was mapped by the framework's
 * account repo ("Undefined constant …\DBAccount::MODEL_ALIAS").
 */
class DBEntityLoadedRelationEntityClassTest extends TestCase
{
    public function testTheLazyLoadEntityClassNameDecidesTheClassOfALoadedRelation(): void
    {
        $this->assertSame(
            LoadedRelationSpecificTarget::class,
            DBEntity::resolveEntityClassOfLoadedRelation(
                ReflectionClass::instance(LoadedRelationApplicationHolder::class),
                'target',
                LoadedRelationDeclaredTarget::class
            )
        );
    }

    public function testWithoutAnEntityClassNameTheDeclaredTypeStays(): void
    {
        $this->assertSame(
            LoadedRelationDeclaredTarget::class,
            DBEntity::resolveEntityClassOfLoadedRelation(
                ReflectionClass::instance(LoadedRelationModuleHolder::class),
                'target',
                LoadedRelationDeclaredTarget::class
            )
        );
    }

    public function testAnEntityClassNameOutsideTheDeclaredTypeIsIgnored(): void
    {
        $this->assertSame(
            LoadedRelationDeclaredTarget::class,
            DBEntity::resolveEntityClassOfLoadedRelation(
                ReflectionClass::instance(LoadedRelationUnrelatedHolder::class),
                'target',
                LoadedRelationDeclaredTarget::class
            )
        );
    }
}
