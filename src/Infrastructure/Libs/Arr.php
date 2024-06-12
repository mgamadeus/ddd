<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

use Exception;
use stdClass;

class Arr
{
    public static function toObject(array $array): mixed
    {
        $resultObj = new stdClass();
        $resultArr = [];
        $hasIntKeys = false;
        $hasStrKeys = false;
        foreach ($array as $k => $v) {
            if (!$hasIntKeys) {
                $hasIntKeys = is_int($k);
            }
            if (!$hasStrKeys) {
                $hasStrKeys = is_string($k);
            }
            if ($hasIntKeys && $hasStrKeys) {
                $e = new Exception(
                    'Current level has both integer and string keys, thus it is impossible to keep array or convert to object'
                );
                $e->vars = ['level' => $array];
                throw $e;
            }
            if ($hasStrKeys) {
                $resultObj->{$k} = is_array($v) ? self::toObject($v) : $v;
            } else {
                $resultArr[$k] = is_array($v) ? self::toObject($v) : $v;
            }
        }
        return ($hasStrKeys) ? $resultObj : $resultArr;
    }

    public static function fromObject($obj): ?array
    {
        $arr = is_object($obj) ? get_object_vars($obj) : $obj;
        if ($obj && (is_array($obj) || is_object($obj))) {
            foreach ($arr as $key => $val) {
                $val = (is_array($val) || is_object($val)) ? self::fromObject($val) : $val;
                $arr[$key] = $val;
            }
        }
        return $arr;
    }

    /**
     * array_merge_recursive does indeed merge arrays, but it converts values with duplicate
     * keys to arrays rather than overwriting the value in the first array with the duplicate
     * value in the second array, as array_merge does. I.e., with array_merge_recursive,
     * this happens (documented behavior):
     *
     * array_merge_recursive(array('key' => 'org value'), array('key' => 'new value'));
     *     => array('key' => array('org value', 'new value'));
     *
     * array_merge_recursive_distinct does not change the datatypes of the values in the arrays.
     * Matching keys' values in the second array overwrite those in the first array, as is the
     * case with array_merge, i.e.:
     *
     * array_merge_recursive_distinct(array('key' => 'org value'), array('key' => 'new value'));
     *     => array('key' => array('new value'));
     *
     * Parameters are passed by reference, though only for performance reasons. They're not
     * altered by this function.
     *
     * @param array $array1
     * @param array $array2
     * @return array
     * @author Daniel <daniel (at) danielsmedegaardbuus (dot) dk>
     * @author Gabriel Sobrinho <gabriel (dot) sobrinho (at) gmail (dot) com>
     */
    public static function mergeRecursiveDistinct(array $array1, array $array2):?array {
        $merged = $array1;

        foreach ($array2 as $key => &$value) {
            if (is_array($value) && isset($merged [$key]) && is_array($merged [$key])) {
                $merged [$key] = self::mergeRecursiveDistinct($merged [$key], $value);
            } else {
                $merged [$key] = $value;
            }
        }

        return $merged;
    }
}
