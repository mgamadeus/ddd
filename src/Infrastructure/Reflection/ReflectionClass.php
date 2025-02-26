<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Infrastructure\Libs\Config;
use ReflectionClassConstant;
use ReflectionException;
use RuntimeException;

class ReflectionClass extends \ReflectionClass
{
    // Scalar Data Types
    public const STRING = 'string';
    public const BOOL = 'bool';

    public const INTEGER = 'int';

    public const DOUBLE = 'double';

    public const FLOAT = 'float';
    public const INTEGER_FULL_NAME = 'integer';

    public const BOOLEAN = 'boolean';

    // Compound Data Types
    public const ARRAY = 'array';
    public const OBJECT = 'object';

    public const SCALAR_BASE_TYPES = [
        self::STRING => true,
        self::BOOL => true,
        self::INTEGER => true,
        self::FLOAT => true
    ];
    public const GET_TYPE_ALLOCATIONS = [
        'string' => self::STRING,
        'boolean' => self::BOOL,
        'integer' => self::INTEGER,
        'double' => self::DOUBLE,
        'array' => self::ARRAY,
        'object' => self::OBJECT
    ];
    public const BASE_TYPES = [
        self::STRING,
        self::BOOL,
        self::INTEGER,
        self::DOUBLE,
        self::FLOAT,
        self::OBJECT,
        self::ARRAY
    ];

    public const NUMERIC_TYPES = [self::INTEGER, self::FLOAT, self::DOUBLE];
    public const SCALAR_TYPES = [
        self::STRING,
        self::BOOL,
        self::INTEGER,
        self::FLOAT,
        self::DOUBLE,
        self::INTEGER_FULL_NAME,
        self::BOOLEAN
    ];

    public const NO_REFLECTION = 'no_reflection';

    public static $getPropertiesCache = [];

    public static $getPropertiesOfCurrentClassCache = [];

    public static $attributesCache = [];
    public static $propertyCache = [];
    public static $relectionClassCache = [];

    public static $classesWithNameSpaceCache = [];
    public static $getParentClassCache = [];

    public static $traitNamesByClass = [];

    public static $isLazyLoadedPropertyToBeAddedAsParentCache = [];
    public static $constantsDescriptionCache = [];

    public static $attributeInstanceCache = [];
    public static $reflectionPropertyAllowedTypesCache = [];
    /** @var UseStatement[] */
    protected $useStatements = [];

    protected static ?array $objectTypeMigrations = null;

    public function getDocCommentInstance(): ReflectionDocComment
    {
        $reflectionDocComment = new ReflectionDocComment((string)$this->getDocComment());
        return $reflectionDocComment;
    }

    /**
     * @return array|null Returns associative array with associations of
     * old objectType => new objectType
     */
    public static function getObjectTypeMigrations(): ?array
    {
        if (!isset(self::$objectTypeMigrations)) {
            self::$objectTypeMigrations = Config::get('Common.objectTypeMigrations') ?? [];
        }
        return self::$objectTypeMigrations;
    }

    /**
     * Checks if ReflectionProperty is of numeric type
     * @param \ReflectionProperty $property
     * @return bool
     */
    public static function isNumericType(\ReflectionProperty &$property): bool
    {
        if (in_array($property->getType()->getName(), self::NUMERIC_TYPES)) {
            return true;
        }
        return false;
    }

    /**
     * returns the properties as in system \ReflectionClass, this has less overhead than getProperties()
     * and is more suited for speedy serialization
     * @param int|null $filter
     * @return array|null
     */
    public function getPropertiesForSerialization(?int $filter = null): ?array
    {
        return parent::getProperties($filter);
    }

