<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\SearchRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'SearchRows')]
class DBSearchRowModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'SearchRow';

	public const string TABLE_NAME = 'SearchRows';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\SearchRows\SearchRow';

	/** Mirrors DBTranslatedRowModel's own virtualNameSearch shape (generator template, DatabaseModel.php:257-296),
	 *  with every 'name' token renamed to 'summary' — the ONE virtual-column shape step 4a's PostgresDdlRenderer
	 *  ports (ddd_translatable_text), regardless of fullTextMode (D14: the search column itself is mode-agnostic,
	 *  only its INDEX differs). */
	public static array $virtualColumns = ['virtualSummarySearch' => [ 'createIndex' => false, 'stored' => true, 'as' => '( CASE WHEN summary IS NULL OR JSON_VALID(summary) = 0 THEN \'\' ELSE REGEXP_REPLACE( TRIM( BOTH \' \' FROM REGEXP_REPLACE( REGEXP_REPLACE(JSON_UNQUOTE(summary), \'^\\\\{\\\\s*|\\\\s*\\\\}\\\\s*$\', \'\'), \'"[^"]+"\\\\s*:\\\\s*"([^"]*)"\\\\s*(,\\\\s*)?\', \'\\\\1 | \' ) ), \'\\\\s*\\\\|\\\\s*$\', \'\' ) END )', 'referenceColumn' => 'summarySearch', 'referenceColumnStored' => true, ]];

	#[DatabaseColumn(isMergableJSONColumn: true)]
	#[ORM\Column(type: 'json')]
	public mixed $summary;

	#[ORM\Column(type: 'string')]
	public ?string $tags;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'string')]
	public string $virtualSummarySearch;

}
