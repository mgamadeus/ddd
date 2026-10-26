<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use Doctrine\ORM\Query\TokenType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * An entity named like a DQL reserved word (`Order`) cannot be the DQL identification variable — every query on it was
 * a syntax error — so the generated MODEL_ALIAS gets the Model suffix. Everything else keeps its name.
 */
class DatabaseModelReservedDqlAliasTest extends TestCase
{
    protected function modelAliasFor(string $entityName): string
    {
        /** @var DatabaseModel $databaseModel */
        $databaseModel = (new ReflectionClass(DatabaseModel::class))->newInstanceWithoutConstructor();
        $databaseModel->name = $entityName;
        return $databaseModel->getModelAlias();
    }

    public function testAReservedWordGetsTheModelSuffix(): void
    {
        $this->assertSame('OrderModel', $this->modelAliasFor('Order'));
        $this->assertSame('GroupModel', $this->modelAliasFor('Group'));
    }

    public function testAnOrdinaryNameAndANonReservedFunctionNameStayUnchanged(): void
    {
        $this->assertSame('Venue', $this->modelAliasFor('Venue'));
        // SIZE, CURRENT_DATE … are DQL functions, not lexer keywords — an entity named so works as alias today
        $this->assertSame('Size', $this->modelAliasFor('Size'));
    }

    /** The list must be exactly Doctrine's reserved words, so a Doctrine update cannot leave it silently stale. */
    public function testTheListIsExactlyTheReservedWordsOfTheInstalledDoctrineLexer(): void
    {
        $reservedWords = [];
        foreach ((new ReflectionClass(TokenType::class))->getConstants() as $constantName => $tokenValue) {
            if (is_int($tokenValue) && $tokenValue >= TokenType::T_ALL) {
                $reservedWords[] = substr($constantName, 2);
            }
        }
        sort($reservedWords);
        $listedWords = DatabaseModel::RESERVED_DQL_KEYWORDS;
        sort($listedWords);

        $this->assertSame($reservedWords, $listedWords);
    }
}
