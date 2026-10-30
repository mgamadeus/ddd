<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\BlobRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'BlobRows')]
class DBBlobRowModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'BlobRow';

	public const string TABLE_NAME = 'BlobRows';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\BlobRows\BlobRow';

	#[ORM\Column(type: 'string')]
	public ?string $label;

	#[ORM\Column(type: 'string')]
	public mixed $payload;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}
