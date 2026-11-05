<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\StoredBaseRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'StoredBaseParents')]
class DBStoredBaseParentModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'StoredBaseParent';

	public const string TABLE_NAME = 'StoredBaseParents';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\StoredBaseRows\StoredBaseParent';

	#[ORM\Column(type: 'string')]
	public ?string $label;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}