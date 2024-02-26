<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\MappingException;
use ReflectionClass;

use function count;
use function enum_exists;
use function in_array;
use function trim;

use const PHP_VERSION_ID;

/**
 * Extends Doctrine ClassMetadataInfo in order to allow defining property of dicriminator columns
 * e.g. type on Posts, otherwise it generates an Error
 */
class ClassMetadata extends \Doctrine\ORM\Mapping\ClassMetadata
{

    public function isTypedPropertyAccessible($propertyName)
    {
        $reflectionClass = new ReflectionClass(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $reflectionMethod = $reflectionClass->getMethod('isTypedProperty');
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($this, $propertyName);
    }

    public function validateAndCompleteTypedFieldMappingAccessible(array $mapping)
    {
        $reflectionClass = new ReflectionClass(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $reflectionMethod = $reflectionClass->getMethod('validateAndCompleteTypedFieldMapping');
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($this, $mapping);
    }

    /**
     * Validates & completes the given field mapping.
     *
     * @psalm-param array{
     *     fieldName?: string,
     *     columnName?: string,
     *     id?: bool,
     *     generated?: int,
     *     enumType?: class-string,
     * } $mapping The field mapping to validate & complete.
     *
     * @return FieldMapping The updated mapping.
     *
     * @throws MappingException
     */
    protected function validateAndCompleteFieldMapping(array $mapping): array
    {
        // Check mandatory fields
        if (!isset($mapping['fieldName']) || !$mapping['fieldName']) {
            throw MappingException::missingFieldName($this->name);
        }

        if ($this->isTypedPropertyAccessible($mapping['fieldName'])) {
            $mapping = $this->validateAndCompleteTypedFieldMappingAccessible($mapping);
        }

        if (!isset($mapping['type'])) {
            // Default to string
            $mapping['type'] = 'string';
        }

        // Complete fieldName and columnName mapping
        if (!isset($mapping['columnName'])) {
            $mapping['columnName'] = $this->namingStrategy->propertyToColumnName($mapping['fieldName'], $this->name);
        }

        if ($mapping['columnName'][0] === '`') {
            $mapping['columnName'] = trim($mapping['columnName'], '`');
            $mapping['quoted'] = true;
        }

        $this->columnNames[$mapping['fieldName']] = $mapping['columnName'];

        if (isset($this->fieldNames[$mapping['columnName']]) || ($this->discriminatorColumn && $this->discriminatorColumn['name'] === $mapping['columnName'])) {
            // this makes no sense, we remove this.
            //throw MappingException::duplicateColumnName($this->name, $mapping['columnName']);
        }

        $this->fieldNames[$mapping['columnName']] = $mapping['fieldName'];

        // Complete id mapping
        if (isset($mapping['id']) && $mapping['id'] === true) {
            if ($this->versionField === $mapping['fieldName']) {
                throw MappingException::cannotVersionIdField($this->name, $mapping['fieldName']);
            }

            if (!in_array($mapping['fieldName'], $this->identifier, true)) {
                $this->identifier[] = $mapping['fieldName'];
            }

            // Check for composite key
            if (!$this->isIdentifierComposite && count($this->identifier) > 1) {
                $this->isIdentifierComposite = true;
            }
        }

        if (Type::hasType($mapping['type']) && Type::getType($mapping['type'])->canRequireSQLConversion()) {
            if (isset($mapping['id']) && $mapping['id'] === true) {
                throw MappingException::sqlConversionNotAllowedForIdentifiers(
                    $this->name,
                    $mapping['fieldName'],
                    $mapping['type']
                );
            }

            $mapping['requireSQLConversion'] = true;
        }

        if (isset($mapping['generated'])) {
            if (!in_array($mapping['generated'], [self::GENERATED_NEVER, self::GENERATED_INSERT, self::GENERATED_ALWAYS]
            )) {
                throw MappingException::invalidGeneratedMode($mapping['generated']);
            }

            if ($mapping['generated'] === self::GENERATED_NEVER) {
                unset($mapping['generated']);
            }
        }

        if (isset($mapping['enumType'])) {
            if (PHP_VERSION_ID < 80100) {
                throw MappingException::enumsRequirePhp81($this->name, $mapping['fieldName']);
            }

            if (!enum_exists($mapping['enumType'])) {
                throw MappingException::nonEnumTypeMapped($this->name, $mapping['fieldName'], $mapping['enumType']);
            }

            if (!empty($mapping['id'])) {
                $this->containsEnumIdentifier = true;
            }
        }

        return $mapping;
    }
}