    /**
     * Returns properties including new ReflectionArrayType for providing information about the array type, in case that proeprty is Array
     * @param int|null $filter
     * @return ReflectionProperty[]|\ReflectionProperty[]|null
     * @throws ReflectionException
     */
    public function getProperties(?int $filter = null): array
    {
        $cacheKey = $this->name . '_' . $filter;
        if (isset(self::$getPropertiesCache[$cacheKey])) {
            return self::$getPropertiesCache[$cacheKey];
        }
        $properties = parent::getProperties($filter);
        $returnProperties = [];
        foreach ($properties as $property) {
            $propertyType = $property->getType();
            $returnProperty = new ReflectionProperty($this->name, $property->name);
            $returnProperties[] = $returnProperty;
        }
        /*
        usort($returnProperties, function (\ReflectionProperty $a, \ReflectionProperty $b) {
            if ($a->getName() == 'id' && $b->getName() != 'id') {
                return -1;
            }
            if ($a->getName() != 'id' && $b->getName() == 'id') {
                return 1;
            }

            if ($a->getName() == 'objectType' && $b->getName() != 'objectType') {
                return 1;
            }
            if ($a->getName() != 'objectType' && $b->getName() == 'objectType') {
                return -1;
            }
            return 0;
        });*/
        self::$getPropertiesCache[$cacheKey] = $returnProperties;
        return $returnProperties;
    }

    /**
     * Returns properties of current class excluding the ons inherited from parent class
     * including new ReflectionArrayType for providing information about the array type, in case that proeprty is Array
     * @param int|null $filter
     * @return ReflectionProperty[]|\ReflectionProperty[]|null
     * @throws ReflectionException
     */
    public function getPropertiesOfCurrentClass(?int $filter = null): array
    {
        $cacheKey = $this->name . '_' . $filter;
        if (isset(self::$getPropertiesOfCurrentClassCache[$cacheKey])) {
            return self::$getPropertiesOfCurrentClassCache[$cacheKey];
        }
        $properties = $this->getProperties($filter);
        $returnProperties = [];
        foreach ($properties as $property) {
            if ($property->getDeclaringClass()->getName() == $this->getName()) {
                $returnProperties[] = $property;
            }
        }
        self::$getPropertiesOfCurrentClassCache[$cacheKey] = $returnProperties;
        return $returnProperties;
    }

    public function getParentClass(): ReflectionClass|false
    {
        if (isset(self::$getParentClassCache[$this->name])) {
            return self::$getParentClassCache[$this->name];
        } else {
            $parentClassName = get_parent_class($this->name);
            self::$getParentClassCache[$this->name] = $parentClassName ? self::instance($parentClassName) : false;
            return self::$getParentClassCache[$this->name];
        }
    }

    /**
     * returns an instance of reflection class
     * instances are statically cached per className
     * @param string $className
     * @return mixed|ReflectionClass
     * @throws ReflectionException
     */
    public static function instance(string $className): ?ReflectionClass
    {
        if (isset(self::$relectionClassCache[$className])) {
            $cached = self::$relectionClassCache[$className];
            return $cached === self::NO_REFLECTION ? null : $cached;
        }

        try {
            $reflection = new ReflectionClass($className);
            self::$relectionClassCache[$className] = $reflection;
            return $reflection;
        } catch (ReflectionException $e) {
            self::$relectionClassCache[$className] = self::NO_REFLECTION;
            return null;
        }
    }

    public function getProperty(string $propertyName): ReflectionProperty
    {
        if ($cachedProperty = (self::$propertyCache[$this->name][$propertyName] ?? false)) {
            return $cachedProperty;
        }
        $property = parent::getProperty($propertyName);
        //$propertyType = $property->getType();
        /*if (!$propertyType || $propertyType instanceof \ReflectionUnionType) {
            return $property;
        }*/
        $returnProperty = new ReflectionProperty($this->name, $property->name);
        self::$propertyCache[$this->name][$propertyName] = $returnProperty;
        return $returnProperty;
    }


    /**
     * Returns for a property that has to be lazyloaded if it has to be loaded as parent or as a child
     * This method caches its results statically
     * @param string $propertyName
     * @return bool
     * @throws ReflectionException
     */
    public function isLazyLoadedPropertyToBeAddedAsParent(string $propertyName): bool
    {
        $cacheKey = $this->getName() . '_' . $propertyName;
        if (isset(self::$isLazyLoadedPropertyToBeAddedAsParentCache[$cacheKey])) {
            return self::$isLazyLoadedPropertyToBeAddedAsParentCache[$cacheKey];
        }
        $reflectionProperty = $this->getProperty($propertyName);
        $addAsParent = false;
        foreach ($reflectionProperty->getAttributes(LazyLoad::class) as $lazyLoadAttribute) {
            /** @var LazyLoad $lazyLoadAttributeInstance */
            $lazyLoadAttributeInstance = $lazyLoadAttribute->newInstance();
            if ($lazyLoadAttributeInstance->addAsParent) {
                $addAsParent = true;
            }
        }
        self::$isLazyLoadedPropertyToBeAddedAsParentCache[$cacheKey] = $addAsParent;
        return $addAsParent;
    }

