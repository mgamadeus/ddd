<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Translations;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\Lazyload\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Repo\DB\Translations\DBEntityTranslation;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Represents the entity of a Translation
 * @method static DBEntityTranslation getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBEntityTranslation::class)]
class EntityTranslation extends Entity
{
    /** @var string Language of Translation */
    #[Length(max: 2, min: 2)]
    public string $language;

    /** @var string Content of the translation */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public string $content;

    /** @var string Searchable content with fulltext index (concatenated columns for rapid search operations) */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_FULLTEXT)]
    public string $searchableContent;
}
