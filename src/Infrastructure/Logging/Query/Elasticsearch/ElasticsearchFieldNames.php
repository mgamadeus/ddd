<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;

/**
 * The index's field names where they depend on its mapping. String fields aggregate and filter on their keyword
 * sub-field — `error.type.keyword` with Logstash / Filebeat dynamic mapping (the default), `error.type` itself with an
 * index template that maps them as keyword (suffix ''). Numeric fields (`account.id`, the location field) never take
 * a suffix.
 */
class ElasticsearchFieldNames extends ValueObject
{
    public function __construct(
        public readonly string $keywordSuffix = IssuesRegistrySettings::DEFAULT_ES_KEYWORD_SUFFIX,
        public readonly string $locationField = IssuesRegistrySettings::DEFAULT_ES_LOCATION_FIELD,
        public readonly string $workspaceField = IssuesRegistrySettings::DEFAULT_ES_WORKSPACE_FIELD,
    ) {
        parent::__construct();
    }

    public static function fromSettings(IssuesRegistrySettings $issuesRegistrySettings): static
    {
        return new static(
            $issuesRegistrySettings->elasticsearchKeywordSuffix,
            $issuesRegistrySettings->elasticsearchLocationField,
            $issuesRegistrySettings->elasticsearchWorkspaceField,
        );
    }

    /** The aggregatable / exactly matchable name of a string field */
    public function keyword(string $fieldName): string
    {
        return $fieldName . $this->keywordSuffix;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
