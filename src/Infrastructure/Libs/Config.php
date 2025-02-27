<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

use DDD\Infrastructure\Traits\SingletonTrait;
use RuntimeException;

class Config
{
    use SingletonTrait;

    /**
     * Array containing the configuration root folders
     * @var string[]
     */
    protected static array $rootDirectories;
    protected static array $configTree;
    protected static ?array $env = null;

    /**
     * Add new config directory in which we can search for values
     * Newer config directories will be searched first
     *
     * @param string $configRootDirectory
     * @return void
     */
    protected function addConfigRootDirectory(string $configRootDirectory): void
    {
        if (!isset(self::$rootDirectories)) {
            self::$rootDirectories = [];
        }

        if (!is_dir($configRootDirectory)) {
            throw new RuntimeException('Please provide a valid directory path for config');
        }

        if (in_array($configRootDirectory, self::$rootDirectories, true)) {
            return;
        }

        // Add the new root directory at the beginning of the array
        array_unshift(self::$rootDirectories, $configRootDirectory);
    }

    /**
     * Public method to add config directory that also handles the Config instance creation
     *
     * @param string $configRootDirectory
     * @return void
     */
    public static function addConfigDirectory(string $configRootDirectory): void
    {
        /** @var Config $configInstance */
        $configInstance = self::getInstance();
        $configInstance->addConfigRootDirectory($configRootDirectory);
    }

    /**
     * @param string $searchString
     * @param bool $prioritizeDirectorySearch
     * @return mixed
     */
    public static function get(string $searchString, bool $prioritizeDirectorySearch = false): mixed
    {
        /** @var Config $configInstance */
        $configInstance = self::getInstance();
        return $configInstance->searchInConfig($searchString, $prioritizeDirectorySearch);
    }

    /**
     * @param array $configRoots
     * @param string $searchString
     * @param bool $prioritizeDirectorySearch
     * @return mixed
     */
    protected function searchInConfig(
        string $searchString,
        bool $prioritizeDirectorySearch
    ): mixed {
        if ($searchResult = $this->searchInConfigTree($searchString)) {
            return $searchResult;
        }
        $result = $this->searchForFileInRootDirectories($searchString, $prioritizeDirectorySearch);
        if (!$result) {
            return null;
        }
        [$configFile, $relativeSearchPath] = $result;
        if (!isset(self::$configTree)) {
            self::$configTree = [];
        }
        $currentTreeElement = &self::$configTree;
        foreach ($relativeSearchPath as $searchPathElement) {
            $currentTreeElement[$searchPathElement] = [];
            $currentTreeElement = &$currentTreeElement[$searchPathElement];
        }
        $currentTreeElement = require $configFile;
        return $this->searchInConfigTree($searchString);
    }

    /**
     * @param array $configRoots
     * @param string $searchString
     * @param bool $prioritizeDirectorySearch
     * @return array|null
     */
    protected function searchForFileInRootDirectories(
        string $searchString,
        bool $prioritizeDirectorySearch
    ): array|null {
        foreach (self::$rootDirectories as $configRoot) {
            $result = $this->searchForFileInRootDirectory($configRoot, $searchString, $prioritizeDirectorySearch);
            if (!$result) {
                continue;
            }
            [$filePath, $relativeSearchPath] = $result;
            return [$filePath, $relativeSearchPath];
        }

        return null;
    }

    /**
     * @param string $configPath
     * @param string $searchString
     * @param bool $prioritizeDirectorySearch
     * @return string|null
     */
    protected function searchForFileInRootDirectory(
        string $configPath,
        string $searchString,
        bool $prioritizeDirectorySearch,
        array $relativeSearchPath = []
    ): array|null {
        if ($searchString === '') {
            return null;
        }

        $searchKeys = explode('.', $searchString);
        if (!$searchKeys || count($searchKeys) === 0) {
            return null;
        }

        $currentKey = array_shift($searchKeys);
        $relativeSearchPath[] = $currentKey;

        $dirItems = scandir($configPath);

        if (!$dirItems || count($dirItems) < 1) {
            return null;
        }

        $searchString = implode('.', $searchKeys);

        // There are cases where inside a folder we may encounter a file and a folder with the same name
        // In those cases we must prioritize one over the other
        // If file found and $prioritizeFilesOverDirectories is true then return the file
        $fileKey = array_search(strtolower($currentKey) . '.php', array_map('strtolower', $dirItems), true);
        $filePath = $fileKey ? trim($configPath . DIRECTORY_SEPARATOR . $dirItems[$fileKey]) : null;
        if (!$prioritizeDirectorySearch && $filePath && is_file($filePath)) {
            return [$filePath, $relativeSearchPath];
        }

        $dirKey = array_search(strtolower($currentKey), array_map('strtolower', $dirItems), true);
        $dirItemPath = $dirKey ? trim($configPath . DIRECTORY_SEPARATOR . $dirItems[$dirKey]) : null;
        if ($dirItemPath && is_dir($dirItemPath)) {
            return $this->searchForFileInRootDirectory(
                $dirItemPath,
                $searchString,
                $prioritizeDirectorySearch,
                $relativeSearchPath
            );
        }

        if ($filePath && is_file($filePath)) {
            return [$filePath, $relativeSearchPath];
        }

        return null;
    }

    /**
     * @param string $searchString
     * @return mixed
     */
    public function searchInConfigTree(string $searchString): mixed
    {
        if (!isset(self::$configTree)) {
            return null;
        }
        $explodedSearchString = self::explodeSearchString($searchString);
        $currentIndex = &self::$configTree;
        foreach ($explodedSearchString as $currentSubindex) {
            if (isset($currentIndex[$currentSubindex])) {
                $currentIndex = &$currentIndex[$currentSubindex];
            } else {
                return null;
            }
        }
        return $currentIndex;
    }

    public static function explodeSearchString(string $searchString): array
    {
        return explode('.', $searchString);
    }

    public static function setEnv(array $env): void
    {
        self::$env = $env;
    }

    /**
     * Returns Environment Variable
     * @param string $varname
     * @return string|false
     */
    public static function getEnv(string $varname): bool|int|float|string|null
    {
        if (isset(self::$env) && isset(self::$env[$varname])) {
            $value = self::$env[$varname] ?? null;
        }
        else {
            $value = $_ENV[$varname] ?? null;
        }
        // Check and return boolean values
        if ($value !== null) {
            if (strtolower($value) === 'true') {
                return true;
            } elseif (strtolower($value) === 'false') {
                return false;
            }

            // Check and return numeric values
            if (is_numeric($value)) {
                // Floats have a decimal point
                if (strpos($value, '.') !== false) {
                    return (float)$value;
                } else {
                    return (int)$value;
                }
            }
        }

        // Return the original string or null if not set
        return $value;
    }
}
