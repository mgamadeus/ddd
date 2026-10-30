<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\SearchRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\FullTextMode;
use DDD\Tests\Integration\Fixtures\Repo\DB\SearchRows\DBSearchRow;

/**
 * D14 `fullTextMode: DOCUMENT` + D19 `TOKEN_MATCH` fixture (§6 step 5). Deliberately NOT part of
 * {@see \DDD\Tests\Integration\IntegrationTestCase::FIXTURE_ENTITY_CLASSES} — that constant's three entities
 * (CounterRow/TranslatedRow/SpatialRow) are the ones captured in `tests/golden/mariadb/*.sql`; adding a fourth
 * there would change every golden-backed test class's `setUpBeforeClass.sql` capture. This entity's own table is
 * created/dropped only by {@see \DDD\Tests\Integration\Query\TokenMatchAndDocumentFulltextTest}, which overrides
 * `setUpBeforeClass()`/`tearDownAfterClass()` to add it on top of (never instead of) the shared three.
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBSearchRow::class)]
#[QueryOptions]
class SearchRow extends Entity
{
    use QueryOptionsTrait, TranslatableTrait;

    /** @var string|null Long-text fulltext (D14 DOCUMENT mode — `to_tsvector`/`websearch_to_tsquery` on PostgreSQL) */
    #[Translatable(fullTextIndex: true, fullTextMode: FullTextMode::DOCUMENT)]
    public ?string $summary;

    /** @var string|null Whitespace-joined exact token list (D19/Mig §3.5, the Radbonus `Tracks.allocations` shape) */
    #[DatabaseIndex(DatabaseIndex::TYPE_FULLTEXT, fullTextMode: FullTextMode::TOKEN_MATCH)]
    public ?string $tags;
}
