<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use PHPUnit\Framework\TestCase;

/**
 * The referential actions each dialect gets for a FK on the base of a STORED generated column
 * ({@see DatabaseForeignKey::getOnUpdateActionForDialect()}).
 */
class DatabaseForeignKeyDialectActionsTest extends TestCase
{
    protected function createForeignKey(string $onUpdateAction, string $onDeleteAction, bool $isBaseOfStoredGeneratedColumn = true): DatabaseForeignKey
    {
        $databaseForeignKey = new DatabaseForeignKey(onUpdateAction: $onUpdateAction, onDeleteAction: $onDeleteAction);
        $databaseForeignKey->isBaseOfStoredGeneratedColumn = $isBaseOfStoredGeneratedColumn;
        $databaseForeignKey->internalIdColumn = 'teamId';
        $databaseForeignKey->foreignTable = 'Teams';
        return $databaseForeignKey;
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> */
    public static function actionCases(): array
    {
        $cascade = DatabaseForeignKey::ACTION_CASCADE;
        $setNull = DatabaseForeignKey::ACTION_SET_NULL;
        $setDefault = DatabaseForeignKey::ACTION_SET_DEFAULT;
        $restrict = DatabaseForeignKey::ACTION_RESTRICT;
        $noAction = DatabaseForeignKey::ACTION_NO_ACTION;
        // declared update, declared delete → MariaDB update, MariaDB delete, PostgreSQL update, PostgreSQL delete
        return [
            'cascade / cascade' => [$cascade, $cascade, $restrict, $cascade, $cascade, $cascade],
            'cascade / set null' => [$cascade, $setNull, $restrict, $restrict, $cascade, $setNull],
            'set default / set default' => [$setDefault, $setDefault, $restrict, $restrict, $setDefault, $setDefault],
            'restrict / restrict' => [$restrict, $restrict, $restrict, $restrict, $restrict, $restrict],
            'no action / cascade' => [$noAction, $cascade, $noAction, $cascade, $noAction, $cascade],
        ];
    }

    /**
     * @dataProvider actionCases
     */
    public function testEachDialectGetsItsActions(
        string $declaredOnUpdate,
        string $declaredOnDelete,
        string $mariaDbOnUpdate,
        string $mariaDbOnDelete,
        string $postgresOnUpdate,
        string $postgresOnDelete
    ): void {
        $databaseForeignKey = $this->createForeignKey($declaredOnUpdate, $declaredOnDelete);

        self::assertSame($mariaDbOnUpdate, $databaseForeignKey->getOnUpdateActionForDialect(SqlDialect::MARIADB));
        self::assertSame($mariaDbOnDelete, $databaseForeignKey->getOnDeleteActionForDialect(SqlDialect::MARIADB));
        self::assertSame($postgresOnUpdate, $databaseForeignKey->getOnUpdateActionForDialect(SqlDialect::POSTGRES));
        self::assertSame($postgresOnDelete, $databaseForeignKey->getOnDeleteActionForDialect(SqlDialect::POSTGRES));
        // the declared intent stays on the object
        self::assertSame($declaredOnUpdate, $databaseForeignKey->onUpdateAction);
        self::assertSame($declaredOnDelete, $databaseForeignKey->onDeleteAction);
    }

    public function testAForeignKeyWithoutAStoredGeneratedColumnKeepsItsActionsOnMariaDb(): void
    {
        $databaseForeignKey = $this->createForeignKey(DatabaseForeignKey::ACTION_CASCADE, DatabaseForeignKey::ACTION_SET_NULL, false);

        self::assertSame(DatabaseForeignKey::ACTION_CASCADE, $databaseForeignKey->getOnUpdateActionForDialect(SqlDialect::MARIADB));
        self::assertSame(DatabaseForeignKey::ACTION_SET_NULL, $databaseForeignKey->getOnDeleteActionForDialect(SqlDialect::MARIADB));
        self::assertFalse($databaseForeignKey->requiresCopyAlgorithmOnMariaDb());
    }

    public function testOnDeleteCascadeOnTheBaseOfAStoredColumnIsAddedWithTheCopyAlgorithm(): void
    {
        $cascadeForeignKey = $this->createForeignKey(DatabaseForeignKey::ACTION_CASCADE, DatabaseForeignKey::ACTION_CASCADE);
        $restrictForeignKey = $this->createForeignKey(DatabaseForeignKey::ACTION_RESTRICT, DatabaseForeignKey::ACTION_RESTRICT);

        self::assertTrue($cascadeForeignKey->requiresCopyAlgorithmOnMariaDb());
        self::assertStringEndsWith('ON UPDATE RESTRICT ON DELETE CASCADE, ALGORITHM=COPY', $cascadeForeignKey->getSql('Journals'));
        self::assertFalse($restrictForeignKey->requiresCopyAlgorithmOnMariaDb());
        self::assertStringEndsWith('ON UPDATE RESTRICT ON DELETE RESTRICT', $restrictForeignKey->getSql('Journals'));
    }
}
