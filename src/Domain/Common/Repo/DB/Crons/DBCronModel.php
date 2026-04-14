<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Crons;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'Crons')]
class DBCronModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'Cron';

	public const string TABLE_NAME = 'Crons';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Crons\Cron';

	#[ORM\Column(type: 'string')]
	public ?string $name;

	#[ORM\Column(type: 'string')]
	public ?string $description;

	#[ORM\Column(type: 'string')]
	public ?string $schedule;

	#[ORM\Column(type: 'string')]
	public ?string $command;

	#[ORM\Column(type: 'boolean')]
	public ?bool $isActive = true;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $lastExecutionStartedAt;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $nextExecutionScheduledAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $updated;

	#[ORM\OneToMany(targetEntity: DBCronExecutionModel::class, mappedBy: 'cron')]
	public PersistentCollection $executions;

}