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
#[ORM\Table(name: 'WorkerStatBuckets')]
class DBWorkerStatBucketModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'WorkerStatBucket';

	public const string TABLE_NAME = 'WorkerStatBuckets';

	public const string ENTITY_CLASS = 'DDD\Domain\Common\Entities\Workers\WorkerStatBucket';

	#[ORM\Column(type: 'string')]
	public string $transport;

	#[ORM\Column(type: 'string')]
	public string $messageClass;

	#[ORM\Column(type: 'datetime')]
	public DateTime $bucketStart;

	#[DatabaseColumn(onUpdateAction:'`handledCount` + VALUES(`handledCount`)')]
	#[ORM\Column(type: 'integer')]
	public int $handledCount = 0;

	#[DatabaseColumn(onUpdateAction:'`thrownCount` + VALUES(`thrownCount`)')]
	#[ORM\Column(type: 'integer')]
	public int $thrownCount = 0;

	#[DatabaseColumn(onUpdateAction:'`caughtCount` + VALUES(`caughtCount`)')]
	#[ORM\Column(type: 'integer')]
	public int $caughtCount = 0;

	#[DatabaseColumn(onUpdateAction:'`redeliveredCount` + VALUES(`redeliveredCount`)')]
	#[ORM\Column(type: 'integer')]
	public int $redeliveredCount = 0;

	#[DatabaseColumn(onUpdateAction:'`durationTotalMs` + VALUES(`durationTotalMs`)')]
	#[ORM\Column(type: 'integer')]
	public int $durationTotalMs = 0;

	#[DatabaseColumn(onUpdateAction:'GREATEST(`durationMaxMs`, VALUES(`durationMaxMs`))')]
	#[ORM\Column(type: 'integer')]
	public int $durationMaxMs = 0;

	#[DatabaseColumn(onUpdateAction:'`waitCount` + VALUES(`waitCount`)')]
	#[ORM\Column(type: 'integer')]
	public int $waitCount = 0;

	#[DatabaseColumn(onUpdateAction:'`waitTotalMs` + VALUES(`waitTotalMs`)')]
	#[ORM\Column(type: 'integer')]
	public int $waitTotalMs = 0;

	#[DatabaseColumn(onUpdateAction:'GREATEST(`waitMaxMs`, VALUES(`waitMaxMs`))')]
	#[ORM\Column(type: 'integer')]
	public int $waitMaxMs = 0;

	#[DatabaseColumn(onUpdateAction:'COALESCE(VALUES(`lastErrorClass`), `lastErrorClass`)')]
	#[ORM\Column(type: 'string')]
	public ?string $lastErrorClass;

	#[DatabaseColumn(onUpdateAction:'COALESCE(VALUES(`lastErrorMessage`), `lastErrorMessage`)')]
	#[ORM\Column(type: 'string')]
	public ?string $lastErrorMessage;

	#[DatabaseColumn(onUpdateAction:'COALESCE(VALUES(`lastErrorAt`), `lastErrorAt`)')]
	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastErrorAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}