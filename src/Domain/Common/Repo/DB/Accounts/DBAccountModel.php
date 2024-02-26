<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Accounts;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'EntityAccounts')]
class DBAccountModel extends DoctrineModel
{
	public const MODEL_ALIAS = 'Account';

	public const TABLE_NAME = 'EntityAccounts';

	public const ENTITY_CLASS = 'DDD\Domain\Common\Entities\Accounts\Account';

	#[ORM\Column(type: 'string')]
	public ?string $password;

	#[ORM\Column(type: 'string')]
	public ?string $email;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}