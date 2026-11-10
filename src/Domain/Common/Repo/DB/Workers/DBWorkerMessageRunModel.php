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
#[ORM\Table(name: 'WorkerMessageRuns')]
class DBWorkerMessageRunModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'WorkerMessageRun';

	public const string TABLE_NAME = 'WorkerMessageRuns';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Workers\WorkerMessageRun';

	#[ORM\Column(type: 'string')]
	public string $transport;

	#[ORM\Column(type: 'string')]
	public string $messageClass;

	#[ORM\Column(type: 'string')]
	public ?string $processKey;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $dispatchedAt;

	#[ORM\Column(type: 'string')]
	public ?string $dispatchedFromRoute;

	#[ORM\Column(type: 'string')]
	public ?string $dispatchedFromCommand;

	#[ORM\Column(type: 'datetime')]
	public DateTime $startedAt;

	#[ORM\Column(type: 'integer')]
	public int $durationMs = 0;

	#[ORM\Column(type: 'integer')]
	public ?int $waitMs;

	#[ORM\Column(type: 'string')]
	public string $outcome;

	#[ORM\Column(type: 'integer')]
	public int $attempt = 1;

	#[ORM\Column(type: 'boolean')]
	public ?bool $willRetry;

	#[ORM\Column(type: 'string')]
	public ?string $errorClass;

	#[ORM\Column(type: 'string')]
	public ?string $errorMessage;

	#[ORM\Column(type: 'string')]
	public ?string $issueGroupHash;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}