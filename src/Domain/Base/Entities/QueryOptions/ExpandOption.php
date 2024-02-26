<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;

class ExpandOption extends ValueObject
{
    /** @var string The proeprty name to expand */
    public ?string $propertyName;

    protected ?string $uniqueKey = null;

    /** @var FiltersOptions Filter to be applied on expansion */
    public FiltersOptions $filters;

    /** @var OrderByOptions OrderBy options to be applied on expansion */
    public OrderByOptions $orderByOptions;

    /** @var ExpandOptions Expand options can be recursive */
    public ExpandOptions $expandOptions;

    /** @var int Number of results to be skipped / offsetted */
    public int $skip = 0;

    /** @var int The number of results to be returned */
    public ?int $top = null;

    /**
     * @param string $propertyName
     * @param string $direction
     * @throws BadRequestException
     */
    public function __construct(string $propertyName = null, string $expandParameters = null)
    {
        $this->propertyName = $propertyName;
        if ($expandParameters) {
            $this->parseExpandParameters($expandParameters);
        }
        parent::__construct();
    }

    /**
     * Parses expand parameters
     * @param string $expandParameters
     * @return void
     * @throws BadRequestException
     */
    public function parseExpandParameters(string $expandParameters)
    {
        // first we detect expand options
        $expandPattern = '/expand\s*=\s*(?P<expandOptions>((\s*\w+\s*(?P<expandParameters>\((?:[^()]+|(?&expandParameters))*\))?)+,?)+)/mi';
        preg_match_all($expandPattern, $expandParameters, $matches);
        if (isset($matches['expandOptions'])) {
            foreach ($matches['expandOptions'] as $currentMatch) {
                $this->expandOptions = ExpandOptions::fromString($matches['expandOptions'][0]);
            }
            $expandParameters = preg_replace(
                '/(?P<expandText>expand\s*=\s*(?P<expandOptions>((\s*\w+\s*(?P<expandParameters>\((?:[^()]+|(?&expandParameters))*\))?)+,?)+))/mi',
                '',
                $expandParameters
            );
        }
        preg_match_all(
            '/filters\s*=\s*(?P<filters>[^;]+)|orderBy\s*=\s*(?P<orderBy>[^;]+)|top\s*=\s*(?P<top>[^;]+)|skip\s*=\s*(?P<skip>[^;]+)/mi',
            $expandParameters,
            $matches
        );
        if (isset($matches['skip'])) {
            foreach ($matches['skip'] as $currentMatch) {
                if ($currentMatch) {
                    $this->skip = (int)$currentMatch;
                }
            }
        }
        if (isset($matches['top'])) {
            foreach ($matches['top'] as $currentMatch) {
                if ($currentMatch) {
                    $this->top = (int)$currentMatch;
                }
            }
        }
        if (isset($matches['orderBy'])) {
            foreach ($matches['orderBy'] as $currentMatch) {
                if ($currentMatch) {
                    $this->orderByOptions = OrderByOptions::fromString($currentMatch);
                }
            }
        }
        if (isset($matches['filters'])) {
            foreach ($matches['filters'] as $currentMatch) {
                if ($currentMatch) {
                    $this->filters = FiltersOptions::fromString($currentMatch);
                }
            }
        }
    }

    public function uniqueKey(): string
    {
        if ($this->uniqueKey) {
            return $this->uniqueKey;
        }
        $key = md5(json_encode($this->toObject(true, true)));
        $this->uniqueKey = self::uniqueKeyStatic($key);
        return $this->uniqueKey;
    }
}