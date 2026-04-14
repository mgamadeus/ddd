<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'CacheScopeInvalidations')]
class DBCacheScopeInvalidationModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'CacheScopeInvalidation';

	public const string TABLE_NAME = 'CacheScopeInvalidations';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\CacheScopes\Invalidations\CacheScopeInvalidation';

	#[ORM\Column(type: 'string')]
	public string $cacheScope;

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[ORM\Column(type: 'integer')]
	public ?int $numberOfTimesToInvalidateCache;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $invalidateUntil;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAccountModel::class)]
	#[ORM\JoinColumn(name: 'accountId', referencedColumnName: 'id')]
	public ?DBAccountModel $account;

}