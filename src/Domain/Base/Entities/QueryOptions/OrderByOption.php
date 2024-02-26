<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Validation\Constraints\Choice;

class OrderByOption extends ValueObject
{
    public const ASC = 'ASC';
    public const DESC = 'DESC';

    /** @var string The orderBy property name */
    public ?string $propertyName;

    /** @var string The direction to sort */
    #[Choice(options: [self::ASC, self::DESC])]
    public string $direction;

    /** @var FiltersDefinition The definition the option is based on */
    protected ?FiltersDefinition $filtersDefinition = null;

    /**
     * @return FiltersDefinition
     */
    public function getFiltersDefinition(): ?FiltersDefinition
    {
        return $this->filtersDefinition;
    }

    /**
     * @param FiltersDefinition $filtersDefinition
     */
    public function setFiltersDefinition(FiltersDefinition $filtersDefinition): void
    {
        $this->filtersDefinition = $filtersDefinition;
    }

    /**
     * @param string $propertyName
     * @param string $direction
     * @throws BadRequestException
     */
    public function __construct(string $propertyName = null, string $direction = self::ASC)
    {
        $this->propertyName = $propertyName;
        $direction = strtoupper($direction);
        if (!in_array($direction, [self::ASC, self::DESC])) {
            throw new BadRequestException(
                'OrderBy direction has to be one of [' . implode(', ', [self::ASC, self::DESC]) . ']'
            );
        }
        $this->direction = $direction;
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return $this->propertyName . '_' . $this->direction;
    }


}