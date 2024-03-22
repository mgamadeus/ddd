<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Translations;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'EntityTranslations')]
class DBEntityTranslationModel extends DoctrineModel
{
	public const MODEL_ALIAS = 'EntityTranslation';

	public const TABLE_NAME = 'EntityTranslations';

	public const ENTITY_CLASS = 'DDD\Domain\Common\Entities\Translations\EntityTranslation';

	#[ORM\Column(type: 'string')]
	public ?string $language;

	#[ORM\Column(type: 'string')]
	public ?string $content;

	#[ORM\Column(type: 'string')]
	public ?string $searchableContent;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

}