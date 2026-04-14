<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Accounts\LoginTokens;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'LoginTokens')]
class DBLoginTokenModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'LoginToken';

	public const string TABLE_NAME = 'LoginTokens';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Accounts\LoginTokens\LoginToken';

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[ORM\Column(type: 'string')]
	public ?string $token;

	#[ORM\Column(type: 'integer')]
	public ?int $usageLimit;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $validUntil;

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