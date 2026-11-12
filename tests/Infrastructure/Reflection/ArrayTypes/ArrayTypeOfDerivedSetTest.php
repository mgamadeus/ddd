<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Reflection\ArrayTypes;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ReflectionArrayType;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Tests\Infrastructure\Reflection\ArrayTypes\ApplicationSets\DerivedSetRestatingElements;
use DDD\Tests\Infrastructure\Reflection\ArrayTypes\ApplicationSets\DerivedSetWithNullableElements;
use DDD\Tests\Infrastructure\Reflection\ArrayTypes\ApplicationSets\DerivedSetWithoutDeclaration;
use DDD\Tests\Infrastructure\Reflection\ArrayTypes\ApplicationSets\PlainDerivedObjectSet;
use DDD\Tests\Infrastructure\Reflection\ArrayTypes\Elements\DeclaredElement;
use DDD\Tests\Infrastructure\Reflection\ArrayTypes\ModuleSets\ModuleSetWithDeclaredElements;
use PHPUnit\Framework\TestCase;

/** The element type of a set's elements is resolved where it is declared, not in the namespace of a derived set */
class ArrayTypeOfDerivedSetTest extends TestCase
{
    protected function getArrayTypeOfElements(string $setClassName): ReflectionArrayType
    {
        $reflectionType = ReflectionClass::instance($setClassName)->getProperty('elements')->getType();
        $this->assertInstanceOf(ReflectionArrayType::class, $reflectionType);
        return $reflectionType;
    }

    public function testTheModuleSetResolvesItsDeclarationWithItsOwnUseStatements(): void
    {
        $this->assertSame(
            DeclaredElement::class,
            $this->getArrayTypeOfElements(ModuleSetWithDeclaredElements::class)->getArrayType()->getName()
        );
    }

    public function testADerivedSetInAnotherNamespaceInheritsTheDeclarationOfItsParent(): void
    {
        $this->assertSame(
            DeclaredElement::class,
            $this->getArrayTypeOfElements(DerivedSetWithoutDeclaration::class)->getArrayType()->getName()
        );
    }

    public function testADerivedSetsOwnDeclarationWins(): void
    {
        $this->assertSame(
            ValueObject::class,
            $this->getArrayTypeOfElements(DerivedSetRestatingElements::class)->getArrayType()->getName()
        );
    }

    public function testThePropertysOwnVarIsResolvedInTheNamespaceOfTheClassDeclaringTheProperty(): void
    {
        $this->assertSame(
            BaseObject::class,
            $this->getArrayTypeOfElements(PlainDerivedObjectSet::class)->getArrayType()->getName()
        );
    }

    public function testNullableElementsDeclaredByTheParentStayNullable(): void
    {
        $this->assertTrue($this->getArrayTypeOfElements(DerivedSetWithNullableElements::class)->allowsNullElements());
        $this->assertFalse($this->getArrayTypeOfElements(DerivedSetWithoutDeclaration::class)->allowsNullElements());
    }
}
