<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Database\UpsertConflictKey;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionClass;

/**
 * Resolves the `ON CONFLICT` arbiter for one row (dual-dialect architecture plan §4.6, Mig §3.3 "Conflict target,
 * inferred"). Full rule per Mig §3.3: (1) id set → PK; (2) one UNIQUE key left after dropping supersets → that
 * key; (3) several → the single key whose columns are all set on this row; (4) still ambiguous →
 * `#[UpsertConflictKey([...])]` when every ambiguous candidate is NOT-NULL-backed; when at least one ambiguous
 * candidate has a nullable column, the runtime fallback instead ({@see ConflictTargetResolution::nullableFallback()}).
 *
 * **(1)-(3) unchanged** from the pre-PostgreSQL-write-path implementation (no supersets-dropping logic was ever
 * added here — out of scope for this change, no fixture or known entity needs it). **New here: (4).**
 *
 * `#[UpsertConflictKey]` and the nullable-column check are evaluated in that order — the attribute wins even
 * when one of the ambiguous candidates happens to have a nullable column, since the whole point of declaring it
 * is "I know which key this write relies on"; the nullable fallback only fires when NO attribute resolved the
 * row, which the generator-fail rule (Mig §3.3: "fails for an entity with two or more non-redundant keys made
 * only of NOT NULL columns and no attribute") would otherwise force you to add anyway if every candidate were
 * NOT-NULL-backed.
 */
class ConflictTargetResolver
{
    /**
     * @param string[] $presentColumnNames The row's own (non-identifier) column names — a candidate UNIQUE key
     *   qualifies only when every one of its columns is among them.
     */
    public static function resolve(DatabaseModel $databaseModel, array $presentColumnNames, bool $hasId): ConflictTargetResolution
    {
        if ($hasId) {
            $primaryKeyColumn = $databaseModel->columns?->getPrimaryKeyColumn();
            return ConflictTargetResolution::target($primaryKeyColumn !== null ? [$primaryKeyColumn->name] : []);
        }

        $candidates = [];
        foreach ($databaseModel->indexes->getElements() as $index) {
            if ($index->indexType !== DatabaseIndex::TYPE_UNIQUE || $index->indexColumns === []) {
                continue;
            }
            if (array_diff($index->indexColumns, $presentColumnNames) === []) {
                $candidates[] = $index->indexColumns;
            }
        }
        if ($candidates === []) {
            return ConflictTargetResolution::target([]);
        }
        if (count($candidates) === 1) {
            return ConflictTargetResolution::target($candidates[0]);
        }

        $declaredColumns = self::declaredConflictKeyColumns($databaseModel, $candidates);
        if ($declaredColumns !== null) {
            return ConflictTargetResolution::target($declaredColumns);
        }

        if (!self::everyCandidateIsNotNullBacked($databaseModel, $candidates)) {
            return ConflictTargetResolution::nullableFallback($candidates);
        }

        throw new InternalErrorException(
            'ConflictTargetResolver: "' . $databaseModel->sqlTableName . '" (' . ($databaseModel->entityClassWithNamespace?->getNameWithNamespace() ?? 'unknown entity')
            . ') has ' . count($candidates) . ' UNIQUE keys whose columns are all set on this row — ambiguous '
            . 'arbiter: ' . self::describeCandidates($candidates) . '. Declare #[UpsertConflictKey(columns: [...])] '
            . 'on the entity naming the one key this entity\'s write path relies on being merged.'
        );
    }

    /**
     * @param string[][] $candidates
     * @return string[]|null The matching candidate's column list (declaration order), or null when no
     *   `#[UpsertConflictKey]` is present on the entity at all.
     * @throws InternalErrorException when the attribute IS present but names columns matching none of the
     *   ambiguous candidates (typo / renamed column) — caught at the first write rather than silently ignored.
     */
    protected static function declaredConflictKeyColumns(DatabaseModel $databaseModel, array $candidates): ?array
    {
        $entityClassName = $databaseModel->entityClassWithNamespace?->getNameWithNamespace();
        if ($entityClassName === null) {
            return null;
        }
        /** @var UpsertConflictKey|null $attributeInstance */
        $attributeInstance = ReflectionClass::instance($entityClassName)->getAttributeInstance(UpsertConflictKey::class);
        if ($attributeInstance === null) {
            return null;
        }

        $declared = $attributeInstance->columns;
        sort($declared);
        foreach ($candidates as $candidate) {
            $sortedCandidate = $candidate;
            sort($sortedCandidate);
            if ($sortedCandidate === $declared) {
                return $candidate;
            }
        }

        throw new InternalErrorException(
            'ConflictTargetResolver: #[UpsertConflictKey] on ' . $entityClassName . ' names columns ('
            . implode(', ', $attributeInstance->columns) . ') that match none of this row\'s ambiguous UNIQUE '
            . 'candidates: ' . self::describeCandidates($candidates) . ' — check for a typo or a renamed column.'
        );
    }

    /** @param string[][] $candidates */
    protected static function everyCandidateIsNotNullBacked(DatabaseModel $databaseModel, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            foreach ($candidate as $columnName) {
                $column = $databaseModel->columns?->getColumnByName($columnName);
                if ($column === null || $column->allowsNull) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param string[][] $candidates */
    protected static function describeCandidates(array $candidates): string
    {
        return implode('; ', array_map(
            static fn (array $columns): string => '(' . implode(', ', $columns) . ')',
            $candidates
        ));
    }
}
