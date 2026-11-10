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
#[ORM\Table(name: 'WorkerTransportSamples')]
class DBWorkerTransportSampleModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'WorkerTransportSample';

	public const string TABLE_NAME = 'WorkerTransportSamples';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Workers\WorkerTransportSample';

	#[ORM\Column(type: 'string')]
	public string $transport;

	#[ORM\Column(type: 'datetime')]
	public DateTime $bucketStart;

	#[DatabaseColumn(onUpdateAction:'COALESCE(GREATEST(`waitingMax`, VALUES(`waitingMax`)), VALUES(`waitingMax`), `waitingMax`)')]
	#[ORM\Column(type: 'integer')]
	public ?int $waitingMax;

	#[DatabaseColumn(onUpdateAction:'COALESCE(VALUES(`waitingLast`), `waitingLast`)')]
	#[ORM\Column(type: 'integer')]
	public ?int $waitingLast;

	#[DatabaseColumn(onUpdateAction:'GREATEST(`inFlightMax`, VALUES(`inFlightMax`))')]
	#[ORM\Column(type: 'integer')]
	public int $inFlightMax = 0;

	#[ORM\Column(type: 'integer')]
	public int $inFlightLast = 0;

	#[DatabaseColumn(onUpdateAction:'GREATEST(`processCountMax`, VALUES(`processCountMax`))')]
	#[ORM\Column(type: 'integer')]
	public int $processCountMax = 0;

	#[ORM\Column(type: 'integer')]
	public int $processCountLast = 0;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastSampledAt;

	#[DatabaseColumn(onUpdateAction:'COALESCE(VALUES(`lastSampledByCommandAt`), `lastSampledByCommandAt`)')]
	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastSampledByCommandAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}