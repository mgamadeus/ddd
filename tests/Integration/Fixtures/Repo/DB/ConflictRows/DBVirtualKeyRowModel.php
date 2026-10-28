<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'VirtualKeyRows')]
class DBVirtualKeyRowModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'VirtualKeyRow';

	public const string TABLE_NAME = 'VirtualKeyRows';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\ConflictRows\VirtualKeyRow';

	public static array $virtualColumns = ['virtualCountryId' => [ 'createIndex' => false, 'stored' => true, 'as' => '(IFNULL(countryId, 0))', 'referenceColumn' => 'countryId', 'referenceColumnStored' => true, ]];

	#[ORM\Column(type: 'string')]
	public ?string $groupKey;

	#[ORM\Column(type: 'integer')]
	public ?int $countryId;

	#[ORM\Column(type: 'string')]
	public ?string $label;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'integer')]
	public int $virtualCountryId;

}