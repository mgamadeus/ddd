<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Encryption;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'EntityEncryptionScopes')]
class DBEncryptionScopeModel extends DoctrineModel
{
	public const MODEL_ALIAS = 'EncryptionScope';

	public const TABLE_NAME = 'EntityEncryptionScopes';

	public const ENTITY_CLASS = 'DDD\Domain\Common\Entities\Encryption\EncryptionScope';

	#[ORM\Column(type: 'string')]
	public ?string $scope;

	#[ORM\Column(type: 'string')]
	public ?string $description;

	#[ORM\Column(type: 'string')]
	public ?string $scopePassword;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $updated;

}