<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\GeographyRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'GeographyRows')]
class DBGeographyRowModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'GeographyRow';

	public const string TABLE_NAME = 'GeographyRows';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\GeographyRows\GeographyRow';

	#[ORM\Column(type: 'string')]
	public ?string $label;

	#[ORM\Column(type: 'cartesian_linestring')]
	public mixed $route;

	#[ORM\Column(type: 'point')]
	public mixed $position;

	#[ORM\Column(type: 'point')]
	public mixed $plainPosition;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}