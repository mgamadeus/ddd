<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ClassWithNamespace;

/**
 * @property DatabaseModelImports $parent
 * @method DatabaseModelImports getParent()
 */
class DatabaseModelImport extends ValueObject
{
    public ClassWithNamespace $importClassWithNamespace;
    public string $baseNamespace = '';
    public string $alias = '';


    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->importClassWithNamespace->getNameWithNamespace());
    }

    /**
     * @param string $indexType
     * @param array $indexColumns
     */
    public function __construct(
        ?ClassWithNamespace $importClassWithNamespace = null,
        string $baseNamespace = '',
        string $alias = ''
    ) {
        $this->importClassWithNamespace = $importClassWithNamespace;
        parent::__construct();
    }

    public function getImportDefinition():?string{
        if ($this->baseNamespace == $this->importClassWithNamespace->namespace)
            return null;
        return 'use ' . $this->importClassWithNamespace->getNameWithNamespace() .($this->alias?' as ' . $this->alias:''). ';';
    }
}