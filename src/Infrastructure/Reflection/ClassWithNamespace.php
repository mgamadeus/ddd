<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

use DDD\Infrastructure\Traits\Serializer\SerializerTrait;

class ClassWithNamespace
{
    use SerializerTrait;

    /** @var string Regular class */
    public const TYPE_CLASS = 'CLASS';

    /** @var string Regular class */
    public const TYPE_TRAIT = 'TRAIT';

    /** @var string Regular class */
    public const TYPE_INTERFACE = 'INTERFACE';

    /** @var string Class name */
    public string $name = '';


    /** @var string Class namespace */
    public string $namespace = '';

    /** @var string File name of the class */
    public string $filename = '';

    /** @var string File name of the class */
    public string $type = self::TYPE_CLASS;

    public string $extends;

    public function __construct(string $name, string $namespace = '', $filename = '', string $type = self::TYPE_CLASS)
    {
        // if name includes namespace we need to split it
        if (strpos($name, '\\') !== false) {
            $tName = substr($name, strrpos($name, '\\') + 1);
            $namespace = substr($name, 0, strrpos($name, '\\'));
            $name = $tName;
        }
        $this->name = $name;
        $this->namespace = $namespace;
        $this->filename = $filename;
        $this->type = $type;
    }

    public function getNameWithNamespace($nameSpaceSeparator = '\\'): string
    {
        $namespace = $this->namespace;
        if (!$namespace) {
            return $this->name;
        }
        if ($namespace[strlen($namespace) - 1] != '\\') {
            $namespace .= '\\';
        }
        if ($namespace[0] == '\\') {
            $namespace = substr($namespace[0], 1);
        }
        //  $namespace = '\\' . $namespace;
        if ($nameSpaceSeparator != '\\') {
            $namespace = str_replace('\\', $nameSpaceSeparator, $namespace);
        }
        return $namespace . $this->name;
    }
}