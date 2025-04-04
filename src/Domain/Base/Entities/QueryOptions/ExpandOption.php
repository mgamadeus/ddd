<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class ExpandOption extends ValueObject
{
    /** @var string The property name to expand */
    public ?string $propertyName;

    protected ?string $uniqueKey = null;

    /** @var FiltersOptions Filter to be applied on expansion */
    public FiltersOptions $filters;

    /** @var OrderByOptions OrderBy options to be applied on expansion */
    public OrderByOptions $orderByOptions;

    /** @var ExpandOptions Expand options can be recursive */
    public ExpandOptions $expandOptions;

    /** @var SelectOptions|null Select options to be applied on expansion */
    public ?SelectOptions $selectOptions = null;

    /** @var int Number of results to be skipped / offset */
    public int $skip = 0;

    /** @var int The number of results to be returned */
    public ?int $top = null;

    /** @var string|null Cursor for point to a resultset that was previously provided */
    public ?string $skiptoken = null;

    /**
     * @param string|null $propertyName
     * @param string|null $expandParameters
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
     * Parses expand parameters including select clauses.
     *
     * @param string $expandParameters
     * @return void
     * @throws BadRequestException
     */
    public function parseExpandParameters(string $expandParameters)
    {
        // Detect expand options.
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
        // Parse filters, orderBy, top, skip, and select.
        preg_match_all(
            '/filters\s*=\s*(?P<filters>[^;]+)|orderBy\s*=\s*(?P<orderBy>[^;]+)|top\s*=\s*(?P<top>[^;]+)|skip\s*=\s*(?P<skip>[^;]+)|skiptoken\s*=\s*(?P<skiptoken>[^;]+)|select\s*=\s*(?P<select>[^;]+)/mi',
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
        if (isset($matches['skiptoken'])) {
            foreach ($matches['skiptoken'] as $currentMatch) {
                if ($currentMatch) {
                    $this->skiptoken = $currentMatch;
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
        if (isset($matches['select'])) {
            foreach ($matches['select'] as $currentMatch) {
                if ($currentMatch) {
                    $this->selectOptions = SelectOptions::fromString($currentMatch);
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