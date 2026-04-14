<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Settings;

use DDD\Domain\Base\Entities\ValueObject;

class Setting extends ValueObject
{
    public function __construct()
    {
        return parent::__construct();
    }

    /** We assume one ServiceSetting Class per Set so we set the uniqueKey to be the class name */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic();
    }

}