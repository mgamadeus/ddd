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
#[ORM\Table(name: 'IssueGroups')]
class DBIssueGroupModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'IssueGroup';

	public const string TABLE_NAME = 'IssueGroups';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Issues\IssueGroup';

	#[ORM\Column(type: 'string')]
	public ?string $groupHash;

	#[ORM\Column(type: 'string')]
	public ?string $status = 'OPEN';

	#[ORM\Column(type: 'string')]
	public ?string $exceptionType;

	#[ORM\Column(type: 'string')]
	public ?string $sampleMessage;

	#[ORM\Column(type: 'string')]
	public ?string $slug;

	#[ORM\Column(type: 'string')]
	public ?string $logOrigin;

	#[ORM\Column(type: 'string')]
	public ?string $section;

	#[ORM\Column(type: 'string')]
	public ?string $environment;

	#[ORM\Column(type: 'string')]
	public ?string $workspace;

	#[ORM\Column(type: 'string')]
	public ?string $sampleEventId;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $firstSeenAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastSeenAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $registeredAt;

	#[ORM\Column(type: 'integer')]
	public ?int $eventCount = 0;

	#[ORM\Column(type: 'string')]
	public ?string $releaseFirstSeen;

	#[ORM\Column(type: 'string')]
	public ?string $releaseLastSeen;

	#[ORM\Column(type: 'json')]
	public mixed $entryPoints;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $resolvedAt;

	#[ORM\Column(type: 'boolean')]
	public ?bool $isNew = false;

	#[ORM\Column(type: 'boolean')]
	public ?bool $isRegression = false;

	#[ORM\Column(type: 'boolean')]
	public ?bool $alertPending = false;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $alertSentAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\OneToMany(targetEntity: DBIssueModel::class, mappedBy: 'issueGroup')]
	public PersistentCollection $issues;

}