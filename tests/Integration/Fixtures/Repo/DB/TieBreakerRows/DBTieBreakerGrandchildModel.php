<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'TieBreakerGrandchildren')]
class DBTieBreakerGrandchildModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'TieBreakerGrandchild';

	public const string TABLE_NAME = 'TieBreakerGrandchildren';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerGrandchild';

	#[ORM\Column(type: 'integer')]
	public int $tieBreakerChildId;

	#[ORM\Column(type: 'string')]
	public ?string $label;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\ManyToOne(targetEntity: DBTieBreakerChildModel::class)]
	#[ORM\JoinColumn(name: 'tieBreakerChildId', referencedColumnName: 'id')]
	public DBTieBreakerChildModel $tieBreakerChild;

}