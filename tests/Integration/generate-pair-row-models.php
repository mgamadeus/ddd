<?php

declare(strict_types=1);

/**
 * Generator for the PairRows fixture models (a one-to-one relation), same mechanism as generate-fixture-models.php:
 * php tests/Integration/generate-pair-row-models.php
 *
 * The inverse side (DBPairParentModel::$pairDetail) is not generated for fixtures outside src/Domain: re-add the
 * hand-added block of DBPairParentModel afterwards, and the inversedBy of DBPairDetailModel::$pairParent.
 */

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;

require __DIR__ . '/bootstrap.php';

(new ReflectionProperty(\DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo::class, 'defaultRepoType'))
    ->setValue(null, \DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo::DB);

$classes = [
    \DDD\Tests\Integration\Fixtures\Entities\PairRows\PairParent::class,
    \DDD\Tests\Integration\Fixtures\Entities\PairRows\PairDetail::class,
];

foreach ($classes as $fixtureEntityClass) {
    $databaseModel = DatabaseModel::fromEntityClass($fixtureEntityClass);
    $modelFile = $databaseModel->getModelClassNameWithNameSpace()->filename;
    file_put_contents($modelFile, $databaseModel->getDoctrineModelCode());
    echo "generated $modelFile\n";
}
