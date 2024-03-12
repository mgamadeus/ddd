<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Encryption;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'EncryptionScopePasswords')]
class DBEncryptionScopePasswordModel extends DoctrineModel
{
	public const MODEL_ALIAS = 'EncryptionScopePassword';

	public const TABLE_NAME = 'EncryptionScopePasswords';

	public const ENTITY_CLASS = 'DDD\Domain\Common\Entities\Encryption\EncryptionScopePassword';

	#[ORM\Column(type: 'integer')]
	public ?int $encryptionScopeId;

	#[ORM\Column(type: 'string')]
	public ?string $passwordHash;

	#[ORM\Column(type: 'string')]
	public ?string $encryptionScopePassword;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBEncryptionScopeModel::class)]
	#[ORM\JoinColumn(name: 'encryptionScopeId', referencedColumnName: 'id')]
	public ?DBEncryptionScopeModel $encryptionScope;

}