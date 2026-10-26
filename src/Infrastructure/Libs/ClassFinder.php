<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use SplFileObject;

class ClassFinder
{
    public static bool $clearCache = false;
    protected static array $excludedFiles = ['Vendor'];

    /**
     * scans a directory recursively and returns all PHP class names found
     * @param string $directory
     * @return ClassWithNamespace[]
     */
    public static function getClassesInDirectory(
        string $directory,
        array $excludedFiles = [],
        $cached = true,
        $cacheTtl = 300
    ): array {
        $cacheKey = 'rc_getClassesInDirectory.' . $directory . '_' . implode($excludedFiles);
        $cache = Cache::instance();
        if ($cached) {
            $classes = $cache->get($cacheKey);
            if ($classes && !self::$clearCache) {
                return $classes;
            }
        }
        $results = [];
        $files = self::getPHPFilesInDirectory($directory, $results, $excludedFiles);
        $classes = [];
        foreach ($files as $file) {
            $class = self::getClassFromFile($file);
            if (!$class) {
                continue;
            }
            $classes[] = $class;
        }
        $cache->set($cacheKey, $classes);
        return $classes;
    }

    /**
     * scans a directory recursively and returns all file names
     * @param string $dir
     * @param array $results
     * @return array|mixed
     */
    protected static function getPHPFilesInDirectory(string $dir, &$results = [], array $excludedFiles = [])
    {
        $files = scandir($dir);

        foreach ($files as $value) {
            if (in_array($value, $excludedFiles)) {
                continue;
            }
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (!is_dir($path)) {
                if (strpos($path, '.php')) {
                    $results[] = $path;
                }
            } elseif ($value != '.' && $value != '..') {
                self::getPHPFilesInDirectory($path, $results);
            }
        }

        return $results;
    }

    protected static function getClassFromFile(string $fileName): ?ClassWithNamespace
    {
        $file = new SplFileObject($fileName);
        $maxLines = 200;
        $lineIndex = 0;
        $namespace = '';
        $className = null;
        $type = null;
        while (!$file->eof()) {
            $line = $file->fgets();
            $lineIndex++;
            if ($lineIndex > $maxLines) {
                return null;
            }
            if (empty($namespace) && preg_match('/^namespace\s+([^;]+);/', $line, $matches)) {
                $namespace = $matches[1];
            }
            if (empty($className) && preg_match('/^class\s+([^{\s]+)/', $line, $matches)) {
                $className = $matches[1];
                $type = ClassWithNamespace::TYPE_CLASS;
                break;
            }
            if (empty($className) && preg_match('/^trait\s+([^{\s]+)/', $line, $matches)) {
                $className = $matches[1];
                $type = ClassWithNamespace::TYPE_TRAIT;
                break;
            }
            if (empty($className) && preg_match('/^interface\s+([^{\s]+)/', $line, $matches)) {
                $className = $matches[1];
                $type = ClassWithNamespace::TYPE_INTERFACE;
                break;
            }
        }
        if (!$className) {
            return null;
        }
        return new ClassWithNamespace($className, $namespace, $fileName, $type);
    }

}