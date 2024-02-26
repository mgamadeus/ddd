<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use SplFileObject;

class ClassFinder
{
    public static bool $clearCache = false;
    private static array $excludedFiles = ['Vendor'];

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
    private static function getPHPFilesInDirectory(string $dir, &$results = [], array $excludedFiles = [])
    {
        $files = scandir($dir);

        foreach ($files as $key => $value) {
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

    private static function getClassFromFile(string $fileName): ?ClassWithNamespace
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

    /**
     * returns Class name from within a file if present
     * @param string $file
     * @return null|ClassWithNamespace
     */
    private static function getClassFromFileasd(string $file): ?ClassWithNamespace
    {
        //echo $file . '<br />';
        $fp = fopen($file, 'r');
        $class = $buffer = '';
        $i = 0;
        $namespace = '';
        while (!$class) {
            if (feof($fp)) {
                break;
            }

            $buffer .= fread($fp, 5012);
            $tokens = token_get_all($buffer);
            //var_dump($tokens);

            if (strpos($buffer, '{') === false) {
                continue;
            }
            for (; $i < count($tokens); $i++) {
                if (
                    in_array($tokens[$i][0], [T_CLASS, T_TRAIT, T_INTERFACE]) || (isset($tokens[$i][1]) && in_array(
                            $tokens[$i][1],
                            ['class', 'trait', 'interface']
                        ))
                ) {
                    for ($j = $i + 1; $j < count($tokens); $j++) {
                        if ($tokens[$j] == '{' && !empty($tokens[$i + 2][1])) {
                            $class = $tokens[$i + 2][1];
                            break;
                        }
                    }
                }
                if ($tokens[$i][0] == T_NAMESPACE || (isset($tokens[$i][1]) && $tokens[$i][1] == 'namespace')) {
                    if (isset($tokens[$i + 2])) {
                        $namespace = $tokens[$i + 2][1];
                    }
                }
                if ($class) {
                    break;
                }
            }
        }
        if (!$class) {
            return null;
        }
        $classWithName = new ClassWithNamespace($class, $namespace, $file);
        return $classWithName;
    }
}