    /**
     * @return ClassWithNamespace Returns ClassWithNamespace for current base class
     */
    public function getClassWithNamespace(): ClassWithNamespace
    {
        if (isset(self::$classesWithNameSpaceCache[$this->name])) {
            return self::$classesWithNameSpaceCache[$this->name];
        }
        $type = ClassWithNamespace::TYPE_CLASS;
        if ($this->isInterface()) {
            $type = ClassWithNamespace::TYPE_INTERFACE;
        } elseif ($this->isTrait()) {
            $type = ClassWithNamespace::TYPE_TRAIT;
        }
        $classWithNamespace = new ClassWithNamespace($this->name,'', $this->getFileName(), $type);
        self::$classesWithNameSpaceCache[$this->name] = $classWithNamespace;
        return $classWithNamespace;
    }

    /**
     * This method returns for a class name usage within this class the complete class including the namespace
     * it considers usage of namespaces, the namespace of the class itself etc.
     * e.g. class has the following definitions:
     *
     * namespace DDD;
     * use DDD\Presentation\OA\Path;
     * use DDD\Presentation as Pres;
     * ---
     * $className can be e.g.
     * \SomeRootClass => \SomeRootClass
     * Path => App\Presentation\OA\Path
     * Pres\OA\Path => DDD\Presentation\OA\Path
     * ClassWithinNamespace => DDD\ClassWithinNamespace
     *
     * @param string $className
     * @return ClassWithNamespace
     */
    public function getClassWithNamespaceConsideringUseStatements(string $className): ClassWithNamespace
    {
        if ($className[0] == '\\') {
            //className is in root namespace with absolutely defined namespace path
            return new ClassWithNamespace($className);
        }
        $useStatements = $this->getUseStatements();
        foreach ($useStatements as $useStatement) {
            //className is matched with useAs
            if ($className == $useStatement->useAs) {
                return new ClassWithNamespace($useStatement->classOrNamespace);
            }
            /* we have something like
             * use App\Presentation as Pres;
             * and $className = Pres\OA\Path[]
             */
            if (strpos($className, $useStatement->useAs . '\\') === 0) {
                $className = $useStatement->classOrNamespace . substr($className, strlen($useStatement->useAs));
                return new ClassWithNamespace($className);
            }
        }
        //className is relative to namespace
        if ($this->getNamespaceName()) {
            return new ClassWithNamespace($className, $this->getNamespaceName());
        }

        //we have no namespace, class is in root and not matched with use statements
        return new ClassWithNamespace($className);
    }

