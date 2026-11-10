<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ScopedExpand\ScopedExpandHolder;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ScopedExpand\ScopedExpandItems;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * expand() makes an expand clause the target class' default QueryOptions only while the property loads: a later load
 * of the same class in the request gets the class' own defaults again (no inherited select, page or filters).
 */
class ExpandScopesDefaultQueryOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, 'DB');
        ScopedExpandHolder::$topSeenByLoadMethod = null;
    }

    public function testTheExpandClauseAppliesDuringTheLoadAndNotAfterIt(): void
    {
        $topBefore = ScopedExpandItems::getDefaultQueryOptions()->getTop();
        $holder = new ScopedExpandHolder();
        $expandOptions = ExpandOptions::fromString('items(top=5;select=name)');
        $holder->getQueryOptions()->setExpand($expandOptions, false);

        $holder->expand();

        $this->assertSame(5, ScopedExpandHolder::$topSeenByLoadMethod);
        $this->assertSame($topBefore, ScopedExpandItems::getDefaultQueryOptions()->getTop());
        $this->assertNull(ScopedExpandItems::getDefaultQueryOptions()->getSelect());
        $this->assertSame(5, $holder->items->getQueryOptions()->getTop());
    }
}
