<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Issues;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'Issues')]
class DBIssueModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'Issue';

	public const string TABLE_NAME = 'Issues';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Issues\Issue';

	#[ORM\Column(type: 'string')]
	public ?string $issueHash;

	#[ORM\Column(type: 'string')]
	public ?string $environment;

	#[ORM\Column(type: 'integer')]
	public ?int $issueGroupId;

	#[ORM\Column(type: 'string')]
	public ?string $entryPoint;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $firstSeenAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastSeenAt;

	#[ORM\Column(type: 'integer')]
	public ?int $eventCount = 0;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBIssueGroupModel::class)]
	#[ORM\JoinColumn(name: 'issueGroupId', referencedColumnName: 'id')]
	public ?DBIssueGroupModel $issueGroup;

}