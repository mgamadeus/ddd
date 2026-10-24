<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'TranslatedRows')]
class DBTranslatedRowModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'TranslatedRow';

	public const string TABLE_NAME = 'TranslatedRows';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRow';

	public static array $virtualColumns = ['virtualNameSearch' => [ 'createIndex' => false, 'stored' => true, 'as' => '( CASE WHEN name IS NULL OR JSON_VALID(name) = 0 THEN \'\' ELSE REGEXP_REPLACE( TRIM( BOTH \' \' FROM REGEXP_REPLACE( REGEXP_REPLACE(JSON_UNQUOTE(name), \'^\\\\{\\\\s*|\\\\s*\\\\}\\\\s*$\', \'\'), \'"[^"]+"\\\\s*:\\\\s*"([^"]*)"\\\\s*(,\\\\s*)?\', \'\\\\1 | \' ) ), \'\\\\s*\\\\|\\\\s*$\', \'\' ) END )', 'referenceColumn' => 'nameSearch', 'referenceColumnStored' => true, ]];

	#[DatabaseColumn(isMergableJSONColumn: true)]
	#[ORM\Column(type: 'json')]
	public mixed $name;

	#[DatabaseColumn(isMergableJSONColumn: true)]
	#[ORM\Column(type: 'json')]
	public mixed $title;

	#[DatabaseColumn(isMergableJSONColumn: true)]
	#[ORM\Column(type: 'json')]
	public mixed $settings;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'string')]
	public string $virtualNameSearch;

}