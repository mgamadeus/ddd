<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Workers;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'WorkerProcesses')]
class DBWorkerProcessModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'WorkerProcess';

	public const string TABLE_NAME = 'WorkerProcesses';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Workers\WorkerProcess';

	#[ORM\Column(type: 'string')]
	public string $processKey;

	#[ORM\Column(type: 'string')]
	public ?string $hostName;

	#[ORM\Column(type: 'integer')]
	public ?int $processId;

	#[ORM\Column(type: 'string')]
	public string $transport;

	#[ORM\Column(type: 'datetime')]
	public DateTime $startedAt;

	#[DatabaseColumn(onUpdateAction:'COALESCE(`firstMessageAt`, VALUES(`firstMessageAt`))')]
	#[ORM\Column(type: 'datetime')]
	public ?DateTime $firstMessageAt;

	#[ORM\Column(type: 'datetime')]
	public DateTime $lastSeenAt;

	#[ORM\Column(type: 'string')]
	public string $state;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $stoppedAt;

	#[ORM\Column(type: 'string')]
	public ?string $stopReason;

	#[ORM\Column(type: 'integer')]
	public int $handledCount = 0;

	#[ORM\Column(type: 'integer')]
	public int $failedCount = 0;

	#[ORM\Column(type: 'integer')]
	public ?int $memoryBytes;

	#[ORM\Column(type: 'integer')]
	public ?int $memoryPeakBytes;

	#[ORM\Column(type: 'string')]
	public ?string $currentMessageClass;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $currentMessageSince;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $restartRequestedAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}