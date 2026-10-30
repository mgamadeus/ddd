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
#[ORM\Table(name: 'StoredBaseChildren')]
class DBStoredBaseChildModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'StoredBaseChild';

	public const string TABLE_NAME = 'StoredBaseChildren';

	public const string ENTITY_CLASS = 'DDD\Tests\Integration\Fixtures\Entities\StoredBaseRows\StoredBaseChild';

	public static array $virtualColumns = ['virtualStoredBaseParentId' => [ 'createIndex' => false, 'stored' => true, 'as' => '(IFNULL(storedBaseParentId, 0))', 'referenceColumn' => 'storedBaseParentId', 'referenceColumnStored' => true, ]];

	#[ORM\Column(type: 'integer')]
	public ?int $storedBaseParentId;

	#[ORM\Column(type: 'string')]
	public ?string $label;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'integer')]
	public int $virtualStoredBaseParentId;

	#[ORM\ManyToOne(targetEntity: DBStoredBaseParentModel::class)]
	#[ORM\JoinColumn(name: 'storedBaseParentId', referencedColumnName: 'id')]
	public ?DBStoredBaseParentModel $storedBaseParent;

}