<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\ContactInfos;

use DDD\Domain\Base\Entities\ValueObject;

abstract class ContactInfo extends ValueObject
{
    public const TYPE_EMAIL = 'EMAIL';
    public const TYPE_PHONE = 'PHONE';

    public ?string $scope;
    public ?string $type;
    public ?string $value;

    /**
     * @return string|null
     */
    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $this->normalize($value);
    }

    public function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }

    /**
     * @param string $value
     * @return void
     */
    abstract public function normalizeAndSetValue(string $value): void;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(($this->type ?? '') . '_'.($this->scope ?? ''));
    }
}
