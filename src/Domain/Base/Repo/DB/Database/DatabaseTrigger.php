<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Validation\Constraints\Choice;
use ReflectionException;

/**
 * Entities with Trigger Attributes will include the trigger in Database Model Generation
 * SQL for Trigger is expected to be named the follwoing way:
 * e.g. Entity with Trigger Attribute applied:
 *
 * - Domain/Accounts/Entities/Tracks/Track.php
 * - Trigger executionOrder: BEFORE
 * - Trigger executeOnOperation: [INSERT]
 * => Trigger is excpected to be placed
 * Domain/Accounts/Repo/Doctrine/Tracks/TrackBeforeInsertTrigger.sql
 * 
 * multiple Operations are concatenated with And e.g. TrackBeforeInsertAndUpdateTrigger.sql
 */
#[Attribute(Attribute::TARGET_CLASS)]
class DatabaseTrigger extends ValueObject
{
    use BaseAttributeTrait;

    public const EXECUTE_BEFORE = 'BEFORE';
    public const EXECUTE_AFTER = 'AFTER';

    public const OPERATION_INSERT = 'INSERT';
    public const OPERATION_UPDATE = 'UPDATE';
    public const OPERATION_DELETE = 'DELETE';

    /** @var string When to execute the trigger */
    #[Choice([self::EXECUTE_BEFORE, self::EXECUTE_AFTER])]
    public string $exectutionOrder = self::EXECUTE_BEFORE;

    /** @var array|string[] The operations on which to execute the trigger */
    public array $executeOnOperations = [self::OPERATION_INSERT];


    /**
     * @param string ...$columns
     */
    public function __construct(string $exectutionOrder, array $executeOnOperations)
    {
        $this->exectutionOrder = $exectutionOrder;
        $this->executeOnOperations = $executeOnOperations;
        parent::__construct();
    }

    /**
     * @param ClassWithNamespace $classOnWhichTriggerIsApplied
     * @return string
     * @throws ReflectionException
     */
    public function getSql(ClassWithNamespace $classOnWhichTriggerIsApplied): string
    {
        $fileName = ReflectionClass::instance($classOnWhichTriggerIsApplied->getNameWithNamespace())->getFileName();
        $fileNameExplode = explode('/', $fileName);
        array_pop($fileNameExplode);
        foreach ($fileNameExplode as $index => $folder) {
            if ($folder == 'Entities') {
                $fileNameExplode[$index] = 'Repo/DB';
            }
        }
        $triggerDirectory = implode('/', $fileNameExplode);
        $triggerFileName = $classOnWhichTriggerIsApplied->name . ucfirst(strtolower($this->exectutionOrder)) . implode(
                'And',
                array_map(function ($operations) {
                    return ucfirst(strtolower($operations));
                }, $this->executeOnOperations)
            ) . 'Trigger.sql';
        $triggerFullPath = $triggerDirectory . '/' . $triggerFileName;
        if (file_exists($triggerFullPath)) {
            return file_get_contents($triggerFullPath);
        }
        return '';
    }

    public function uniqueKey(): string
    {
        return parent::uniqueKey($this->exectutionOrder . implode($this->executeOnOperations));
    }


}