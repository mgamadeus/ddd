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
#[ORM\Table(name: 'CronExecutions')]
class DBCronExecutionModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'CronExecution';

	public const string TABLE_NAME = 'CronExecutions';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Crons\CronExecution';

	#[ORM\Column(type: 'integer')]
	public ?int $cronId;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $executionStartedAt;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $executionEndedAt;

	#[ORM\Column(type: 'string')]
	public ?string $state;

	#[ORM\Column(type: 'string')]
	public ?string $executionState;

	#[ORM\Column(type: 'string')]
	public ?string $output;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBCronModel::class)]
	#[ORM\JoinColumn(name: 'cronId', referencedColumnName: 'id')]
	public ?DBCronModel $cron;

}