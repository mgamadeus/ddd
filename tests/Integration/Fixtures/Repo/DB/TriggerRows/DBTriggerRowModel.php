<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TriggerRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'TriggerRows')]
class DBTriggerRowModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'TriggerRow';

	public const string TABLE_NAME = 'TriggerRows';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\TriggerRows\TriggerRow';

	#[ORM\Column(type: 'string')]
	public ?string $code;

	#[ORM\Column(type: 'boolean')]
	public ?bool $active = true;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}
