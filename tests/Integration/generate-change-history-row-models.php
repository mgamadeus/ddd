<?php

declare(strict_types=1);

/**
 * Generates the DB*Model.php files of the ChangeHistoryRows fixture (an entity with ChangeHistory) —
 * same mechanism as generate-fixture-models.php, scoped to this class, which are not part of
 * IntegrationTestCase::FIXTURE_ENTITY_CLASSES. Run after changing it: php tests/Integration/generate-change-history-row-models.php
 */

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;

require __DIR__ . '/bootstrap.php';

(new ReflectionProperty(\DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo::class, 'defaultRepoType'))
    ->setValue(null, \DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo::DB);

foreach ([
    \DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow::class,
] as $fixtureEntityClass) {
    $databaseModel = DatabaseModel::fromEntityClass($fixtureEntityClass);
    $modelFile = $databaseModel->getModelClassNameWithNameSpace()->filename;
    file_put_contents($modelFile, $databaseModel->getDoctrineModelCode());
    echo "generated $modelFile\n";
}
