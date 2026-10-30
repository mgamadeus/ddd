<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\SpatialRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'SpatialRows')]
class DBSpatialRowModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'SpatialRow';

	public const string TABLE_NAME = 'SpatialRows';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\SpatialRows\SpatialRow';

	#[ORM\Column(type: 'string')]
	public ?string $label;

	#[ORM\Column(type: 'point')]
	public mixed $location;

	#[ORM\Column(type: 'vector', length: 3)]
	public mixed $embedding;

	#[ORM\Column(type: 'cartesian_polygon')]
	public mixed $layout;

	#[ORM\Column(type: 'boolean')]
	public ?bool $flag = false;

	#[ORM\Column(type: 'json')]
	public mixed $payload;

	#[ORM\Column(type: 'integer')]
	public ?int $amount;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}