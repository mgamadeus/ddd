<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinition;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecution;
use DDD\Infrastructure\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[LazyLoadRepo(LazyLoadRepo::DB, DBCronExecution::class)]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_FULLTEXT, indexColumns: ['teaser'])]
#[DatabaseIndex(indexType: DatabaseIndex::TYPE_FULLTEXT, indexColumns: ['composedA', 'composedB'])]
class FulltextProbeEntity extends Entity
{
    use QueryOptionsTrait, TranslatableTrait;

    public string $plain = '';

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_FULLTEXT)]
    public string $content = '';

    public string $teaser = '';

    public string $composedA = '';

    public string $composedB = '';

    #[Translatable(fullTextIndex: true)]
    public string $title = '';

    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_INDEX)]
    public string $plainlyIndexed = '';
}

class FiltersFulltextOperatorTest extends TestCase
{
    protected FiltersDefinitions $definitions;

    protected function setUp(): void
    {
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, 'DB');
        $this->definitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(FulltextProbeEntity::class);
        dddTestContainer()->getParameterBag()->remove(FiltersOptions::FULLTEXT_STRICT_PARAMETER);
    }

    protected function tearDown(): void
    {
        dddTestContainer()->getParameterBag()->remove(FiltersOptions::FULLTEXT_STRICT_PARAMETER);
    }

    protected function supportsFulltext(string $propertyName): ?bool
    {
        return $this->definitions->getFilterDefinitionForPropertyName($propertyName)?->supportsFulltext;
    }

    public function testAPropertyLevelFulltextIndexIsDetected(): void
    {
        $this->assertTrue($this->supportsFulltext('content'));
    }

    public function testAClassLevelSingleColumnFulltextIndexIsDetected(): void
    {
        $this->assertTrue($this->supportsFulltext('teaser'));
    }

    public function testACompositeFulltextIndexDoesNotServeASingleColumnMatch(): void
    {
        // MySQL matches MATCH(col) against an index on exactly that column list — a (a, b) index answers 1191
        $this->assertFalse($this->supportsFulltext('composedA'));
        $this->assertFalse($this->supportsFulltext('composedB'));
    }

    public function testATranslatableFulltextPropertyAndItsVirtualColumnBothCount(): void
    {
        $this->assertTrue($this->supportsFulltext('title'), 'the property the caller filters on');
        $this->assertTrue($this->supportsFulltext(Translatable::getFullTextSearchVirtualColumnName('title')));
    }

    public function testAPropertyWithoutAFulltextIndexIsNotAdvertised(): void
    {
        $this->assertFalse($this->supportsFulltext('plain'));
        $this->assertFalse($this->supportsFulltext('plainlyIndexed'), 'a plain INDEX is not a FULLTEXT index');
    }

    public function testTheAssociativeDefinitionFormCanDeclareIt(): void
    {
        $definitions = new FiltersDefinitions(
            ['propertyName' => 'handDeclared', 'supportsFulltext' => true],
            'plainOne',
        );
        $this->assertTrue($definitions->getFilterDefinitionForPropertyName('handDeclared')->supportsFulltext);
        $this->assertFalse($definitions->getFilterDefinitionForPropertyName('plainOne')->supportsFulltext);
    }

    public function testValidationAcceptsAFulltextOperatorByDefaultEvenWithoutAnIndex(): void
    {
        // default OFF: the framework does not own the schema, so an undeclared index must not turn into a refusal
        $filters = FiltersOptions::fromString("plain ft 'search me'");
        $this->assertTrue($filters->validateAgainstDefinitions($this->definitions));
    }

    public function testStrictModeRefusesAFulltextOperatorWithoutAnIndex(): void
    {
        dddTestContainer()->setParameter(FiltersOptions::FULLTEXT_STRICT_PARAMETER, true);
        $filters = FiltersOptions::fromString("plain ft 'search me'");

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessageMatches('/no FULLTEXT index/');
        $filters->validateAgainstDefinitions($this->definitions);
    }

    public function testStrictModeStillAcceptsAnIndexedProperty(): void
    {
        dddTestContainer()->setParameter(FiltersOptions::FULLTEXT_STRICT_PARAMETER, true);
        foreach (["content ft 'x'", "teaser fb 'x'", "title ft 'x'"] as $filterQuery) {
            $filters = FiltersOptions::fromString($filterQuery);
            $this->assertTrue($filters->validateAgainstDefinitions($this->definitions), $filterQuery);
        }
    }

    public function testStrictModeLeavesNonFulltextOperatorsAlone(): void
    {
        dddTestContainer()->setParameter(FiltersOptions::FULLTEXT_STRICT_PARAMETER, true);
        $filters = FiltersOptions::fromString("plain eq 'x'");
        $this->assertTrue($filters->validateAgainstDefinitions($this->definitions));
    }
}
