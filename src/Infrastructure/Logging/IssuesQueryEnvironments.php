<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * The environments of one registry job, in configuration order.
 *
 * @property IssuesQueryEnvironment[] $elements;
 * @method IssuesQueryEnvironment[] getElements()
 * @method IssuesQueryEnvironment|null first()
 * @method IssuesQueryEnvironment|null getByUniqueKey(string $uniqueKey)
 */
class IssuesQueryEnvironments extends ObjectSet
{
    /**
     * From `ISSUES_QUERY_ENVIRONMENTS`: a JSON object `{"<name>": "<selector>", …}`.
     *
     * @throws InternalErrorException Not a JSON object of non-empty names and selectors
     */
    public static function fromJson(string $json): static
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw new InternalErrorException('ISSUES_QUERY_ENVIRONMENTS must be a JSON object {"<environment>": "<selector>", …}');
        }
        $issuesQueryEnvironments = new static();
        foreach ($decoded as $name => $selector) {
            if (!is_string($selector) || trim((string)$name) === '' || trim($selector) === '') {
                throw new InternalErrorException(sprintf('ISSUES_QUERY_ENVIRONMENTS: environment "%s" needs a non-empty selector', $name));
            }
            $issuesQueryEnvironment = new IssuesQueryEnvironment(trim((string)$name), trim($selector));
            $issuesQueryEnvironments->add($issuesQueryEnvironment);
        }
        return $issuesQueryEnvironments;
    }

    public function getByName(string $name): ?IssuesQueryEnvironment
    {
        return $this->getByUniqueKey(IssuesQueryEnvironment::uniqueKeyStatic($name));
    }

    /** @return string[] */
    public function getNames(): array
    {
        return array_map(static fn(IssuesQueryEnvironment $issuesQueryEnvironment): string => $issuesQueryEnvironment->name, $this->getElements());
    }
}
