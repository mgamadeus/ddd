<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

use Exception;
use stdClass;

class Arr
{
    public static function toObject(array $array):mixed
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
}
