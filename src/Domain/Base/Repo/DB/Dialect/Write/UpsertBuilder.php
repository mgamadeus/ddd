<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\QueryBuilder;

/**
 * One collaborator per engine (dual-dialect architecture plan §4.3 `SqlDialect::upsertBuilder()`), executing the
 * three write shapes `DatabaseRepoEntity`/`DatabaseRepoEntitySet` drive:
 * {@see \DDD\Domain\Base\Repo\DatabaseRepoEntity::update()} (→ `upsert()`),
 * {@see \DDD\Domain\Base\Repo\DB\DBEntity::updatePartialIgnoringRights()} (→ `updateColumnsById()`) and
 * {@see \DDD\Domain\Base\Repo\DatabaseRepoEntitySet::batchUpdate()} (→ `upsertMultiple()`). Deviation from the
 * plan's zero-arg `SqlDialect::upsertBuilder(): UpsertBuilder` signature: the builder is stateless (plan rule 3),
 * but executing needs a connection, so every method here takes the calling `DoctrineEntityManager` explicitly
 * instead of the dialect caching one internally.
 */
interface UpsertBuilder
{
    public function upsert(
        DoctrineEntityManager $entityManager,
        DoctrineModel &$doctrineModel,
        ?QueryBuilder $updateRightsQueryBuilder = null,
        ?array $onlyFieldNames = null
    ): ?int;

    public function updateColumnsById(DoctrineEntityManager $entityManager, DoctrineModel &$doctrineModel, array $fieldNames): ?int;

    /** @param DoctrineModel[] $doctrineModels */
    public function upsertMultiple(DoctrineEntityManager $entityManager, array &$doctrineModels, bool $useInsertIgnore = false): void;

    /**
     * "Insert or lose the race": a plain INSERT that returns null on a unique-key collision instead of merging
     * onto the row that won the race (architecture §4.3 — Dineful `ServiceTasksService::insertIfAbsent()`,
     * `TableAssignmentsService::tryAssign()`). Every OTHER error throws.
     * @throws Exception
     */
    public function insertOrNull(DoctrineEntityManager $entityManager, DoctrineModel &$doctrineModel): ?int;
}
