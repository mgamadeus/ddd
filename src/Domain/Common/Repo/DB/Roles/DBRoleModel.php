<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Roles;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'Roles')]
class DBRoleModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'Role';

	public const string TABLE_NAME = 'Roles';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Roles\Role';

	#[ORM\Column(type: 'string')]
	public ?string $name;

	#[ORM\Column(type: 'string')]
	public ?string $description;

	#[ORM\Column(type: 'boolean')]
	public ?bool $isAdminRole = false;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}