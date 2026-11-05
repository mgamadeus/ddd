<?php

declare(strict_types=1);

/**
 * Generates the DB*Model.php files of the StoredBaseRows fixtures (a FK on the base of a STORED generated column) —
 * same mechanism as generate-fixture-models.php, scoped to these two classes, which are not part of
 * IntegrationTestCase::FIXTURE_ENTITY_CLASSES. Run after changing either: php tests/Integration/generate-stored-base-row-models.php
 */

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;

require __DIR__ . '/bootstrap.php';

(new ReflectionProperty(\DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo::class, 'defaultRepoType'))
    ->setValue(null, \DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo::DB);

foreach ([
    \DDD\Tests\Integration\Fixtures\Entities\StoredBaseRows\StoredBaseParent::class,
    \DDD\Tests\Integration\Fixtures\Entities\StoredBaseRows\StoredBaseChild::class,
] as $fixtureEntityClass) {
    $databaseModel = DatabaseModel::fromEntityClass($fixtureEntityClass);
    $modelFile = $databaseModel->getModelClassNameWithNameSpace()->filename;
    file_put_contents($modelFile, $databaseModel->getDoctrineModelCode());
    echo "generated $modelFile\n";
}
