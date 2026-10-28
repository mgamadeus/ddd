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
#[ORM\Table(name: 'IssuesJobStates')]
class DBIssuesJobStateModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'IssuesJobState';

	public const string TABLE_NAME = 'IssuesJobStates';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Issues\IssuesJobState';

	#[ORM\Column(type: 'string')]
	public ?string $name = 'default';

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $watermark;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $activatedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $seedUntil;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastRunStartedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastSuccessfulRunAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $newestEventSeenAt;

	#[ORM\Column(type: 'string')]
	public ?string $lastError;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $explosionAlertSentAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}