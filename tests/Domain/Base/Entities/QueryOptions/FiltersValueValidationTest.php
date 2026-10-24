<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinition;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecution;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[LazyLoadRepo(LazyLoadRepo::DB, DBCronExecution::class)]
class ValueKindProbeEntity extends Entity
{
    use QueryOptionsTrait, TranslatableTrait;

    public ?int $amount = null;

    public ?float $ratio = null;

    public bool $isActive = false;

    public ?string $label = null;

    public ?Date $dueOn = null;

    public ?DateTime $occurredAt = null;

    /** @var string[]|null */
    public ?array $tags = null;

    #[Translatable]
    public ?string $title = null;

    #[Translatable(fullTextIndex: true)]
    public ?string $name = null;

    public int|float $mixedNumber = 0;
}

/**
 * Filter values are validated by content against the column kind: a quoted literal is the normal form ('12' is an
 * int), a filter the database cannot answer or a value that does not fit is a 400 instead of an empty result or a 500.
 */
class FiltersValueValidationTest extends TestCase
{
    protected FiltersDefinitions $definitions;

    protected function setUp(): void
    {
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, 'DB');
        $this->definitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(ValueKindProbeEntity::class);
    }

    public function testTheColumnKindsComeFromTheDeclaredTypes(): void
    {
        $expectedKinds = [
            'amount' => FiltersDefinition::COLUMN_KIND_INT,
            'ratio' => FiltersDefinition::COLUMN_KIND_FLOAT,
            'isActive' => FiltersDefinition::COLUMN_KIND_BOOL,
            'label' => FiltersDefinition::COLUMN_KIND_STRING,
            'dueOn' => FiltersDefinition::COLUMN_KIND_DATETIME,
            'occurredAt' => FiltersDefinition::COLUMN_KIND_DATETIME,
            'tags' => FiltersDefinition::COLUMN_KIND_JSON,
            'title' => FiltersDefinition::COLUMN_KIND_TRANSLATABLE,
            'name' => FiltersDefinition::COLUMN_KIND_TRANSLATABLE_SEARCHABLE,
            // the inherited id is int|string|null: an int
            'id' => FiltersDefinition::COLUMN_KIND_INT,
            // any other union: no kind, no validation
            'mixedNumber' => null,
        ];
        foreach ($expectedKinds as $propertyName => $expectedKind) {
            $this->assertSame(
                $expectedKind,
                $this->definitions->getFilterDefinitionForPropertyName($propertyName)?->columnKind,
                $propertyName
            );
        }
    }

    /** @return array<string, array{0: string}> */
    public static function acceptedFilters(): array
    {
        return [
            'quoted int' => ["amount eq '7'"],
            'quoted bool 1' => ["isActive eq '1'"],
            'quoted bool true' => ["isActive eq 'true'"],
            'bool in upper case' => ["isActive eq 'TRUE'"],
            'unquoted 0 (a float 0.0 from the parser)' => ['isActive eq 0'],
            'unquoted int list (floats from the parser)' => ['amount in [7,8]'],
            'decimal on an int column' => ["amount ge '1.5'"],
            'float' => ["ratio lt '0.25'"],
            'datetime with time' => ["occurredAt ge '2026-01-01 00:00:00'"],
            'date on a Date column' => ["dueOn lt '2026-09-21'"],
            'date on a DateTime column' => ["occurredAt lt '2026-09-21'"],
            'ISO with offset' => ["occurredAt ge '2026-01-01T10:00:00+02:00'"],
            'ISO with Z' => ["occurredAt ge '2026-01-01T10:00:00Z'"],
            'between on datetime' => ["occurredAt bw ['2026-01-01 00:00:00','2026-01-01 23:59:59']"],
            'quoted id' => ["id eq '12'"],
            'null in lower case' => ["occurredAt ne 'null'"],
            'NULL on a JSON column' => ["tags eq 'NULL'"],
            'bare null on a plain Translatable' => ['title eq null'],
            'wildcard on an int (LIKE as before)' => ["amount eq '*4*'"],
            'wildcard on a plain Translatable (LIKE as before)' => ["title eq '*piz*'"],
            'exact on a searchable Translatable' => ["name eq 'Pizza'"],
            'string' => ["label eq 'anything at all'"],
            'no kind' => ["mixedNumber eq 'not checked'"],
        ];
    }

    /** @dataProvider acceptedFilters */
    public function testAFilterTheDatabaseCanAnswerIsAccepted(string $filterQuery): void
    {
        $this->assertTrue(FiltersOptions::fromString($filterQuery)->validateAgainstDefinitions($this->definitions));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedFilters(): array
    {
        return [
            'text on an int' => ["amount eq 'abc'", 'not a valid int'],
            'hash on an int' => ["amount eq '#12'", 'not a valid int'],
            'hash on the id' => ["id eq '#12'", 'not a valid int'],
            'yes on a bool' => ["isActive eq 'yes'", 'not a valid bool'],
            'text on a datetime' => ["occurredAt eq 'x'", 'not a valid datetime'],
            'partial date' => ["occurredAt eq '2026-10'", 'not a valid datetime'],
            'impossible date' => ["dueOn eq '2026-13-45'", 'not a valid datetime'],
            'one bad element of a list' => ["amount in ['7','x']", 'not a valid int'],
            'exact on a plain Translatable' => ["title eq 'Pizza'", 'translations of all languages'],
            'comparison on a JSON column' => ["tags eq 'x'", 'JSON column'],
            'wildcard on a JSON column' => ["tags eq '*x*'", 'JSON column'],
            'list on a JSON column' => ["tags in ['a']", 'JSON column'],
        ];
    }

    /** @dataProvider refusedFilters */
    public function testAFilterTheDatabaseCannotAnswerIsA400(string $filterQuery, string $expectedMessagePart): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessagePart, '/') . '/');

        FiltersOptions::fromString($filterQuery)->validateAgainstDefinitions($this->definitions);
    }

    protected function cronExpandValidation(string $filterQuery): bool
    {
        $expandOptions = ExpandOptions::fromString('executions');
        $expandOptions->validateAgainstDefinitionsFromReferenceClass(Cron::class);
        return FiltersOptions::fromString($filterQuery)->validateAgainstDefinitions(
            FiltersDefinitions::getFiltersDefinitionsForReferenceClass(Cron::class),
            $expandOptions
        );
    }

    /** The dotted filter through an expand lost its operator and ended in an Error (HTTP 500). */
    public function testAnExpandedFilterIsValidatedLikeARootOne(): void
    {
        $this->assertTrue($this->cronExpandValidation("executions.cronId eq '1'"));
    }

    public function testAnInvalidExpandedFilterIsA400(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessageMatches('/not a valid int/');

        $this->cronExpandValidation("executions.cronId eq 'abc'");
    }

    public function testAFulltextOperatorThroughAnExpandWithoutAnIndexIsA400(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessageMatches('/no FULLTEXT index/');

        $this->cronExpandValidation("executions.state fb 'x'");
    }
}
