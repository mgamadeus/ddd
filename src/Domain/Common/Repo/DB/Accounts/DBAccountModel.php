<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Accounts;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'Accounts')]
class DBAccountModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'Account';

	public const string TABLE_NAME = 'Accounts';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Accounts\Account';

	#[ORM\Column(type: 'string')]
	public ?string $password;

	#[ORM\Column(type: 'string')]
	public ?string $email;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}