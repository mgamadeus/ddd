<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Schema;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Ordered {@see DdlStatement}s, as a {@see DdlRenderer} returns them; execution follows insertion order unless the
 * caller sorts them by phase ({@see self::sortedByPhase()}) to batch many tables together.
 *
 * @property DdlStatement[] $elements
 * @method DdlStatement|null first()
 * @method DdlStatement[] getElements()
 */
class DdlStatements extends ObjectSet
{
    /**
     * @param DdlStatement ...$statements
     * @return static
     */
    public static function fromStatements(DdlStatement ...$statements): static
    {
        $set = new static();
        foreach ($statements as $statement) {
            $set->add($statement);
        }
        return $set;
    }

    /**
     * The statements of this set followed by those of $other, as a new set.
     *
     * @param DdlStatements $other
     * @return static
     */
    public function withStatementsOf(DdlStatements $other): static
    {
        $merged = new static();
        foreach ([...$this->elements, ...$other->elements] as $statement) {
            $merged->add($statement);
        }
        return $merged;
    }

    /**
     * Stable sort by {@see DdlStatement::CREATE_PHASE_ORDER}, as a new set; statements with an unrecognised phase
     * sort last, in encounter order. Does not rely on PHP's sort stability (explicit position tie-break) so
     * behaviour is the same on any supported PHP version.
     *
     * @return static
     */
    public function sortedByPhase(): static
    {
        $rank = array_flip(DdlStatement::CREATE_PHASE_ORDER);
        $unknownRank = count(DdlStatement::CREATE_PHASE_ORDER);

        // method-local [rank, position, statement] triples, only to sort
        $decorated = [];
        foreach (array_values($this->elements) as $position => $statement) {
            $decorated[] = [$rank[$statement->phase] ?? $unknownRank, $position, $statement];
        }
        usort($decorated, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $sorted = new static();
        foreach ($decorated as $entry) {
            $statement = $entry[2];
            $sorted->add($statement);
        }
        return $sorted;
    }

    /**
     * The statements of one phase, in order, as a new set.
     *
     * @param string $phase One of the DdlStatement::PHASE_* constants
     * @return static
     */
    public function withPhase(string $phase): static
    {
        return static::fromStatements(...array_filter(
            array_values($this->elements),
            static fn (DdlStatement $statement): bool => $statement->phase === $phase
        ));
    }

    /**
     * The statements of every other phase, in order, as a new set.
     *
     * @param string $phase One of the DdlStatement::PHASE_* constants
     * @return static
     */
    public function withoutPhase(string $phase): static
    {
        return static::fromStatements(...array_filter(
            array_values($this->elements),
            static fn (DdlStatement $statement): bool => $statement->phase !== $phase
        ));
    }

    /** @return string[] The SQL of every statement, in order */
    public function getSqlList(): array
    {
        return array_map(static fn (DdlStatement $statement): string => $statement->sql, array_values($this->elements));
    }
}
