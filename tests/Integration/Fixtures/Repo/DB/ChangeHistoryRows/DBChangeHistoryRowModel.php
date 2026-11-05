<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\ChangeHistoryRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'ChangeHistoryRows')]
class DBChangeHistoryRowModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'ChangeHistoryRow';

	public const string TABLE_NAME = 'ChangeHistoryRows';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow';

	#[ORM\Column(type: 'string')]
	public ?string $label;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}