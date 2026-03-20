<?php

namespace DDD\Infrastructure\Validation\Constraints;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadTrait;
use DDD\Domain\Base\Entities\Traits\EntityTrait;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Validators\NotContainingUrlPrefix\NotContainingUrlPrefixConstraint;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validator for the {@see UniqueProperty} constraint.
 *
 * Checks whether a given property value is unique across all entities of the same type in the database.
 * The validation is performed by querying the database repository associated with the entity to count
 * existing records that share the same property value. If the entity being validated already has an ID,
 * that entity is excluded from the count to allow valid updates.
 *
 * This validator only applies to objects recognized as entities (via {@see DefaultObject::isEntity()})
 * that have a DB repository class available.
 */
class UniquePropertyValidator extends ConstraintValidator
{

    /**
     * Validates that the given property value is unique among all persisted entities of the same type.
     *
     * Performs the following steps:
     * 1. Verifies the constraint is an instance of {@see UniqueProperty}
     * 2. Skips validation if the value is null or empty
     * 3. Determines whether the validated object is a recognized entity with a DB repository
     * 4. Queries the database to count other entities with the same property value
     * 5. Adds a constraint violation if a duplicate value is found
     *
     * @param mixed      $value      The property value to validate for uniqueness
     * @param Constraint $constraint The constraint instance (must be {@see UniqueProperty})
     *
     * @return void
     *
     * @throws UnexpectedTypeException If the constraint is not an instance of {@see UniqueProperty}
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueProperty) {
            throw new UnexpectedTypeException($constraint, UniqueProperty::class);
        }
        if (!$value) return;

        if (!isset($value)) {
            return;
        }
        $entity = $this->context->getObject();
        $propertyName = $this->context->getPropertyName();
        $isEntity = DefaultObject::isEntity($entity);
        if ($isEntity) {
            /** @var LazyLoadTrait $entity */
            /** @var DBEntity $repoClassName */
            $repoClassName = $entity::getRepoClass(LazyLoadRepo::DB);
            if ($repoClassName){
                $queryBuidler = $repoClassName::createQueryBuilder();
                $alias = $repoClassName::getBaseModelAlias();
                $entityHasId = isset($entity->id);
                $queryBuidler
                    ->select("COUNT({$alias}.id)")
                    ->from($repoClassName::BASE_ORM_MODEL, $alias);
                if (isset($entity->id)){
                    $queryBuidler->andWhere("{$alias}.id != :currentId")
                        ->setParameter("currentId", $entity->id);
                }
                $count = $queryBuidler->andWhere("{$alias}.{$propertyName} = :propertyValue")
                    ->setParameter('propertyValue', $value)
                    ->getQuery()
                    ->getSingleScalarResult();
                if ($count){
                    $violationMessage = __('Property "%propertyName%" in class "%className%" has to be unique but value "%value%" is already in use', placeholders: ['propertyName' => $propertyName, 'className' => $entity::class, 'value' => $value]);
                    $this->context->buildViolation($violationMessage)->addViolation();
                }
            }
        }

    }
}
