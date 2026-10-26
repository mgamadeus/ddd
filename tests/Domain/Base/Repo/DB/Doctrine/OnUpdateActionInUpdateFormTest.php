<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Doctrine;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineBoundSql;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * An onUpdateAction is written for INSERT … ON DUPLICATE KEY UPDATE, where VALUES(col) is the incoming value. In an
 * UPDATE, VALUES() is NULL, so the UPDATE paths bind the value in its place: a verbatim copy turned
 * `counter + COALESCE(VALUES(counter), 1)` into +1 whatever was sent.
 */
class OnUpdateActionInUpdateFormTest extends TestCase
{
    protected function onUpdateActionAssignment(string $columnName, string $onUpdateAction, DoctrineBoundSql $valueExpression): DoctrineBoundSql
    {
        $entityManager = (new ReflectionClass(DoctrineEntityManager::class))->newInstanceWithoutConstructor();
        $buildOnUpdateActionAssignment = new ReflectionMethod($entityManager, 'buildOnUpdateActionAssignment');
        return $buildOnUpdateActionAssignment->invoke($entityManager, "`$columnName`", $columnName, $onUpdateAction, $valueExpression);
    }

    public function testValuesOfTheOwnColumnBecomesTheBoundValue(): void
    {
        $assignment = $this->onUpdateActionAssignment(
            'counter',
            '`counter` + COALESCE(VALUES(`counter`), 1)',
            new DoctrineBoundSql('?', [5], ['integer'])
        );

        $this->assertSame('`counter` = `counter` + COALESCE((?), 1)', $assignment->sql);
        $this->assertSame([5], $assignment->values);
        $this->assertSame(['integer'], $assignment->types);
    }

    public function testEveryOccurrenceIsBoundInOrder(): void
    {
        $assignment = $this->onUpdateActionAssignment(
            'total',
            'GREATEST(total, values(total)) + VALUES( total )',
            new DoctrineBoundSql('?', [7], ['integer'])
        );

        $this->assertSame('`total` = GREATEST(total, (?)) + (?)', $assignment->sql);
        $this->assertSame([7, 7], $assignment->values);
    }

    public function testAKeepExistingActionBindsNothing(): void
    {
        $assignment = $this->onUpdateActionAssignment('keptValue', '`keptValue`', new DoctrineBoundSql('?', [99], ['integer']));

        $this->assertSame('`keptValue` = `keptValue`', $assignment->sql);
        $this->assertSame([], $assignment->values);
    }

    public function testValuesOfAnotherColumnIsRefused(): void
    {
        $this->expectException(LogicException::class);

        $this->onUpdateActionAssignment('counter', '`counter` + VALUES(`amount`)', new DoctrineBoundSql('?', [5], ['integer']));
    }
}
