<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'CounterRows')]
class DBCounterRowModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'CounterRow';

	public const string TABLE_NAME = 'CounterRows';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow';

	#[ORM\Column(type: 'string')]
	public ?string $businessKey;

	#[DatabaseColumn(onUpdateAction:'`counter` + COALESCE(VALUES(`counter`), 1)')]
	#[ORM\Column(type: 'integer')]
	public ?int $counter;

	#[DatabaseColumn(onUpdateAction:'`keptValue`')]
	#[ORM\Column(type: 'integer')]
	public ?int $keptValue;

	#[ORM\Column(type: 'string')]
	public ?string $label;

	#[ORM\Column(type: 'integer')]
	public ?int $amount;

	#[ORM\Column(type: 'float')]
	public ?float $ratio;

	#[ORM\Column(type: 'boolean')]
	public ?bool $isActive = true;

	#[ORM\Column(type: 'date')]
	public ?DateTime $dueOn;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $occurredAt;

	#[ORM\Column(type: 'integer')]
	public ?int $displayOrder;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}