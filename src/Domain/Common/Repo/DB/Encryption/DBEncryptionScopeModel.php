<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Encryption;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'EncryptionScopes')]
class DBEncryptionScopeModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'EncryptionScope';

	public const string TABLE_NAME = 'EncryptionScopes';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Encryption\EncryptionScope';

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