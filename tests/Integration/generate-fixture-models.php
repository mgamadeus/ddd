<?php

declare(strict_types=1);

/**
 * Regenerates the DB*Model.php files of the integration fixtures with the framework's own generator. The app command
 * app:generate-doctrine-models-for-entities never scans tests/, so the fixtures take the generator's public pieces
 * directly. Run after changing a fixture entity: php tests/Integration/generate-fixture-models.php
 */

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;

require __DIR__ . '/bootstrap.php';

(new ReflectionProperty(\DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, \DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo::DB);

foreach (\DDD\Tests\Integration\IntegrationTestCase::FIXTURE_ENTITY_CLASSES as $fixtureEntityClass) {
    $databaseModel = DatabaseModel::fromEntityClass($fixtureEntityClass);
    $modelFile = $databaseModel->getModelClassNameWithNameSpace()->filename;
    file_put_contents($modelFile, $databaseModel->getDoctrineModelCode());
    echo "generated $modelFile\n";
}