    /**
     * Parse the use statements from read source by
     * tokenizing and reading the tokens. Returns
     * an array of use statements and aliases.
     * @return UseStatement[]
     */
    public function getUseStatements(): array
    {
        if ($this->useStatements) {
            return $this->useStatements;
        }

        if (!$this->isUserDefined()) {
            throw new RuntimeException('Must parse use statements from user defined classes.');
        }

        $source = $this->readFileSource();
        //$this->useStatements = $this->tokenizeSource($source);
        $tokens = token_get_all($source);
        //echo json_encode($tokens);die();
        $currentUse = null;
        $useSet = false;
        $asSet = false;
        /**
         * use App\Presentation\OA\Path as Path;
         * use App\Infrastructure\Traits\Serializer\Serializer;
         * use App;
         */
        foreach ($tokens as $token) {
            if ($token[0] == T_USE) {
                $useSet = true;
                continue;
            }
            if ($useSet) {
                if (!$currentUse && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_RELATIVE])) {
                    $currentUse = new UseStatement($token[1]);
                }
                if ($token[0] == T_AS) {
                    $asSet = true;
                    continue;
                }
                if ($token[0] == T_STRING && $asSet && $currentUse->classOrNamespace) {
                    $currentUse->useAs = $token[1];
                }
                if ($token == ';' && $currentUse->classOrNamespace) {
                    if (!$currentUse->useAs) {
                        $currentUse->useAs = basename($currentUse->classOrNamespace);
                    }
                    $this->useStatements[] = $currentUse;
                    $currentUse = null;
                    $asSet = false;
                    $useSet = false;
                    continue;
                }
            }
            if (isset($token[2]) && $token[2] >= $this->getStartLine() || $token[0] == T_CLASS) {
                break;
            }
        }
        return $this->useStatements;
    }

    /**
     * Read file source up to the line where our class is defined.
     *
     * @return string
     */
    private function readFileSource()
    {
        $file = fopen($this->getFileName(), 'r');
        $line = 0;
        $source = '';
        while (!feof($file)) {
            ++$line;

            if ($line >= $this->getStartLine()) {
                break;
            }

            $source .= fgets($file);
        }
        fclose($file);
        return $source;
    }

    /**
     * This function returns the type of the property. If the Property is a Nullable Type, the type will be returned
     * without the question mark since we do not want to stare null values. Also, If the Type is a Union Type
     * this function will decide which Data Type to use.
     * @param ReflectionProperty $property
     * @param mixed $valueToBeAssigned
     * @return string
     */
    public function getReflectionType(\ReflectionProperty|ReflectionProperty $property): string
    {
        /** @var ReflectionNamedType|ReflectionArrayType|ReflectionUnionType $propertyType */
        $propertyType = $property->getType();

        /** TODO: handle Union Types */
        if ($propertyType instanceof ReflectionUnionType || $propertyType instanceof \ReflectionUnionType) {
            return '';
        } elseif ($propertyType instanceof ReflectionArrayType) {
            return $propertyType->getArrayType()->getName();
        } else {
            return $propertyType->getName();
        }
    }

    /**
     * returns allowed Types for reflection property (cached)
     * This method is created in order to optimize performance of setPropertyFromObject in Serialized
     * @param \ReflectionProperty|ReflectionProperty $property
     * @return ReflectionAllowedTypes
     */
    public function getAllowedTypesForProperty(\ReflectionProperty|ReflectionProperty &$property
    ): ReflectionAllowedTypes {
        $cacheKey = $this->name . '_' . $property->name;
        if (isset(self::$reflectionPropertyAllowedTypesCache[$cacheKey])) {
            return self::$reflectionPropertyAllowedTypesCache[$cacheKey];
        }
        $allowedTypes = new ReflectionAllowedTypes($property);
        self::$reflectionPropertyAllowedTypesCache[$cacheKey] = $allowedTypes;
        return $allowedTypes;
    }

    /**
     * returns true if class has trait of type $traitName
     * @param string $traitName
     * @return bool
     */
    public function hasTrait(string $traitName)
    {
        if (!isset(self::$traitNamesByClass[$this->getName()])) {
            self::$traitNamesByClass[$this->getName()] = [];
            foreach (parent::getTraitNames() as $actTraitName) {
                self::$traitNamesByClass[$this->getName()][$actTraitName] = true;
            }
        }
        return isset(self::$traitNamesByClass[$this->getName()][$traitName]);
    }

    /**
     * Returns the instance of the first found class attribute of the given name
     * @param string|null $name
     * @param int $flags
     * @return mixed|object|null
     * @throws ReflectionException
     */
    public function getAttributeInstance(?string $name = null): mixed
    {
        $classAttributes = $this->getAttributes($name);
        foreach ($classAttributes as $attribute) {
            $attributeInstance = $attribute->newInstance();
            return $attribute->newInstance();
        }
        return null;
    }

    /**
     * Cached getAttributes
     * @param string|null $attributeName
     * @param int $flags
     * @return array|ReflectionAttribute[]
     */
    public function getAttributes(?string $attributeName = null, int $flags = 0): array
    {
        $key = $this->name;
        if (!isset(self::$attributesCache[$key])) {
            self::$attributesCache[$key] = [];
            $index = 0;
            foreach (parent::getAttributes() as $systemAttribute) {
                $attribute = new ReflectionAttribute($systemAttribute, $this->name, null, $index);
                if (!isset(self::$attributesCache[$key][$attribute->getName()])) {
                    self::$attributesCache[$key][$attribute->getName()] = [];
                }
                self::$attributesCache[$key][$attribute->getName()][] = $attribute;
                $index++;
            }
        }
        if (!$attributeName) {
            $return = [];
            foreach (self::$attributesCache[$key] as $attributeName => $attributes) {
                $return = array_merge($return, $attributes);
            }
            return $return;
        }
        return self::$attributesCache[$key][$attributeName] ?? [];
    }

    /**
     * @param string $attributeName
     * @return bool
     */
    public function hasAttribute(string $attributeName): bool
    {
        return !empty(count($this->getAttributes($attributeName)));
    }

    /**
     * Returns first attribute instance for property and attribute name
     * @param string $propertyName
     * @param string $attributeName
     * @return mixed
     * @throws ReflectionException
     */
    public function getAttributeInstanceForProperty(string $propertyName, string $attributeName): mixed
    {
        $index = $this->getName() . '_' . $propertyName . '_' . $attributeName;
        if (isset(self::$attributeInstanceCache[$index])) {
            return self::$attributeInstanceCache[$index];
        } else {
            $reflectionProperty = $this->getProperty($propertyName);
            if (!$reflectionProperty) {
                self::$attributeInstanceCache[$index] = null;
                return null;
            }
            if ($attributes = $reflectionProperty->getAttributes($attributeName)) {
                foreach ($attributes as $attribute) {
                    $attributeInstance = $attribute->newInstance();
                    self::$attributeInstanceCache[$index] = $attributeInstance;
                    return $attributeInstance;
                }
            } else {
                self::$attributeInstanceCache[$index] = null;
                return null;
            }
        }
        return null;
    }

    /**
     * @return string[] Returns assiociative array of constant values and their doc comment based descsriptions
     */
    public function getConstantsDescriptions(): array
    {
        if (isset(self::$constantsDescriptionCache[$this->name])) {
            return self::$constantsDescriptionCache[$this->name];
        }
        $constantDescriptions = [];
        foreach (
            $this->getReflectionConstants(
                ReflectionClassConstant::IS_PUBLIC
            ) as $reflectionClassConstant
        ) {
            if (!($reflectionClassConstant->getValue() && is_string(
                    $reflectionClassConstant->getValue()
                ))) {
                continue;
            }

            $docComment = $reflectionClassConstant->getDocComment();
            $constantDescription = '';
            if ($docComment) {
                $constantDocComment = new ReflectionDocComment($docComment);
                $constantDescription = $constantDocComment->getDescription();
            }
            $constantDescriptions[$reflectionClassConstant->getValue()] = $constantDescription;
        }
        self::$constantsDescriptionCache[$this->name] = $constantDescriptions;
        return $constantDescriptions;
    }

    /**
     * Returns constant description for constant value
     * @param string $constantValue
     * @return string|null
     */
    public function getConstantDescriptionForConstantValue(string $constantValue): ?string
    {
        return $this->getConstantsDescriptions()[$constantValue] ?? null;
    }

    /**
     * Returns the closest common ancestor class of given classes, works also if one of them is inheriting the other
     * @param string ...$classes
     * @return string|null
     */
    public static function findClosestCommonAncestor(string ...$classes): ?string
    {
        if (count($classes) < 2) {
            return null;
        }

        $parentLists = [];
        foreach ($classes as $class) {
            $parentLists[] = array_merge([$class], array_values(class_parents($class)));
        }

        // Use the first class's parents as the base for comparison
        foreach ($parentLists[0] as $parent) {
            $isCommon = true;
            for ($i = 1; $i < count($parentLists); $i++) {
                if (!in_array($parent, $parentLists[$i], true)) {
                    $isCommon = false;
                    break;
                }
            }
            if ($isCommon) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * Checks if property is initialized within object, supports stdClass and namd classes
     * @param object $object
     * @param string $propertyName
     * @return bool
     * @throws ReflectionException
     */
    public static function isPropertyInitialized(object $object, string $propertyName):bool {
        if (isset($object->$propertyName))
            return true;
        if ($object instanceof \stdClass)
            return property_exists($object, $propertyName);
        $reflectionClass = ReflectionClass::instance($object::class);
        $reflectionProperty = $reflectionClass->getProperty($propertyName);
        return $reflectionProperty->isInitialized($object);
    }
}