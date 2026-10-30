<?php

declare(strict_types=1);

/**
 * Throwaway generator for the two ConflictRows fixtures (2026-10-25 UpsertConflictKey + identity-catch-up task)
 * — same mechanism as generate-fixture-models.php, scoped to the two classes this change adds, since they are
 * deliberately NOT part of IntegrationTestCase::FIXTURE_ENTITY_CLASSES (see the test class's own docblock for
 * why). Run after changing either fixture entity: php tests/Integration/generate-conflict-row-models.php
 */

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;

require __DIR__ . '/bootstrap.php';

(new ReflectionProperty(\DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo::class, 'defaultRepoType'))
    ->setValue(null, \DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo::DB);

$classes = [
    \DDD\Tests\Integration\Fixtures\Entities\ConflictRows\AmbiguousConflictRow::class,
    \DDD\Tests\Integration\Fixtures\Entities\ConflictRows\ConflictAttributeRow::class,
    \DDD\Tests\Integration\Fixtures\Entities\ConflictRows\VirtualKeyRow::class,
];

foreach ($classes as $fixtureEntityClass) {
    $databaseModel = DatabaseModel::fromEntityClass($fixtureEntityClass);
    $modelFile = $databaseModel->getModelClassNameWithNameSpace()->filename;
    file_put_contents($modelFile, $databaseModel->getDoctrineModelCode());
    echo "generated $modelFile\n";
}
