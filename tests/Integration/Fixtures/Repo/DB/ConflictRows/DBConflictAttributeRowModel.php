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
#[ORM\Table(name: 'ConflictAttributeRows')]
class DBConflictAttributeRowModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'ConflictAttributeRow';

	public const string TABLE_NAME = 'ConflictAttributeRows';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\ConflictRows\ConflictAttributeRow';

	#[ORM\Column(type: 'string')]
	public ?string $handle;

	#[ORM\Column(type: 'string')]
	public ?string $code;

	#[ORM\Column(type: 'string')]
	public ?string $label;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}